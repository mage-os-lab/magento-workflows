<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsActionsCore\Action\Notify\Webhook;
use MageOS\WorkflowsActionsCore\Exception\BlockedHostException;
use MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake\FakeHttpClient;
use MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake\FakeRequest;
use MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake\FakeResponse;
use MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake\FakeUri;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Shim bootstrap for the standalone runner ONLY. Its shim autoloader covers
// Magento\ / Psr\Log\ exclusively, so the Guzzle / PSR-7 stand-ins under
// dev/tests/shims are required explicitly here — guarded so that a real
// Composer install (CI runs this suite under real Magento + Guzzle) always
// wins and these requires never execute.
// ---------------------------------------------------------------------------
(static function (): void {
    $shims = dirname(__DIR__, 6) . '/dev/tests/shims';
    if (!interface_exists(\Psr\Http\Message\MessageInterface::class)) {
        require_once $shims . '/Psr/Http/Message/MessageInterface.php';
        require_once $shims . '/Psr/Http/Message/StreamInterface.php';
        require_once $shims . '/Psr/Http/Message/UriInterface.php';
        require_once $shims . '/Psr/Http/Message/RequestInterface.php';
        require_once $shims . '/Psr/Http/Message/ResponseInterface.php';
    }
    if (!interface_exists(\GuzzleHttp\Exception\GuzzleException::class)) {
        require_once $shims . '/GuzzleHttp/Exception/GuzzleException.php';
        require_once $shims . '/GuzzleHttp/Exception/TransferException.php';
        require_once $shims . '/GuzzleHttp/Exception/ConnectException.php';
        require_once $shims . '/GuzzleHttp/Exception/RequestException.php';
    }
    if (!class_exists(\GuzzleHttp\RequestOptions::class)) {
        require_once $shims . '/GuzzleHttp/RequestOptions.php';
    }
    if (!class_exists(\GuzzleHttp\Client::class)) {
        require_once $shims . '/GuzzleHttp/Client.php';
    }
})();

/**
 * Behavior tests for the notify.webhook execute() path — the SSRF hardening
 * and failure-classification promises of docs/10-security.md ("SSRF hardening")
 * and docs/07-actions.md ("Webhook action with response capture").
 *
 * The private-range classifier itself (isForbiddenIp) and header sanitizing
 * (buildHeaders) are covered by WebhookTest; nothing here duplicates that.
 * All destinations use literal IPs (or localhost via /etc/hosts) so no test
 * depends on external DNS.
 */
class WebhookExecuteTest extends TestCase
{
    // Public: an anonymous fake class inside a test method references it,
    // and anonymous classes are distinct classes with no access to privates.
    public const SECRET_VALUE = 'tok-SECRET-9f8e7d';

