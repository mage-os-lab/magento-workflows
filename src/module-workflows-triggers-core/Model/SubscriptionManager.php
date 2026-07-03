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
 * Async-events API assumptions (centralized in this class):
 * - AsyncEventRepositoryInterface::getList(SearchCriteriaInterface) returns
 *   SearchResults of AsyncEventInterface; ::save(AsyncEventInterface, bool
 *   $checkResources) persists — we pass $checkResources = false because these
 *   system-owned subscriptions are not authored by an ACL-bearing API user.
 * - AsyncEventInterface exposes get/setSubscriptionId, get/setEventName,
 *   get/setRecipientUrl, get/setVerificationToken, get/setMetadata,
 *   get/setStatus (active = true/1).
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
     * Recipient URL / ownership marker for a workflow id.
     */
    public function recipientFor(int $workflowId): string
    {
        return WorkflowNotifier::RECIPIENT_PREFIX . $workflowId;
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
