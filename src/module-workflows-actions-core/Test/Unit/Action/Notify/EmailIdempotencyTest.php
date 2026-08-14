<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify;

use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\TransportBuilder;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Idempotency\SendClaimStoreInterface;
use MageOS\Workflows\Model\Idempotency\SendOnceGuard;
use MageOS\Workflows\Test\Unit\Stub\FakeSendClaimStore;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsActionsCore\Action\Notify\Email;
use PHPUnit\Framework\TestCase;

/**
 * Behavior tests for the notify.email send-once guard (docs/08-execution-model.md
 * crash safety: non-idempotent actions claim a per-step dedupe key before the
 * side effect) and the ad-hoc body HTML escaping (docs/07-actions.md:
 * interpolated values in the ad-hoc body are HTML-escaped).
 *
 * The guard is a DURABLE claim (mageos_workflow_send_log, UNIQUE(claim_key))
 * rather than the cache check-and-set it replaced, so these tests pin the
 * semantics that change with it:
 *  - the claim is taken BEFORE the transport and confirmed after;
 *  - a claim held by a crashed earlier attempt suppresses the redelivery and
 *    says honestly that the message may never have gone out;
 *  - only a failure that provably precedes the send releases the claim; a
 *    failure from the send call itself keeps it and is terminal.
 *
 * Mode / recipient validation is covered by EmailTest; nothing here duplicates it.
 */
class EmailIdempotencyTest extends TestCase
{
    private const SCOPE = 'notify.email';

    private function guard(FakeSendClaimStore $store): SendOnceGuard
    {
        return new SendOnceGuard($store);
    }

