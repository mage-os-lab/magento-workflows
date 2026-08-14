<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Block\Adminhtml\Execution;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Model\Webapi\ExecutionDetailRedactor;
use MageOS\Workflows\Model\Webapi\StepDetailRedactor;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsAdminUi\Block\Adminhtml\Execution\View;
use PHPUnit\Framework\TestCase;

/**
 * The admin execution-detail page was the last unredacted read path onto stored
 * execution detail: it printed step `error`, step `result` and the whole
 * `context` bag straight out of the row, to anyone holding the weakest grant
 * (MageOS_Workflows::view), while the steps REST route had been masking the same
 * fields since it shipped. Those fields are written by the PRODUCTION executor
 * from interpolated action config, so a webhook URL with its token or a raw HTTP
 * exception message lands in them verbatim.
 *
 * These assertions pin the fix where it belongs — in the block, using the shared
 * ExecutionDetailRedactor and therefore the SAME rules as REST, not a
 * template-side string hack.
 *
 * View extends Backend\Block\Template, whose real constructor is layout-heavy
 * and unavailable here, so the block is an anonymous subclass with a no-op
 * constructor and its promoted dependency injected by reflection (the posture
 * ViewLabelsTest documents).
 */
class ViewRedactionTest extends TestCase
{
    private const SENTINEL = 'xoxb-9f8e7d6c5b4a3210';

    private function step(?string $result, ?string $error): WorkflowExecutionStepInterface
    {
        return new class ($result, $error) implements WorkflowExecutionStepInterface {
            public function __construct(private readonly ?string $result, private readonly ?string $error)
            {
            }

            public function getStepExecutionId(): ?int
            {
                return 1;
            }

            public function setStepExecutionId(int $id): self
            {
                return $this;
            }

            public function getExecutionId(): int
            {
                return 1;
            }

            public function setExecutionId(int $executionId): self
            {
                return $this;
            }

            public function getStepKey(): string
            {
                return 'notify';
            }

            public function setStepKey(string $stepKey): self
            {
                return $this;
            }

            public function getStatus(): string
            {
                return 'failed';
            }

            public function setStatus(string $status): self
            {
                return $this;
            }

            public function getResult(): ?string
            {
                return $this->result;
            }

            public function setResult(?string $result): self
            {
                return $this;
            }

            public function getError(): ?string
            {
                return $this->error;
            }

            public function setError(?string $error): self
            {
                return $this;
            }

            public function getStartedAt(): ?string
            {
                return null;
            }

            public function setStartedAt(?string $startedAt): self
            {
                return $this;
            }

            public function getFinishedAt(): ?string
            {
                return null;
            }

            public function setFinishedAt(?string $finishedAt): self
            {
                return $this;
            }

            public function getResumeAt(): ?string
            {
                return null;
            }

            public function setResumeAt(?string $resumeAt): self
            {
                return $this;
            }
        };
    }

    private function block(?WorkflowExecutionInterface $execution): View
    {
        $block = new class ($execution) extends View {
            public function __construct(private readonly ?WorkflowExecutionInterface $stub = null)
            {
            }

            public function getExecution(): ?WorkflowExecutionInterface
            {
                return $this->stub;
            }
        };

        // The REAL redactor and the REAL rules, exercised through the block.
        $property = new \ReflectionProperty(View::class, 'detailRedactor');
        $property->setValue($block, new ExecutionDetailRedactor(
            new SecretsProviderStub(['slack_hook' => self::SENTINEL]),
            new StepDetailRedactor()
        ));

        return $block;
    }

    private function execution(?string $context): WorkflowExecutionStub
    {
        $execution = new WorkflowExecutionStub('uuid-1', 1, 1);
        $execution->setContext($context);

        return $execution;
    }

    public function testStepErrorIsRedacted(): void
    {
        $step = $this->step(null, 'POST https://hooks.test/x?token=' . self::SENTINEL . ' returned 500');

        $rendered = $this->block($this->execution(null))->getStepError($step);

        $this->assertFalse(str_contains($rendered, self::SENTINEL), 'The step error must not print a live token');
        $this->assertStringContainsString('***', $rendered);
        $this->assertStringContainsString('returned 500', $rendered, 'Redaction, not suppression');
    }

    public function testStepResultIsRedacted(): void
    {
        $result = (string) json_encode(['status' => 'success', 'output' => ['note' => 'sent ' . self::SENTINEL]]);

        $rendered = $this->block($this->execution(null))->getStepResult($this->step($result, null));

        $this->assertFalse(str_contains($rendered, self::SENTINEL));
        $this->assertStringContainsString('***slack_hook***', $rendered);
    }

    /**
     * The URL case is the one that used to slip through: stored JSON escapes
     * the solidus, so the blob does not contain the plaintext secret byte for
     * byte.
     */
    public function testContextIsRedactedEvenWhenTheSecretIsAJsonEscapedUrl(): void
    {
        $secretUrl = 'https://hooks.test/services/' . self::SENTINEL;
        $context = (string) json_encode(['steps' => ['notify' => ['url' => $secretUrl]]]);
        $this->assertFalse(str_contains($context, $secretUrl), 'stored JSON escapes the slashes');

        $rendered = $this->block($this->execution($context))->getContextJson();

        $this->assertFalse(str_contains($rendered, self::SENTINEL), 'The context bag must not print a live token');
    }

    public function testEmptyFieldsStayEmptySoTheTemplateCanHideTheirBoxes(): void
    {
        $block = $this->block($this->execution(null));

        $this->assertSame('', $block->getStepError($this->step(null, null)));
        $this->assertSame('', $block->getStepResult($this->step(null, null)));
        $this->assertSame('', $block->getContextJson());
    }

    public function testNoExecutionYieldsAnEmptyContextRatherThanAFatal(): void
    {
        $this->assertSame('', $this->block(null)->getContextJson());
    }

    /**
     * The template must not reach past the block for these three fields — that
     * is how the unredacted read path existed in the first place.
     */
    public function testTheTemplateNeverPrintsTheRawFields(): void
    {
        $path = dirname(__DIR__, 5) . '/view/adminhtml/templates/execution/view.phtml';
        $this->assertTrue(is_file($path), 'Missing execution/view.phtml');
        $template = (string) file_get_contents($path);

        foreach (['$step->getError()', '$step->getResult()', '$execution->getContext()'] as $raw) {
            $this->assertStringNotContainsString(
                $raw,
                $template,
                'The template must read ' . $raw . ' through the block, which redacts it'
            );
        }
        $this->assertStringContainsString('$block->getStepError($step)', $template);
        $this->assertStringContainsString('$block->getStepResult($step)', $template);
        $this->assertStringContainsString('$block->getContextJson()', $template);
    }
}
