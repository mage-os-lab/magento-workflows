<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Validation;

use Magento\Framework\DataObject;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Model\Validation\Check\FanOutAlignmentCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use PHPUnit\Framework\TestCase;

/**
 * Save-time fan-out type alignment: the two alignment invariants (trigger
 * source == relation source; relation target == workflow entity type), the
 * schedule-type rejection, and the malformed/unknown-relation guards.
 */
class FanOutAlignmentCheckTest extends TestCase
{
    private function relation(string $source, string $target): RelationInterface
    {
        return new class ($source, $target) implements RelationInterface {
            public function __construct(private readonly string $source, private readonly string $target)
            {
            }

            public function getCode(): string
            {
                return 'customer.open_orders';
            }

            public function getLabel(): string
            {
                return "the customer's open orders";
            }

            public function getSourceEntityType(): string
            {
                return $this->source;
            }

            public function getTargetEntityType(): string
            {
                return $this->target;
            }

            public function getCardinality(): string
            {
                return self::CARDINALITY_MANY;
            }

            public function resolveIds(DataObject $source, ?int $websiteId): array
            {
                return [];
            }
        };
    }

    private function triggerRegistry(string $event, string $entity): TriggerRegistry
    {
        return new class ($event, $entity) extends TriggerRegistry {
            public function __construct(private readonly string $event, private readonly string $entity)
            {
            }

            public function getByEvent(string $event): ?array
            {
                return $event === $this->event
                    ? ['event' => $this->event, 'entity' => $this->entity, 'label' => 'X']
                    : null;
            }

            public function getAll(): array
            {
                return [$this->event => ['event' => $this->event, 'entity' => $this->entity, 'label' => 'X']];
            }
        };
    }

    private function check(string $relSource = 'customer', string $relTarget = 'sales_order'): FanOutAlignmentCheck
    {
        return new FanOutAlignmentCheck(
            new RelationPool(['customer.open_orders' => $this->relation($relSource, $relTarget)]),
            $this->triggerRegistry('customer.group_changed', 'customer')
        );
    }

    private function subject(
        ?string $fanOut,
        string $triggerType = WorkflowInterface::TRIGGER_TYPE_EVENT,
        string $triggerRef = 'customer.group_changed',
        string $entityType = 'sales_order'
    ): ValidationSubject {
        return new ValidationSubject(
            '{"schema":3,"steps":[],"entry":null}',
            null,
            $triggerType,
            $triggerRef,
            $entityType,
            $fanOut
        );
    }

    private function context(): ValidationContext
    {
        return new ValidationContext();
    }

    private function fanOut(string $relation = 'customer.open_orders'): string
    {
        return (string) json_encode(['relation' => $relation, 'cap' => 50]);
    }

    public function testNoFanOutNoOps(): void
    {
        $messages = $this->check()->check($this->subject(null), $this->context());
        $this->assertSame([], $messages);
    }

    public function testAlignedFanOutPasses(): void
    {
        // trigger entity customer == relation source customer;
        // relation target sales_order == workflow entity type sales_order.
        $messages = $this->check()->check($this->subject($this->fanOut()), $this->context());
        $this->assertSame([], $messages);
    }

    public function testSourceMismatchRejected(): void
    {
        // Relation fans out from sales_order, but the workflow triggers on customer.
        $check = $this->check('sales_order', 'sales_order');
        $messages = $check->check($this->subject($this->fanOut()), $this->context());

        $this->assertCount(1, $messages);
        $this->assertSame(FanOutAlignmentCheck::CODE_SOURCE_MISMATCH, $messages[0]->getCode());
    }

    public function testTargetMismatchRejected(): void
    {
        // Relation produces customer entities, but the workflow acts on sales_order.
        $check = $this->check('customer', 'customer');
        $messages = $check->check($this->subject($this->fanOut()), $this->context());

        $this->assertCount(1, $messages);
        $this->assertSame(FanOutAlignmentCheck::CODE_TARGET_MISMATCH, $messages[0]->getCode());
    }

    public function testBothMismatchesRejected(): void
    {
        // Relation quote -> quote: neither the source nor the target aligns.
        $check = $this->check('quote', 'quote');
        $messages = $check->check($this->subject($this->fanOut()), $this->context());

        $codes = array_map(static fn ($m) => $m->getCode(), $messages);
        $this->assertCount(2, $messages);
        $this->assertTrue(in_array(FanOutAlignmentCheck::CODE_SOURCE_MISMATCH, $codes, true));
        $this->assertTrue(in_array(FanOutAlignmentCheck::CODE_TARGET_MISMATCH, $codes, true));
    }

    public function testScheduleTypeRejected(): void
    {
        $subject = $this->subject($this->fanOut(), WorkflowInterface::TRIGGER_TYPE_SCHEDULE, '0 3 * * *');
        $messages = $this->check()->check($subject, $this->context());

        $this->assertCount(1, $messages);
        $this->assertSame(FanOutAlignmentCheck::CODE_SCHEDULE_UNSUPPORTED, $messages[0]->getCode());
    }

    public function testUnknownRelationRejected(): void
    {
        $messages = $this->check()->check($this->subject($this->fanOut('nope.relation')), $this->context());

        $this->assertCount(1, $messages);
        $this->assertSame(FanOutAlignmentCheck::CODE_UNKNOWN_RELATION, $messages[0]->getCode());
    }

    public function testMalformedFanOutRejected(): void
    {
        $messages = $this->check()->check($this->subject('{"cap":5}'), $this->context());

        $this->assertCount(1, $messages);
        $this->assertSame(FanOutAlignmentCheck::CODE_MALFORMED, $messages[0]->getCode());
    }
}
