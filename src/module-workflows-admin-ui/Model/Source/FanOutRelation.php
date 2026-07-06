<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Workflows\Model\Relation\RelationPool;

/**
 * Fan-out relation select for the workflow form, sourced from the DI-registered
 * relation pool (peer: MageOS\Workflows\Model\Relation\RelationPool). The
 * leading empty option is "no fan-out" — the workflow then dispatches one
 * execution per triggering entity as usual.
 *
 * The list is the full catalogue; save-time alignment (trigger source ==
 * relation source, relation target == workflow entity type) is enforced by
 * FanOutAlignmentCheck, so an incompatible pick is rejected with a readable
 * message rather than filtered here (the form has no live view of the chosen
 * trigger/entity type).
 */
class FanOutRelation implements OptionSourceInterface
{
    public function __construct(
        private readonly RelationPool $relationPool
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => (string) __('— No fan-out (one run per triggering entity) —')]];
        foreach ($this->relationPool->getAll() as $relation) {
            $options[] = [
                'value' => $relation->getCode(),
                'label' => sprintf(
                    '%s → %s (%s)',
                    $relation->getSourceEntityType(),
                    $relation->getTargetEntityType(),
                    $relation->getLabel()
                ),
            ];
        }
        return $options;
    }
}
