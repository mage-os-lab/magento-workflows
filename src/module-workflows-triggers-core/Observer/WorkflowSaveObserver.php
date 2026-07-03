<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\WorkflowsTriggersCore\Model\SubscriptionManager;

/**
 * Keeps the hidden async-event subscription in sync with the workflow
 * lifecycle. Registered on both 'mageos_workflow_save_after' and
 * 'mageos_workflow_delete_after' (the standard AbstractModel events fired
 * by the core repository, event prefix 'mageos_workflow').
 *
 * Sync rules:
 * - save of an event-triggered workflow in Enabled or Shadow status
 *   -> ensure an active subscription (Shadow workflows receive live events;
 *      their actions only simulate, see docs/architecture-plan.md section 9)
 * - save in Disabled/Suspended status, or with a non-event trigger type
 *   -> deactivate the subscription
 * - delete -> deactivate the subscription
 *
 * Errors bubble: a workflow save that cannot bind its event stream must
 * fail loudly, not enable a workflow that will never fire.
 */
class WorkflowSaveObserver implements ObserverInterface
{
    private const DELETE_EVENT_SUFFIX = '_delete_after';

    public function __construct(
        private readonly SubscriptionManager $subscriptionManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $workflow = $observer->getEvent()->getData('object')
            ?? $observer->getEvent()->getData('data_object');
        if (!$workflow instanceof WorkflowInterface) {
            return;
        }

        if ($this->isDeleteEvent($observer) || !$this->isActiveWorkflow($workflow)) {
            $this->subscriptionManager->disableSubscription($workflow);
            $this->subscriptionManager->disableStaleWaitSubscriptions((int) $workflow->getWorkflowId());

            return;
        }

        // ensureSubscription internally releases the trigger binding for
        // non-event trigger types; wait subscriptions are trigger-type
        // independent (a scheduled workflow with wait steps still listens).
        $this->subscriptionManager->ensureSubscription($workflow);
        $this->subscriptionManager->ensureWaitSubscriptions($workflow, $this->waitEventsOf($workflow));
    }

    /**
     * Wait events referenced by the saved definition. An unparseable
     * definition contributes none (and releases stale wait subscriptions) —
     * definition validity is the save pipeline's concern, not the
     * subscription sync's.
     *
     * @return string[]
     */
    private function waitEventsOf(WorkflowInterface $workflow): array
    {
        try {
            return Definition::fromJson((string) $workflow->getDefinition())->getWaitEvents();
        } catch (\InvalidArgumentException $e) {
            return [];
        }
    }

    private function isDeleteEvent(Observer $observer): bool
    {
        return str_ends_with((string) $observer->getEvent()->getName(), self::DELETE_EVENT_SUFFIX);
    }

    private function isActiveWorkflow(WorkflowInterface $workflow): bool
    {
        return in_array(
            $workflow->getStatus(),
            [WorkflowInterface::STATUS_ENABLED, WorkflowInterface::STATUS_SHADOW],
            true
        );
    }
}
