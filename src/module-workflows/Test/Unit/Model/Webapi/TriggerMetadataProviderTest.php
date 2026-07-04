<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Model\Webapi\TriggerMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflows/meta/triggers projects TriggerRegistry records to DTOs.
 */
class TriggerMetadataProviderTest extends TestCase
{
    /**
     * Stub registry: empty constructor (no parent call), so no Config\Data is
     * required — mirrors the StubSimulationContextFactory pattern.
     *
     * @param array<string, array<string, string|null>> $triggers
     */
    private function registry(array $triggers): TriggerRegistry
    {
        return new class ($triggers) extends TriggerRegistry {
            /** @param array<string, array<string, string|null>> $triggers */
            public function __construct(private readonly array $triggers)
            {
            }

            public function getAll(): array
            {
                return $this->triggers;
            }
        };
    }

    public function testProjectsEveryTrigger(): void
    {
        $registry = $this->registry([
            'sales.order.created' => [
                'event' => 'sales.order.created',
                'entity' => 'sales_order',
                'label' => 'Order Created',
                'group' => 'Sales',
                'resolver' => null,
            ],
            'customer.registered' => [
                'event' => 'customer.registered',
                'entity' => 'customer',
                'label' => 'Customer Registered',
                'group' => null,
            ],
        ]);

        $triggers = (new TriggerMetadataProvider($registry))->getTriggers();

        $this->assertCount(2, $triggers);
        $this->assertSame('sales.order.created', $triggers[0]->getEvent());
        $this->assertSame('sales_order', $triggers[0]->getEntity());
        $this->assertSame('Order Created', $triggers[0]->getLabel());
        $this->assertSame('Sales', $triggers[0]->getGroup());
        $this->assertNull($triggers[1]->getGroup());
    }

    public function testEmptyRegistryYieldsEmptyList(): void
    {
        $this->assertSame([], (new TriggerMetadataProvider($this->registry([])))->getTriggers());
    }
}
