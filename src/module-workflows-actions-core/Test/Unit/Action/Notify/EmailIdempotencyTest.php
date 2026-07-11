<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\TransportBuilder;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsActionsCore\Action\Notify\Email;
use PHPUnit\Framework\TestCase;

/**
 * Behavior tests for the notify.email idempotency guard (docs/08-execution-model.md
 * crash safety: non-idempotent actions check a per-step dedupe key — "email
 * send logs the key before SMTP") and the ad-hoc body HTML escaping
 * (docs/07-actions.md: interpolated values in the ad-hoc body are HTML-escaped).
 *
 * Mode / recipient validation is covered by EmailTest; nothing here duplicates it.
 */
class EmailIdempotencyTest extends TestCase
{
    /**
     * @param \ArrayObject $events shared chronological event log
     */
    private function recordingCache(\ArrayObject $events): CacheInterface
    {
        return new class($events) implements CacheInterface {
            /** @var array<string, string> */
            public array $store = [];
            public function __construct(public \ArrayObject $events)
            {
            }
            public function load($identifier)
            {
                $this->events[] = 'guard_checked';
                return $this->store[$identifier] ?? false;
            }
            public function save($data, $identifier, $tags = [], $lifeTime = null)
            {
                $this->events[] = 'guard_saved';
                $this->store[$identifier] = (string)$data;
                return true;
            }
            public function remove($identifier)
            {
                $this->events[] = 'guard_released';
                unset($this->store[$identifier]);
                return true;
            }
            public function getFrontend()
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function clean($tags = [])
            {
                return true;
            }
        };
    }

    private function recordingTransportBuilder(\ArrayObject $events): TransportBuilder
    {
        return new class($events) extends TransportBuilder {
            /** @var array captured template vars of the LAST build */
            public array $vars = [];
            public ?string $templateId = null;
            public int $sendAttempts = 0;
            public ?\Throwable $throwOnSend = null;
            // Bypass the real TransportBuilder DI constructor
            public function __construct(public \ArrayObject $events)
            {
            }
            public function setTemplateIdentifier($id): self
            {
                $this->templateId = (string)$id;
                return $this;
            }
            public function setTemplateOptions($options): self
            {
                return $this;
            }
            public function setTemplateVars($vars): self
            {
                $this->vars = $vars;
                return $this;
            }
            public function setFromByScope($scope, $storeId = null): self
            {
                return $this;
            }
            public function addTo($email, $name = ''): self
            {
                return $this;
            }
            public function getTransport()
            {
                $owner = $this;
                return new class($owner) {
                    public function __construct(private object $owner)
                    {
                    }
                    public function sendMessage(): void
                    {
                        $this->owner->sendAttempts++;
                        $this->owner->events[] = 'smtp_send';
                        if ($this->owner->throwOnSend !== null) {
                            throw $this->owner->throwOnSend;
                        }
                    }
                };
            }
        };
    }

    private function ctx(string $uuid = 'exec-uuid-1', ?string $stepKey = null): ExecutionContext
    {
        $execution = new WorkflowExecutionStub(uuid: $uuid);
        if ($stepKey !== null) {
            $execution->setCurrentStep($stepKey);
        }
        return new ExecutionContext($execution);
    }

    private function templateConfig(): array
    {
        return ['template_id' => 'order_update', 'to' => 'customer@example.com'];
    }

    // -- docs/08: the dedupe key is checked AND set before SMTP ---------------

    public function testGuardIsCheckedAndSetBeforeSmtpSend(): void
    {
        $events = new \ArrayObject();
        $action = new Email($this->recordingTransportBuilder($events), $this->recordingCache($events));

        $result = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($result->isSuccess());
        // Crash-safety ordering: check, set, ONLY THEN the side effect
        $this->assertSame(['guard_checked', 'guard_saved', 'smtp_send'], $events->getArrayCopy());
    }

    public function testRedeliveryWithSameExecutionAndStepDoesNotSendAgain(): void
    {
        $events = new \ArrayObject();
        $builder = $this->recordingTransportBuilder($events);
        $action = new Email($builder, $this->recordingCache($events));

        $first = $action->execute($this->ctx('exec-uuid-1', 'notify_customer'), $this->templateConfig());
        $second = $action->execute($this->ctx('exec-uuid-1', 'notify_customer'), $this->templateConfig());

        $this->assertTrue($first->isSuccess());
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
        $this->assertStringContainsString('Duplicate', (string)($second->getOutput()['reason'] ?? ''));
        $this->assertSame(1, $builder->sendAttempts, 'a redelivered step must NOT double-mail the customer');
    }

