<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Plugin;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\ValidatorException;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Validation\ValidationResultRegistry;
use MageOS\Workflows\Model\WorkflowFactory;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #4 (docs/20 §4): the ValidateWorkflowOnSave plugin is DI-wired onto the
 * repository INTERFACE — this suite proves the merged configuration actually
 * delivers it (the "protection lives in di.xml" risk, docs/19). An invalid
 * definition throws ValidatorException through a plain repository save; a
 * status-only save of a workflow whose stored definition references a
 * nonexistent action skips validation (the disable-after-uninstall guarantee);
 * warnings land in ValidationResultRegistry, never as exceptions.
 *
 * @magentoDbIsolation enabled
 */
class ValidateWorkflowOnSaveTest extends TestCase
{
    use WorkflowEngineTestTrait;

    private WorkflowRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->repository = $this->om()->get(WorkflowRepositoryInterface::class);
    }

    public function testInvalidDefinitionThrowsValidatorExceptionThroughRealSave(): void
    {
        $workflow = $this->newWorkflow('invalid def', [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'does.not.exist', 'config' => [], 'next' => null],
            ],
        ]);

        try {
            $this->repository->save($workflow);
            $this->fail('Expected ValidatorException for an unknown action code');
        } catch (ValidatorException $e) {
            $this->assertStringContainsString('cannot be saved', $e->getMessage());
        }

        // The plugin records the failing result before throwing.
        $result = $this->om()->get(ValidationResultRegistry::class)->get();
        $this->assertNotNull($result);
        $this->assertTrue($result->hasErrors(), 'The failing validation result lands in the registry too');
    }

    /**
     * The documented disable-after-uninstall guarantee: a workflow whose stored
     * definition references an action whose module was removed must still be
     * flippable. The row is staged directly through the resource model (past
     * validation); flipping status through the repository must not re-validate.
     */
    public function testStatusOnlySaveSkipsValidationOfAStoredInvalidDefinition(): void
    {
        $workflowId = $this->insertWorkflowRow([
            'name' => 'stored invalid',
            'status' => WorkflowInterface::STATUS_ENABLED,
            'definition' => [
                'schema' => 1,
                'entry' => 's1',
                'steps' => [
                    's1' => ['type' => 'action', 'action' => 'uninstalled.action', 'config' => [], 'next' => null],
                ],
            ],
        ]);

        $loaded = $this->repository->getById($workflowId);
        $loaded->setStatus(WorkflowInterface::STATUS_DISABLED);

        // Must NOT throw despite the stored definition being unvalidatable.
        $saved = $this->repository->save($loaded);
        $this->assertSame(WorkflowInterface::STATUS_DISABLED, $saved->getStatus());
        $this->assertSame(
            WorkflowInterface::STATUS_DISABLED,
            $this->repository->getById($workflowId)->getStatus()
        );
    }

    public function testWarningsLandInRegistryAndDoNotBlockTheSave(): void
    {
        // Valid (no errors) but carries an UNREACHABLE_STEP warning: s2 has no
        // incoming edge.
        $workflow = $this->newWorkflow('warn only', [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'a'], 'next' => null],
                's2' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'b'], 'next' => null],
            ],
        ]);

        $saved = $this->repository->save($workflow);
        $this->assertNotNull($saved->getWorkflowId(), 'A warning must not block the save');

        $result = $this->om()->get(ValidationResultRegistry::class)->get();
        $this->assertNotNull($result);
        $this->assertFalse($result->hasErrors(), 'Warnings are not errors');
        $this->assertNotSame([], $result->getWarnings(), 'The unreachable-step warning is captured');
    }

    /**
     * ACL AuthorizationException path (docs/09): in the adminhtml area the
     * ValidationContext is admin-context, so ActionAuthorizationCheck runs. With
     * no authenticated admin the current role resolves to none, so authoring a
     * real action is refused with AuthorizationException — distinct from the
     * generic ValidatorException.
     *
     * @magentoAppArea adminhtml
     */
    public function testUnauthorizedActionThrowsAuthorizationExceptionInAdminArea(): void
    {
        $workflow = $this->newWorkflow('acl gate', [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null],
            ],
        ]);

        $this->expectException(AuthorizationException::class);
        $this->repository->save($workflow);
    }

    private function newWorkflow(string $name, array $definition): WorkflowInterface
    {
        /** @var WorkflowInterface $workflow */
        $workflow = $this->om()->get(WorkflowFactory::class)->create();
        $workflow->setName($name);
        $workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef('sales.order.created');
        $workflow->setEntityType('sales_order');
        $workflow->setDefinition(json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $workflow;
    }
}
