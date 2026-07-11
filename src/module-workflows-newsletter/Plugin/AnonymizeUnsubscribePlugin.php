<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Plugin;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\WorkflowsCustomer\Action\Customer\Anonymize;

/**
 * Contributes newsletter unsubscription to customer.anonymize when this
 * optional pack is installed (domain-packs S5). Before the split the
 * Anonymize action unsubscribed the customer inline; that coupling on
 * Magento\Newsletter is now expressed as a plugin so workflows-customer needs
 * no newsletter dependency.
 *
 * Placement rule 5: an optional-domain pack may target the class it augments,
 * so this plugin references workflows-customer's Anonymize directly.
 *
 * Behaviour preservation — the pre-extraction contract this restores:
 *   - Unsubscribe runs BEFORE the action's irreversible save (we unsubscribe,
 *     then $proceed). An unsubscribe infrastructure failure returns a
 *     retryable failure WITHOUT proceeding, so the customer is not scrubbed
 *     until the unsubscribe has succeeded and the whole step retries safely.
 *   - unsubscribeCustomer() is keyed by customer id and idempotent, so a retry
 *     re-unsubscribes harmlessly.
 *   - A missing subscription (NoSuchEntityException) is not an error — the
 *     action proceeds and the customer is anonymized anyway.
 *   - We only act when the action would actually anonymize: the confirm gate
 *     mirrors Anonymize's own terminal-failure guard, so an unconfirmed
 *     request is a pure no-op (it must never unsubscribe).
 *   - On success we re-add the historical 'unsubscribed' => true output marker
 *     the inline implementation set, keeping the action's observable output
 *     identical when both packs are installed.
 *
 * The one deliberate divergence from the exact pre-split interleaving: because
 * a plugin cannot slot between the action's already-anonymized skip check and
 * its save, an already-anonymized customer now receives one extra
 * unsubscribeCustomer() call (idempotent no-op) before the action returns
 * SKIPPED. The observable result — SKIPPED, no save — is unchanged.
 */
class AnonymizeUnsubscribePlugin
{
    public function __construct(
        private readonly SubscriptionManagerInterface $subscriptionManager
    ) {
    }

    /**
     * @param callable(ExecutionContextInterface, array): ActionResultInterface $proceed
     */
    public function aroundExecute(
        Anonymize $subject,
        callable $proceed,
        ExecutionContextInterface $ctx,
        array $config
    ): ActionResultInterface {
        // Unconfirmed requests must stay a no-op, exactly as before the split.
        if (!$this->isConfirmed($config)) {
            return $proceed($ctx, $config);
        }

        // Unsubscribe FIRST, while the real email still identifies the
        // subscriber row; an infrastructure failure here blocks the scrub.
        try {
            $this->subscriptionManager->unsubscribeCustomer($ctx->getEntityId(), $ctx->getStoreId());
        } catch (NoSuchEntityException $e) {
            // No subscription (or no such customer yet) — fine, let the action decide.
        } catch (\Exception $e) {
            return ActionResult::failure(
                'Could not unsubscribe customer before anonymizing: ' . $e->getMessage(),
                true
            );
        }

        $result = $proceed($ctx, $config);

        if ($result->getStatus() === ActionResultInterface::STATUS_SUCCESS) {
            return ActionResult::success($result->getOutput() + ['unsubscribed' => true]);
        }

        return $result;
    }

    /**
     * Mirrors Anonymize::checkConfirm — the flag must be exactly true (bool
     * true or its form-serialized twins). Kept in sync deliberately: the plugin
     * must gate on the same condition the action uses to decide it will run.
     */
    private function isConfirmed(array $config): bool
    {
        $confirm = $config['confirm'] ?? null;
        return $confirm === true || $confirm === 'true' || $confirm === '1' || $confirm === 1;
    }
}
