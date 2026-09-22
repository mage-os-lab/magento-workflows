<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Math\Random;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use MageOS\WorkflowsTriggersCore\Model\OwnershipBypassRegistry;
use MageOS\WorkflowsTriggersCore\Model\SubscriptionManager;
use MageOS\WorkflowsTriggersCore\Observer\WorkflowSaveObserver;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeAsyncEventFactory;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeSearchCriteriaBuilder;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\InMemoryAsyncEventRepository;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end save/delete -> subscription sync through a REAL
 * SubscriptionManager against the in-memory repository, pinning the
 * observer docblock's rules: Enabled/Shadow event workflows ensure an
 * active subscription (plus one wait subscription per waited-on event from
 * the definition); Disabled/Suspended saves and deletes release everything;
 * wait subscriptions are trigger-type independent.
 */
class WorkflowSaveObserverTest extends TestCase
{
    private const WAIT_DEFINITION = '{"schema":2,"entry":"w1","steps":{"w1":{"type":"wait",'
        . '"config":{"event":"sales.order.shipped","timeout":"P2D"}}}}';

    private InMemoryAsyncEventRepository $repository;

    private WorkflowSaveObserver $observer;

    public function setUp(): void
    {
        $this->repository = new InMemoryAsyncEventRepository();
        $this->observer = new WorkflowSaveObserver(new SubscriptionManager(
            $this->repository,
            new FakeAsyncEventFactory(),
            new FakeSearchCriteriaBuilder(),
            new OwnershipBypassRegistry(),
            new Random()
        ));
    }

    private function workflow(int $status = WorkflowInterface::STATUS_ENABLED): WorkflowStub
    {
        return (new WorkflowStub(5))
            ->setStatus($status)
            ->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT)
            ->setTriggerRef('sales.order.created');
    }

    /**
     * @param mixed $object
     */
    private function observerEvent($object, string $eventName = 'mageos_workflow_save_after'): Observer
    {
        return new Observer(['event' => new Event(['object' => $object, 'name' => $eventName])]);
    }

    public function testEnabledEventWorkflowSaveEnsuresActiveSubscription(): void
    {
        $this->observer->execute($this->observerEvent($this->workflow()));

        $subscription = $this->repository->findByRecipient('workflow:5');
        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->getStatus());
        $this->assertSame('sales.order.created', $subscription->getEventName());
        $this->assertSame('workflow', $subscription->getMetadata());
    }

    public function testShadowWorkflowAlsoReceivesLiveEvents(): void
    {
        $this->observer->execute($this->observerEvent($this->workflow(WorkflowInterface::STATUS_SHADOW)));

        $subscription = $this->repository->findByRecipient('workflow:5');
        $this->assertNotNull($subscription, 'shadow workflows receive live events (actions only simulate)');
        $this->assertTrue($subscription->getStatus());
    }

    public function testWaitStepsInDefinitionEnsureWaitSubscriptions(): void
    {
        $workflow = $this->workflow()->setDefinition(self::WAIT_DEFINITION);

        $this->observer->execute($this->observerEvent($workflow));

        $waitSubscription = $this->repository->findByRecipient('workflow:5:wait:sales.order.shipped');
        $this->assertNotNull($waitSubscription);
        $this->assertTrue($waitSubscription->getStatus());
        $this->assertSame('sales.order.shipped', $waitSubscription->getEventName());
    }

    public function testDisabledSaveDeactivatesTriggerAndWaitSubscriptions(): void
    {
        $enabled = $this->workflow()->setDefinition(self::WAIT_DEFINITION);
        $this->observer->execute($this->observerEvent($enabled));

        $disabled = $this->workflow(WorkflowInterface::STATUS_DISABLED)->setDefinition(self::WAIT_DEFINITION);
        $this->observer->execute($this->observerEvent($disabled));

        $this->assertFalse($this->repository->findByRecipient('workflow:5')->getStatus());
        $this->assertFalse($this->repository->findByRecipient('workflow:5:wait:sales.order.shipped')->getStatus());
    }

    public function testDeleteEventDeactivatesEvenWhenStatusEnabled(): void
    {
        $this->observer->execute($this->observerEvent($this->workflow()->setDefinition(self::WAIT_DEFINITION)));

        $this->observer->execute($this->observerEvent(
            $this->workflow()->setDefinition(self::WAIT_DEFINITION),
            'mageos_workflow_delete_after'
        ));

        $this->assertFalse($this->repository->findByRecipient('workflow:5')->getStatus());
        $this->assertFalse($this->repository->findByRecipient('workflow:5:wait:sales.order.shipped')->getStatus());
    }

    public function testUnparseableDefinitionReleasesWaitSubscriptionsButKeepsTrigger(): void
    {
        $this->observer->execute($this->observerEvent($this->workflow()->setDefinition(self::WAIT_DEFINITION)));

        $this->observer->execute($this->observerEvent($this->workflow()->setDefinition('this is not json')));

        $this->assertTrue(
            $this->repository->findByRecipient('workflow:5')->getStatus(),
            'trigger binding survives a bad definition'
        );
        $this->assertFalse(
            $this->repository->findByRecipient('workflow:5:wait:sales.order.shipped')->getStatus(),
            'wait events of an unparseable definition are released'
        );
    }

    public function testScheduleWorkflowWithWaitStepsKeepsWaitButReleasesTrigger(): void
    {
        // Start as an enabled event workflow, then re-save as schedule-triggered.
        $this->observer->execute($this->observerEvent($this->workflow()));

        $scheduled = $this->workflow()
            ->setTriggerType(WorkflowInterface::TRIGGER_TYPE_SCHEDULE)
            ->setDefinition(self::WAIT_DEFINITION);
        $this->observer->execute($this->observerEvent($scheduled));

        $this->assertFalse(
            $this->repository->findByRecipient('workflow:5')->getStatus(),
            'switching to schedule releases the event trigger binding'
        );
        $this->assertTrue(
            $this->repository->findByRecipient('workflow:5:wait:sales.order.shipped')->getStatus(),
            'wait subscriptions are trigger-type independent'
        );
    }

    public function testNonWorkflowObjectIsIgnored(): void
    {
        $this->observer->execute($this->observerEvent(new \stdClass()));

        $this->assertSame(0, $this->repository->saveCount);
        $this->assertCount(0, $this->repository->items);
    }
}
