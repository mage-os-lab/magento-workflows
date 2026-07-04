<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Controller\Adminhtml\Data;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use MageOS\Workflows\Api\WorkflowDryRunInterface;

/**
 * Same-origin, session-authed JSON dry-run for the canvas overlay. POST (form
 * key enforced by the admin router), gated by ::dry_run — the same ACL as the
 * REST dry-run route, which does NOT imply ::manual_run. Delegates to the core
 * WorkflowDryRunInterface (side-effect-free; secrets already redacted by the
 * dry-run VariableResolver, F7). Only the safe overlay fields are projected.
 */
class DryRun extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::dry_run';

    public function __construct(
        Action\Context $context,
        private readonly WorkflowDryRunInterface $dryRun
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        $workflowId = (int) $this->getRequest()->getParam('workflow_id');
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        if ($workflowId <= 0) {
            return $result->setHttpResponseCode(400)->setData(['error' => (string) __('Missing workflow_id')]);
        }

        try {
            $dryRunResult = $this->dryRun->runOnSaved($workflowId, $entityId ?: null);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(422)->setData(['error' => $e->getMessage()]);
        }

        $steps = [];
        foreach ($dryRunResult->getSteps() as $step) {
            // Project only overlay-safe fields (the interpolated `config`/
            // `condition` blobs stay server-side even though dry-run redacts
            // secrets — the overlay does not need them).
            $steps[] = [
                'step_key' => $step->getStepKey(),
                'type' => $step->getType(),
                'status' => $step->getStatus(),
                'path_ids' => $step->getPathIds(),
                'would' => $step->getWould(),
                'edge_taken' => $step->getEdgeTaken(),
            ];
        }

        return $result->setData([
            'valid' => $dryRunResult->getValid(),
            'skipped' => $dryRunResult->getSkipped(),
            'truncated' => $dryRunResult->getTruncated(),
            'steps' => $steps,
        ]);
    }
}
