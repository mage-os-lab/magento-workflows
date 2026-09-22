<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\Data\WorkflowInterfaceFactory;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\ValidationResultRegistry;

/**
 * Persists general fields plus the step-graph definition.
 *
 * v1 primary path: the raw JSON textarea (see the form's "definition" field).
 * Forward-compatible path: if a dynamicRows "steps" array is posted (stretch-goal UI, not
 * shipped in v1), it is assembled into a linear chain -- each row becomes one step keyed
 * s1..sN, "next" pointers chain them in posted order, entry = the first row -- and run
 * through the exact same Definition::fromJson/fromArray validation as the textarea path.
 *
 * Validation happens in the F2 pipeline behind WorkflowRepositoryInterface::save
 * (structural, graph, action codes, per-action ACL, conditions shape — see
 * MageOS\Workflows\Plugin\ValidateWorkflowOnSave); this controller only
 * normalizes the posted definition and surfaces the pipeline's messages.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    /**
     * DataPersistor key under which posted form data survives a failed save,
     * so the edit form (DataProvider) can restore the merchant's input.
     */
    public const PERSISTOR_KEY = 'mageos_workflow';

    public function __construct(
        Action\Context $context,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly WorkflowInterfaceFactory $workflowFactory,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly ValidationResultRegistry $validationResultRegistry,
        // Framework class, so this adds no module dependency: admin-ui never
        // depends on the optional canvas package. Used only to decide whether
        // a `back=canvas` round-trip has anywhere to land.
        private readonly ModuleManager $moduleManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $resultRedirect->setPath('mageos_workflows/workflow/index');
        }

        $workflowId = !empty($data['workflow_id']) ? (int) $data['workflow_id'] : null;

        try {
            $workflow = $workflowId
                ? $this->workflowRepository->getById($workflowId)
                : $this->workflowFactory->create();

            $definitionJson = $this->resolveDefinitionJson($data);
            $definition = Definition::fromJson($definitionJson);

            $workflow->setName((string) ($data['name'] ?? ''));
            $workflow->setStatus((int) ($data['status'] ?? WorkflowInterface::STATUS_DISABLED));
            $workflow->setTriggerType((string) ($data['trigger_type'] ?? WorkflowInterface::TRIGGER_TYPE_EVENT));
            $workflow->setTriggerRef((string) ($data['trigger_ref'] ?? ''));
            $workflow->setEntityType((string) ($data['entity_type'] ?? ''));
            $workflow->setConditionsSerialized(
                isset($data['conditions_serialized']) && $data['conditions_serialized'] !== ''
                    ? (string) $data['conditions_serialized']
                    : null
            );
            $workflow->setDefinition($definition->toJson());
            $workflow->setFanOut($this->resolveFanOut($data));
            $workflow->setLoopGuardDepth((int) ($data['loop_guard_depth'] ?? 1));
            $workflow->setWebsiteIds(
                isset($data['website_ids']) ? array_map('intval', (array) $data['website_ids']) : []
            );

            $this->workflowRepository->save($workflow);
            $this->dataPersistor->clear(self::PERSISTOR_KEY);
            $this->messageManager->addSuccessMessage(__('The workflow has been saved.'));
            $this->surfaceValidationWarnings();

            [$path, $params] = $this->resolveSuccessRedirect(
                $this->getRequest()->getParam('back'),
                (int) $workflow->getWorkflowId()
            );
            return $resultRedirect->setPath($path, $params);
        } catch (NoSuchEntityException $e) {
            // The record being edited was deleted meanwhile: keep the merchant's input and
            // reopen it as a new workflow instead of dropping everything on the grid page.
            unset($data['workflow_id']);
            $this->dataPersistor->set(self::PERSISTOR_KEY, $data);
            $this->messageManager->addErrorMessage(
                __('This workflow no longer exists. Your input has been kept below; saving will create a new workflow.')
            );
            return $resultRedirect->setPath('mageos_workflows/workflow/edit');
        } catch (\InvalidArgumentException|AuthorizationException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (LocalizedException $e) {
            // Validation pipeline errors (ValidatorException et al.) carry actionable text
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Something went wrong while saving the workflow.'));
        }

        // Any failure path: persist the full posted data so the edit form restores it.
        $this->dataPersistor->set(self::PERSISTOR_KEY, $data);

        return $resultRedirect->setPath(
            'mageos_workflows/workflow/edit',
            $workflowId ? ['workflow_id' => $workflowId] : []
        );
    }

    /**
     * Where a SUCCESSFUL save lands: [route path, route params].
     *
     * `back=canvas` is the visual editor's round-trip. The canvas posts through
     * this same controller — it has no save path of its own (docs/discovery/
     * canvas.md §4) — and needs to come back to itself rather than to the
     * classic form; that is what makes canvas-first authoring of a NEW workflow
     * work at all, since the id only exists once this save has run. The canvas
     * package is optional, so the flag is honored only while its module is
     * enabled and otherwise degrades to the ordinary "Save and Continue Edit"
     * behavior. Any other truthy `back` keeps that classic behavior; falsy
     * returns to the grid.
     *
     * Failure paths deliberately do NOT consult this: they always return to the
     * classic form, where DataPersistor restores the merchant's input and the
     * validation messages render.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resolveSuccessRedirect(mixed $back, int $workflowId): array
    {
        if ($back === 'canvas' && $this->moduleManager->isEnabled('MageOS_WorkflowsCanvas')) {
            return ['mageos_workflows_canvas/canvas/edit', ['workflow_id' => $workflowId]];
        }
        if ($back) {
            return ['mageos_workflows/workflow/edit', ['workflow_id' => $workflowId]];
        }
        return ['mageos_workflows/workflow/index', []];
    }

    /**
     * Non-blocking findings from the validation pipeline (unreachable steps,
     * post-delay stale branches, …) surface as form warnings after a
     * successful save.
     */
    private function surfaceValidationWarnings(): void
    {
        $result = $this->validationResultRegistry->get();
        if ($result === null) {
            return;
        }
        foreach ($result->getWarnings() as $warning) {
            $this->messageManager->addWarningMessage($warning->getMessage());
        }
    }

    /**
     * Assemble the fan_out column JSON ({relation, cap}) from the two form
     * fields; null when no relation is chosen (today's per-entity behavior).
     * A blank cap defers to the global ceiling — omitted rather than stored 0.
     *
     * @param array<string, mixed> $data
     */
    private function resolveFanOut(array $data): ?string
    {
        $relation = trim((string) ($data['fan_out_relation'] ?? ''));
        if ($relation === '') {
            return null;
        }
        $config = ['relation' => $relation];
        $cap = $data['fan_out_cap'] ?? '';
        if (is_numeric($cap) && (int) $cap > 0) {
            $config['cap'] = (int) $cap;
        }
        return (string) json_encode($config, JSON_UNESCAPED_SLASHES);
    }

    private function resolveDefinitionJson(array $data): string
    {
        if (isset($data['steps']) && is_array($data['steps']) && $data['steps'] !== []) {
            return (string) json_encode($this->buildDefinitionFromRows($data['steps']), JSON_THROW_ON_ERROR);
        }
        $definition = $data['definition'] ?? '';
        if (is_string($definition) && trim($definition) !== '') {
            return $definition;
        }
        return (string) json_encode(
            ['schema' => Definition::SCHEMA_VERSION, 'steps' => [], 'entry' => null],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Linear-chain assembly for the (stretch-goal) dynamicRows "steps" editor.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{schema:int, steps: array<string, array>, entry: string|null}
     */
    private function buildDefinitionFromRows(array $rows): array
    {
        $rows = array_values($rows);
        $keys = [];
        foreach ($rows as $index => $row) {
            $keys[$index] = 's' . ($index + 1);
        }

        $steps = [];
        foreach ($rows as $index => $row) {
            $type = (string) ($row['type'] ?? Definition::STEP_ACTION);
            $step = ['type' => $type];

            switch ($type) {
                case Definition::STEP_DELAY:
                    $step['config'] = [
                        'duration' => (string) ($row['delay_duration'] ?? $row['config']['duration'] ?? 'PT1H'),
                    ];
                    $step['next'] = $keys[$index + 1] ?? null;
                    break;
                case Definition::STEP_BRANCH:
                    $step['conditions_serialized'] = $row['conditions_serialized'] ?? null;
                    $step['revalidate_entity'] = $this->resolveRevalidateEntity($rows, $index, $row);
                    $step['on_true'] = $keys[$index + 1] ?? null;
                    $step['on_false'] = null;
                    break;
                case Definition::STEP_STOP:
                    break;
                case Definition::STEP_ACTION:
                default:
                    $step['type'] = Definition::STEP_ACTION;
                    $step['action'] = (string) ($row['action'] ?? '');
                    $step['config'] = $this->decodeConfig($row['config'] ?? []);
                    $step['next'] = $keys[$index + 1] ?? null;
                    break;
            }

            $steps[$keys[$index]] = $step;
        }

        return [
            'schema' => Definition::SCHEMA_VERSION,
            'steps' => $steps,
            'entry' => $keys[0] ?? null,
        ];
    }

    /**
     * `revalidate_entity` default for an assembled branch row.
     *
     * An explicit value in the posted row always wins. Absent one, the default
     * follows [06 §Delay semantics](docs/06-conditions.md#delay-semantics):
     * a branch that directly follows a delay step re-hydrates by default
     * (true) -- evaluating a post-delay branch against the frozen trigger
     * snapshot is usually a mistake and would otherwise raise the
     * GRAPH_POST_DELAY_STALE warning on every form-built delay->branch. A
     * branch anywhere else keeps the historical default (false).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $row
     */
    private function resolveRevalidateEntity(array $rows, int $index, array $row): bool
    {
        if (array_key_exists('revalidate_entity', $row)) {
            return (bool) $row['revalidate_entity'];
        }
        $previousType = $index > 0
            ? (string) ($rows[$index - 1]['type'] ?? Definition::STEP_ACTION)
            : '';
        return $previousType === Definition::STEP_DELAY;
    }

    private function decodeConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }
        if (is_string($config) && trim($config) !== '') {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

}
