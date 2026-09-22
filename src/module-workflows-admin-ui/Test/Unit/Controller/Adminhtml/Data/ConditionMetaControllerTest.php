<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Controller\Adminhtml\Data;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MageOS\Workflows\Model\Rule\ConditionMetaProvider;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Data\ConditionMeta;
use PHPUnit\Framework\TestCase;

/**
 * The condition-metadata feed the condition builder reads. Like its peer
 * Data/Options it is a read surface over a core provider, so the contract under
 * test is: ::view (not ::manage), GET-only, and a response envelope that
 * separates a client mistake (missing/unknown entity type, unreachable node
 * FQCN — 400) from a broken condition class (500 with no internals leaked).
 *
 * The controller has a heavy Action\Context constructor, so ACL/HTTP are
 * asserted by reflection and the response decision is invoked on an
 * un-constructed instance with only the provider injected (peer convention:
 * Workflow\ConditionsControllerTest, sales AttributeTest).
 */
class ConditionMetaControllerTest extends TestCase
{
    public function testMetadataFeedIsGatedByView(): void
    {
        $this->assertSame(
            'MageOS_Workflows::view',
            ConditionMeta::ADMIN_RESOURCE,
            'Reading the operators and labels behind a condition tree is a view action.'
        );
    }

    public function testMetadataFeedIsGetOnly(): void
    {
        $this->assertTrue(
            is_a(ConditionMeta::class, HttpGetActionInterface::class, true),
            'The metadata feed must declare itself GET-only.'
        );
        $this->assertFalse(
            is_a(ConditionMeta::class, HttpPostActionInterface::class, true),
            'Authoring posts to the ::manage apply target, never here.'
        );
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function respond(ConditionMetaProvider $provider, string $entityType, string $nodeType): array
    {
        $reflection = new \ReflectionClass(ConditionMeta::class);
        /** @var ConditionMeta $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('conditionMetaProvider')->setValue($controller, $provider);
        return (new \ReflectionMethod(ConditionMeta::class, 'buildResponse'))
            ->invoke($controller, $entityType, $nodeType);
    }

    public function testMissingEntityTypeIsAClientError(): void
    {
        [$code, $payload] = $this->respond(new ConditionMetaProviderStub(), '', '');

        $this->assertSame(400, $code);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('entity_type', (string)$payload['error']);
    }

    public function testAnOmittedNodeTypeDefaultsToTheEntityRoot(): void
    {
        $provider = new ConditionMetaProviderStub();

        [$code, $payload] = $this->respond($provider, 'sales_order', '');

        $this->assertSame(200, $code);
        $this->assertTrue($payload['success']);
        $this->assertSame('Root\\Combine', $payload['root']);
        // The provider was asked for the root, not for an empty node type.
        $this->assertSame(['sales_order', 'Root\\Combine'], $provider->lastCall);
        $this->assertSame('Root\\Combine', $payload['node']['type']);
    }

    public function testAnExplicitNodeTypeIsPassedThroughVerbatim(): void
    {
        $provider = new ConditionMetaProviderStub();

        [$code, $payload] = $this->respond($provider, 'sales_order', 'Some\\Leaf');

        $this->assertSame(200, $code);
        $this->assertSame(['sales_order', 'Some\\Leaf'], $provider->lastCall);
        // The root travels alongside every response so the client can tell
        // whether the node it is editing is still the tree's root.
        $this->assertSame('Root\\Combine', $payload['root']);
    }

    public function testRejectedNodeTypeIsA400CarryingTheProviderReason(): void
    {
        $provider = new ConditionMetaProviderStub(new \InvalidArgumentException('Condition type "X" is not reachable'));

        [$code, $payload] = $this->respond($provider, 'sales_order', 'X');

        $this->assertSame(400, $code);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('not reachable', (string)$payload['error']);
    }

    public function testABrokenConditionClassIsA500WithoutLeakingInternals(): void
    {
        $provider = new ConditionMetaProviderStub(new \RuntimeException('SQLSTATE[42S02]: table sales_order missing'));

        [$code, $payload] = $this->respond($provider, 'sales_order', '');

        $this->assertSame(500, $code);
        $this->assertFalse($payload['success']);
        $this->assertStringNotContainsString('SQLSTATE', (string)$payload['error']);
    }
}

/**
 * ConditionMetaProvider stand-in recording what the controller asked for; the
 * real provider's collaborators (ObjectManager + the DI pools) are irrelevant
 * to the controller's contract.
 */
final class ConditionMetaProviderStub extends ConditionMetaProvider
{
    /** @var array{0: string, 1: string}|null */
    public ?array $lastCall = null;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function getRootType(string $entityType): string
    {
        return 'Root\\Combine';
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetaForNode(string $entityType, string $nodeType): array
    {
        $this->lastCall = [$entityType, $nodeType];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return ['type' => $nodeType, 'kind' => 'combine'];
    }
}
