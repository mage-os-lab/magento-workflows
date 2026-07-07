<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use MageOS\WorkflowsActionsCore\Action\Customer\Newsletter;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #20 (docs/20-integration-test-plan.md §5) — customer.newsletter mutates
 * real newsletter_subscriber rows and is idempotent: subscribing twice leaves
 * one subscribed row; unsubscribe flips the same row to unsubscribed.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 * @magentoAppArea frontend
 */
class NewsletterTest extends ActionTestCase
{
    private CustomerRepositoryInterface $customerRepository;
    private SubscriberFactory $subscriberFactory;
    private Newsletter $action;

    protected function setUp(): void
    {
        $this->customerRepository = $this->resolve(CustomerRepositoryInterface::class);
        $this->subscriberFactory = $this->resolve(SubscriberFactory::class);
        $this->action = $this->resolve(Newsletter::class);
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testSubscribeThenUnsubscribeMutatesTheRow(): void
    {
        $customerId = $this->customerId();
        $ctx = $this->buildContext($customerId, 1);

        $subscribe = $this->action->execute($ctx, ['action' => 'subscribe']);
        $this->assertTrue($subscribe->isSuccess(), $subscribe->getError() ?? '');
        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $this->subscriberStatus($customerId));

        $unsubscribe = $this->action->execute($ctx, ['action' => 'unsubscribe']);
        $this->assertTrue($unsubscribe->isSuccess(), $unsubscribe->getError() ?? '');
        $this->assertSame(Subscriber::STATUS_UNSUBSCRIBED, $this->subscriberStatus($customerId));
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testSubscribeIsIdempotent(): void
    {
        $customerId = $this->customerId();
        $ctx = $this->buildContext($customerId, 1);

        $this->action->execute($ctx, ['action' => 'subscribe']);
        $firstSubscriberId = (int)$this->loadSubscriber($customerId)->getId();

        $this->action->execute($ctx, ['action' => 'subscribe']);
        $secondSubscriberId = (int)$this->loadSubscriber($customerId)->getId();

        $this->assertSame($firstSubscriberId, $secondSubscriberId, 'Re-subscribe must not create a second row');
        $this->assertSame(Subscriber::STATUS_SUBSCRIBED, $this->subscriberStatus($customerId));
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testInvalidActionIsTerminalFailure(): void
    {
        $ctx = $this->buildContext($this->customerId(), 1);
        $result = $this->action->execute($ctx, ['action' => 'bogus']);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    private function customerId(): int
    {
        return (int)$this->customerRepository->get('customer@example.com')->getId();
    }

    private function loadSubscriber(int $customerId): Subscriber
    {
        return $this->subscriberFactory->create()->loadByCustomerId($customerId);
    }

    private function subscriberStatus(int $customerId): int
    {
        return (int)$this->loadSubscriber($customerId)->getSubscriberStatus();
    }
}
