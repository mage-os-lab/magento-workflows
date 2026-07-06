<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Model;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Model\Aggregation\AggregationConfig;
use MageOS\Workflows\Model\Aggregation\BatchContextBuilder;
use MageOS\Workflows\Model\Aggregation\ItemProjector;
use MageOS\Workflows\Model\Aggregation\MembershipEvaluatorInterface;
use MageOS\Workflows\Model\Rule\Hydrator\EntityDataConverter;
use Psr\Log\LoggerInterface;

/**
 * Turns a schedule-type workflow's root condition tree into matched entities
 * and dispatches one execution per match (docs/05-triggers.md#scheduled-triggers-workflows-scheduler).
 *
 * - Mapped path: ConditionToSearchCriteria produces a SearchCriteria, which
 *   is queried against the entity's repository, page size 500.
 * - Fallback path: the condition tree could not be index-mapped (nested or
 *   unsupported), so we page through the repository unfiltered by the root
 *   conditions (the watermark filter still applies) within the match cap.
 *
 * Collected mode (05 B1): when the workflow carries a `collected` aggregation
 * config, matches are NOT dispatched one-per-row; their projections are
 * accumulated and one batch execution is dispatched. Because a batch has a
 * single execution and no per-execution re-filter, membership is enforced
 * per item HERE — on the mapped AND fallback paths alike — before a row joins
 * items[] (the same snapshot-only membership evaluator B2 uses). Projections
 * are routed through EntityDataConverter so the item shape (lifted EAV
 * attributes included) matches B2's event-snapshot items exactly.
 *
 * Guard rails: per-run match cap (config mageos_workflows/scheduler/match_cap,
 * default 5000) and a watermark so re-runs don't reprocess the same rows.
 * Cross-execution dedupe on (workflow_id, entity_id) is the dispatcher/
 * engine's debounce, not this class's job.
 */
class QueryRunner
{
    private const XML_PATH_MATCH_CAP = 'mageos_workflows/scheduler/match_cap';
    private const DEFAULT_MATCH_CAP = 5000;
    private const PAGE_SIZE = 500;

    /**
     * @param array<string, object> $repositories entity_type => repository exposing
     *        getList(SearchCriteriaInterface): SearchResultsInterface
     * @param array<string, string> $dtoInterfaces entity_type => DTO interface FQN
     *        used by EntityDataConverter for the converter path (models/DTOs
     *        with getData()/__toArray() ignore it)
     */
    public function __construct(
        private readonly DispatcherInterface $dispatcher,
        private readonly ConditionToSearchCriteria $conditionToSearchCriteria,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly array $repositories = [],
        private readonly ?EntityDataConverter $entityDataConverter = null,
        private readonly ?MembershipEvaluatorInterface $membershipEvaluator = null,
        private readonly ?ItemProjector $itemProjector = null,
        private readonly ?BatchContextBuilder $batchContextBuilder = null,
        private readonly array $dtoInterfaces = []
    ) {
    }

