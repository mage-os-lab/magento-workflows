<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\DryRun\DryRunPersister;
use MageOS\Workflows\Model\DryRun\DryRunRequest;
use MageOS\Workflows\Model\DryRun\DryRunService;
use MageOS\Workflows\Model\DryRun\RecentEntityProviderPool;

/**
 * Admin dry-run surface (03): renders a form pre-filled from the saved workflow
 * (the edit form carries in-progress edits over via sessionStorage — modest JS,
 * see the edit-form button) and, on POST, runs the DryRunService against the
 * posted — possibly UNSAVED — definition and renders the trace panel below.
 *
 * Gated by the dedicated ::dry_run resource (NOT ::manual_run). A dry-run of a
 * SAVED workflow whose posted definition still matches the stored one is
 * persisted as an audit row (default on); anything edited is transient.
 */
class DryRun extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::dry_run';

    public const REGISTRY_TRACE = 'mageos_dryrun_trace';
    public const REGISTRY_INPUT = 'mageos_dryrun_input';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly DryRunService $dryRunService,
        private readonly DryRunPersister $dryRunPersister,
        private readonly RecentEntityProviderPool $recentEntityProviderPool,
        private readonly Registry $coreRegistry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $request = $this->getRequest();
        $workflowId = (int) $request->getParam('workflow_id');

        $workflow = null;
        if ($workflowId > 0) {
            try {
                $workflow = $this->workflowRepository->getById($workflowId);
            } catch (NoSuchEntityException $e) {
                $workflow = null;
            }
        }

        $entityType = (string) ($request->getParam('entity_type')
            ?: ($workflow !== null ? $workflow->getEntityType() : ''));

        $definitionJson = $this->resolveDefinition($request->getParam('definition'), $workflow);
        $conditions = $request->getParam('conditions_serialized');
        $conditions = is_string($conditions) && $conditions !== '' ? $conditions : ($workflow?->getConditionsSerialized());

        $input = [
            'workflow_id' => $workflowId ?: null,
            'workflow_name' => $workflow?->getName() ?? '',
            'entity_type' => $entityType,
            'definition' => $definitionJson,
            'conditions_serialized' => (string) ($conditions ?? ''),
            'entity_id' => null,
            'recent' => $this->recentEntityProviderPool->getRecent($entityType),
            'persisted_execution_id' => null,
        ];

        if ($request->isPost() && $this->getRequest()->getParam('run')) {
            $entityId = $this->parseEntityId($request->getParam('entity_id'));
            $input['entity_id'] = $entityId;

            $trace = $this->dryRunService->run(new DryRunRequest(
                $definitionJson,
                $input['conditions_serialized'] !== '' ? $input['conditions_serialized'] : null,
                $entityType,
                $entityId,
                null,
                $workflowId ?: null,
                $input['workflow_name'],
                $workflow?->getFanOut()
            ));

            if ($workflow !== null && $entityId !== null && $this->matchesSavedDefinition($definitionJson, $workflow)) {
                $input['persisted_execution_id'] = $this->dryRunPersister->persist(
                    $trace,
                    $workflow,
                    $entityId,
                    0
                );
            }

            $this->coreRegistry->register(self::REGISTRY_TRACE, $trace);
        }

        $this->coreRegistry->register(self::REGISTRY_INPUT, $input);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Workflows::workflow_index');
        $resultPage->getConfig()->getTitle()->prepend(__('Dry-Run Workflow'));
        return $resultPage;
    }

    /**
     * Prefer the posted (possibly unsaved) definition; fall back to the saved one.
     */
    private function resolveDefinition(mixed $posted, ?WorkflowInterface $workflow): string
    {
        if (is_string($posted) && trim($posted) !== '') {
            return $posted;
        }
        return $workflow !== null ? $workflow->getDefinition() : '';
    }

    private function parseEntityId(mixed $raw): ?int
    {
        $raw = is_scalar($raw) ? trim((string) $raw) : '';
        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * True when the posted definition is byte-equivalent (after normalization)
     * to the stored one — the only case an audit row should be written.
     */
    private function matchesSavedDefinition(string $posted, WorkflowInterface $workflow): bool
    {
        try {
            return Definition::fromJson($posted)->toJson() === Definition::fromJson($workflow->getDefinition())->toJson();
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
}
