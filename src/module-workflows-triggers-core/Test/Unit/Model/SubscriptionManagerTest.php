<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use MageOS\WorkflowsTriggersCore\Model\OwnershipBypassRegistry;
use MageOS\WorkflowsTriggersCore\Model\SubscriptionManager;
use MageOS\WorkflowsTriggersCore\Model\WorkflowNotifier;
use MageOS\WorkflowsTriggersCore\Plugin\SubscriptionOwnershipPlugin;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeAsyncEvent;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeAsyncEventFactory;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeSearchCriteriaBuilder;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\GuardedAsyncEventRepository;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\InMemoryAsyncEventRepository;
use PHPUnit\Framework\TestCase;

/**
 * Pins docs/05-triggers.md's subscription lifecycle contract: enabling an
 * event-triggered workflow programmatically creates (or reactivates) exactly
 * one hidden subscription — event_name = trigger ref, metadata = "workflow",
 * recipient = "workflow:<id>" ownership marker — and disabling deactivates
 * it. Wait steps get one subscription per waited-on event, reconciled at
 * save time and independent of the workflow's own trigger type. All writes
 * run under the ownership bypass so the manager is not blocked by the very
 * plugin that protects its rows from everyone else.
 */
class SubscriptionManagerTest extends TestCase
{
    private InMemoryAsyncEventRepository $repository;

    private OwnershipBypassRegistry $registry;

    public function setUp(): void
    {
        $this->repository = new InMemoryAsyncEventRepository();
        $this->registry = new OwnershipBypassRegistry();
    }

    private function manager(?GuardedAsyncEventRepository $guarded = null): SubscriptionManager
    {
        return new SubscriptionManager(
            $guarded ?? $this->repository,
            new FakeAsyncEventFactory(),
            new FakeSearchCriteriaBuilder(),
            $this->registry,
            new Random()
        );
    }

