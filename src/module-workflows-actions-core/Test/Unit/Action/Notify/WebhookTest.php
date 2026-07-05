<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use MageOS\WorkflowsActionsCore\Action\Notify\Webhook;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    private Webhook $webhook;
    private \ReflectionClass $reflection;

    public function setUp(): void
    {
        // Use reflection to construct without calling the type-hinted constructor
        // This avoids needing the real GuzzleHttp\Client class at parse time
        $this->reflection = new \ReflectionClass(Webhook::class);
        $this->webhook = $this->reflection->newInstanceWithoutConstructor();
    }

    public function testIsForbiddenIpWithLoopback127(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, '127.0.0.1'));
    }

    public function testIsForbiddenIpWithIpv6Loopback(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, '::1'));
    }

    public function testIsForbiddenIpWithPrivateRange10(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, '10.0.0.5'));
    }

    public function testIsForbiddenIpWithPrivateRange172(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, '172.16.0.1'));
    }

    public function testIsForbiddenIpWithPrivateRange192(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, '192.168.1.1'));
    }

    public function testIsForbiddenIpWithMetadataEndpoint(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, '169.254.169.254'));
    }

    public function testIsForbiddenIpWithIpv6Metadata(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, 'fd00:ec2::254'));
    }

    public function testIsForbiddenIpWithIpv6LinkLocal(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, 'fe80::1'));
    }

    public function testIsForbiddenIpWithIpv6UniqueLocal(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, 'fc00::1'));
        $this->assertTrue($method->invoke($this->webhook, 'fd00::1'));
    }

    public function testIsForbiddenIpWithIpv6Brackets(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, '[::1]'));
    }

    public function testIsForbiddenIpWithIpv4MappedPrivate(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertTrue($method->invoke($this->webhook, '::ffff:10.0.0.1'));
    }

    public function testIsForbiddenIpWithPublicAddress(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        $this->assertFalse($method->invoke($this->webhook, '93.184.216.34'));
    }

    public function testIsForbiddenIpWithPublicIpv6(): void
    {
        $method = $this->reflection->getMethod('isForbiddenIp');
        // 2606:4700:4700::1111 (Cloudflare) is genuine global-unicast; unlike the
        // 2001:db8::/32 documentation range, filter_var classifies it as public on
        // every PHP version (8.1's reserved-range list rejects 2001:db8::).
        $this->assertFalse($method->invoke($this->webhook, '2606:4700:4700::1111'));
    }

    public function testBuildHeadersWithEmptyConfig(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $headers = $method->invoke($this->webhook, [], '');
        $this->assertCount(0, $headers);
    }

    public function testBuildHeadersStripsHostHeader(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $config = ['headers' => '{"Host": "example.com", "X-Custom": "value"}'];
        $headers = $method->invoke($this->webhook, $config, '');
        $this->assertFalse(in_array('Host', array_keys($headers)));
        $this->assertSame('value', $headers['X-Custom'] ?? null);
    }

    public function testBuildHeadersStripsContentLengthHeader(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $config = ['headers' => '{"Content-Length": "100"}'];
        $headers = $method->invoke($this->webhook, $config, '');
        $this->assertFalse(in_array('Content-Length', array_keys($headers)));
    }

    public function testBuildHeadersStripsHopByHopHeaders(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $config = ['headers' => '{"Transfer-Encoding": "chunked", "Connection": "close"}'];
        $headers = $method->invoke($this->webhook, $config, '');
        $this->assertFalse(in_array('Transfer-Encoding', array_keys($headers)));
        $this->assertFalse(in_array('Connection', array_keys($headers)));
    }

    public function testBuildHeadersStripsCarriageReturnAndNewline(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $config = ['headers' => '{"X-Custom": "value\\r\\ninjection"}'];
        $headers = $method->invoke($this->webhook, $config, '');
        $this->assertSame('valueinjection', $headers['X-Custom'] ?? null);
    }

    public function testBuildHeadersRejectsInvalidHeaderNames(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $config = ['headers' => '{"X@Invalid": "value", "X-Valid": "ok"}'];
        $headers = $method->invoke($this->webhook, $config, '');
        $this->assertFalse(in_array('X@Invalid', array_keys($headers)));
        $this->assertSame('ok', $headers['X-Valid'] ?? null);
    }

    public function testBuildHeadersAddsContentTypeForJsonBody(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $headers = $method->invoke($this->webhook, [], '{"key": "value"}');
        $this->assertSame('application/json', $headers['Content-Type'] ?? null);
    }

    public function testBuildHeadersNoContentTypeForEmptyBody(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $headers = $method->invoke($this->webhook, [], '');
        $this->assertFalse(isset($headers['Content-Type']));
    }

    public function testBuildHeadersSignsBodyWithHmac(): void
    {
        $method = $this->reflection->getMethod('buildHeaders');
        $body = '{"test": "data"}';
        $config = ['sign_with' => 'my-secret-key'];
        $headers = $method->invoke($this->webhook, $config, $body);
        $expected = hash_hmac('sha256', $body, 'my-secret-key');
        $this->assertSame($expected, $headers['X-MageOS-Webhook-Signature'] ?? null);
    }

}
