<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsActionsCore\Action\Customer\Anonymize;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #20 (docs/20-integration-test-plan.md §5) — customer.anonymize scrubs
 * the documented field set on a real customer (docs/07 note 4), requires
 * confirm: true, and is idempotent (an already-anonymized customer skips).
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 * @magentoAppArea adminhtml
 */
class AnonymizeTest extends ActionTestCase
{
    private CustomerRepositoryInterface $customerRepository;
    private Anonymize $action;

    protected function setUp(): void
    {
        $this->customerRepository = $this->resolve(CustomerRepositoryInterface::class);
        $this->action = $this->resolve(Anonymize::class);
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testScrubsDocumentedFieldsWithConfirm(): void
    {
        $customerId = $this->customerId();
        $ctx = $this->buildContext($customerId, 1);

        $result = $this->action->execute($ctx, ['confirm' => true]);
        $this->assertTrue($result->isSuccess(), $result->getError() ?? '');
        $this->assertTrue((bool)$result->getOutput()['unsubscribed']);

        $scrubbed = $this->customerRepository->getById($customerId);
        $this->assertSame(
            sprintf('anonymized+%d@invalid.example', $customerId),
            strtolower((string)$scrubbed->getEmail())
        );
        $this->assertSame('Anonymized', $scrubbed->getFirstname());
        $this->assertSame('Customer', $scrubbed->getLastname());
        $this->assertNull($scrubbed->getDob());
        $this->assertNull($scrubbed->getTaxvat());
        // gender is an int-backed select attribute: clearing with '' persists
        // as 0 ("not specified"), which reads back '0', not null. Either way
        // no PII remains - assert emptiness, not null identity.
        $this->assertEmpty($scrubbed->getGender());
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testRerunOnAnonymizedCustomerIsSkipped(): void
    {
        $customerId = $this->customerId();
        $ctx = $this->buildContext($customerId, 1);

        $this->assertTrue($this->action->execute($ctx, ['confirm' => true])->isSuccess());
        $second = $this->action->execute($ctx, ['confirm' => true]);
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testWithoutConfirmIsTerminalFailureAndDoesNotScrub(): void
    {
        $customerId = $this->customerId();
        $original = $this->customerRepository->getById($customerId)->getEmail();
        $ctx = $this->buildContext($customerId, 1);

        $result = $this->action->execute($ctx, []);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        // Untouched.
        $this->assertSame($original, $this->customerRepository->getById($customerId)->getEmail());
    }

    private function customerId(): int
    {
        return (int)$this->customerRepository->get('customer@example.com')->getId();
    }
}
