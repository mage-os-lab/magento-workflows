<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Idempotency;

use MageOS\Workflows\Model\Idempotency\SendClaimStoreInterface;
use MageOS\Workflows\Model\Idempotency\SendOnceGuard;
use MageOS\Workflows\Test\Unit\Stub\FakeSendClaimStore;
use PHPUnit\Framework\TestCase;

/**
 * The shared policy every unrecallable-send action applies (notify.email,
 * order.send_email): claim answers propagate, an existing claim is DESCRIBED
 * honestly (sent vs claimed-but-unconfirmed), and the bookkeeping calls are
 * best-effort so a store hiccup can never turn a delivered message into a
 * failure — or, worse, into a released claim that lets the next delivery send
 * a second copy.
 */
class SendOnceGuardTest extends TestCase
{
    private const SCOPE = 'notify.email';
    private const KEY = 'uuid:step';

    public function testClaimDelegatesTheAnswer(): void
    {
        $store = new FakeSendClaimStore();
        $guard = new SendOnceGuard($store);

        $this->assertTrue($guard->claim(self::SCOPE, self::KEY));
        $this->assertFalse($guard->claim(self::SCOPE, self::KEY));
    }

    public function testAnUnanswerableClaimPropagatesSoTheCallerCanPark(): void
    {
        $store = new FakeSendClaimStore();
        $store->failClaim = new \RuntimeException('server has gone away');
        $guard = new SendOnceGuard($store);

        $this->expectException(\RuntimeException::class);
        $guard->claim(self::SCOPE, self::KEY);
    }

    public function testAConfirmedClaimIsDescribedAsAnActualSend(): void
    {
        $store = new FakeSendClaimStore();
        $store->seedClaim(self::SCOPE, self::KEY, SendClaimStoreInterface::STATUS_SENT);

        $reason = (new SendOnceGuard($store))->describeClaim(self::SCOPE, self::KEY);

        $this->assertStringContainsString('already sent', $reason);
        $this->assertStringContainsString('2026-01-01 00:00:01 UTC', $reason);
    }

    public function testAnUnconfirmedClaimSaysTheMessageMayNeverHaveGoneOut(): void
    {
        // The crash window is the whole point of this wording: claiming is not
        // the same as sending, and the operator has to know which one they got.
        $store = new FakeSendClaimStore();
        $store->seedClaim(self::SCOPE, self::KEY);

        $reason = (new SendOnceGuard($store))->describeClaim(self::SCOPE, self::KEY);

        $this->assertStringContainsString('never confirmed', $reason);
        $this->assertStringContainsString('may never have gone out', $reason);
        $this->assertStringNotContainsString('already sent', $reason);
    }

    public function testAVanishedClaimFallsBackToNeutralWording(): void
    {
        $reason = (new SendOnceGuard(new FakeSendClaimStore()))->describeClaim(self::SCOPE, self::KEY);

        $this->assertStringContainsString('already claimed', $reason);
    }

    public function testAFailingLookupStillProducesAReason(): void
    {
        $store = new FakeSendClaimStore();
        $store->failFind = new \RuntimeException('read timeout');

        $reason = (new SendOnceGuard($store))->describeClaim(self::SCOPE, self::KEY);

        $this->assertStringContainsString('Duplicate delivery suppressed', $reason);
    }

    public function testReleaseAndConfirmAreBestEffort(): void
    {
        // A store failure here must not escape: the caller is either already
        // returning a failure (release) or has already sent (confirm).
        $store = new FakeSendClaimStore();
        $store->failRelease = new \RuntimeException('deadlock');
        $store->failConfirm = new \RuntimeException('deadlock');
        $guard = new SendOnceGuard($store);

        $guard->release(self::SCOPE, self::KEY);
        $guard->confirm(self::SCOPE, self::KEY);

        $this->assertTrue(true, 'neither call may throw');
    }

    public function testReleaseMakesTheKeyClaimableAgain(): void
    {
        $store = new FakeSendClaimStore();
        $guard = new SendOnceGuard($store);
        $guard->claim(self::SCOPE, self::KEY);

        $guard->release(self::SCOPE, self::KEY);

        $this->assertTrue($guard->claim(self::SCOPE, self::KEY));
    }
}
