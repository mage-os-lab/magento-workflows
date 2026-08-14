<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;

/**
 * The READ side of workflow executions as exposed over REST:
 * GET /V1/workflow-executions and GET /V1/workflow-executions/:executionId,
 * both gated by `MageOS_Workflows::view`.
 *
 * Why this exists instead of pointing the routes straight at
 * WorkflowExecutionRepositoryInterface (which they used to):
 *
 * An execution row's `context` blob is written by the production executor from
 * interpolated action config, so it can contain real secret values — a webhook
 * URL with its token, a raw exception message from an HTTP client. The
 * repository is ALSO what the engine loads executions through (Executor,
 * ResumeConsumer, Dispatcher, the approvals gate reader), and those callers need
 * the context verbatim or the workflow resumes against masked data. Redaction
 * therefore cannot live in the repository. It lives here, in the read model the
 * REST routes bind to — the same layer at which
 * WorkflowExecutionStepsProviderInterface redacts step detail, using the same
 * rule set.
 *
 * The returned DTOs are ordinary WorkflowExecutionInterface objects, so the
 * response shape is byte-for-byte what the repository-bound routes produced —
 * only the `context` field differs, and only where it held a credential.
 *
 * @api
 */
interface WorkflowExecutionReaderInterface
{
    /**
     * One execution, with its context blob redacted.
     *
     * @param int $executionId
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionInterface
     * @throws NoSuchEntityException when the execution does not exist
     */
    public function getById(int $executionId): WorkflowExecutionInterface;

    /**
     * A page of executions, each with its context blob redacted.
     *
     * Pagination is the repository's: a caller that names no page size gets the
     * default, and one that asks for more than the cap gets the cap. See
     * MageOS\Workflows\Model\WorkflowExecutionRepository::getList().
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Magento\Framework\Api\SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