    /**
     * @return string|null the watermark to persist for this workflow (unchanged when nothing matched)
     */
    public function run(WorkflowInterface $workflow, ?string $previousWatermark): ?string
    {
        $workflowId = (int) $workflow->getWorkflowId();
        $entityType = $workflow->getEntityType();

        $repository = $this->repositories[$entityType] ?? null;
        if ($repository === null || !method_exists($repository, 'getList')) {
            $this->logger->warning(sprintf(
                'QueryRunner: no queryable repository configured for entity type "%s" (workflow #%d)',
                $entityType,
                $workflowId
            ));
            return $previousWatermark;
        }

        // Per docs: always watermark on created_at for orders, updated_at otherwise.
        $watermarkField = $entityType === 'sales_order' ? 'created_at' : 'updated_at';

        $matchCap = (int) $this->scopeConfig->getValue(self::XML_PATH_MATCH_CAP);
        if ($matchCap <= 0) {
            $matchCap = self::DEFAULT_MATCH_CAP;
        }

        $mappedCriteria = $this->conditionToSearchCriteria->convert(
            $workflow->getConditionsSerialized(),
            $entityType
        );
        $isMapped = $mappedCriteria !== null;
        if (!$isMapped) {
            $this->logger->warning(sprintf(
                'QueryRunner: workflow #%d (%s) root conditions could not be index-mapped; '
                . 'falling back to unfiltered paging within the match cap. '
                . 'The engine will re-evaluate root conditions per-execution.',
                $workflowId,
                $workflow->getName()
            ));
        }
        $baseFilterGroups = $isMapped ? $mappedCriteria->getFilterGroups() : [];

        $sortOrder = $this->sortOrderBuilder
            ->setField($watermarkField)
            ->setDirection(SortOrder::SORT_ASC)
            ->create();

        $aggregation = $this->collectedConfig($workflow);
        if ($aggregation !== null) {
            return $this->runCollected(
                $workflow,
                $repository,
                $baseFilterGroups,
                $sortOrder,
                $watermarkField,
                $previousWatermark,
                $matchCap,
                $aggregation
            );
        }

        $newWatermark = $previousWatermark;
        $matched = 0;
        $currentPage = 1;
        $items = [];

        do {
            $filterGroups = $this->pageFilterGroups($baseFilterGroups, $watermarkField, $previousWatermark);

            $criteria = $this->searchCriteriaBuilder
                ->setFilterGroups($filterGroups)
                ->setSortOrders([$sortOrder])
                ->setPageSize(self::PAGE_SIZE)
                ->setCurrentPage($currentPage)
                ->create();

            $items = $repository->getList($criteria)->getItems();

            foreach ($items as $entity) {
                if ($matched >= $matchCap) {
                    break 2;
                }

                $flat = $this->toFlatArray($entity);
                $entityId = $this->resolveEntityId($entity, $flat);
                if ($entityId === null) {
                    continue;
                }

                $payload = ['entity_id' => $entityId] + $flat;

                $this->dispatcher->dispatch($workflowId, $payload, WorkflowInterface::TRIGGER_TYPE_SCHEDULE);
                $matched++;

                $newWatermark = $this->advanceWatermark($newWatermark, $flat[$watermarkField] ?? null);
            }

            $currentPage++;
        } while (count($items) === self::PAGE_SIZE && $matched < $matchCap);

        return $newWatermark;
    }

