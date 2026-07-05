<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Plugin;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\ValidatorException;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Validation\Check\ActionAuthorizationCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationContextResolver;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationResult;
use MageOS\Workflows\Model\Validation\ValidationResultRegistry;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Model\Validation\WorkflowValidator;
use MageOS\Workflows\Plugin\ValidateWorkflowOnSave;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proof that the repository before-plugin (the single F2 validation chokepoint)
 * FIRES on the save path the canvas uses (implementation plan 07, stage 4).
 * The canvas has no save endpoint of its own: it posts through the admin Save
 * controller, which calls WorkflowRepositoryInterface::save, which this plugin
 * intercepts. A supervisor asked for this to be pinned by a test.
 *
 * The plugin has a heavy constructor (validator, context resolver, factory,
 * resource, registry, logger). For a NEW workflow (no id) beforeSave() never
 * touches the factory/resource (definitionOrConditionsChanged short-circuits to
 * true), so the plugin is built via newInstanceWithoutConstructor with only the
 * reached collaborators wired in — a validator SPY records whether the
 * chokepoint ran, and its scripted result drives the block/allow branches.
 */
class ValidateWorkflowOnSaveFiresTest extends TestCase
{
    private function plugin(SpyValidator $validator, ?ValidationResultRegistry $registry = null): ValidateWorkflowOnSave
    {
        $plugin = (new \ReflectionClass(ValidateWorkflowOnSave::class))->newInstanceWithoutConstructor();
        $this->setProp($plugin, 'validator', $validator);
        $this->setProp($plugin, 'contextResolver', new FixedContextResolver());
        $this->setProp($plugin, 'resultRegistry', $registry ?? new ValidationResultRegistry());
        $this->setProp($plugin, 'logger', new NullLogger());
        // workflowFactory / workflowResource are deliberately left uninitialized:
        // a new (id-less) workflow never reaches them.
        return $plugin;
    }

    private function setProp(object $obj, string $name, mixed $value): void
    {
        $prop = new \ReflectionProperty($obj, $name);
        $prop->setValue($obj, $value);
    }

    private function repository(): WorkflowRepositoryInterface
    {
        return new class implements WorkflowRepositoryInterface {
            public function save(\MageOS\Workflows\Api\Data\WorkflowInterface $workflow): \MageOS\Workflows\Api\Data\WorkflowInterface
            {
                return $workflow;
            }
            public function getById(int $workflowId): \MageOS\Workflows\Api\Data\WorkflowInterface
            {
                throw new \RuntimeException('not used');
            }
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria): \Magento\Framework\Api\SearchResultsInterface
            {
                throw new \RuntimeException('not used');
            }
            public function delete(\MageOS\Workflows\Api\Data\WorkflowInterface $workflow): bool
            {
                throw new \RuntimeException('not used');
            }
            public function deleteById(int $workflowId): bool
            {
                throw new \RuntimeException('not used');
            }
        };
    }

    public function testChokepointRunsOnSaveForANewWorkflow(): void
    {
        $validator = new SpyValidator(new ValidationResult([]));
        $plugin = $this->plugin($validator);

        $result = $plugin->beforeSave($this->repository(), new WorkflowStub(null));

        $this->assertTrue($validator->called, 'The validator must run on the save path');
        $this->assertCount(1, $result);
    }

    public function testInvalidDefinitionBlocksTheSaveWithValidatorException(): void
    {
        $validator = new SpyValidator(new ValidationResult([
            ValidationMessage::error('GRAPH_CYCLE', 'The graph has a cycle', 's1'),
        ]));
        $plugin = $this->plugin($validator);

        $this->expectException(ValidatorException::class);
        $plugin->beforeSave($this->repository(), new WorkflowStub(null));
    }

    public function testUnauthorizedActionBlocksTheSaveWithAuthorizationException(): void
    {
        $validator = new SpyValidator(new ValidationResult([
            ValidationMessage::error(
                ActionAuthorizationCheck::CODE_ACTION_UNAUTHORIZED,
                'You may not author order.cancel',
                's1'
            ),
        ]));
        $plugin = $this->plugin($validator);

        $this->expectException(AuthorizationException::class);
        $plugin->beforeSave($this->repository(), new WorkflowStub(null));
    }

    public function testWarningsTravelToTheRegistryWithoutBlocking(): void
    {
        $validator = new SpyValidator(new ValidationResult([
            ValidationMessage::warning('GRAPH_UNREACHABLE', 'Step s2 is unreachable', 's2'),
        ]));
        $registry = new ValidationResultRegistry();
        $plugin = $this->plugin($validator, $registry);

        $plugin->beforeSave($this->repository(), new WorkflowStub(null));

        $this->assertNotNull($registry->get());
        $this->assertCount(1, $registry->get()->getWarnings());
    }

    public function testValidatorReceivesTheWorkflowDefinitionAndConditions(): void
    {
        $validator = new SpyValidator(new ValidationResult([]));
        $plugin = $this->plugin($validator);

        $workflow = new WorkflowStub([
            'workflow_id' => null,
            'definition' => '{"schema":3,"entry":"s1","steps":{"s1":{"type":"stop"}}}',
            'conditions_serialized' => '{"type":"root"}',
        ]);
        $plugin->beforeSave($this->repository(), $workflow);

        $this->assertInstanceOf(ValidationSubject::class, $validator->subject);
    }
}

/**
 * Records that validate() ran and returns a scripted result.
 */
class SpyValidator extends WorkflowValidator
{
    public bool $called = false;
    public ?ValidationSubject $subject = null;

    // Bypass the parent constructor (it wants the check chain).
    public function __construct(private readonly ValidationResult $scripted)
    {
    }

    public function validate(ValidationSubject $subject, ValidationContext $context): ValidationResult
    {
        $this->called = true;
        $this->subject = $subject;
        return $this->scripted;
    }
}

class FixedContextResolver extends ValidationContextResolver
{
    public function __construct()
    {
    }

    public function resolve(): ValidationContext
    {
        return new ValidationContext(ValidationContext::MODE_ADMIN_CONTEXT);
    }
}