    /**
     * Transport double that logs into the claim store's event list, so one
     * chronological log covers both the guard and the side effect.
     */
    private function recordingTransportBuilder(FakeSendClaimStore $store): TransportBuilder
    {
        return new class($store) extends TransportBuilder {
            /** @var array captured template vars of the LAST build */
            public array $vars = [];
            public ?string $templateId = null;
            public int $sendAttempts = 0;
            public ?\Throwable $throwOnBuild = null;
            public ?\Throwable $throwOnSend = null;
            // Bypass the real TransportBuilder DI constructor
            public function __construct(public FakeSendClaimStore $store)
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
                if ($this->throwOnBuild !== null) {
                    throw $this->throwOnBuild;
                }
                $owner = $this;
                return new class($owner) {
                    public function __construct(private object $owner)
                    {
                    }
                    public function sendMessage(): void
                    {
                        $this->owner->sendAttempts++;
                        $this->owner->store->events[] = 'smtp_send';
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

    /**
     * The key the action itself will use: execution UUID + step key, with the
     * action code standing in for an unset current step (AbstractAction::stepKey).
     */
    private function dedupeKey(string $uuid = 'exec-uuid-1', ?string $stepKey = null): string
    {
        return $this->ctx($uuid, $stepKey)->getDedupeKey($stepKey ?? self::SCOPE);
    }

    private function templateConfig(): array
    {
        return ['template_id' => 'order_update', 'to' => 'customer@example.com'];
    }

    // -- docs/08: the dedupe key is claimed before SMTP, confirmed after -----

    public function testClaimIsTakenBeforeSmtpSendAndConfirmedAfterwards(): void
    {
        $store = new FakeSendClaimStore();
        $action = new Email($this->recordingTransportBuilder($store), $this->guard($store));

        $result = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($result->isSuccess());
        // Crash-safety ordering: claim, side effect, ONLY THEN confirm.
        $this->assertSame(['claimed', 'smtp_send', 'confirmed'], $store->events);
        $this->assertSame(SendClaimStoreInterface::STATUS_SENT, $store->statusOf(self::SCOPE, $this->dedupeKey()));
    }

    public function testRedeliveryWithSameExecutionAndStepDoesNotSendAgain(): void
    {
        $store = new FakeSendClaimStore();
        $builder = $this->recordingTransportBuilder($store);
        $action = new Email($builder, $this->guard($store));

        $first = $action->execute($this->ctx('exec-uuid-1', 'notify_customer'), $this->templateConfig());
        $second = $action->execute($this->ctx('exec-uuid-1', 'notify_customer'), $this->templateConfig());

        $this->assertTrue($first->isSuccess());
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
        $this->assertStringContainsString('Duplicate', (string)($second->getOutput()['reason'] ?? ''));
        $this->assertSame(1, $builder->sendAttempts, 'a redelivered step must NOT double-mail the customer');
    }

    public function testDifferentExecutionUuidOrStepKeyIsNotDeduped(): void
    {
        $store = new FakeSendClaimStore();
        $builder = $this->recordingTransportBuilder($store);
        $action = new Email($builder, $this->guard($store));

        $action->execute($this->ctx('exec-uuid-1', 'notify_customer'), $this->templateConfig());
        // Same step key, different execution: a new workflow run must send
        $action->execute($this->ctx('exec-uuid-2', 'notify_customer'), $this->templateConfig());
        // Same execution, different step key: another email step must send
        $action->execute($this->ctx('exec-uuid-1', 'notify_warehouse'), $this->templateConfig());

        $this->assertSame(3, $builder->sendAttempts, 'dedupe key must be execution UUID + step key');
    }

    // -- the crash window: claimed, never confirmed --------------------------

    public function testAClaimLeftByACrashedAttemptSuppressesTheRedeliveryHonestly(): void
    {
        // Delivery #1 claimed, handed the mail to the transport, and the
        // process died before the step row (or the confirmation) landed.
        $store = new FakeSendClaimStore();
        $store->seedClaim(self::SCOPE, $this->dedupeKey('exec-uuid-1', 'notify_customer'));
        $builder = $this->recordingTransportBuilder($store);
        $action = new Email($builder, $this->guard($store));

        $result = $action->execute($this->ctx('exec-uuid-1', 'notify_customer'), $this->templateConfig());

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(0, $builder->sendAttempts);
        $reason = (string)($result->getOutput()['reason'] ?? '');
        $this->assertStringContainsString('never confirmed', $reason);
        $this->assertStringContainsString('may never have gone out', $reason);
    }

    public function testAConfirmedClaimReportsAnActualSend(): void
    {
        $store = new FakeSendClaimStore();
        $store->seedClaim(
            self::SCOPE,
            $this->dedupeKey('exec-uuid-1', 'notify_customer'),
            SendClaimStoreInterface::STATUS_SENT
        );
        $builder = $this->recordingTransportBuilder($store);
        $action = new Email($builder, $this->guard($store));

        $result = $action->execute($this->ctx('exec-uuid-1', 'notify_customer'), $this->templateConfig());

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertStringContainsString('already sent', (string)($result->getOutput()['reason'] ?? ''));
        $this->assertSame(0, $builder->sendAttempts);
    }

    // -- release only for failures that provably precede the send ------------

    public function testTransportBuildFailureReleasesTheClaimSoARetryCanSend(): void
    {
        $store = new FakeSendClaimStore();
        $builder = $this->recordingTransportBuilder($store);
        $builder->throwOnBuild = new MailException(__('Template could not be loaded'));
        $action = new Email($builder, $this->guard($store));

        $result = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'nothing reached a mail server, so a retry is safe');
        $this->assertSame(['claimed', 'released'], $store->events);
        $this->assertNull($store->statusOf(self::SCOPE, $this->dedupeKey()));

        // ... so the queue redelivery actually sends:
        $builder->throwOnBuild = null;
        $retry = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($retry->isSuccess());
        $this->assertSame(1, $builder->sendAttempts);
    }

    public function testAFailureFromTheSendItselfKeepsTheClaimAndIsTerminal(): void
    {
        // Magento funnels every transport error into one MailException, so
        // "refused before DATA" and "accepted, then the link dropped" are
        // indistinguishable — the claim stands rather than risking a second copy.
        $store = new FakeSendClaimStore();
        $builder = $this->recordingTransportBuilder($store);
        $builder->throwOnSend = new MailException(__('SMTP connection lost'));
        $action = new Email($builder, $this->guard($store));

        $result = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'an unconfirmable send must not be retried automatically');
        $this->assertStringContainsString('will NOT be retried', (string)$result->getError());
        $this->assertFalse(
            in_array('released', $store->events, true),
            'a claim may not be released once the transport call was entered'
        );
        $this->assertSame(
            SendClaimStoreInterface::STATUS_CLAIMED,
            $store->statusOf(self::SCOPE, $this->dedupeKey()),
            'the claim is held, unconfirmed'
        );

        // An (unexpected) redelivery skips instead of mailing a second copy.
        $builder->throwOnSend = null;
        $again = $action->execute($this->ctx(), $this->templateConfig());
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $again->getStatus());
        $this->assertSame(1, $builder->sendAttempts);
    }

    public function testAnUnanswerableClaimParksTheStepInsteadOfSendingUnguarded(): void
    {
        $store = new FakeSendClaimStore();
        $store->failClaim = new \RuntimeException('SQLSTATE[HY000]: server has gone away');
        $builder = $this->recordingTransportBuilder($store);
        $action = new Email($builder, $this->guard($store));

        $result = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('Could not claim', (string)$result->getError());
        $this->assertSame(0, $builder->sendAttempts, 'no send may happen under an unanswered guard');
    }

    public function testAFailingConfirmationDoesNotSpoilASuccessfulSend(): void
    {
        // confirm() is bookkeeping: the mail is already gone, so a store hiccup
        // there must not turn a delivered email into a step failure.
        $store = new FakeSendClaimStore();
        $store->failConfirm = new \RuntimeException('deadlock on update');
        $builder = $this->recordingTransportBuilder($store);
        $action = new Email($builder, $this->guard($store));

        $result = $action->execute($this->ctx(), $this->templateConfig());

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $builder->sendAttempts);
    }

    public function testTheGuardDependencyIsRequiredSoItCannotArriveNull(): void
    {
        // Magento's ObjectManager does not auto-inject a parameter that has a
        // default value, so an "optional" guard would be no guard at all in
        // production. This pins it as required.
        $parameters = (new \ReflectionClass(Email::class))->getConstructor()->getParameters();
        $guard = $parameters[1];

        $this->assertSame('sendOnceGuard', $guard->getName());
        $this->assertSame(SendOnceGuard::class, (string)$guard->getType());
        $this->assertFalse($guard->isDefaultValueAvailable());
        $this->assertFalse($guard->allowsNull());
    }

    public function testGuardAlsoCoversAdhocMode(): void
    {
        $store = new FakeSendClaimStore();
        $builder = $this->recordingTransportBuilder($store);
        $action = new Email($builder, $this->guard($store));
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
        $store = new FakeSendClaimStore();
        $builder = $this->recordingTransportBuilder($store);
        $action = new Email($builder, $this->guard($store));

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