    private function ctx(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub());
    }

    private function webhook(
        FakeHttpClient $client,
        array $configValues = [],
        array $secrets = []
    ): Webhook {
        return new Webhook($client, new StubScopeConfig($configValues), new SecretsProviderStub($secrets));
    }

    // -- docs/10: private / loopback / metadata destinations are rejected
    //    BEFORE any HTTP request leaves the box -------------------------------

    public function testPrivateRangeDestinationIsTerminalFailureWithNoHttpRequest(): void
    {
        $client = new FakeHttpClient();
        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://10.0.0.5/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'SSRF block must be terminal, not retried');
        $this->assertStringContainsString('blocked', (string)$result->getError());
        $this->assertCount(0, $client->requests, 'no HTTP request may be issued for a blocked destination');
    }

    public function testLoopbackDestinationIsBlockedWithNoHttpRequest(): void
    {
        $client = new FakeHttpClient();
        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://127.0.0.1/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertCount(0, $client->requests);
    }

    public function testCloudMetadataEndpointIsBlockedWithNoHttpRequest(): void
    {
        $client = new FakeHttpClient();
        $result = $this->webhook($client)->execute(
            $this->ctx(),
            ['url' => 'https://169.254.169.254/latest/meta-data/']
        );

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertCount(0, $client->requests);
    }

    public function testHostnameResolvingToLoopbackIsBlockedWithNoHttpRequest(): void
    {
        // "localhost" resolves via /etc/hosts (no external DNS): the action
        // must resolve-then-validate and refuse the loopback destination.
        $client = new FakeHttpClient();
        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://localhost/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('blocked', (string)$result->getError());
        $this->assertCount(0, $client->requests);
    }

    // -- docs/10: admin allowlist lets a private host through, and the
    //    connection is pinned to the validated IP (DNS pinning) --------------

    public function testAllowlistedPrivateHostProceedsAndIsPinnedToValidatedIp(): void
    {
        $client = new FakeHttpClient(static fn () => new FakeResponse(200, '{"ok":true}'));
        $webhook = $this->webhook($client, [
            Webhook::CONFIG_PRIVATE_HOST_ALLOWLIST => '10.0.0.5, other.internal',
        ]);

        $result = $webhook->execute($this->ctx(), ['url' => 'https://10.0.0.5/hook', 'body' => '{}']);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $client->requests);
        $this->assertSame(200, $result->getOutput()['status_code']);
        $this->assertSame(['ok' => true], $result->getOutput()['response']);

        $options = $client->requests[0]['options'];
        $this->assertArrayHasKey('curl', $options);
        $this->assertSame(['10.0.0.5:443:10.0.0.5'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function testAllowlistedHostnameIsResolvedOnceAndPinned(): void
    {
        $client = new FakeHttpClient(static fn () => new FakeResponse(200, '{}'));
        $webhook = $this->webhook($client, [
            Webhook::CONFIG_PRIVATE_HOST_ALLOWLIST => 'localhost',
        ]);

        $result = $webhook->execute($this->ctx(), ['url' => 'https://localhost/hook']);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $client->requests);
        $options = $client->requests[0]['options'];
        $this->assertArrayHasKey('curl', $options);
        // Pinned to whatever the resolver returned for localhost (127.0.0.1 / ::1)
        $this->assertStringContainsString('localhost:443:', $options['curl'][CURLOPT_RESOLVE][0]);
    }

    public function testNonAllowlistedPublicIpIsAlsoPinned(): void
    {
        $client = new FakeHttpClient(static fn () => new FakeResponse(200, '{}'));
        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $this->assertTrue($result->isSuccess());
        $options = $client->requests[0]['options'];
        $this->assertSame(['93.184.216.34:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    // -- docs/10: plain HTTP is a double opt-in (step flag AND global config) --

    public function testPlainHttpIsDeniedByDefault(): void
    {
        $client = new FakeHttpClient();
        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'http://93.184.216.34/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('HTTPS', (string)$result->getError());
        $this->assertCount(0, $client->requests);
    }

    public function testPlainHttpWithOnlyTheStepFlagIsStillDenied(): void
    {
        $client = new FakeHttpClient();
        $result = $this->webhook($client)->execute(
            $this->ctx(),
            ['url' => 'http://93.184.216.34/hook', 'allow_http' => true]
        );

        $this->assertTrue($result->isFailure());
        $this->assertCount(0, $client->requests, 'step flag alone must not enable plain HTTP');
    }

    public function testPlainHttpWithOnlyTheGlobalConfigIsStillDenied(): void
    {
        $client = new FakeHttpClient();
        $webhook = $this->webhook($client, [Webhook::CONFIG_ALLOW_INSECURE_HTTP => true]);
        $result = $webhook->execute($this->ctx(), ['url' => 'http://93.184.216.34/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertCount(0, $client->requests, 'global config alone must not enable plain HTTP');
    }

    public function testPlainHttpProceedsWithDoubleOptIn(): void
    {
        $client = new FakeHttpClient(static fn () => new FakeResponse(200, '{}'));
        $webhook = $this->webhook($client, [Webhook::CONFIG_ALLOW_INSECURE_HTTP => true]);

        $result = $webhook->execute(
            $this->ctx(),
            ['url' => 'http://93.184.216.34/hook', 'allow_http' => true]
        );

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $client->requests);
    }

    // -- docs/10: redirect destinations are re-validated; a private-range
    //    target aborts the transfer -------------------------------------------

    public function testRedirectToPrivateRangeTargetIsAbortedTerminally(): void
    {
        $client = new FakeHttpClient(static function (string $method, string $uri, array $options) {
            $onRedirect = $options[RequestOptions::ALLOW_REDIRECTS]['on_redirect'];
            // Simulate Guzzle announcing a redirect to an internal address:
            // the re-validation hook must throw and abort the transfer.
            $onRedirect(new FakeRequest(), new FakeResponse(302), new FakeUri('10.0.0.9'));
            return new FakeResponse(200, '{"never":"reached"}');
        });

        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'a blocked redirect is terminal, not retryable');
        $this->assertStringContainsString('redirect blocked', (string)$result->getError());
    }

    public function testRedirectBlockWrappedByGuzzleIsStillReportedAsBlocked(): void
    {
        // Real Guzzle surfaces middleware exceptions wrapped in a
        // GuzzleException chain; the action must still classify it as a
        // terminal SSRF block, not a retryable transport error.
        $client = new FakeHttpClient(static function (string $method, string $uri, array $options) {
            $onRedirect = $options[RequestOptions::ALLOW_REDIRECTS]['on_redirect'];
            try {
                $onRedirect(new FakeRequest(), new FakeResponse(302), new FakeUri('169.254.169.254'));
            } catch (BlockedHostException $e) {
                throw new RequestException('transfer aborted', new FakeRequest(), null, $e);
            }
            return new FakeResponse(200, '{}');
        });

        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('redirect blocked', (string)$result->getError());
    }

    public function testRedirectToPublicTargetIsAllowedThrough(): void
    {
        $client = new FakeHttpClient(static function (string $method, string $uri, array $options) {
            $onRedirect = $options[RequestOptions::ALLOW_REDIRECTS]['on_redirect'];
            $onRedirect(new FakeRequest(), new FakeResponse(302), new FakeUri('8.8.4.4'));
            return new FakeResponse(200, '{"ok":true}');
        });

        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['ok' => true], $result->getOutput()['response']);
    }

    public function testRedirectsOnlyPermitHttpsWithoutTheInsecureOptIn(): void
    {
        $client = new FakeHttpClient(static fn () => new FakeResponse(200, '{}'));
        $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $redirects = $client->requests[0]['options'][RequestOptions::ALLOW_REDIRECTS];
        $this->assertSame(['https'], $redirects['protocols']);
        $this->assertSame(3, $redirects['max']);
    }

    // -- docs/07: 5xx / timeout retryable, 4xx terminal ------------------------

    public function testServerErrorIsARetryableFailure(): void
    {
        $client = new FakeHttpClient(static fn () => new FakeResponse(500, ''));
        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), '5xx must be redelivered via the queue');
        $this->assertSame(500, $result->getOutput()['status_code']);
    }

    public function testClientErrorIsATerminalFailure(): void
    {
        $client = new FakeHttpClient(static fn () => new FakeResponse(404, '{"error":"nope"}'));
        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), '4xx must fail the step terminally');
        $this->assertSame(404, $result->getOutput()['status_code']);
    }

    public function testConnectTimeoutIsARetryableFailure(): void
    {
        $client = new FakeHttpClient(static function () {
            throw new ConnectException('cURL error 28: operation timed out', new FakeRequest());
        });

        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'timeouts must be redelivered via the queue');
    }

    // -- docs/10: response caps — oversized / unparseable bodies capture
    //    {parse_error: true}, never raw bytes ---------------------------------

    public function testResponseBodyOverTheCapIsTruncatedToParseErrorNotRawBytes(): void
    {
        $oversized = '{"padding":"' . str_repeat('A', 300000) . 'CANARY-BYTES"}'; // > 256KB, valid JSON
        $client = new FakeHttpClient(static fn () => new FakeResponse(200, $oversized));

        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $output = $result->getOutput();
        $this->assertTrue($output['truncated']);
        $this->assertSame(['parse_error' => true], $output['response']);
        $this->assertStringNotContainsString('CANARY-BYTES', json_encode($output) ?: '');
    }

    public function testNonJsonResponseCapturesParseErrorInsteadOfRawBytes(): void
    {
        $client = new FakeHttpClient(
            static fn () => new FakeResponse(200, '<html>internal-page-CANARY</html>')
        );

        $result = $this->webhook($client)->execute($this->ctx(), ['url' => 'https://93.184.216.34/hook']);

        $this->assertSame(['parse_error' => true], $result->getOutput()['response']);
        $this->assertStringNotContainsString('internal-page-CANARY', json_encode($result->getOutput()) ?: '');
    }

    // -- docs/07 + docs/10: auth secret resolved at send time, never leaked ----

    public function testBearerSecretIsResolvedAtSendTimeIntoTheHeaderOnly(): void
    {
        $secretReads = new \ArrayObject();
        $secrets = new class([], $secretReads) extends SecretsProviderStub {
            private \ArrayObject $reads;
            public function __construct(array $unused, \ArrayObject $reads)
            {
                parent::__construct(['fraud_api' => WebhookExecuteTest::SECRET_VALUE]);
                $this->reads = $reads;
            }
            public function get(string $key): ?string
            {
                $this->reads[] = $key;
                return parent::get($key);
            }
        };
        $client = new FakeHttpClient(static fn () => new FakeResponse(200, '{"score":12}'));
        $webhook = new Webhook($client, new StubScopeConfig([]), $secrets);

        $result = $webhook->execute($this->ctx(), [
            'url' => 'https://93.184.216.34/hook',
            'auth_type' => 'bearer',
            'auth_secret' => 'fraud_api',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['fraud_api'], $secretReads->getArrayCopy(), 'secret resolved exactly once, at send time');

        $sentHeaders = $client->requests[0]['options'][RequestOptions::HEADERS];
        $this->assertSame('Bearer ' . self::SECRET_VALUE, $sentHeaders['Authorization']);

        // The resolved value must never surface in the step result
        $surface = json_encode($result->getOutput()) . '|' . (string)$result->getError();
        $this->assertStringNotContainsString(self::SECRET_VALUE, $surface);
    }

    public function testBasicSecretNeverAppearsInFailureResultOrError(): void
    {
        $client = new FakeHttpClient(static fn () => new FakeResponse(500, ''));
        $webhook = $this->webhook($client, [], ['portal' => 'svc-user:' . self::SECRET_VALUE]);

        $result = $webhook->execute($this->ctx(), [
            'url' => 'https://93.184.216.34/hook',
            'auth_type' => 'basic',
            'auth_secret' => 'portal',
        ]);

        $this->assertTrue($result->isFailure());
        $sentHeaders = $client->requests[0]['options'][RequestOptions::HEADERS];
        $this->assertSame('Basic ' . base64_encode('svc-user:' . self::SECRET_VALUE), $sentHeaders['Authorization']);

        $surface = json_encode($result->getOutput()) . '|' . (string)$result->getError();
        $this->assertStringNotContainsString(self::SECRET_VALUE, $surface);
        $this->assertStringNotContainsString(base64_encode('svc-user:' . self::SECRET_VALUE), $surface);
    }

    public function testSecretNeverAppearsInTransportFailureError(): void
    {
        $client = new FakeHttpClient(static function () {
            throw new ConnectException('connection refused', new FakeRequest());
        });
        $webhook = $this->webhook($client, [], ['fraud_api' => self::SECRET_VALUE]);

        $result = $webhook->execute($this->ctx(), [
            'url' => 'https://93.184.216.34/hook',
            'auth_type' => 'bearer',
            'auth_secret' => 'fraud_api',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringNotContainsString(
            self::SECRET_VALUE,
            json_encode($result->getOutput()) . '|' . (string)$result->getError()
        );
    }

    public function testMissingAuthSecretFailsBeforeAnyRequest(): void
    {
        $client = new FakeHttpClient();
        $result = $this->webhook($client)->execute($this->ctx(), [
            'url' => 'https://93.184.216.34/hook',
            'auth_type' => 'bearer',
            'auth_secret' => 'not_configured',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('auth secret not found', (string)$result->getError());
        $this->assertCount(0, $client->requests);
    }

    // -- misc contract: userinfo smuggling is refused --------------------------

    public function testUserinfoInUrlIsRefusedWithoutRequest(): void
    {
        $client = new FakeHttpClient();
        $result = $this->webhook($client)->execute(
            $this->ctx(),
            ['url' => 'https://admin:pw@93.184.216.34/hook']
        );

        $this->assertTrue($result->isFailure());
        $this->assertCount(0, $client->requests);
        $this->assertSame(ActionResultInterface::STATUS_FAILURE, $result->getStatus());
    }
}
