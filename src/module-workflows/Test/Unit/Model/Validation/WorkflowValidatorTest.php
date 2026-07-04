<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Validation;

use Magento\Framework\AuthorizationInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Validation\Check\ActionAuthorizationCheck;
use MageOS\Workflows\Model\Validation\Check\ActionCodesCheck;
use MageOS\Workflows\Model\Validation\Check\ConditionsShapeCheck;
use MageOS\Workflows\Model\Validation\Check\GraphCheck;
use MageOS\Workflows\Model\Validation\Check\ProfileCheck;
use MageOS\Workflows\Model\Validation\Check\StructuralCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Model\Validation\WorkflowValidator;
use MageOS\Workflows\Test\Unit\Stub\StubAction;
use PHPUnit\Framework\TestCase;

class WorkflowValidatorTest extends TestCase
{
    private function validator(bool $authorized = true, array $profiles = []): WorkflowValidator
    {
        $pool = new ActionPool([
            'order.add_comment' => new StubAction('order.add_comment', 'Add Order Comment'),
            'notify.webhook' => new StubAction(
                'notify.webhook',
                'Call a Webhook',
                'MageOS_Workflows::action_notify'
            ),
        ]);
        $authorization = new class ($authorized) implements AuthorizationInterface {
            public function __construct(private readonly bool $allowed)
            {
            }

            public function isAllowed($resource, $privilege = null)
            {
                return $this->allowed;
            }
        };

        return new WorkflowValidator([
            'structural' => new StructuralCheck(),
            'graph' => new GraphCheck(),
            'profile' => new ProfileCheck($profiles),
            'action_codes' => new ActionCodesCheck($pool),
            'action_authorization' => new ActionAuthorizationCheck($pool, $authorization),
            'conditions_shape' => new ConditionsShapeCheck(),
        ]);
    }

    private function subject(array $definition, ?string $conditions = null): ValidationSubject
    {
        return new ValidationSubject((string) json_encode($definition), $conditions);
    }

    private function validDefinition(): array
    {
        return [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => 's2'],
                's2' => ['type' => 'stop'],
            ],
        ];
    }

    public function testValidWorkflowPasses(): void
    {
        $result = $this->validator()->validate(
            $this->subject($this->validDefinition(), '{"type":"combine","conditions":[]}'),
            new ValidationContext()
        );

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->getMessages());
    }

    public function testUnparseableDefinitionShortCircuitsToStructuralError(): void
    {
        $result = $this->validator()->validate(
            new ValidationSubject('{"schema": 99, "steps": {}, "entry": null}'),
            new ValidationContext()
        );

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->getMessages());
        $this->assertSame(StructuralCheck::CODE_DEFINITION_INVALID, $result->getErrors()[0]->getCode());
        $this->assertStringContainsString('Unsupported definition schema', $result->getErrors()[0]->getMessage());
    }

    public function testUnknownActionCodeIsError(): void
    {
        $definition = $this->validDefinition();
        $definition['steps']['s1']['action'] = 'vendor.not_installed';

        $result = $this->validator()->validate($this->subject($definition), new ValidationContext());

        $this->assertTrue($result->hasErrorWithCode(ActionCodesCheck::CODE_ACTION_UNKNOWN));
        $this->assertSame('s1', $result->getErrors()[0]->getStepKey());
    }

    public function testUnauthorizedActionIsErrorInAdminContext(): void
    {
        $definition = $this->validDefinition();
        $definition['steps']['s1']['action'] = 'notify.webhook';

        $result = $this->validator(false)->validate($this->subject($definition), new ValidationContext());

        $this->assertTrue($result->hasErrorWithCode(ActionAuthorizationCheck::CODE_ACTION_UNAUTHORIZED));
    }

    public function testAuthorizationSkippedInSystemMode(): void
    {
        $definition = $this->validDefinition();
        $definition['steps']['s1']['action'] = 'notify.webhook';

        $result = $this->validator(false)->validate(
            $this->subject($definition),
            new ValidationContext(ValidationContext::MODE_SYSTEM)
        );

        $this->assertTrue($result->isValid());
    }

    public function testAuthorizationSkippedOnDryRun(): void
    {
        $definition = $this->validDefinition();
        $definition['steps']['s1']['action'] = 'notify.webhook';

        $result = $this->validator(false)->validate(
            $this->subject($definition),
            new ValidationContext(ValidationContext::MODE_ADMIN_CONTEXT, ValidationContext::KIND_STANDARD, true)
        );

        $this->assertTrue($result->isValid());
    }

    public function testMalformedRootConditionsIsError(): void
    {
        $result = $this->validator()->validate(
            $this->subject($this->validDefinition(), '{oops'),
            new ValidationContext()
        );

        $this->assertTrue($result->hasErrorWithCode(ConditionsShapeCheck::CODE_CONDITIONS_INVALID));
    }

    public function testMalformedBranchConditionsIsErrorWithStepTarget(): void
    {
        $definition = $this->validDefinition();
        $definition['steps']['s1'] = [
            'type' => 'branch',
            'conditions_serialized' => 'not-json',
            'on_true' => 's2',
            'on_false' => null,
        ];

        $result = $this->validator()->validate($this->subject($definition), new ValidationContext());

        $this->assertTrue($result->hasErrorWithCode(ConditionsShapeCheck::CODE_CONDITIONS_INVALID));
        $this->assertSame('s1', $result->getErrors()[0]->getStepKey());
    }

    public function testWarningsDoNotInvalidate(): void
    {
        $definition = $this->validDefinition();
        $definition['steps']['orphan'] = ['type' => 'stop'];

        $result = $this->validator()->validate($this->subject($definition), new ValidationContext());

        $this->assertTrue($result->isValid());
        $this->assertCount(1, $result->getWarnings());
        $this->assertSame(GraphCheck::CODE_UNREACHABLE_STEP, $result->getWarnings()[0]->getCode());
    }

    public function testProfileCheckInertWithoutProfileForKind(): void
    {
        $result = $this->validator(true, ['aggregated' => ['step_types' => ['action', 'stop']]])
            ->validate($this->subject($this->validDefinition()), new ValidationContext());

        $this->assertTrue($result->isValid());
    }

    public function testProfileCheckEnforcesStepTypeAllowlist(): void
    {
        $definition = $this->validDefinition();
        $definition['steps']['d1'] = ['type' => 'delay', 'config' => ['duration' => 'PT1H'], 'next' => null];
        $definition['steps']['s2']['type'] = 'stop';

        $result = $this->validator(true, ['aggregated' => ['step_types' => ['action', 'stop']]])
            ->validate(
                $this->subject($definition),
                new ValidationContext(ValidationContext::MODE_ADMIN_CONTEXT, 'aggregated')
            );

        $this->assertTrue($result->hasErrorWithCode(ProfileCheck::CODE_STEP_TYPE_FORBIDDEN));
    }

    public function testSwitchCaseConditionsShapeChecked(): void
    {
        $definition = [
            'schema' => 3,
            'entry' => 'sw',
            'steps' => [
                'sw' => [
                    'type' => 'switch',
                    'cases' => [
                        ['key' => 'bad', 'conditions_serialized' => '{broken', 'next' => null],
                    ],
                    'default' => null,
                ],
            ],
        ];

        $result = $this->validator()->validate($this->subject($definition), new ValidationContext());

        $this->assertTrue($result->hasErrorWithCode(ConditionsShapeCheck::CODE_CONDITIONS_INVALID));
        $errors = $result->getErrors();
        $this->assertSame('sw', $errors[0]->getStepKey());
        $this->assertSame('case:bad', $errors[0]->getEdge());
    }
}
