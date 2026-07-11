<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use MageOS\AsyncEvents\Api\AsyncEventRepositoryInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterfaceFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;

/**
 * Binds workflow lifecycle to hidden async-event subscriptions.
 *
 * An enabled (or shadow) event-triggered workflow owns exactly one active
 * subscription: event_name = the workflow's trigger_ref, metadata =
 * "workflow" (selects WorkflowNotifier), recipient_url = "workflow:<id>"
 * (doubles as the ownership marker per docs/10-security.md).
 *
 * The subscription id mapping is managed here: resolved by a
 * find-by-recipient getList() search and memoized per request, so repeated
 * saves in one request do one lookup.
 *
 * All repository writes run inside OwnershipBypassRegistry::bypass() so
 * SubscriptionOwnershipPlugin lets them through.
 *
 * Async-events API (verified against mage-os/mageos-async-events @ b249976):
 * - Api/AsyncEventRepositoryInterface.php: getList(SearchCriteriaInterface):
 *   AsyncEventSearchResultsInterface, get(int): AsyncEventDisplayInterface, and
 *   save(AsyncEventInterface, bool $checkResources = true): AsyncEventDisplayInterface.
 *   We pass $checkResources = false because these system-owned subscriptions are
 *   not authored by an ACL-bearing API user (validateResources() would otherwise
 *   assert the current session's permissions — AsyncEventRepository.php:73-77).
 * - Api/Data/AsyncEventInterface.php exposes get/setSubscriptionId, get/setEventName,
 *   get/setRecipientUrl, get/setVerificationToken, get/setMetadata, get/setStatus
 *   (status is a bool; active = true, mapped to the `status` int column, filtered
 *   as `status = 1` by EventDispatcher). recipient_url/event_name/metadata/status
 *   are real `async_event_subscriber` columns (etc/db_schema.xml).
 * - CAUTION (upstream behavior, AsyncEventRepository.php:85-99): save() of an
 *   EXISTING row (subscription_id set) reloads the persisted entity and applies
 *   ONLY status and metadata; a changed event_name/recipient_url/verification_token
 *   is silently DROPPED. This class is safe today because a workflow's recipient
 *   is derived from its immutable id and we always re-set status before save;
 *   the one exposed gap is re-pointing a bound workflow's trigger_ref, which will
 *   not update the subscription's event_name on a real install (see the maintainer
 *   note in docs/14-risks.md) — reactivation and metadata sync are unaffected.
 */
class SubscriptionManager
{
    private const FIELD_RECIPIENT_URL = 'recipient_url';

    /**
     * Memoized subscription-id mapping: recipient_url => subscription id
     * (0 = known absent).
     *
     * @var array<string, int>
     */
    private array $subscriptionIdByRecipient = [];

    public function __construct(
        private readonly AsyncEventRepositoryInterface $asyncEventRepository,
        private readonly AsyncEventInterfaceFactory $asyncEventFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly OwnershipBypassRegistry $bypassRegistry,
        private readonly Random $random
    ) {
    }

    /**
     * Creates or reactivates the hidden subscription backing an
     * event-triggered workflow. Safe to call on every save: updates the
     * event_name when the trigger_ref changed, reactivates when previously
     * disabled, no-ops when already in sync. Non-event workflows are
     * delegated to disableSubscription() so switching a workflow from
     * event to schedule/manual releases its binding.
     *
     * @throws LocalizedException
     */
    public function ensureSubscription(WorkflowInterface $workflow): void
    {
        $workflowId = (int) $workflow->getWorkflowId();
        if ($workflowId <= 0) {
            return;
        }
        if ($workflow->getTriggerType() !== WorkflowInterface::TRIGGER_TYPE_EVENT) {
            $this->disableSubscription($workflow);

            return;
        }

        $recipient = $this->recipientFor($workflowId);
        $subscription = $this->findByRecipient($recipient);

        if ($subscription === null) {
            /** @var AsyncEventInterface $subscription */
            $subscription = $this->asyncEventFactory->create();
            $subscription->setRecipientUrl($recipient);
            $subscription->setVerificationToken($this->random->getRandomString(32));
        } elseif ($this->isActive($subscription)
            && (string) $subscription->getEventName() === $workflow->getTriggerRef()
            && (string) $subscription->getMetadata() === WorkflowNotifier::NOTIFIER_NAME
        ) {
            return; // already in sync
        }

        $subscription->setEventName($workflow->getTriggerRef());
        $subscription->setMetadata(WorkflowNotifier::NOTIFIER_NAME);
        $subscription->setStatus(true);

        $this->save($subscription, $recipient);
    }

    /**
     * Deactivates the workflow's hidden subscription (workflow disabled,
     * suspended, deleted, or re-bound to a non-event trigger). The row is
     * kept, deactivated, so async-events trace history stays intact and
     * re-enabling reactivates the same subscription id.
     *
     * @throws LocalizedException
     */
    public function disableSubscription(WorkflowInterface $workflow): void
    {
        $workflowId = (int) $workflow->getWorkflowId();
        if ($workflowId <= 0) {
            return;
        }

        $recipient = $this->recipientFor($workflowId);
        $subscription = $this->findByRecipient($recipient);
        if ($subscription === null || !$this->isActive($subscription)) {
            return;
        }

        $subscription->setStatus(false);
        $this->save($subscription, $recipient);
    }

