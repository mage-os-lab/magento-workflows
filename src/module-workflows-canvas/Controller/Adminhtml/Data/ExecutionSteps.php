<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Controller\Adminhtml\Data;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\WorkflowExecutionStepsProviderInterface;

/**
 * Same-origin, session-authed JSON feed for the execution overlay. Delegates to
 * the SAME core provider the REST endpoint uses (no logic duplication), so the
 * safe-projection + redaction guarantees hold identically. The admin session +
 * ::view ACL are the auth here; the webapi route stays for third-party/CI.
 */
class ExecutionSteps extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::view';

    public function __construct(
        Action\Context $context,
        private readonly WorkflowExecutionStepsProviderInterface $stepsProvider
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        $executionId = (int) $this->getRequest()->getParam('execution_id');
        if ($executionId <= 0) {
            return $result->setHttpResponseCode(400)->setData(['error' => (string) __('Missing execution_id')]);
        }

        try {
            $steps = $this->stepsProvider->getSteps($executionId);
        } catch (NoSuchEntityException $e) {
            return $result->setHttpResponseCode(404)->setData(['error' => (string) __('Execution not found')]);
        }

        $rows = [];
        foreach ($steps as $step) {
            $rows[] = [
                'step_key' => $step->getStepKey(),
                'status' => $step->getStatus(),
                'started_at' => $step->getStartedAt(),
                'finished_at' => $step->getFinishedAt(),
                'edge_taken' => $step->getEdgeTaken(),
                'error_summary' => $step->getErrorSummary(),
            ];
        }

        return $result->setData(['steps' => $rows]);
    }
}
