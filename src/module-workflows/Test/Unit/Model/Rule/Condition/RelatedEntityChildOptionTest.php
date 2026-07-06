<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule\Condition;

use Magento\Framework\DataObject;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use PHPUnit\Framework\TestCase;

/**
 * The "Related Entity" child option is offered under a root combine ONLY where
 * the RelationPool holds a relation sourced from that entity type
 * (docs/discovery/implementation/02: Order/Quote/Customer — NOT Product, which
 * sources no relation in the seed set). Exercised through the shared helper on
 * AbstractWorkflowCombine (the real root combines depend on heavyweight EAV
 * metadata services that are out of reach of the shim runner; Product\Combine
 * never calls the helper at all, so its options are unchanged by construction).
 */
class RelatedEntityChildOptionTest extends TestCase
{
    private function combine(): object
    {
        return new class extends AbstractWorkflowCombine {
            public function __construct()
            {
                parent::__construct(new class extends \Magento\Rule\Model\Condition\Context {
                    public function __construct()
                    {
                    }
                }, []);
            }

            /**
             * @return array
             */
            public function optionsFor(RelationPool $pool, string $entityType): array
            {
                return $this->relatedEntityChildOptions($pool, $entityType);
            }
        };
    }

    private function orderRelation(): RelationInterface
    {
        return new class implements RelationInterface {
            public function getCode(): string
            {
                return 'order.customer_by_email';
            }

            public function getLabel(): string
            {
                return 'x';
            }

            public function getSourceEntityType(): string
            {
                return 'sales_order';
            }

            public function getTargetEntityType(): string
            {
                return 'customer';
            }

            public function getCardinality(): string
            {
                return self::CARDINALITY_ONE;
            }

            public function resolveIds(DataObject $source, ?int $websiteId): array
            {
                return [];
            }
        };
    }

    public function testSourceEntityWithRelationsGetsTheOption(): void
    {
        $pool = new RelationPool(['order.customer_by_email' => $this->orderRelation()]);

        $options = $this->combine()->optionsFor($pool, 'sales_order');

        $this->assertCount(1, $options);
        $this->assertSame(RelatedEntityCombine::class, $options[0]['value']);
    }

    public function testProductSourcesNoRelationSoNoOption(): void
    {
        // Same pool (only an order relation) — catalog_product must stay bare.
        $pool = new RelationPool(['order.customer_by_email' => $this->orderRelation()]);

        $this->assertSame([], $this->combine()->optionsFor($pool, 'catalog_product'));
    }

    public function testEmptyPoolYieldsNoOptionForAnyEntity(): void
    {
        $pool = new RelationPool([]);

        $this->assertSame([], $this->combine()->optionsFor($pool, 'sales_order'));
        $this->assertSame([], $this->combine()->optionsFor($pool, 'customer'));
    }
}
