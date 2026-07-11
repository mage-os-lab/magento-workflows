<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Webapi;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ActionMetadataProviderInterface;
use MageOS\Workflows\Api\Data\ValidationMessageInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DefinitionValidationInterface;
use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\Workflows\Api\OptionSourceProviderInterface;
use MageOS\Workflows\Api\RelationMetadataProviderInterface;
use MageOS\Workflows\Api\SecretMetadataProviderInterface;
use MageOS\Workflows\Api\TriggerMetadataProviderInterface;
use MageOS\Workflows\Api\WorkflowDryRunInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowExecutionStepsProviderInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;
use MageOS\Workflows\Model\WorkflowFactory;
use PHPUnit\Framework\TestCase;

/**
 * Plan #32 (docs/20-integration-test-plan.md §7): every service class bound
 * in etc/webapi.xml, resolved from the real object manager (so the merged
 * production DI actually delivers the interface -> implementation binding,
 * not a hand-picked class) and exercised for its documented behavior: CRUD,
 * validate, dry-run, and the meta/execution read endpoints. Full HTTP+ACL
 * enforcement is the api-functional lane (§8) and explicitly out of scope
 * here — these are direct service-contract calls through the object manager.
 *
 * @magentoDbIsolation enabled
 */
class ServiceContractTest extends TestCase
{
    private WorkflowRepositoryInterface $workflowRepository;
    private WorkflowFactory $workflowFactory;
    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->workflowRepository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->workflowFactory = $objectManager->get(WorkflowFactory::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
    }

    /**
     * GET/POST/PUT/DELETE /V1/workflows (WorkflowRepositoryInterface).
     */
    public function testWorkflowRepositoryServiceCrudContract(): void
    {
        $workflow = $this->newWorkflow('service contract crud');
        $saved = $this->workflowRepository->save($workflow);
        $workflowId = (int) $saved->getWorkflowId();
        $this->assertGreaterThan(0, $workflowId);

        $loaded = $this->workflowRepository->getById($workflowId);
        $this->assertSame('service contract crud', $loaded->getName());

        $objectManager = Bootstrap::getObjectManager();
        $searchCriteria = $objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter(WorkflowInterface::NAME, 'service contract crud')
            ->create();
        $list = $this->workflowRepository->getList($searchCriteria);
        $this->assertGreaterThanOrEqual(1, $list->getTotalCount());

        $this->assertTrue($this->workflowRepository->deleteById($workflowId));
        $this->expectException(NoSuchEntityException::class);
        $this->workflowRepository->getById($workflowId);
    }

