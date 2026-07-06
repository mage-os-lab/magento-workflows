<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * customer.anonymize — GDPR-assist scrubbing of a customer account.
 *
 * *** WARNING: DESTRUCTIVE AND IRREVERSIBLE. ***
 * This action overwrites the customer's name and email and clears personal
 * fields (dob, taxvat, gender, middlename, prefix, suffix). The original
 * values are NOT retained anywhere by this action — once executed there is
 * no undo. It exists to assist right-to-erasure workflows; wire it only
 * behind triggers you fully trust, and it hard-requires confirm: true in the
 * step config as a deliberate speed bump.
 *
 * The email becomes anonymized+<customer_id>@invalid.example (.example is an
 * RFC 2606 reserved TLD, so mail can never actually route). That pattern
 * doubles as the idempotency marker: an already-anonymized customer skips.
 * The newsletter unsubscribe runs BEFORE the save (while the real email
 * still identifies the subscriber), so a failed save retries the whole
 * sequence safely.
 */
class Anonymize extends AbstractAction implements SimulateableActionInterface
{
    private const ANONYMIZED_FIRSTNAME = 'Anonymized';
    private const ANONYMIZED_LASTNAME = 'Customer';
    private const ANONYMIZED_EMAIL_PATTERN = 'anonymized+%d@invalid.example';

    private const SCRUBBED_FIELDS = [
        'firstname',
        'lastname',
        'email',
        'dob',
        'taxvat',
        'gender',
        'middlename',
        'prefix',
        'suffix',
    ];

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SubscriptionManagerInterface $subscriptionManager
    ) {
    }

    public function getCode(): string
    {
        return 'customer.anonymize';
    }

    public function getLabel(): string
    {
        return (string)__('Anonymize Customer (GDPR)');
    }

    public function getGroup(): string
    {
        return (string)__('Customer');
    }

    public function getApplicableEntities(): array
    {
        return ['customer'];
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'confirm', 'label' => 'Confirm Anonymization', 'type' => 'boolean', 'required' => true,
                'notice' => 'Must be exactly true. DESTRUCTIVE AND IRREVERSIBLE: name, email and personal '
                    . 'fields are overwritten with no undo.'],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $denied = $this->checkConfirm($config);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $customer = $this->customerRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Customer %1 not found', $ctx->getEntityId()));
        }

        $anonymizedEmail = sprintf(self::ANONYMIZED_EMAIL_PATTERN, $ctx->getEntityId());
        if (strtolower((string)$customer->getEmail()) === $anonymizedEmail) {
            return ActionResult::skipped('Customer is already anonymized');
        }

        // Unsubscribe FIRST, while the real email still identifies the
        // subscriber row; a later save failure retries the full sequence
        // (re-unsubscribing is a no-op).
        try {
            $this->subscriptionManager->unsubscribeCustomer($ctx->getEntityId(), $ctx->getStoreId());
        } catch (NoSuchEntityException $e) {
            // No subscription to remove — fine
        } catch (\Exception $e) {
            return ActionResult::failure('Could not unsubscribe customer before anonymizing: ' . $e->getMessage(), true);
        }

        $customer->setFirstname(self::ANONYMIZED_FIRSTNAME);
        $customer->setLastname(self::ANONYMIZED_LASTNAME);
        $customer->setEmail($anonymizedEmail);
        $customer->setDob(null);
        $customer->setTaxvat(null);
        $customer->setGender(null);
        $customer->setMiddlename(null);
        $customer->setPrefix(null);
        $customer->setSuffix(null);

        try {
            $this->customerRepository->save($customer);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not anonymize customer: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'customer_id' => $ctx->getEntityId(),
            'email' => $anonymizedEmail,
            'scrubbed' => self::SCRUBBED_FIELDS,
            'unsubscribed' => true,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $denied = $this->checkConfirm($config);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $customer = $this->customerRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Customer %1 not found', $ctx->getEntityId()));
        }

        $anonymizedEmail = sprintf(self::ANONYMIZED_EMAIL_PATTERN, $ctx->getEntityId());
        if (strtolower((string)$customer->getEmail()) === $anonymizedEmail) {
            return ActionResult::skipped('Customer is already anonymized');
        }

        return $this->simulated(
            sprintf(
                'IRREVERSIBLY scrub customer %d: overwrite %s, then unsubscribe from newsletter',
                $ctx->getEntityId(),
                implode(', ', self::SCRUBBED_FIELDS)
            ),
            ['scrubbed' => self::SCRUBBED_FIELDS]
        );
    }

    /**
     * The confirm flag must be exactly true (bool true, or its unavoidable
     * form-serialized twins "true"/"1"). Anything else is a terminal failure.
     */
    private function checkConfirm(array $config): ?ActionResult
    {
        $confirm = $config['confirm'] ?? null;
        if ($confirm === true || $confirm === 'true' || $confirm === '1' || $confirm === 1) {
            return null;
        }
        return ActionResult::failure((string)__('customer.anonymize requires confirm: true'));
    }
}