    /**
     * B1 collected mode: accumulate per-item projections of members and
     * dispatch exactly one batch execution.
     *
     * @param object $repository
     * @param \Magento\Framework\Api\Search\FilterGroup[] $baseFilterGroups
     */
    private function runCollected(
        WorkflowInterface $workflow,
        object $repository,
        array $baseFilterGroups,
        SortOrder $sortOrder,
        string $watermarkField,
        ?string $previousWatermark,
        int $matchCap,
        AggregationConfig $aggregation
    ): ?string {
        $workflowId = (int) $workflow->getWorkflowId();
        $itemCap = $aggregation->getItemCap();
        $fields = $this->itemProjector->fieldsFor(
            $aggregation->getProjection(),
            $workflow->getConditionsSerialized()
        );

        $newWatermark = $previousWatermark;
        $scanned = 0;
        $memberCount = 0;
        $items = [];
        $currentPage = 1;
        $pageItems = [];

        do {
            $filterGroups = $this->pageFilterGroups($baseFilterGroups, $watermarkField, $previousWatermark);

            $criteria = $this->searchCriteriaBuilder
                ->setFilterGroups($filterGroups)
                ->setSortOrders([$sortOrder])
                ->setPageSize(self::PAGE_SIZE)
                ->setCurrentPage($currentPage)
                ->create();

            $pageItems = $repository->getList($criteria)->getItems();

            foreach ($pageItems as $entity) {
                if ($scanned >= $matchCap) {
                    break 2;
                }

                $flat = $this->toFlatArrayForBatch($entity, $workflow->getEntityType());
                $entityId = $this->resolveEntityId($entity, $flat);
                if ($entityId === null) {
                    continue;
                }
                $flat['entity_id'] = $entityId;
                $scanned++;
                // Watermark advances for every scanned row (member or not) so a
                // non-matching row is never re-scanned into a later digest.
                $newWatermark = $this->advanceWatermark($newWatermark, $flat[$watermarkField] ?? null);

                // Membership enforced per item on BOTH paths: mapped criteria
                // may be approximate; the fallback path did no filtering at all.
                if (!$this->membershipEvaluator->matches($workflow, $flat)) {
                    continue;
                }
                $memberCount++;
                if (count($items) < $itemCap) {
                    $items[] = $this->itemProjector->project($flat, $fields);
                }
            }

            $currentPage++;
        } while (count($pageItems) === self::PAGE_SIZE && $scanned < $matchCap);

        $this->dispatchBatch(
            $workflow,
            $aggregation,
            $memberCount,
            $items,
            $itemCap,
            $previousWatermark,
            $newWatermark
        );

        return $newWatermark;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function dispatchBatch(
        WorkflowInterface $workflow,
        AggregationConfig $aggregation,
        int $memberCount,
        array $items,
        int $itemCap,
        ?string $windowFrom,
        ?string $windowTo
    ): void {
        $workflowId = (int) $workflow->getWorkflowId();

        if ($memberCount < $aggregation->getMinItems()) {
            // Schedule-shaped mode: a window closing under the minimum drops
            // with a debug log (05 §Compatibility, min_items provisional
            // default). The watermark still advances so those rows are not
            // re-digested.
            $this->logger->debug('Workflow collected digest below min_items; dropped', [
                'workflow_id' => $workflowId,
                'members' => $memberCount,
                'min_items' => $aggregation->getMinItems(),
            ]);
            return;
        }

        $context = $this->batchContextBuilder->build(
            $memberCount,
            $items,
            ['from' => $windowFrom, 'to' => $windowTo],
            $itemCap
        );

        $this->dispatcher->dispatch($workflowId, $context, WorkflowInterface::TRIGGER_TYPE_SCHEDULE);
    }

    /**
     * @return AggregationConfig|null the config when this is a collected-mode
     *         aggregated workflow AND the collected-mode collaborators are
     *         wired; null keeps the per-entity path
     */
    private function collectedConfig(WorkflowInterface $workflow): ?AggregationConfig
    {
        if ($this->membershipEvaluator === null
            || $this->itemProjector === null
            || $this->batchContextBuilder === null
            || $this->entityDataConverter === null
        ) {
            return null;
        }
        try {
            $config = AggregationConfig::fromJson($workflow->getAggregation());
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning(sprintf(
                'QueryRunner: workflow #%d has an invalid aggregation config; running per-entity. %s',
                (int) $workflow->getWorkflowId(),
                $e->getMessage()
            ));
            return null;
        }
        return $config !== null && $config->isCollected() ? $config : null;
    }

    /**
     * @param \Magento\Framework\Api\Search\FilterGroup[] $baseFilterGroups
     * @return \Magento\Framework\Api\Search\FilterGroup[]
     */
    private function pageFilterGroups(array $baseFilterGroups, string $watermarkField, ?string $previousWatermark): array
    {
        $filterGroups = $baseFilterGroups;
        if ($previousWatermark !== null && $previousWatermark !== '') {
            $watermarkFilter = $this->filterBuilder
                ->setField($watermarkField)
                ->setValue($previousWatermark)
                ->setConditionType('gt')
                ->create();
            $filterGroups[] = $this->filterGroupBuilder->setFilters([$watermarkFilter])->create();
        }
        return $filterGroups;
    }

    private function advanceWatermark(?string $current, mixed $candidate): ?string
    {
        if (is_string($candidate) && $candidate !== '' && ($current === null || $candidate > $current)) {
            return $candidate;
        }
        return $current;
    }

    /**
     * Converts the repository DTO to a flat snapshot array for the dispatch
     * payload, via getData() (extensible/EAV models) or __toArray() as a
     * fallback for plain data objects.
     */
    private function toFlatArray(object $entity): array
    {
        if (method_exists($entity, 'getData')) {
            $data = $entity->getData();
            if (is_array($data)) {
                return $data;
            }
        }
        if (method_exists($entity, '__toArray')) {
            return $entity->__toArray();
        }
        return [];
    }

    /**
     * Collected-mode flattening: route through EntityDataConverter so custom
     * (EAV) / extension attributes are lifted to top-level keys, matching the
     * B2 event-snapshot item shape exactly (a `|pluck:'my_eav_attr'` resolves
     * identically under both modes).
     */
    private function toFlatArrayForBatch(object $entity, string $entityType): array
    {
        return $this->entityDataConverter->toFlatArray($entity, $this->dtoInterfaces[$entityType] ?? '');
    }

    private function resolveEntityId(object $entity, array $flat): ?int
    {
        if (isset($flat['entity_id']) && is_numeric($flat['entity_id'])) {
            return (int) $flat['entity_id'];
        }
        if (method_exists($entity, 'getId')) {
            $id = $entity->getId();
            return $id !== null && is_numeric($id) ? (int) $id : null;
        }
        return null;
    }
}