    /**
     * POST /V1/workflows/validate (DefinitionValidationInterface).
     */
    public function testDefinitionValidationServiceDistinguishesValidFromInvalid(): void
    {
        $service = Bootstrap::getObjectManager()->get(DefinitionValidationInterface::class);

        $goodDefinition = json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $goodResult = $service->validate($goodDefinition, null, 'event', 'sales.order.created', 'sales_order');
        $this->assertTrue($goodResult->getValid());
        $this->assertNotSame('', $goodResult->getPlainLanguage());
        foreach ($goodResult->getMessages() as $message) {
            $this->assertNotSame(ValidationMessageInterface::SEVERITY_ERROR, $message->getSeverity());
        }

        // entry points at a step that does not exist in `steps` -> a graph error.
        $badDefinition = json_encode([
            'schema' => 1,
            'entry' => 'does_not_exist',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $badResult = $service->validate($badDefinition, null, 'event', 'sales.order.created', 'sales_order');
        $this->assertFalse($badResult->getValid());
        $this->assertNotEmpty($badResult->getMessages());
        $hasError = false;
        foreach ($badResult->getMessages() as $message) {
            if ($message->getSeverity() === ValidationMessageInterface::SEVERITY_ERROR) {
                $hasError = true;
            }
        }
        $this->assertTrue($hasError, 'An invalid entry step must produce at least one error-severity message');
    }

    /**
     * POST /V1/workflows/dry-run and /V1/workflows/:id/dry-run
     * (WorkflowDryRunInterface).
     */
    public function testWorkflowDryRunServiceRunsOnADefinitionAndOnASavedWorkflow(): void
    {
        $service = Bootstrap::getObjectManager()->get(WorkflowDryRunInterface::class);

        $definitionJson = json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $onDefinition = $service->runOnDefinition(
            $definitionJson,
            'sales_order',
            null,
            null,
            ['entity_id' => 1, 'store_id' => 1]
        );
        $this->assertTrue($onDefinition->getValid());
        $this->assertFalse($onDefinition->getSkipped());
        $this->assertNotEmpty($onDefinition->getSteps());

        $saved = $this->workflowRepository->save($this->newWorkflow('dry run saved fixture', $definitionJson));
        $workflowId = (int) $saved->getWorkflowId();

        $onSaved = $service->runOnSaved($workflowId, null, ['entity_id' => 1, 'store_id' => 1]);
        $this->assertTrue($onSaved->getValid());
        $this->assertNotEmpty($onSaved->getSteps());

        $this->expectException(NoSuchEntityException::class);
        $service->runOnSaved(999999999);
    }

    /**
     * GET /V1/workflows/meta/actions, /meta/triggers, /meta/entity-types,
     * /meta/relations, /meta/secrets, /meta/options.
     *
     * ActionMetadataProvider ACL-filters the palette (an action whose
     * getAclResource() the current admin lacks is hidden — convenience, never
     * the security gate; the save path re-authorizes). With no authenticated
     * admin, AuthorizationInterface::isAllowed() denies every resource and the
     * palette comes back empty. ACL enforcement is out of scope for this
     * service-contract suite (§8 covers it), so we authenticate the default
     * full-access admin — the "full monorepo install" reader — so the provider
     * projects the real ActionPool.
     *
     * @magentoAppArea adminhtml
     * @magentoAppIsolation enabled
     */
    public function testMetaProvidersReturnNonEmptyCorrectlyShapedPayloads(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        $objectManager->get(\Magento\Backend\Model\Auth::class)->login(
            \Magento\TestFramework\Bootstrap::ADMIN_NAME,
            \Magento\TestFramework\Bootstrap::ADMIN_PASSWORD
        );

        $actions = $objectManager->get(ActionMetadataProviderInterface::class)->getActions();
        $this->assertNotEmpty($actions, 'ActionPool must be non-empty in a full monorepo install');
        foreach ($actions as $action) {
            $this->assertNotSame('', $action->getCode());
            $this->assertNotSame('', $action->getLabel());
            $this->assertNotSame('', $action->getGroup());
            $this->assertIsArray(json_decode($action->getConfigForm(), true));
        }
        $actionCodes = array_map(static fn ($a) => $a->getCode(), $actions);
        $this->assertContains('order.add_comment', $actionCodes);

        $triggers = $objectManager->get(TriggerMetadataProviderInterface::class)->getTriggers();
        $this->assertNotEmpty($triggers);
        foreach ($triggers as $trigger) {
            $this->assertNotSame('', $trigger->getEvent());
            $this->assertNotSame('', $trigger->getEntity());
        }
        $this->assertContains('sales.order.created', array_map(static fn ($t) => $t->getEvent(), $triggers));

        $entityTypes = $objectManager->get(EntityTypeMetadataProviderInterface::class)->getEntityTypes();
        $this->assertNotEmpty($entityTypes);
        $byCode = [];
        foreach ($entityTypes as $entityType) {
            $byCode[$entityType->getCode()] = $entityType->getLabel();
        }
        $this->assertSame('Order', $byCode['sales_order'] ?? null);

        $relations = $objectManager->get(RelationMetadataProviderInterface::class)->getRelations();
        $this->assertNotEmpty($relations);
        $relationCodes = array_map(static fn ($r) => $r->getCode(), $relations);
        $this->assertContains('order.customer_by_email', $relationCodes);
        foreach ($relations as $relation) {
            $this->assertContains($relation->getCardinality(), ['one', 'many']);
        }

        // Secrets: names only, never values.
        $secretsProvider = $objectManager->get(SecretsProviderInterface::class);
        $secretsProvider->set('service_contract_secret_a', 'super-secret-value');
        $secretNames = $objectManager->get(SecretMetadataProviderInterface::class)->getSecretNames();
        $this->assertContains('service_contract_secret_a', $secretNames);
        foreach ($secretNames as $name) {
            $this->assertIsString($name);
            $this->assertStringNotContainsString('super-secret-value', $name);
        }

        // Options: a bounded source (order_statuses) returns real, non-empty options.
        $optionProvider = $objectManager->get(OptionSourceProviderInterface::class);
        $options = $optionProvider->getOptions('order_statuses');
        $this->assertNotEmpty($options);
        foreach ($options as $option) {
            $this->assertNotSame('', $option->getValue());
            $this->assertNotSame('', $option->getLabel());
        }

        $this->expectException(NoSuchEntityException::class);
        $optionProvider->getOptions('not_a_registered_source');
    }

    /**
     * GET /V1/workflow-executions[/:id], /V1/workflow-executions/:id/steps.
     */
    public function testExecutionServicesListGetAndStepsContract(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $workflowId = (int) $this->workflowRepository->save($this->newWorkflow('service contract execution fixture'))->getWorkflowId();

        $connection = $this->resourceConnection->getConnection();
        $executionTable = $this->resourceConnection->getTableName('mageos_workflow_execution');
        $uuid = $this->randomUuid();
        $connection->insert($executionTable, [
            'uuid' => $uuid,
            'workflow_id' => $workflowId,
            'workflow_version' => 1,
            'definition_snapshot' => '{"schema":1,"entry":"s1","steps":{}}',
            'entity_id' => 1,
            'store_id' => 1,
            'status' => WorkflowExecutionInterface::STATUS_COMPLETE,
        ]);
        $executionId = (int) $connection->lastInsertId($executionTable);

        $stepTable = $this->resourceConnection->getTableName('mageos_workflow_execution_step');
        $connection->insert($stepTable, [
            'execution_id' => $executionId,
            'step_key' => 'branch_step',
            'status' => 'complete',
            'result' => '{"result":true}',
        ]);

        $executionRepository = $objectManager->get(WorkflowExecutionRepositoryInterface::class);
        $byId = $executionRepository->getById($executionId);
        $this->assertSame($uuid, $byId->getUuid());
        $byUuid = $executionRepository->getByUuid($uuid);
        $this->assertSame($executionId, $byUuid->getExecutionId());

        $searchCriteria = $objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter(WorkflowExecutionInterface::WORKFLOW_ID, $workflowId)
            ->create();
        $list = $executionRepository->getList($searchCriteria);
        $this->assertGreaterThanOrEqual(1, $list->getTotalCount());

        $stepsProvider = $objectManager->get(WorkflowExecutionStepsProviderInterface::class);
        $steps = $stepsProvider->getSteps($executionId);
        $this->assertCount(1, $steps);
        $this->assertSame('branch_step', $steps[0]->getStepKey());
        $this->assertSame('complete', $steps[0]->getStatus());
        $this->assertSame('on_true', $steps[0]->getEdgeTaken(), 'branch result:true must derive edge_taken=on_true');
        $this->assertNull($steps[0]->getErrorSummary());

        $this->expectException(NoSuchEntityException::class);
        $stepsProvider->getSteps(999999999);
    }

    private function newWorkflow(string $name, ?string $definitionJson = null): WorkflowInterface
    {
        $workflow = $this->workflowFactory->create();
        $workflow->setName($name);
        $workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef('sales.order.created');
        $workflow->setEntityType('sales_order');
        $workflow->setDefinition($definitionJson ?? json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $workflow;
    }

    private function randomUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
