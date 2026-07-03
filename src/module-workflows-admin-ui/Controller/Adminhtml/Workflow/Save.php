<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ActionMetadataInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\Data\WorkflowInterfaceFactory;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;

/**
 * Persists general fields plus the step-graph definition.
 *
 * v1 primary path: the raw JSON textarea (see the form's "definition" field).
 * Forward-compatible path: if a dynamicRows "steps" array is posted (stretch-goal UI, not
 * shipped in v1), it is assembled into a linear chain -- each row becomes one step keyed
 * s1..sN, "next" pointers chain them in posted order, entry = the first row -- and run
 * through the exact same Definition::fromJson/fromArray validation as the textarea path.
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
        private readonly ActionPool $actionPool,
        private readonly DataPersistorInterface $dataPersistor
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
            $this->authorizeActionCodes($definition);
            $this->validateConditionsSerialized($data['conditions_serialized'] ?? null);

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
            $workflow->setLoopGuardDepth((int) ($data['loop_guard_depth'] ?? 1));
            $workflow->setWebsiteIds(
                isset($data['website_ids']) ? array_map('intval', (array) $data['website_ids']) : []
            );

            $this->workflowRepository->save($workflow);
            $this->dataPersistor->clear(self::PERSISTOR_KEY);
            $this->messageManager->addSuccessMessage(__('The workflow has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath(
                    'mageos_workflows/workflow/edit',
                    ['workflow_id' => $workflow->getWorkflowId()]
                );
            }
            return $resultRedirect->setPath('mageos_workflows/workflow/index');
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
     * v1 conditions are an opaque serialized condition tree; the only save-time contract is
     * "empty, or a JSON structure". Deeper semantic validation is intentionally out of scope.
     *
     * @throws \InvalidArgumentException
     */
    private function validateConditionsSerialized(mixed $conditionsSerialized): void
    {
        if ($conditionsSerialized === null
            || (is_scalar($conditionsSerialized) && trim((string) $conditionsSerialized) === '')
        ) {
            return;
        }
        $decoded = is_scalar($conditionsSerialized)
            ? json_decode((string) $conditionsSerialized, true)
            : null;
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(
                (string) __('The Conditions field must be empty or contain a valid JSON condition tree (object or array).')
            );
        }
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
                    $step['revalidate_entity'] = !empty($row['revalidate_entity']);
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

    /**
     * Re-authorize every action code referenced by the definition against the current
     * admin's ACL, per action-group gate (docs/09 -- authoring gates, not just execution).
     *
     * @throws \InvalidArgumentException
     * @throws AuthorizationException
     */
    private function authorizeActionCodes(Definition $definition): void
    {
        foreach ($definition->getActionCodes() as $code) {
            if (!$this->actionPool->has($code)) {
                throw new \InvalidArgumentException((string) __('Unknown workflow action "%1".', $code));
            }
            $action = $this->actionPool->get($code);
            $resource = ($action instanceof ActionMetadataInterface ? $action->getAclResource() : null)
                ?? 'MageOS_Workflows::manage';
            if (!$this->_authorization->isAllowed($resource)) {
                throw new AuthorizationException(
                    __('You are not authorized to author the "%1" action into a workflow.', $code)
                );
            }
        }
    }
}
