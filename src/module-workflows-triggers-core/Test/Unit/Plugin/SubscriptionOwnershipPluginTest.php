<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Plugin;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\WorkflowsTriggersCore\Model\OwnershipBypassRegistry;
use MageOS\WorkflowsTriggersCore\Plugin\SubscriptionOwnershipPlugin;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeAsyncEvent;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\InMemoryAsyncEventRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins docs/10-security.md#subscription-ownership: subscriptions whose
 * recipient carries the workflow:<id> ownership marker are managed
 * exclusively by SubscriptionManager — the admin UI / REST / third-party
 * path (repository save/delete WITHOUT the bypass) is refused, on both the
 * incoming recipient and the persisted one, so an owned subscription can
 * neither be edited in place, re-pointed away from its workflow, nor can a
 * foreign subscription be re-pointed INTO the workflow namespace.
 */
class SubscriptionOwnershipPluginTest extends TestCase
{
    private OwnershipBypassRegistry $registry;

    private SubscriptionOwnershipPlugin $plugin;

    private InMemoryAsyncEventRepository $repository;

    private bool $proceedCalled = false;

    /** @var array */
    private array $proceedArgs = [];

    public function setUp(): void
    {
        $this->registry = new OwnershipBypassRegistry();
        $this->plugin = new SubscriptionOwnershipPlugin($this->registry);
        $this->repository = new InMemoryAsyncEventRepository();
        $this->proceedCalled = false;
        $this->proceedArgs = [];
    }

    private function proceed(): callable
    {
        return function (...$args) {
            $this->proceedCalled = true;
            $this->proceedArgs = $args;
            return $args[0] ?? null;
        };
    }

    private function subscription(string $recipient, int $id = 0): FakeAsyncEvent
    {
        $subscription = new FakeAsyncEvent();
        $subscription->setSubscriptionId($id);
        $subscription->setRecipientUrl($recipient);
        return $subscription;
    }

    public function testRefusesSaveOfWorkflowOwnedRecipient(): void
    {
        $refused = null;
        try {
            $this->plugin->aroundSave($this->repository, $this->proceed(), $this->subscription('workflow:7'));
        } catch (LocalizedException $exception) {
            $refused = $exception;
        }

        $this->assertNotNull($refused);
        $this->assertStringContainsString('managed by workflow #7', $refused->getMessage());
        $this->assertFalse($this->proceedCalled, 'refused save must never reach the repository');
    }

    public function testRefusesRepointingOwnedPersistedRowAway(): void
    {
        $persisted = $this->subscription('workflow:9');
        $this->repository->seed($persisted);
        // Incoming update carries a harmless-looking recipient but targets the owned row.
        $incoming = $this->subscription('https://example.com/hook', $persisted->getSubscriptionId());

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('managed by workflow #9');

        $this->plugin->aroundSave($this->repository, $this->proceed(), $incoming);
    }

    public function testRefusesRepointingForeignRowIntoWorkflowNamespace(): void
    {
        $persisted = $this->subscription('https://example.com/hook');
        $this->repository->seed($persisted);
        $incoming = $this->subscription('workflow:4', $persisted->getSubscriptionId());

        $this->expectException(LocalizedException::class);

        $this->plugin->aroundSave($this->repository, $this->proceed(), $incoming);
    }

    public function testAllowsSaveOfNonOwnedSubscription(): void
    {
        $incoming = $this->subscription('https://example.com/hook');

        $result = $this->plugin->aroundSave($this->repository, $this->proceed(), $incoming, false);

        $this->assertTrue($this->proceedCalled);
        $this->assertSame($incoming, $result);
        $this->assertSame($incoming, $this->proceedArgs[0]);
        $this->assertFalse($this->proceedArgs[1], 'trailing $checkResources argument must be forwarded');
    }

    public function testAllowsOwnedSaveUnderBypass(): void
    {
        $incoming = $this->subscription('workflow:7');

        $this->registry->bypass(
            fn () => $this->plugin->aroundSave($this->repository, $this->proceed(), $incoming, false)
        );

        $this->assertTrue($this->proceedCalled, 'SubscriptionManager writes pass through under bypass');
    }

    public function testRefusesDeleteOfOwnedEntity(): void
    {
        $refused = false;
        try {
            $this->plugin->aroundDelete($this->repository, $this->proceed(), $this->subscription('workflow:4'));
        } catch (LocalizedException $exception) {
            $refused = true;
        }

        $this->assertTrue($refused);
        $this->assertFalse($this->proceedCalled, 'refused delete must never reach the repository');
    }

    public function testRefusesDeleteByIdOfOwnedPersistedRow(): void
    {
        $persisted = $this->subscription('workflow:6');
        $this->repository->seed($persisted);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('managed by workflow #6');

        $this->plugin->aroundDelete($this->repository, $this->proceed(), $persisted->getSubscriptionId());
    }

    public function testAllowsDeleteOfNonOwnedEntity(): void
    {
        $entity = $this->subscription('https://example.com/hook');

        $this->plugin->aroundDelete($this->repository, $this->proceed(), $entity);

        $this->assertTrue($this->proceedCalled);
    }

    public function testAllowsDeleteOfUnknownIdNothingToProtect(): void
    {
        $this->plugin->aroundDelete($this->repository, $this->proceed(), 999);

        $this->assertTrue($this->proceedCalled);
    }

    public function testAllowsOwnedDeleteUnderBypass(): void
    {
        $entity = $this->subscription('workflow:4');

        $this->registry->bypass(
            fn () => $this->plugin->aroundDelete($this->repository, $this->proceed(), $entity)
        );

        $this->assertTrue($this->proceedCalled);
    }

    public function testNewSubscriptionWithoutIdSkipsPersistedLookup(): void
    {
        // No row with id 0 exists; the plugin must not treat "no id yet" as a lookup.
        $incoming = $this->subscription('https://example.com/hook', 0);

        $result = $this->plugin->aroundSave($this->repository, $this->proceed(), $incoming);

        $this->assertTrue($this->proceedCalled);
        $this->assertInstanceOf(AsyncEventInterface::class, $result);
    }
}
