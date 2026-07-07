<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Notify;

use Magento\Framework\App\CacheInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsActionsCore\Action\Notify\Email;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #19 (docs/20-integration-test-plan.md §5) — notify.email captured via
 * the integration framework's TransportBuilderMock: template resolution,
 * recipient resolution, and the send-once guard whose marker persists so a
 * redelivery does NOT resend.
 *
 * The two-connection race for the non-atomic check-and-set (Email.php:106-109,
 * docs/19 risk note) is pinned separately as @group known-divergence: the
 * guard is a plain cache check-then-set, so removing the marker between two
 * sequential executes lets the second send — demonstrating that two truly
 * concurrent consumers could both send.
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
        // Distinct UUID per sending test: the send-once guard is a cache marker
        // keyed on the execution dedupe key (uuid:step), and the cache is NOT
        // reset by @magentoDbIsolation. Sharing the default UUID lets whichever
        // send-test runs first leave a marker that suppresses the FIRST send of
        // the others.
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
     * The send-once guard is a non-atomic cache check-and-set: it protects
     * sequential redelivery but not two concurrent consumers. Proven here by
     * clearing the marker between two sequential executes — the second one
     * sends again, exactly as a racing consumer that read the cache before the
     * first wrote it would. Quarantined (docs/19 risk note, Email.php:106-109).
     *
     * @group known-divergence
     */
    public function testConcurrentSendersCanBothSendKnownDivergence(): void
    {
        $ctx = $this->buildContext(1, 1, 's1', 'e3333333-3333-3333-3333-333333333333');
        $config = ['to' => self::RECIPIENT, 'subject' => self::SUBJECT, 'body' => 'race'];

        $first = $this->action->execute($ctx, $config);
        $this->assertTrue($first->isSuccess());

        // Simulate the racing consumer: the guard key is not yet visible to it.
        $guardKey = 'mageos_workflows_email_sent_' . sha1($ctx->getDedupeKey('s1'));
        $this->resolve(CacheInterface::class)->remove($guardKey);

        $second = $this->action->execute($ctx, $config);
        $this->assertTrue(
            $second->isSuccess(),
            'With the marker cleared (as a concurrent consumer would see it), the second send goes through — '
            . 'the check-and-set is not atomic'
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
