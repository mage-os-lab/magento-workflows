<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Notify;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\WorkflowsActionsCore\Exception\BlockedHostException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * notify.webhook — synchronous HTTP call with response capture.
 *
 * Ships HARDENED, NOT HARDENABLE (docs/10-security.md, SSRF hardening):
 *  - HTTPS required; plain HTTP needs BOTH the per-step allow_http flag AND
 *    the mageos_workflows/webhook/allow_insecure_http admin config (double opt-in).
 *  - DNS is resolved FIRST; any A/AAAA record in private / loopback /
 *    link-local / reserved space or the cloud metadata endpoint rejects the
 *    call, unless the exact host is on the admin-configured allowlist
 *    (mageos_workflows/webhook/private_host_allowlist).
 *  - The connection is PINNED to the validated IP via CURLOPT_RESOLVE so a
 *    rebinding DNS server cannot re-resolve the host elsewhere between
 *    validation and connect.
 *  - Redirects: max 3, each destination re-validated against the same
 *    private-range rules via on_redirect; a violation aborts the transfer.
 *  - HMAC-SHA256 of the body in X-MageOS-Webhook-Signature when sign_with is
 *    configured (same convention as the async-events HTTP notifier).
 *  - Response caps: 256KB body read, json_decode depth 10; parse failure
 *    captures {parse_error: true} instead of raw bytes.
 *  - Optional response_schema: minimal required-key check; mismatch is a
 *    terminal step failure so garbage never reaches downstream branches.
 *  - 5xx / timeout / connect errors are retryable failures (queue
 *    redelivery); 4xx is terminal.
 *
 * Trust boundary: the captured response is attacker-influenceable data. It is
 * merged into steps.<key> for conditions and VALUE interpolation only — the
 * engine never resolves action codes or attribute codes from step output.
 */
class Webhook extends AbstractAction implements SimulateableActionInterface
{
    public const SIGNATURE_HEADER = 'X-MageOS-Webhook-Signature';

    public const CONFIG_ALLOW_INSECURE_HTTP = 'mageos_workflows/webhook/allow_insecure_http';
    public const CONFIG_PRIVATE_HOST_ALLOWLIST = 'mageos_workflows/webhook/private_host_allowlist';
    public const CONFIG_DEFAULT_TIMEOUT = 'mageos_workflows/webhook/default_timeout';
    public const CONFIG_MAX_TIMEOUT = 'mageos_workflows/webhook/max_timeout';

    private const MAX_RESPONSE_BYTES = 262144; // 256KB
    private const MAX_JSON_DEPTH = 10;
    private const MAX_REDIRECTS = 3;
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
    private const STRIPPED_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection'];
    private const METADATA_ENDPOINTS = ['169.254.169.254', 'fd00:ec2::254'];

    public function __construct(
        private readonly Client $httpClient,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getCode(): string
    {
        return 'notify.webhook';
    }

    public function getLabel(): string
    {
        return (string)__('Call Webhook');
    }

    public function getGroup(): string
    {
        return (string)__('Notify');
    }

    public function getApplicableEntities(): array
    {
        return [];
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'url', 'label' => 'URL', 'type' => 'text', 'required' => true,
                'notice' => 'HTTPS required. Private/internal destinations are rejected unless allowlisted.'],
            ['name' => 'method', 'label' => 'Method', 'type' => 'select', 'required' => false,
                'default' => 'POST',
                'options' => [
                    ['value' => 'POST', 'label' => 'POST'],
                    ['value' => 'GET', 'label' => 'GET'],
                    ['value' => 'PUT', 'label' => 'PUT'],
                    ['value' => 'PATCH', 'label' => 'PATCH'],
                    ['value' => 'DELETE', 'label' => 'DELETE'],
                    ['value' => 'HEAD', 'label' => 'HEAD'],
                ]],
            ['name' => 'body', 'label' => 'JSON Body', 'type' => 'textarea', 'required' => false],
            ['name' => 'headers', 'label' => 'Headers (JSON object)', 'type' => 'textarea', 'required' => false],
            ['name' => 'timeout', 'label' => 'Timeout (seconds)', 'type' => 'integer', 'required' => false],
            ['name' => 'capture_as', 'label' => 'Capture Response As', 'type' => 'text', 'required' => false],
            ['name' => 'sign_with', 'label' => 'HMAC Secret', 'type' => 'secret', 'required' => false,
                'notice' => 'Reference a secret, e.g. {{ secrets.fraud_api_key }}. Adds ' . self::SIGNATURE_HEADER . '.'],
            ['name' => 'response_schema', 'label' => 'Required Response Keys (JSON array)', 'type' => 'textarea',
                'required' => false, 'notice' => 'Dot paths that must exist in the response, e.g. ["score"].'],
            ['name' => 'allow_http', 'label' => 'Allow Plain HTTP', 'type' => 'boolean', 'required' => false,
                'notice' => 'Only honored when the global insecure-HTTP config is also enabled.'],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $url = $this->stringConfig($config, 'url');
        if ($url === null) {
            return $this->missingConfig('url');
        }

        $method = strtoupper($this->stringConfig($config, 'method', 'POST') ?? 'POST');
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return ActionResult::failure((string)__('Invalid HTTP method "%1"', $method));
        }

