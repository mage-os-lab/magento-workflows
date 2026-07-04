<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * GET /V1/workflow-executions/:executionId/steps (07): the per-step timeline of
 * one execution, for the canvas execution overlay. Step rows are not otherwise
 * REST-exposed (WorkflowExecutionInterface carries no steps); this endpoint
 * ships them in the canvas stage. ACL `MageOS_Workflows::view`.
 *
 * @api
 */
interface WorkflowExecutionStepsProviderInterface
{
    /**
     * @param int $executionId
     * @return \MageOS\Workflows\Api\Data\ExecutionStepStateInterface[] ordered
     *         by execution order (step_execution_id ASC)
     * @throws NoSuchEntityException when the execution does not exist
     */
    public function getSteps(int $executionId): array;
}
