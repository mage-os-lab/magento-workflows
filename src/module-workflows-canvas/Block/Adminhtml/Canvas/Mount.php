<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Block\Adminhtml\Canvas;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ActionMetadataProviderInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Definition\Definition;

/**
 * Builds the bootstrap config for the React canvas and exposes it as a single
 * JSON string. The template writes it into ONE data-* attribute on the mount
 * div — there is no inline <script>, no server-generated JS (CSP: Magento 2.4.7
 * admin ships Content-Security-Policy; a self-hosted IIFE bundle with no inline
 * script and no eval passes).
 *
 * Everything the read-only viewer needs to draw the graph is bootstrapped here
 * server-side (definition, action labels, trigger/entity labels, ACL grants,
 * the known schema version). Interactive overlays (execution steps, dry-run)
 * are fetched at runtime from same-origin, session-authed admin JSON endpoints
 * whose URLs are also in the config — never from a third-party origin.
 */
class Mount extends Template
{
    public function __construct(
        Context $context,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly ActionMetadataProviderInterface $actionMetadataProvider,
        private readonly AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The bootstrap config, JSON-encoded. Consumed by the bundle's mount-config
     * reader; see app/src/mount.ts.
     */
    public function getConfigJson(): string
    {
        $workflowId = (int) $this->getRequest()->getParam('workflow_id');
        $executionId = (int) $this->getRequest()->getParam('execution_id');

        $config = [
            'workflowId' => $workflowId ?: null,
            'executionId' => $executionId ?: null,
            // The highest schema version this engine understands. The mapping
            // layer refuses to edit a document declaring a higher schema
            // (read-only gate) — mirrors the server's enforced version list.
            'knownSchemaVersion' => Definition::SCHEMA_VERSION,
            'grants' => [
                'manage' => $this->authorization->isAllowed('MageOS_Workflows::manage'),
                'dryRun' => $this->authorization->isAllowed('MageOS_Workflows::dry_run'),
            ],
            'endpoints' => [
                'executionSteps' => $this->getUrl('mageos_workflows_canvas/data/executionSteps'),
                'dryRun' => $this->getUrl('mageos_workflows_canvas/data/dryRun'),
            ],
            'formKey' => $this->getFormKey(),
            'workflow' => null,
            'actions' => $this->actionLabels(),
        ];

        $workflow = $this->loadWorkflow($workflowId);
        if ($workflow !== null) {
            $config['workflow'] = [
                'id' => (int) $workflow->getWorkflowId(),
                'name' => (string) $workflow->getName(),
                'entityType' => (string) $workflow->getEntityType(),
                'triggerType' => (string) $workflow->getTriggerType(),
                'triggerRef' => (string) $workflow->getTriggerRef(),
                'definition' => $this->decodeDefinition($workflow->getDefinition()),
            ];
        }

        return (string) json_encode($config, JSON_UNESCAPED_SLASHES);
    }

    private function loadWorkflow(int $workflowId): ?\MageOS\Workflows\Api\Data\WorkflowInterface
    {
        if ($workflowId <= 0) {
            return null;
        }
        try {
            return $this->workflowRepository->getById($workflowId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeDefinition(?string $definitionJson): ?array
    {
        if ($definitionJson === null || trim($definitionJson) === '') {
            return null;
        }
        $decoded = json_decode($definitionJson, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * code => {label, group} for the actions this admin may see, so node
     * summaries render without a round-trip and unregistered codes in the
     * definition can be flagged as degraded (error node).
     *
     * @return array<string, array{label: string, group: string}>
     */
    private function actionLabels(): array
    {
        $labels = [];
        foreach ($this->actionMetadataProvider->getActions() as $action) {
            $labels[$action->getCode()] = [
                'label' => $action->getLabel(),
                'group' => $action->getGroup(),
            ];
        }
        return $labels;
    }
}
