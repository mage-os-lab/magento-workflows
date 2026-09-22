<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Notify;

use GuzzleHttp\Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use MageOS\WorkflowsActionsCore\Action\Notify\Webhook;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #21 (docs/20-integration-test-plan.md §5) — Webhook DI + SSRF posture,
 * NO network. Proves the merged DI resolves the action with its real
 * collaborators wired, that the shipped SSRF caps are non-empty, and that a
 * private/metadata destination is refused with the documented BlockedHost
 * failure BEFORE any connection attempt (the guard returns before
 * Client::request is reached).
 *
 * DIVERGENCE (documented): docs/20 #21 anticipates the SSRF configuration
 * arriving as constructor arguments. In the shipped implementation the
 * denylist ranges / response caps are class constants + filter_var flags, not
 * DI arguments; only the HTTP client, scope config and secrets provider are
 * injected. This test asserts the constants are present and the behavior holds.
 *
 * @magentoDbIsolation enabled
 */
class WebhookDiWiringTest extends ActionTestCase
{
    private Webhook $action;

    protected function setUp(): void
    {
        $this->action = $this->resolve(Webhook::class);
    }

    public function testMergedDiWiresTheCollaborators(): void
    {
        $reflection = new \ReflectionObject($this->action);

        $client = $this->propertyValue($reflection, 'httpClient');
        $scopeConfig = $this->propertyValue($reflection, 'scopeConfig');
        $secrets = $this->propertyValue($reflection, 'secretsProvider');

        $this->assertInstanceOf(Client::class, $client, 'Guzzle client must be injected');
        $this->assertInstanceOf(ScopeConfigInterface::class, $scopeConfig, 'Scope config must be injected');
        $this->assertInstanceOf(SecretsProviderInterface::class, $secrets, 'Secrets provider must be injected');
    }

    public function testShippedSsrfCapsAreNonEmpty(): void
    {
        $reflection = new \ReflectionClass(Webhook::class);
        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('MAX_RESPONSE_BYTES', $constants);
        $this->assertGreaterThan(0, $constants['MAX_RESPONSE_BYTES']);
        $this->assertArrayHasKey('MAX_JSON_DEPTH', $constants);
        $this->assertGreaterThan(0, $constants['MAX_JSON_DEPTH']);
        $this->assertArrayHasKey('MAX_REDIRECTS', $constants);
        $this->assertArrayHasKey('METADATA_ENDPOINTS', $constants);
        $this->assertNotEmpty($constants['METADATA_ENDPOINTS'], 'Cloud metadata endpoints must be denied');
        $this->assertContains('169.254.169.254', $constants['METADATA_ENDPOINTS']);
    }

    public function testLoopbackDestinationIsRefusedBeforeConnecting(): void
    {
        $ctx = $this->buildContext(1, 1);
        $result = $this->action->execute($ctx, ['url' => 'https://127.0.0.1/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'A blocked destination is terminal, not retryable');
        $this->assertStringContainsString('blocked', (string)$result->getError());
    }

    public function testCloudMetadataDestinationIsRefused(): void
    {
        $ctx = $this->buildContext(1, 1);
        $result = $this->action->execute($ctx, ['url' => 'https://169.254.169.254/latest/meta-data/']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('blocked', (string)$result->getError());
    }

    public function testPlainHttpIsRefusedWithoutDoubleOptIn(): void
    {
        $ctx = $this->buildContext(1, 1);
        // Public-looking host over http, no allow_http flag and no global config:
        // rejected on the HTTPS policy before any DNS/connection work.
        $result = $this->action->execute($ctx, ['url' => 'http://example.com/hook']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('HTTPS', (string)$result->getError());
    }

    private function propertyValue(\ReflectionObject $reflection, string $name): mixed
    {
        // No setAccessible(): a no-op since PHP 8.1 and a deprecation-exception on 8.5.
        return $reflection->getProperty($name)->getValue($this->action);
    }
}
