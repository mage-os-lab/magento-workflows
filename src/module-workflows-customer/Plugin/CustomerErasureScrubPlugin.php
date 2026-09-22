<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Plugin;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use MageOS\WorkflowsCustomer\Model\ExecutionPiiScrubber;
use Psr\Log\LoggerInterface;

/**
 * GDPR erasure hook (docs/10-security.md "PII containment" #3): after a
 * customer account is deleted through the repository, scrub their PII out of
 * persisted workflow execution contexts and step results via
 * ExecutionPiiScrubber (see its docblock for the exact scrub scope).
 *
 * Both repository entry points are covered because Magento's
 * CustomerRepository::deleteById() does NOT route through delete():
 * - delete($customer): the DTO in hand still carries id + email after the
 *   row is gone, so an after-plugin suffices.
 * - deleteById($id): the email must be captured BEFORE deletion (an
 *   around-plugin loads it); a load failure falls through to proceed() so
 *   the repository raises its canonical NoSuchEntityException, and the
 *   scrub then runs id-only.
 *
 * The scrub runs only after a successful deletion and never breaks it: by
 * the time the after-side runs the delete is committed, so a scrub failure
 * is logged loudly (the erasure obligation is NOT met and needs operator
 * follow-up) rather than thrown.
 */
class CustomerErasureScrubPlugin
{
    public function __construct(
        private readonly ExecutionPiiScrubber $scrubber,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterDelete(
        CustomerRepositoryInterface $subject,
        bool $result,
        CustomerInterface $customer
    ): bool {
        if ($result) {
            $this->scrubSafely((int) $customer->getId(), (string) $customer->getEmail());
        }
        return $result;
    }

    /**
     * @param int|string $customerId
     */
    public function aroundDeleteById(
        CustomerRepositoryInterface $subject,
        callable $proceed,
        $customerId
    ): bool {
        $email = '';
        try {
            $email = (string) $subject->getById((int) $customerId)->getEmail();
        } catch (\Throwable) {
            // Missing customer: let proceed() raise the canonical exception.
        }

        $result = (bool) $proceed($customerId);
        if ($result) {
            $this->scrubSafely((int) $customerId, $email);
        }
        return $result;
    }

    private function scrubSafely(int $customerId, string $email): void
    {
        try {
            $this->scrubber->scrubForCustomer($customerId, $email);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'GDPR erasure scrub of workflow executions FAILED for deleted customer %d; '
                . 'their PII may persist in mageos_workflow_execution until retention pruning: %s',
                $customerId,
                $e->getMessage()
            ));
        }
    }
}
