<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Model;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps a schedule-type workflow's root condition tree to a SearchCriteria
 * (docs/05-triggers.md#scheduled-triggers-workflows-scheduler /
 * docs/06-conditions.md).
 *
 * The tree is the same serialized array shape produced by classic
 * Magento\Rule\Model\Condition\Combine::asArray() (docs/03-domain-model.md:
 * "rule condition tree (same format as salesrule)"):
 *
 *   {
 *     "aggregator": "all" | "any",
 *     "conditions": [
 *       {"attribute": "status", "operator": "==", "value": "processing"},
 *       ...
 *     ]
 *   }
 *
 * Only flat, root-level attribute conditions are supported. Anything one
 * level deeper (a condition that is itself a combine, i.e. carries its own
 * "conditions" key) or that uses an operator we don't recognise makes the
 * whole tree unmappable - callers must fall back to load-and-filter.
 */
class ConditionToSearchCriteria
{
    /**
     * Classic Magento\Rule operator strings => SearchCriteria condition types.
     */
    private const OPERATOR_MAP = [
        '==' => 'eq',
        '!=' => 'neq',
        '>=' => 'gteq',
        '<=' => 'lteq',
        '>' => 'gt',
        '<' => 'lt',
        '()' => 'in',
        '!()' => 'nin',
    ];

    public function __construct(
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return SearchCriteriaInterface|null null means "unsupported, fall back"
     */
    public function convert(?string $conditionsSerialized, string $entityType): ?SearchCriteriaInterface
    {
        if ($conditionsSerialized === null || trim($conditionsSerialized) === '') {
            // No conditions at all == match everything, which is trivially mappable.
            return $this->searchCriteriaBuilder->create();
        }

        try {
            $tree = json_decode($conditionsSerialized, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(sprintf(
                'ConditionToSearchCriteria: conditions for entity type "%s" are not valid JSON: %s',
                $entityType,
                $e->getMessage()
            ));
            return null;
        }

        if (!is_array($tree)) {
            return null;
        }

        $aggregator = strtolower((string) ($tree['aggregator'] ?? 'all'));
        if (!in_array($aggregator, ['all', 'any'], true)) {
            return null;
        }

        $conditions = $tree['conditions'] ?? [];
        if (!is_array($conditions)) {
            return null;
        }
        if ($conditions === []) {
            return $this->searchCriteriaBuilder->create();
        }

        $filters = $this->buildFilters($conditions);
        if ($filters === null) {
            return null;
        }

        $filterGroups = $aggregator === 'any'
            ? [$this->filterGroupBuilder->setFilters($filters)->create()]
            : array_map(
                fn ($filter) => $this->filterGroupBuilder->setFilters([$filter])->create(),
                $filters
            );

        return $this->searchCriteriaBuilder->setFilterGroups($filterGroups)->create();
    }

    /**
     * @param array $conditions
     * @return \Magento\Framework\Api\Filter[]|null null means "unsupported"
     */
    private function buildFilters(array $conditions): ?array
    {
        $filters = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition) || isset($condition['conditions'])) {
                // Nested combine node - deeper than the root level we support.
                return null;
            }

            $attribute = $condition['attribute'] ?? null;
            $operator = $condition['operator'] ?? null;
            if (!is_string($attribute) || $attribute === '' || !is_string($operator)) {
                return null;
            }
            if (!isset(self::OPERATOR_MAP[$operator])) {
                return null;
            }

            $conditionType = self::OPERATOR_MAP[$operator];
            $value = $condition['value'] ?? null;
            if (in_array($conditionType, ['in', 'nin'], true)) {
                $value = is_array($value)
                    ? array_values($value)
                    : array_map('trim', explode(',', (string) $value));
            }

            $filters[] = $this->filterBuilder
                ->setField($attribute)
                ->setValue($value)
                ->setConditionType($conditionType)
                ->create();
        }

        return $filters;
    }
}