    public function testDifferentExecutionUuidOrStepKeyIsNotDeduped(): void
    {
        $events = new \ArrayObject();
        $builder = $this->recordingTransportBuilder($events);
        $action = new Email($builder, $this->recordingCache($events));

        $action->execute($this->ctx('exec-uuid-1', 'notify_customer'), $this->templateConfig());
        // Same step key, different execution: a new workflow run must send
        $action->execute($this->ctx('exec-uuid-2', 'notify_customer'), $this->templateConfig());
        // Same execution, different step key: another email step must send
        $action->execute($this->ctx('exec-uuid-1', 'notify_warehouse'), $this->templateConfig());

        $this->assertSame(3, $builder->sendAttempts, 'dedupe key must be execution UUID + step key');
    }

    // -- transient MailException releases the guard so redelivery CAN resend --

    public function testMailExceptionIsRetryableAndReleasesTheGuard(): void
    {
        $events = new \ArrayObject();
        $builder = $this->recordingTransportBuilder($events);
        $builder->throwOnSend = new MailException(__('SMTP connection lost'));
        $action = new Email($builder, $this->recordingCache($events));

        $result = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'transient transport failure must be retryable');
        $this->assertSame(
            ['guard_checked', 'guard_saved', 'smtp_send', 'guard_released'],
            $events->getArrayCopy(),
            'the guard must be released after a transient failure'
        );

        // ... so the queue redelivery actually resends:
        $builder->throwOnSend = null;
        $retry = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($retry->isSuccess());
        $this->assertSame(2, $builder->sendAttempts, 'redelivery after MailException must be able to resend');
    }

    public function testNonTransientExceptionIsTerminalAndKeepsTheGuard(): void
    {
        $events = new \ArrayObject();
        $builder = $this->recordingTransportBuilder($events);
        $builder->throwOnSend = new \RuntimeException('template rendering blew up');
        $action = new Email($builder, $this->recordingCache($events));

        $result = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'config/template errors will not resolve on redelivery');
        $this->assertFalse(
            in_array('guard_released', $events->getArrayCopy(), true),
            'a terminal failure must not release the guard'
        );

        // Guard still held: an (unexpected) redelivery may not attempt SMTP again
        $builder->throwOnSend = null;
        $again = $action->execute($this->ctx(), $this->templateConfig());
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $again->getStatus());
        $this->assertSame(1, $builder->sendAttempts);
    }

    public function testGuardAlsoCoversAdhocMode(): void
    {
        $events = new \ArrayObject();
        $builder = $this->recordingTransportBuilder($events);
        $action = new Email($builder, $this->recordingCache($events));
        $config = ['to' => 'customer@example.com', 'subject' => 'Hi', 'body' => 'Hello'];

        $first = $action->execute($this->ctx(), $config);
        $second = $action->execute($this->ctx(), $config);

        $this->assertTrue($first->isSuccess());
        $this->assertSame('adhoc', $first->getOutput()['mode']);
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
        $this->assertSame(1, $builder->sendAttempts);
    }

    // -- docs/07: ad-hoc body is HTML-escaped in PHP before the |raw template --

    public function testAdhocBodyIsHtmlEscapedBeforeReachingTheRawTemplateVar(): void
    {
        $events = new \ArrayObject();
        $builder = $this->recordingTransportBuilder($events);
        $action = new Email($builder, $this->recordingCache($events));

        $result = $action->execute($this->ctx(), [
            'to' => 'customer@example.com',
            'subject' => 'Order update',
            'body' => "<script>alert('x')</script>\nTom & Jerry <b>bold</b>",
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('mageos_workflows_adhoc', $builder->templateId);

        $body = (string)$builder->vars['body'];
        $this->assertStringNotContainsString('<script>', $body, 'config-borne markup must never reach |raw live');
        $this->assertStringNotContainsString('<b>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringContainsString('&amp;', $body);
        $this->assertStringContainsString('<br />', $body, 'line breaks are preserved as <br />');
        $this->assertSame('Order update', $builder->vars['subject']);
    }
}
