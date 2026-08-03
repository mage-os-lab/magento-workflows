<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Block\Adminhtml\Execution;

use Magento\Store\Model\System\Store as SystemStore;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\Workflows\Model\Webapi\EntityTypeMetadata;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsAdminUi\Block\Adminhtml\Execution\View;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;
use MageOS\WorkflowsAdminUi\Model\Source\ExecutionStatus;
use MageOS\WorkflowsAdminUi\Model\Source\TriggerType;
use PHPUnit\Framework\TestCase;

/**
 * Execution detail used to print stored codes (`complete`, `event`, `1`) straight
 * out of the row. These helpers project them through the SAME option sources the
 * executions grid filters on, so the page and its grid can never disagree.
 *
 * Step statuses are a strict SUBSET of the execution status codes (the step
 * interface declares everything but `cancelled`), which is why one ExecutionStatus
 * source labels both timelines — pinned below so a future divergence fails here
 * rather than silently rendering a raw code.
 *
 * View extends Backend\Block\Template, whose real constructor is layout-heavy and
 * unavailable here, so the block is an anonymous subclass with a no-op constructor
 * and its promoted dependencies injected by reflection (the posture
 * InstallWidgetMappingTest documents).
 */
class ViewLabelsTest extends TestCase
{
    public function testStatusRendersItsLabel(): void
    {
        $execution = $this->execution();
        $execution->setStatus(WorkflowExecutionInterface::STATUS_COMPLETE);

        $this->assertSame('Complete', $this->block($execution)->getStatusLabel());
    }

    public function testUnknownStatusRendersItsRawCode(): void
    {
        $execution = $this->execution();
        $execution->setStatus('quarantined');

        $this->assertSame('quarantined', $this->block($execution)->getStatusLabel());
    }

    public function testTriggerTypeRendersItsLabel(): void
    {
        $execution = $this->execution();
        $execution->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);

        $this->assertSame('Event', $this->block($execution)->getTriggerTypeLabel());
    }

    public function testUnknownTriggerTypeRendersItsRawCode(): void
    {
        $execution = $this->execution();
        $execution->setTriggerType('webhook');

        $this->assertSame('webhook', $this->block($execution)->getTriggerTypeLabel());
    }

    public function testAbsentTriggerTypeStaysEmptySoTheTemplateCanDashIt(): void
    {
        $this->assertSame('', $this->block($this->execution())->getTriggerTypeLabel());
    }

    public function testEntityCellCombinesTheEntityTypeLabelAndTheId(): void
    {
        $execution = $this->execution();
        $execution->setEntityId(142);
        $execution->setContext((string) json_encode(['workflow' => ['entity_type' => 'sales_order']]));

        $this->assertSame('Order #142', $this->block($execution)->getEntityLabel());
    }

    public function testEntityCellFallsBackToTheBareIdWithoutAStampedType(): void
    {
        $execution = $this->execution();
        $execution->setEntityId(142);

        $this->assertSame('142', $this->block($execution)->getEntityLabel());
    }

    public function testEntityCellKeepsAnUninstalledEntityTypeRaw(): void
    {
        $execution = $this->execution();
        $execution->setEntityId(9);
        $execution->setContext((string) json_encode(['workflow' => ['entity_type' => 'b2b_quote']]));

        $this->assertSame('b2b_quote #9', $this->block($execution)->getEntityLabel());
    }

    public function testStoreCellRendersTheStoreViewPathAndKeepsTheId(): void
    {
        $execution = $this->execution();
        $execution->setStoreId(3);

        $this->assertSame('Main Website / Store / Default View (#3)', $this->block($execution)->getStoreLabel());
    }

    public function testStoreCellFallsBackToTheRawIdForAnUnknownStore(): void
    {
        $execution = $this->execution();
        $execution->setStoreId(99);

        $this->assertSame('99', $this->block($execution)->getStoreLabel());
    }

    public function testStoreCellSurvivesAThrowingStoreCatalogue(): void
    {
        $execution = $this->execution();
        $execution->setStoreId(-1);

        $this->assertSame('-1', $this->block($execution)->getStoreLabel());
    }

    /**
     * Every status a STEP can carry must resolve; the execution-only `cancelled`
     * is the one code the two sets do not share.
     */
    public function testEveryStepStatusResolvesThroughTheExecutionStatusSource(): void
    {
        $block = $this->block($this->execution());
        $reflection = new \ReflectionClass(WorkflowExecutionStepInterface::class);

        foreach ($reflection->getConstants() as $name => $code) {
            if (!str_starts_with($name, 'STATUS_')) {
                continue;
            }
            $this->assertTrue(
                $block->getStepStatusLabel((string) $code) !== (string) $code,
                sprintf('step status %s has no label in ExecutionStatus', $name)
            );
        }
    }

    public function testUnknownStepStatusRendersItsRawCode(): void
    {
        $this->assertSame('parked', $this->block($this->execution())->getStepStatusLabel('parked'));
    }

    public function testNoExecutionYieldsEmptyLabelsRatherThanAFatal(): void
    {
        $block = $this->block(null);

        $this->assertSame('', $block->getEntityLabel());
        $this->assertSame('', $block->getStoreLabel());
        $this->assertSame('', $block->getStatusLabel());
        $this->assertSame('', $block->getTriggerTypeLabel());
    }

    private function execution(): WorkflowExecutionStub
    {
        return new WorkflowExecutionStub('uuid-1', 1, 1);
    }

    private function block(?WorkflowExecutionInterface $execution): View
    {
        $block = new class ($execution) extends View {
            public function __construct(private readonly ?WorkflowExecutionInterface $stub = null)
            {
            }

            public function getExecution(): ?WorkflowExecutionInterface
            {
                return $this->stub;
            }
        };

        $dependencies = [
            'executionStatusSource' => new ExecutionStatus(),
            'triggerTypeSource' => new TriggerType(),
            'entityTypeSource' => new EntityType(
                new class implements EntityTypeMetadataProviderInterface {
                    public function getEntityTypes(): array
                    {
                        return [
                            new EntityTypeMetadata('sales_order', 'Order'),
                            new EntityTypeMetadata('customer', 'Customer'),
                        ];
                    }
                }
            ),
            'systemStore' => new class extends SystemStore {
                public function getStoreName($storeId)
                {
                    if ((int) $storeId < 0) {
                        throw new \RuntimeException('store collection unavailable');
                    }
                    return (int) $storeId === 3 ? "Main Website\n    Store\n    Default View" : null;
                }
            },
        ];

        foreach ($dependencies as $name => $value) {
            $property = new \ReflectionProperty(View::class, $name);
            $property->setAccessible(true);
            $property->setValue($block, $value);
        }

        return $block;
    }
}