    /**
     * Ensures one hidden subscription per event a wait step listens on
     * (recipient "workflow:<id>:wait:<event>"), and deactivates stale wait
     * subscriptions whose event is no longer referenced. Wait subscriptions
     * are trigger-type independent: a schedule- or manual-triggered workflow
     * with wait steps still needs its wait events delivered.
     *
     * @param string[] $events wait events referenced by the current definition
     * @throws LocalizedException
     */
    public function ensureWaitSubscriptions(WorkflowInterface $workflow, array $events): void
    {
        $workflowId = (int) $workflow->getWorkflowId();
        if ($workflowId <= 0) {
            return;
        }

        foreach ($events as $event) {
            $recipient = $this->waitRecipientFor($workflowId, $event);
            $subscription = $this->findByRecipient($recipient);

            if ($subscription === null) {
                /** @var AsyncEventInterface $subscription */
                $subscription = $this->asyncEventFactory->create();
                $subscription->setRecipientUrl($recipient);
                $subscription->setVerificationToken($this->random->getRandomString(32));
            } elseif ($this->isActive($subscription)
                && (string) $subscription->getEventName() === $event
                && (string) $subscription->getMetadata() === WorkflowNotifier::NOTIFIER_NAME
            ) {
                continue; // already in sync
            }

            $subscription->setEventName($event);
            $subscription->setMetadata(WorkflowNotifier::NOTIFIER_NAME);
            $subscription->setStatus(true);
            $this->save($subscription, $recipient);
        }

        $this->disableStaleWaitSubscriptions($workflowId, $events);
    }

    /**
     * Deactivates wait subscriptions not in the wanted set (pass [] to
     * release all — workflow disabled, suspended, or deleted).
     *
     * @param string[] $keepEvents
     * @throws LocalizedException
     */
    public function disableStaleWaitSubscriptions(int $workflowId, array $keepEvents = []): void
    {
        if ($workflowId <= 0) {
            return;
        }
        $prefix = $this->waitRecipientPrefix($workflowId);
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(self::FIELD_RECIPIENT_URL, $prefix . '%', 'like')
            ->create();
        foreach ($this->asyncEventRepository->getList($searchCriteria)->getItems() as $subscription) {
            $recipient = (string) $subscription->getRecipientUrl();
            $event = substr($recipient, strlen($prefix));
            if (in_array($event, $keepEvents, true) || !$this->isActive($subscription)) {
                continue;
            }
            $subscription->setStatus(false);
            $this->save($subscription, $recipient);
        }
    }

    /**
     * Recipient URL / ownership marker for a workflow id.
     */
    public function recipientFor(int $workflowId): string
    {
        return WorkflowNotifier::RECIPIENT_PREFIX . $workflowId;
    }

    /**
     * Recipient URL for a wait-step subscription.
     */
    public function waitRecipientFor(int $workflowId, string $event): string
    {
        return $this->waitRecipientPrefix($workflowId) . $event;
    }

    private function waitRecipientPrefix(int $workflowId): string
    {
        return WorkflowNotifier::RECIPIENT_PREFIX . $workflowId . WorkflowNotifier::WAIT_INFIX;
    }

    private function findByRecipient(string $recipient): ?AsyncEventInterface
    {
        $knownId = $this->subscriptionIdByRecipient[$recipient] ?? null;
        if ($knownId === 0) {
            return null;
        }
        if ($knownId !== null) {
            try {
                return $this->asyncEventRepository->get($knownId);
            } catch (\Exception $exception) { // stale mapping — fall through to search
                unset($this->subscriptionIdByRecipient[$recipient]);
            }
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(self::FIELD_RECIPIENT_URL, $recipient)
            ->setPageSize(1)
            ->create();
        $items = $this->asyncEventRepository->getList($searchCriteria)->getItems();
        $subscription = null;
        foreach ($items as $item) {
            $subscription = $item;
            break;
        }

        $this->subscriptionIdByRecipient[$recipient] = $subscription
            ? (int) $subscription->getSubscriptionId()
            : 0;

        return $subscription;
    }

    /**
     * @throws LocalizedException
     */
    private function save(AsyncEventInterface $subscription, string $recipient): void
    {
        $saved = $this->bypassRegistry->bypass(
            fn () => $this->asyncEventRepository->save($subscription, false)
        );

        $subscriptionId = (int) (($saved instanceof AsyncEventInterface ? $saved : $subscription)
            ->getSubscriptionId());
        if ($subscriptionId > 0) {
            $this->subscriptionIdByRecipient[$recipient] = $subscriptionId;
        }
    }

    private function isActive(AsyncEventInterface $subscription): bool
    {
        return (bool) $subscription->getStatus();
    }
}
