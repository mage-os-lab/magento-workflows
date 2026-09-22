<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Rule\Condition\RelatedEntity;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\AbstractCondition;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use PHPUnit\Framework\TestCase;

/**
 * The related-entity node's "Add condition" attribute groups must NAME their
 * target entity. Before a relation is chosen, EVERY registered target's
 * attributes are offered at once (node metadata is served per node TYPE, so
 * the client cannot narrow it by the eventually-chosen relation), and two
 * groups both labeled "Related Attribute" are indistinguishable — "Created At"
 * legitimately exists on customers AND orders.
 */
class CombineChildGroupLabelTest extends TestCase
{
    private function leaf(array $attributes): AbstractCondition
    {
        return new class($attributes) extends AbstractCondition {
            /** @param array<string, string> $attributes */
            public function __construct(private readonly array $attributes)
            {
                $this->loadAttributeOptions()->loadOperatorOptions()->loadValueOptions();
            }

            /**
             * @return $this
             */
            public function loadAttributeOptions()
            {
                $this->setAttributeOption($this->attributes ?? []);
                return $this;
            }

            public function validate(DataObject $model)
            {
                return true;
            }
        };
    }

    private function combine(): RelatedEntityCombine
    {
        $reflection = new \ReflectionClass(RelatedEntityCombine::class);
        /** @var RelatedEntityCombine $combine */
        $combine = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('relationPool')->setValue($combine, new RelationPool([]));
        $reflection->getProperty('targetChildConditions')->setValue($combine, [
            'customer' => $this->leaf(['created_at' => 'Created At', 'email' => 'Email']),
            'sales_order' => $this->leaf(['created_at' => 'Created At', 'status' => 'Order Status']),
        ]);
        return $combine;
    }

    public function testAttributeGroupsNameTheirTargetEntity(): void
    {
        $labels = [];
        foreach ($this->combine()->getNewChildSelectOptions() as $option) {
            if (is_array($option['value'] ?? null)) {
                $labels[] = (string) $option['label'];
            }
        }

        $this->assertSame(
            ['Related Attribute (customer)', 'Related Attribute (sales_order)'],
            $labels,
            'With no relation chosen every target is offered; identical group labels are indistinguishable.'
        );
    }
}
