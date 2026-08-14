<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecution as WorkflowExecutionResource;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecution\CollectionFactory;

class WorkflowExecutionRepository implements WorkflowExecutionRepositoryInterface
{
    /**
     * Page size applied when a caller names none.
     *
     * An execution row is not small: `definition_snapshot` and `context` are
     * both MEDIUMTEXT, and a busy store's execution table is designed to reach
     * millions of rows before TTL pruning catches up. An unpaginated getList
     * therefore hydrates the entire table into PHP memory — at the WEAKEST
     * grant (::view), from a single unauthenticated-looking GET. 50 matches the
     * default a Magento admin grid asks for, so the common "just show me the
     * recent runs" call behaves the way an operator expects.
     */
    public const DEFAULT_PAGE_SIZE = 50;

    /**
     * Hard ceiling on an explicitly requested page size.
     *
     * 200 rows is roughly the point where a response of realistic execution
     * rows (a few KB of context each) is still a handful of megabytes, and it
     * leaves a CI/CD or export client able to drain the table in reasonable
     * chunks by paging. A caller asking for more is clamped, not rejected: the
     * request still succeeds, `total_count` still reports the true total, and
     * the echoed search_criteria reports the page size actually used — so a
     * client can SEE it was capped and page for the rest.
     */
    public const MAX_PAGE_SIZE = 200;

    public function __construct(
        private readonly WorkflowExecutionFactory $executionFactory,
        private readonly WorkflowExecutionResource $executionResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
    {
        /** @var WorkflowExecution $execution */
        try {
            $this->executionResource->save($execution);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save workflow execution: %1', $e->getMessage()), $e);
        }

        return $execution;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $executionId): WorkflowExecutionInterface
    {
        $execution = $this->executionFactory->create();
        $this->executionResource->load($execution, $executionId);
        if (!$execution->getExecutionId()) {
            throw new NoSuchEntityException(
                __('Workflow execution with id "%1" does not exist.', $executionId)
            );
        }

        return $execution;
    }

    /**
     * @inheritDoc
     */
    public function getByUuid(string $uuid): WorkflowExecutionInterface
    {
        $execution = $this->executionFactory->create();
        $this->executionResource->load($execution, $uuid, WorkflowExecutionInterface::UUID);
        if (!$execution->getExecutionId()) {
            throw new NoSuchEntityException(
                __('Workflow execution with uuid "%1" does not exist.', $uuid)
            );
        }

        return $execution;
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        // Bound the page BEFORE the collection processor runs — the pagination
        // processor only applies a LIMIT when the criteria carries a page size,
        // so leaving it null is what made an omitted pageSize mean "the whole
        // table". Written back onto the criteria (rather than onto the
        // collection) on purpose: the criteria is echoed in the search_results
        // payload, so the response tells the caller which page size actually
        // ran instead of quietly disagreeing with itself. The engine does not
        // call getList at all (it loads by id/uuid), so nothing internal is
        // affected; the admin executions grid has its own SearchResult
        // collection virtual type and never comes through here either.
        self::applyPageBounds($searchCriteria);

        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        // getSize() re-counts without the LIMIT, so total_count stays the TRUE
        // number of matching rows — the standard Magento repository contract.
        // Capping the page must never make the result set look smaller than it
        // is, or a client pages until it thinks it is done and silently loses
        // rows.
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    /**
     * Writes the bounded page size back onto the criteria and returns it.
     *
     * Public and static on purpose: standing the repository up needs five DI
     * collaborators (two of them generated factories that do not exist outside
     * a built Magento), so the bound would otherwise be untestable in the
     * zero-dependency unit lane. Same shim-testability pattern as
     * WorkflowExecutionStepsProvider::deriveEdgeTaken(). getList() has exactly
     * one call site for it, so testing this tests the route's real behaviour.
     */
    public static function applyPageBounds(SearchCriteriaInterface $searchCriteria): SearchCriteriaInterface
    {
        $searchCriteria->setPageSize(self::boundedPageSize($searchCriteria->getPageSize()));

        return $searchCriteria;
    }

    /**
     * The page size actually used: the caller's, the default when they named
     * none, capped at MAX_PAGE_SIZE.
     *
     * SearchCriteriaInterface::getPageSize() is untyped, so this takes whatever
     * it hands over: null (the SearchCriteriaBuilder default — "no pagination"),
     * a numeric string from a hand-built criteria, or a nonsense value. Anything
     * that is not a positive number means "the caller named no page size" and
     * gets the default — never "unlimited".
     */
    public static function boundedPageSize(mixed $pageSize): int
    {
        $requested = is_numeric($pageSize) ? (int) $pageSize : 0;
        if ($requested <= 0) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return min($requested, self::MAX_PAGE_SIZE);
    }
}
