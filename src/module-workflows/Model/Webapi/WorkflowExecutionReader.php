<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowExecutionReaderInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;

/**
 * REST read model for workflow executions (see WorkflowExecutionReaderInterface
 * for why the routes no longer bind to the repository directly).
 *
 * It delegates every load to the repository — so pagination, filtering, sorting
 * and total_count all stay exactly the repository's behaviour — and then masks
 * credentials out of the one field that can carry them off the server.
 *
 * Scope of the redaction, deliberately narrow:
 *
 *   - `context` IS redacted. It holds the trigger snapshot plus each completed
 *     step's output, and step outputs are where interpolated secrets land.
 *   - `definition_snapshot` is NOT redacted. A definition references secrets by
 *     NAME only ({{ secrets.foo }}); values never enter it (docs/10 §Secrets),
 *     so running the generic credential-shape rules over it would mask authored
 *     placeholders and template text for no security gain. If that invariant
 *     ever breaks, the fix is upstream in what gets snapshotted, not a second
 *     mask here.
 *
 * Mutating the loaded model in place is safe and intentional: both entry points
 * create their model instances inside this call (the repository news one up per
 * getById, and the collection hydrates fresh rows per getList), nothing here is
 * a shared identity-map instance, and no save path runs on a GET route. The
 * alternative — cloning — would buy nothing and would hand the webapi output
 * processor a different object graph than the repository normally returns.
 */
class WorkflowExecutionReader implements WorkflowExecutionReaderInterface
{
    public function __construct(
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly ExecutionDetailRedactor $detailRedactor
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getById(int $executionId): WorkflowExecutionInterface
    {
        return $this->redactExecution($this->executionRepository->getById($executionId));
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $searchResults = $this->executionRepository->getList($searchCriteria);

        $items = [];
        foreach ($searchResults->getItems() as $item) {
            $items[] = $item instanceof WorkflowExecutionInterface ? $this->redactExecution($item) : $item;
        }
        $searchResults->setItems($items);

        return $searchResults;
    }

    /**
     * Masks the context blob in place and hands the same object back.
     */
    private function redactExecution(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
    {
        $context = $execution->getContext();
        if ($context === null || $context === '') {
            return $execution;
        }
        $redacted = $this->detailRedactor->redact($context);
        if ($redacted !== $context) {
            $execution->setContext($redacted);
        }

        return $execution;
    }
}