    private function eventWorkflow(int $id = 5, string $triggerRef = 'sales.order.created'): WorkflowStub
    {
        return (new WorkflowStub($id))
            ->setStatus(WorkflowInterface::STATUS_ENABLED)
            ->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT)
            ->setTriggerRef($triggerRef);
    }

    private function seedSubscription(string $recipient, string $eventName, bool $active): FakeAsyncEvent
    {
        $subscription = new FakeAsyncEvent();
        $subscription->setRecipientUrl($recipient);
        $subscription->setEventName($eventName);
        $subscription->setMetadata(WorkflowNotifier::NOTIFIER_NAME);
        $subscription->setVerificationToken('seeded-token');
        $subscription->setStatus($active);
        return $this->repository->seed($subscription);
    }

    public function testEnableCreatesSubscriptionWhenAbsent(): void
    {
        $this->manager()->ensureSubscription($this->eventWorkflow());

        $this->assertCount(1, $this->repository->items);
        $subscription = $this->repository->findByRecipient('workflow:5');
        $this->assertNotNull($subscription);
        $this->assertSame('sales.order.created', $subscription->getEventName());
        $this->assertSame('workflow', $subscription->getMetadata());
        $this->assertTrue($subscription->getStatus());
        $this->assertTrue($subscription->getVerificationToken() !== '', 'new subscription needs a token');
        $this->assertTrue($subscription->getSubscriptionId() > 0);
    }

    public function testEnableReactivatesDisabledSubscriptionInsteadOfCreating(): void
    {
        $seeded = $this->seedSubscription('workflow:5', 'sales.order.created', false);

        $this->manager()->ensureSubscription($this->eventWorkflow());

        $this->assertCount(1, $this->repository->items, 're-enable must reuse the row, not add one');
        $this->assertTrue($seeded->getStatus());
        $this->assertSame('seeded-token', $seeded->getVerificationToken(), 'token must survive reactivation');
    }

    public function testEnableIsIdempotentWhenAlreadyInSync(): void
    {
        $this->seedSubscription('workflow:5', 'sales.order.created', true);

        $this->manager()->ensureSubscription($this->eventWorkflow());

        $this->assertSame(0, $this->repository->saveCount, 'in-sync subscription must not be re-saved');
    }

    public function testTriggerRefChangeRebindsEventName(): void
    {
        $seeded = $this->seedSubscription('workflow:5', 'sales.order.created', true);

        $this->manager()->ensureSubscription($this->eventWorkflow(5, 'customer.created'));

        $this->assertSame('customer.created', $seeded->getEventName());
        $this->assertTrue($seeded->getStatus());
        $this->assertCount(1, $this->repository->items);
    }

    public function testDisableDeactivatesButKeepsRow(): void
    {
        $seeded = $this->seedSubscription('workflow:5', 'sales.order.created', true);

        $this->manager()->disableSubscription($this->eventWorkflow());

        $this->assertFalse($seeded->getStatus());
        $this->assertCount(1, $this->repository->items, 'row is kept so trace history and id survive');
    }

    public function testDisableWithoutSubscriptionIsNoOp(): void
    {
        $this->manager()->disableSubscription($this->eventWorkflow());

        $this->assertSame(0, $this->repository->saveCount);
    }

    public function testNonEventTriggerTypeReleasesBinding(): void
    {
        $seeded = $this->seedSubscription('workflow:5', 'sales.order.created', true);
        $workflow = $this->eventWorkflow()->setTriggerType(WorkflowInterface::TRIGGER_TYPE_SCHEDULE);

        $this->manager()->ensureSubscription($workflow);

        $this->assertFalse($seeded->getStatus(), 'switching to schedule must release the event binding');
    }

    public function testEnsureWaitSubscriptionsCreatesOnePerEvent(): void
    {
        $this->manager()->ensureWaitSubscriptions(
            $this->eventWorkflow(),
            ['sales.shipment.created', 'sales.creditmemo.created']
        );

        $first = $this->repository->findByRecipient('workflow:5:wait:sales.shipment.created');
        $second = $this->repository->findByRecipient('workflow:5:wait:sales.creditmemo.created');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->getStatus());
        $this->assertTrue($second->getStatus());
        $this->assertSame('sales.shipment.created', $first->getEventName());
        $this->assertSame('workflow', $first->getMetadata());
    }

    public function testStaleWaitSubscriptionsAreDeactivatedOnReconcile(): void
    {
        $manager = $this->manager();
        $manager->ensureWaitSubscriptions(
            $this->eventWorkflow(),
            ['sales.shipment.created', 'sales.creditmemo.created']
        );

        $manager->ensureWaitSubscriptions($this->eventWorkflow(), ['sales.shipment.created']);

        $kept = $this->repository->findByRecipient('workflow:5:wait:sales.shipment.created');
        $stale = $this->repository->findByRecipient('workflow:5:wait:sales.creditmemo.created');
        $this->assertTrue($kept->getStatus());
        $this->assertFalse($stale->getStatus(), 'a no-longer-referenced wait event must be released');
    }

    public function testDisableStaleWaitSubscriptionsWithEmptyKeepReleasesAll(): void
    {
        $manager = $this->manager();
        $manager->ensureWaitSubscriptions($this->eventWorkflow(), ['sales.shipment.created']);

        $manager->disableStaleWaitSubscriptions(5);

        $waitRow = $this->repository->findByRecipient('workflow:5:wait:sales.shipment.created');
        $this->assertFalse($waitRow->getStatus());
    }

    public function testWaitSubscriptionsAreTriggerTypeIndependent(): void
    {
        $workflow = $this->eventWorkflow()->setTriggerType(WorkflowInterface::TRIGGER_TYPE_SCHEDULE);

        $this->manager()->ensureWaitSubscriptions($workflow, ['sales.order.shipped']);

        $waitRow = $this->repository->findByRecipient('workflow:5:wait:sales.order.shipped');
        $this->assertNotNull($waitRow, 'a scheduled workflow with wait steps still listens');
        $this->assertTrue($waitRow->getStatus());
    }

    public function testWritesRunUnderOwnershipBypass(): void
    {
        $observedBypassStates = [];
        $this->repository->onSave = function () use (&$observedBypassStates): void {
            $observedBypassStates[] = $this->registry->isBypassed();
        };

        $this->manager()->ensureSubscription($this->eventWorkflow());

        $this->assertCount(1, $observedBypassStates);
        $this->assertTrue($observedBypassStates[0], 'repository write must happen inside the bypass');
        $this->assertFalse($this->registry->isBypassed(), 'bypass must not leak past the write');
    }

    public function testManagerPassesThroughOwnershipPluginWhileDirectSaveIsRefused(): void
    {
        $guarded = new GuardedAsyncEventRepository(
            $this->repository,
            new SubscriptionOwnershipPlugin($this->registry)
        );

        // The manager's write survives the guard because of the shared bypass.
        $this->manager($guarded)->ensureSubscription($this->eventWorkflow());
        $this->assertNotNull($this->repository->findByRecipient('workflow:5'));

        // The same write attempted out-of-band (no bypass) is refused.
        $rogue = new FakeAsyncEvent();
        $rogue->setRecipientUrl('workflow:5');
        $refused = false;
        try {
            $guarded->save($rogue, false);
        } catch (LocalizedException $exception) {
            $refused = true;
        }
        $this->assertTrue($refused, 'out-of-band save of an owned recipient must be refused');
    }

    public function testSystemWritesSkipAclResourceCheck(): void
    {
        $this->manager()->ensureSubscription($this->eventWorkflow());

        $this->assertFalse(
            $this->repository->lastCheckResources,
            'system-owned subscription saves must pass $checkResources = false'
        );
    }

    public function testWorkflowWithoutIdIsIgnored(): void
    {
        $workflow = (new WorkflowStub(null))
            ->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT)
            ->setTriggerRef('sales.order.created');

        $manager = $this->manager();
        $manager->ensureSubscription($workflow);
        $manager->disableSubscription($workflow);
        $manager->ensureWaitSubscriptions($workflow, ['sales.order.shipped']);

        $this->assertSame(0, $this->repository->saveCount);
    }
}
