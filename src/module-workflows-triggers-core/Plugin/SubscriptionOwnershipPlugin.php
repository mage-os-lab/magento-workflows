<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Plugin;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AsyncEvents\Api\AsyncEventRepositoryInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\WorkflowsTriggersCore\Model\OwnershipBypassRegistry;
use MageOS\WorkflowsTriggersCore\Model\WorkflowNotifier;

/**
 * Enforces subscription ownership (docs/10-security.md#subscription-ownership):
 * async-event subscriptions whose recipient_url starts with "workflow:" are
 * managed exclusively by SubscriptionManager. The admin UI, REST API, and
 * third-party code all mutate subscriptions through the repository, so an
 * around-plugin here refuses save/delete of owned rows — preventing an
 * out-of-band edit from redirecting a workflow's event stream — unless the
 * shared OwnershipBypassRegistry flag is set by SubscriptionManager while
 * it operates.
 *
 * Both the incoming and (for updates) the persisted recipient are checked,
 * so an owned subscription can neither be edited in place nor re-pointed
 * away from its workflow, and a foreign subscription cannot be re-pointed
 * INTO the workflow namespace.
 *
 * Note: trailing repository arguments (e.g. save()'s $checkResources flag)
 * are forwarded via variadics so this plugin tolerates signature drift
 * across async-events versions. If the installed version's repository has
 * no delete() method, aroundDelete() is simply never wired in.
 */
class SubscriptionOwnershipPlugin
{
    public function __construct(
        private readonly OwnershipBypassRegistry $bypassRegistry
    ) {
    }

    /**
     * @param mixed ...$args trailing repository arguments (e.g. $checkResources)
     * @return mixed
     * @throws LocalizedException
     */
    public function aroundSave(
        AsyncEventRepositoryInterface $subject,
        callable $proceed,
        AsyncEventInterface $asyncEvent,
        ...$args
    ) {
        if (!$this->bypassRegistry->isBypassed()) {
            $this->assertNotOwned((string) $asyncEvent->getRecipientUrl());
            $this->assertPersistedNotOwned($subject, $asyncEvent->getSubscriptionId());
        }

        return $proceed($asyncEvent, ...$args);
    }

    /**
     * @param AsyncEventInterface|int $asyncEvent subscription entity or id,
     *        depending on the installed async-events repository signature
     * @param mixed ...$args
     * @return mixed
     * @throws LocalizedException
     */
    public function aroundDelete(
        AsyncEventRepositoryInterface $subject,
        callable $proceed,
        $asyncEvent,
        ...$args
    ) {
        if (!$this->bypassRegistry->isBypassed()) {
            if ($asyncEvent instanceof AsyncEventInterface) {
                $this->assertNotOwned((string) $asyncEvent->getRecipientUrl());
            } elseif (is_numeric($asyncEvent)) {
                $this->assertPersistedNotOwned($subject, (int) $asyncEvent);
            }
        }

        return $proceed($asyncEvent, ...$args);
    }

    /**
     * @throws LocalizedException
     */
    private function assertPersistedNotOwned(AsyncEventRepositoryInterface $repository, $subscriptionId): void
    {
        if (!$subscriptionId) {
            return;
        }
        try {
            $persisted = $repository->get((int) $subscriptionId);
        } catch (\Exception $exception) {
            return; // nothing persisted -> nothing to protect
        }
        $this->assertNotOwned((string) $persisted->getRecipientUrl());
    }

    /**
     * @throws LocalizedException
     */
    private function assertNotOwned(string $recipientUrl): void
    {
        if (!str_starts_with($recipientUrl, WorkflowNotifier::RECIPIENT_PREFIX)) {
            return;
        }

        $workflowId = substr($recipientUrl, strlen(WorkflowNotifier::RECIPIENT_PREFIX));
        throw new LocalizedException(
            __(
                'This subscription is managed by workflow #%1. '
                . 'Edit the workflow itself to change its trigger binding.',
                $workflowId !== '' ? $workflowId : '?'
            )
        );
    }
}