        $storeId = $ctx->getStoreId();
        $insecureAllowed = $this->isInsecureHttpAllowed($config, $storeId);

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return ActionResult::failure((string)__('Invalid webhook URL "%1"', $url));
        }
        if ($scheme !== 'https' && !$insecureAllowed) {
            return ActionResult::failure(
                (string)__('Webhook URLs must use HTTPS (plain HTTP requires both the step flag and the global config opt-in)')
            );
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ActionResult::failure((string)__('Userinfo in webhook URLs is not allowed'));
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // --- SSRF: resolve first, validate every record, then pin ---
        try {
            $pinnedIp = $this->resolveAndValidate($host, $storeId);
        } catch (BlockedHostException $e) {
            return ActionResult::failure('Webhook destination blocked: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            return ActionResult::failure('DNS resolution failed for "' . $host . '": ' . $e->getMessage(), true);
        }

        $body = (string)($this->stringConfig($config, 'body') ?? '');
        $headers = $this->buildHeaders($config, $body);

        $timeout = min(
            max(1, $this->intConfig($config, 'timeout') ?? $this->configuredDefaultTimeout($storeId)),
            $this->configuredMaxTimeout($storeId)
        );

        $options = [
            RequestOptions::HEADERS => $headers,
            RequestOptions::TIMEOUT => $timeout,
            RequestOptions::CONNECT_TIMEOUT => $timeout,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::STREAM => true,
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'referer' => false,
                'protocols' => $insecureAllowed ? ['http', 'https'] : ['https'],
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($storeId): void {
                    // Re-validate every redirect destination against the same rules
                    $this->resolveAndValidate(strtolower(trim($uri->getHost(), '[]')), $storeId);
                },
            ],
        ];
        if ($body !== '' && $method !== 'GET' && $method !== 'HEAD') {
            $options[RequestOptions::BODY] = $body;
        }
        if ($pinnedIp !== null) {
            // Pin the connection to the address we validated (defeats DNS rebinding)
            $options['curl'] = [
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $pinnedIp)],
            ];
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (BlockedHostException $e) {
            return ActionResult::failure('Webhook redirect blocked: ' . $e->getMessage());
        } catch (ConnectException $e) {
            return ActionResult::failure('Webhook connection failed: ' . $e->getMessage(), true);
        } catch (GuzzleException $e) {
            $blocked = $this->findBlockedHostException($e);
            if ($blocked !== null) {
                return ActionResult::failure('Webhook redirect blocked: ' . $blocked->getMessage());
            }
            // Timeouts and transport-level failures may succeed on redelivery
            return ActionResult::failure('Webhook request failed: ' . $e->getMessage(), true);
        } catch (\Throwable $e) {
            $blocked = $this->findBlockedHostException($e);
            if ($blocked !== null) {
                return ActionResult::failure('Webhook redirect blocked: ' . $blocked->getMessage());
            }
            return ActionResult::failure('Webhook request failed: ' . $e->getMessage());
        }

        $statusCode = $response->getStatusCode();
        [$decoded, $truncated] = $this->readCappedJsonBody($response);

        $output = [
            'status_code' => $statusCode,
            'response' => $decoded,
        ];
        if ($truncated) {
            $output['truncated'] = true;
        }
        $captureAs = $this->stringConfig($config, 'capture_as');
        if ($captureAs !== null) {
            // Informational: the executor stores output under the step key regardless
            $output['capture_as'] = $captureAs;
        }

        if ($statusCode >= 500) {
            return ActionResult::failure((string)__('Webhook returned HTTP %1', $statusCode), true, $output);
        }
        if ($statusCode >= 400) {
            return ActionResult::failure((string)__('Webhook returned HTTP %1', $statusCode), false, $output);
        }

        $schemaError = $this->checkResponseSchema($config, $decoded);
        if ($schemaError !== null) {
            return ActionResult::failure($schemaError, false, $output);
        }

        return ActionResult::success($output);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $url = $this->stringConfig($config, 'url');
        if ($url === null) {
            return $this->missingConfig('url');
        }
        $method = strtoupper($this->stringConfig($config, 'method', 'POST') ?? 'POST');
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return ActionResult::failure((string)__('Invalid HTTP method "%1"', $method));
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return ActionResult::failure((string)__('Invalid webhook URL "%1"', $url));
        }
        if ($scheme !== 'https' && !$this->isInsecureHttpAllowed($config, $ctx->getStoreId())) {
            return ActionResult::failure((string)__('Webhook URLs must use HTTPS'));
        }
        return $this->simulated(
            sprintf('%s %s (no request sent)', $method, $url),
            // Empty capture so downstream shadow references resolve to null, not garbage
            ['status_code' => 0, 'response' => []]
        );
    }

    /**
     * Resolve the host and validate every resolved address against private/
     * reserved/metadata ranges. Returns the IP to pin the connection to, or
     * null for allowlisted hosts left to normal resolution when unresolvable.
     *
     * @throws BlockedHostException when any resolved address is forbidden
     * @throws \RuntimeException when resolution fails entirely (retryable)
     */
    private function resolveAndValidate(string $host, int $storeId): ?string
    {
        if ($host === '') {
            throw new BlockedHostException('empty host');
        }

        $allowlisted = in_array($host, $this->getPrivateHostAllowlist($storeId), true);

        // Literal IP: validate directly, no DNS involved
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$allowlisted && $this->isForbiddenIp($host)) {
                throw new BlockedHostException(sprintf('"%s" is a private/reserved address', $host));
            }
            return $host;
        }

        $ips = $this->resolveIps($host);
        if ($ips === []) {
            throw new \RuntimeException('no A/AAAA records');
        }

        if (!$allowlisted) {
            foreach ($ips as $ip) {
                if ($this->isForbiddenIp($ip)) {
                    throw new BlockedHostException(sprintf(
                        '"%s" resolves to private/reserved address %s',
                        $host,
                        $ip
                    ));
                }
            }
        }

        return $ips[0];
    }

    /**
     * @return string[] all A/AAAA addresses for the host
     */
    private function resolveIps(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A);
        foreach (is_array($records) ? $records : [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = (string)$record['ip'];
            }
        }
        $records = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = (string)$record['ipv6'];
            }
        }
        if ($ips === []) {
            // Fallback resolver: gethostbyname() returns the input on failure
            $ip = gethostbyname($host);
            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Private (RFC1918/ULA), loopback, link-local, reserved, and cloud
     * metadata addresses are forbidden webhook destinations.
     */
    private function isForbiddenIp(string $ip): bool
    {
        $normalized = strtolower(trim($ip, '[]'));

        if (in_array($normalized, self::METADATA_ENDPOINTS, true) || $normalized === '::1') {
            return true;
        }
        // IPv6 link-local (fe80::/10) and unique-local (fc00::/7) belt-and-braces
        if (str_starts_with($normalized, 'fe80:')
            || str_starts_with($normalized, 'fc')
            || str_starts_with($normalized, 'fd')
        ) {
            return true;
        }
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) — validate the embedded IPv4
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $normalized, $matches)) {
            return $this->isForbiddenIp($matches[1]);
        }

        return filter_var(
            $normalized,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * @return string[] lower-case hostnames allowed to resolve into private ranges
     */
    private function getPrivateHostAllowlist(int $storeId): array
    {
        $raw = (string)$this->scopeConfig->getValue(
            self::CONFIG_PRIVATE_HOST_ALLOWLIST,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $hosts = [];
        foreach (preg_split('/[\s,]+/', strtolower($raw)) ?: [] as $entry) {
            if ($entry !== '') {
                $hosts[] = $entry;
            }
        }
        return $hosts;
    }

    /**
     * Plain HTTP needs the per-step flag AND the global admin config (double opt-in)
     */
    private function isInsecureHttpAllowed(array $config, int $storeId): bool
    {
        return $this->boolConfig($config, 'allow_http')
            && $this->scopeConfig->isSetFlag(
                self::CONFIG_ALLOW_INSECURE_HTTP,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
    }

    /**
     * @return array<string, string> sanitized request headers incl. signature
     */
    private function buildHeaders(array $config, string $body): array
    {
        $raw = $config['headers'] ?? [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true, 3);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $headers = [];
        foreach (is_array($raw) ? $raw : [] as $name => $value) {
            if (!is_string($name)
                || !preg_match('/^[A-Za-z0-9\-_]+$/', $name)
                || in_array(strtolower($name), self::STRIPPED_HEADERS, true)
                || !is_scalar($value)
            ) {
                continue;
            }
            // Header injection guard: no CR/LF in values
            $headers[$name] = str_replace(["\r", "\n"], '', (string)$value);
        }
        if (!isset($headers['Content-Type']) && $body !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        $signWith = $this->stringConfig($config, 'sign_with');
        if ($signWith !== null) {
            $headers[self::SIGNATURE_HEADER] = hash_hmac('sha256', $body, $signWith);
        }
        return $headers;
    }

    /**
     * Read at most 256KB of the (streamed) response body and JSON-decode it
     * with capped depth. Parse failures capture {parse_error: true}.
     *
     * @return array{0: array, 1: bool} [decoded body, truncated flag]
     */
    private function readCappedJsonBody(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        $raw = '';
        $truncated = false;
        try {
            while (!$stream->eof()) {
                if (strlen($raw) >= self::MAX_RESPONSE_BYTES) {
                    $truncated = true;
                    break;
                }
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            return [['parse_error' => true], $truncated];
        } finally {
            $stream->close();
        }
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            $raw = substr($raw, 0, self::MAX_RESPONSE_BYTES);
            $truncated = true;
        }

        if (trim($raw) === '') {
            return [[], $truncated];
        }
        // A truncated body is by definition not valid JSON — never parse-capture it
        if ($truncated) {
            return [['parse_error' => true], true];
        }

        $decoded = json_decode($raw, true, self::MAX_JSON_DEPTH);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [['parse_error' => true], $truncated];
        }
        return [$decoded, $truncated];
    }

    /**
     * Minimal structural check: every configured dot-path key must exist in
     * the decoded response. Returns an error string on mismatch.
     */
    private function checkResponseSchema(array $config, array $decoded): ?string
    {
        $schema = $config['response_schema'] ?? null;
        if (is_string($schema) && trim($schema) !== '') {
            $schema = json_decode($schema, true, 4);
        }
        if (!is_array($schema) || $schema === []) {
            return null;
        }
        $required = isset($schema['required']) && is_array($schema['required'])
            ? $schema['required']
            : $schema;

        foreach ($required as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $value = $decoded;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return (string)__('Webhook response is missing required key "%1"', $path);
                }
                $value = $value[$segment];
            }
        }
        return null;
    }

    private function configuredDefaultTimeout(int $storeId): int
    {
        $value = (int)$this->scopeConfig->getValue(self::CONFIG_DEFAULT_TIMEOUT, ScopeInterface::SCOPE_STORE, $storeId);
        return $value > 0 ? $value : 5;
    }

    private function configuredMaxTimeout(int $storeId): int
    {
        $value = (int)$this->scopeConfig->getValue(self::CONFIG_MAX_TIMEOUT, ScopeInterface::SCOPE_STORE, $storeId);
        return $value > 0 ? min($value, 30) : 30;
    }

    private function findBlockedHostException(\Throwable $e): ?BlockedHostException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof BlockedHostException) {
                return $current;
            }
        }
        return null;
    }
}
