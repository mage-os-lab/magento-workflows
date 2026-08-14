<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Notify;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsActionsCore\Action\Notify\Email;
use MageOS\Workflows\Model\Idempotency\SendClaimStoreInterface;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #19 (docs/20-integration-test-plan.md §5) — notify.email captured via
 * the integration framework's TransportBuilderMock: template resolution,
 * recipient resolution, and the send-once guard whose claim persists so a
 * redelivery does NOT resend.
 *
 * The guard is now a DURABLE claim row (mageos_workflow_send_log,
 * UNIQUE(claim_key)) rather than a cache check-and-set, so the old
 * `known-divergence` pin — which proved a second send by deleting the cache
 * marker between two executes, exactly as a racing consumer would have seen it
 * — has been replaced by two tests that pass: flushing the cache no longer
 * un-guards a send, and a claim left behind by a crashed attempt suppresses
 * the redelivery (reporting honestly that the message may never have gone out).
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 * @magentoAppArea frontend
 */
class EmailTest extends ActionTestCase
{
    private const RECIPIENT = 'recipient@example.com';
    private const SUBJECT = 'Integration Ad-hoc Subject';

    private Email $action;

    protected function setUp(): void
    {
        $this->action = $this->resolve(Email::class);
    }

    public function testAdhocEmailIsSentAndCaptured(): void
    {
        // Distinct UUID per sending test. The claim is now a DB row, so
        // @magentoDbIsolation does roll it back between tests — but the UUIDs
        // stay distinct anyway: they document which execution each claim
        // belongs to, and they keep these tests correct if the suite is ever
        // run without isolation.
        $ctx = $this->buildContext(1, 1, 's1', 'e1111111-1111-1111-1111-111111111111');
        $result = $this->action->execute($ctx, [
            'to' => self::RECIPIENT,
            'subject' => self::SUBJECT,
            'body' => "Line one\nLine two",
        ]);

        $this->assertTrue($result->isSuccess(), $result->getError() ?? '');
        $this->assertSame('adhoc', $result->getOutput()['mode']);

        $message = $this->sentMessage();
        $this->assertNotNull($message, 'TransportBuilderMock must have captured a message');
        $this->assertSame(self::SUBJECT, $message->getSubject(), 'Ad-hoc template must render {{var subject}}');
        $this->assertContains(self::RECIPIENT, $this->recipientEmails($message));
    }

    public function testSendOnceGuardSuppressesRedelivery(): void
    {
        $ctx = $this->buildContext(1, 1, 's1', 'e2222222-2222-2222-2222-222222222222');
        $config = ['to' => self::RECIPIENT, 'subject' => self::SUBJECT, 'body' => 'once'];

        $first = $this->action->execute($ctx, $config);
        // Same context => same dedupe key => a redelivered message.
        $second = $this->action->execute($ctx, $config);

        $this->assertTrue($first->isSuccess());
        $this->assertSame(
            ActionResultInterface::STATUS_SKIPPED,
            $second->getStatus(),
            'Send-once guard must suppress the redelivery'
        );
    }

    public function testTemplateAndAdhocAreMutuallyExclusive(): void
    {
        $ctx = $this->buildContext(1);
        $result = $this->action->execute($ctx, [
            'to' => self::RECIPIENT,
            'template_id' => 'some_template',
            'subject' => self::SUBJECT,
            'body' => 'x',
        ]);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    public function testInvalidRecipientIsTerminalFailure(): void
    {
        $ctx = $this->buildContext(1);
        $result = $this->action->execute($ctx, [
            'to' => 'not-an-email',
            'subject' => self::SUBJECT,
            'body' => 'x',
        ]);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    /**
     * The guard survives what erased its predecessor. A cache flush (or a
     * Redis restart, or LRU eviction) used to drop the send-once marker, after
     * which every parked redelivery re-sent; the claim row is untouched by it.
     */
    public function testACacheFlushDoesNotUnguardASend(): void
    {
        $ctx = $this->buildContext(1, 1, 's1', 'e3333333-3333-3333-3333-333333333333');
        $config = ['to' => self::RECIPIENT, 'subject' => self::SUBJECT, 'body' => 'flush'];

        $first = $this->action->execute($ctx, $config);
        $this->assertTrue($first->isSuccess());

        // Everything a cache-backed guard would have lost.
        $this->resolve(CacheInterface::class)->clean();

        $second = $this->action->execute($ctx, $config);
        $this->assertSame(
            ActionResultInterface::STATUS_SKIPPED,
            $second->getStatus(),
            'the durable claim, unlike the cache marker it replaced, survives a cache flush'
        );
    }

    /**
     * The crash window inside a step: an earlier attempt claimed the send and
     * died before confirming it. The redelivery must not send, and must not
     * claim the message arrived — it does not know that.
     */
    public function testAClaimLeftByACrashedAttemptSuppressesTheRedeliveryHonestly(): void
    {
        $ctx = $this->buildContext(1, 1, 's1', 'e4444444-4444-4444-4444-444444444444');
        $this->resolve(SendClaimStoreInterface::class)->claim('notify.email', $ctx->getDedupeKey('s1'));

        $result = $this->action->execute($ctx, [
            'to' => self::RECIPIENT,
            'subject' => self::SUBJECT,
            'body' => 'crash window',
        ]);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $reason = (string)($result->getOutput()['reason'] ?? '');
        $this->assertStringContainsString('never confirmed', $reason);
        $this->assertStringContainsString('may never have gone out', $reason);
    }

    /**
     * The claim really is a row in mageos_workflow_send_log — the property
     * that makes it cluster-wide and flush-proof — and it carries no message
     * content, only the scope + dedupe key (docs/10 PII posture).
     */
    public function testTheClaimIsARowInTheSendLogTableCarryingNoPii(): void
    {
        $ctx = $this->buildContext(1, 1, 's1', 'e5555555-5555-5555-5555-555555555555');
        $this->action->execute($ctx, [
            'to' => self::RECIPIENT,
            'subject' => self::SUBJECT,
            'body' => 'durable',
        ]);

        $resource = $this->resolve(ResourceConnection::class);
        $connection = $resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName('mageos_workflow_send_log'))
                ->where('claim_key = ?', 'notify.email:' . $ctx->getDedupeKey('s1'))
        );

        $this->assertNotEmpty($row, 'the send must leave a durable claim row');
        $this->assertSame('notify.email', $row['scope']);
        $this->assertSame(SendClaimStoreInterface::STATUS_SENT, $row['status']);
        $this->assertFalse(
            in_array(self::RECIPIENT, array_map('strval', array_values($row)), true),
            'the claim ledger stores no recipient'
        );
    }

    private function sentMessage(): ?object
    {
        /** @var TransportBuilderMock $mock */
        $mock = Bootstrap::getObjectManager()->get(TransportBuilderMock::class);
        return $mock->getSentMessage();
    }

    /**
     * @return string[]
     */
    private function recipientEmails(object $message): array
    {
        if (!method_exists($message, 'getTo')) {
            return [];
        }
        $emails = [];
        foreach ((array)$message->getTo() as $address) {
            if (is_object($address) && method_exists($address, 'getEmail')) {
                $emails[] = (string)$address->getEmail();
            } elseif (is_string($address)) {
                $emails[] = $address;
            }
        }
        return $emails;
    }
}
