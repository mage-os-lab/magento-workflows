<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Block\Adminhtml\Canvas;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ActionMetadataProviderInterface;
use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Api\Data\ActionMetadataItemInterface;
use MageOS\Workflows\Api\SecretMetadataProviderInterface;
use MageOS\Workflows\Api\TriggerMetadataProviderInterface;
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
 *
 * `approvalsAvailable` is bootstrapped the same way: read from the nullable
 * ApprovalTaskManagerInterface seam (bound only when MageOS_WorkflowsApprovals
 * is installed), so the palette can gate the "Approval gate" node the same
 * way the server's save-time ApprovalCheck gates authoring it.
 */
class Mount extends Template
{
    public function __construct(
        Context $context,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly ActionMetadataProviderInterface $actionMetadataProvider,
        private readonly TriggerMetadataProviderInterface $triggerMetadataProvider,
        private readonly SecretMetadataProviderInterface $secretMetadataProvider,
        private readonly AuthorizationInterface $authorization,
        array $data = [],
        // Optional (nullable) dependency — the SAME seam core's own
        // Model/Validation/Check/ApprovalCheck.php uses to detect whether the
        // MageOS_WorkflowsApprovals addon is installed: absent addon -> no
        // di.xml preference bound -> null here. Canvas has no module.xml
        // dependency on the addon (docs/discovery/canvas.md §1: "the
        // dependency arrow only ever points inward, from canvas to
        // module-workflows") — it only optionally consumes a core-declared
        // interface, exactly like the save-time check does.
        private readonly ?ApprovalTaskManagerInterface $approvalTaskManager = null
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
        $workflow = $this->loadWorkflow($workflowId);
        $entityType = $workflow !== null ? (string) $workflow->getEntityType() : null;

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
                // Same-origin admin JSON validate proxy (::manage, form key).
                'validate' => $this->getUrl('mageos_workflows_canvas/data/validate'),
                // Same-origin admin JSON option-source proxy (::view).
                'options' => $this->getUrl('mageos_workflows_canvas/data/options'),
                // The EXISTING admin Save controller — the canvas has no save
                // path of its own (docs/discovery/canvas.md §4).
                'save' => $this->getUrl('mageos_workflows/workflow/save'),
            ],
            'formKey' => $this->getFormKey(),
            'workflow' => null,
            'actions' => $this->actionLabels(),
            // Full palette/config metadata (Phase B). ACL-filtered for display
            // by the provider; the save path re-authorizes every action code.
            'actionsMeta' => $this->actionsMeta($entityType),
            'triggers' => $this->triggers(),
            // Secret NAMES only — values are write-only and never bootstrapped.
            'secrets' => $this->secretMetadataProvider->getSecretNames(),
            // Gates the "Approval gate" palette entry (docs/discovery/approval-gate.md
            // §7: canvas node metadata "renders only when both optional packages
            // are present"). A loaded definition's existing `approval` step still
            // renders/dry-runs regardless — this only controls whether NEW ones
            // may be authored, mirroring the save-time APPROVAL_MODULE_MISSING gate.
            'approvalsAvailable' => $this->approvalTaskManager !== null,
        ];

        if ($workflow !== null) {
            $config['workflow'] = [
                'id' => (int) $workflow->getWorkflowId(),
                'name' => (string) $workflow->getName(),
                'status' => (int) $workflow->getStatus(),
                'entityType' => (string) $workflow->getEntityType(),
                'triggerType' => (string) $workflow->getTriggerType(),
                'triggerRef' => (string) $workflow->getTriggerRef(),
                'conditionsSerialized' => $workflow->getConditionsSerialized(),
                'loopGuardDepth' => (int) $workflow->getLoopGuardDepth(),
                'websiteIds' => array_values(array_map('intval', $workflow->getWebsiteIds())),
                'fanOutRelation' => $this->fanOutField($workflow->getFanOut(), 'relation'),
                'fanOutCap' => $this->fanOutField($workflow->getFanOut(), 'cap'),
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

    /**
     * Full palette + config-panel metadata (Phase B), scoped to the workflow's
     * entity type. The provider ACL-filters actions the current admin may not
     * author — a display convenience only; the save path is the real gate. The
     * per-action getConfigForm() JSON is decoded here so the client receives a
     * structured field list (never a string it would have to parse loosely).
     *
     * @return array<int, array<string, mixed>>
     */
    private function actionsMeta(?string $entityType): array
    {
        $meta = [];
        foreach ($this->actionMetadataProvider->getActions($entityType) as $action) {
            /** @var ActionMetadataItemInterface $action */
            $meta[] = [
                'code' => $action->getCode(),
                'label' => $action->getLabel(),
                'group' => $action->getGroup(),
                'applicableEntities' => array_values($action->getApplicableEntities()),
                'configForm' => $this->decodeConfigForm($action->getConfigForm()),
                'aclResource' => $action->getAclResource(),
            ];
        }
        return $meta;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeConfigForm(string $configFormJson): array
    {
        $decoded = json_decode($configFormJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Palette trigger section (grouped), projected from TriggerRegistry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function triggers(): array
    {
        $triggers = [];
        foreach ($this->triggerMetadataProvider->getTriggers() as $trigger) {
            $triggers[] = [
                'event' => $trigger->getEvent(),
                'entity' => $trigger->getEntity(),
                'label' => $trigger->getLabel(),
                'group' => $trigger->getGroup(),
            ];
        }
        return $triggers;
    }

    /**
     * Decode one field of the stored fan_out JSON ({relation, cap}) back into
     * the form's split fields, so a canvas save round-trips fan-out unchanged
     * through the admin Save controller (which reads fan_out_relation/_cap).
     */
    private function fanOutField(?string $fanOutJson, string $field): string
    {
        if ($fanOutJson === null || trim($fanOutJson) === '') {
            return '';
        }
        $decoded = json_decode($fanOutJson, true);
        if (!is_array($decoded) || !isset($decoded[$field])) {
            return '';
        }
        return (string) $decoded[$field];
    }
}
