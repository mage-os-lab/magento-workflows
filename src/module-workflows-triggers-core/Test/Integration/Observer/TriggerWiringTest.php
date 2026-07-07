<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Integration\Observer;

use Magento\Customer\Model\Customer;
use Magento\Framework\Event\ManagerInterface;
use Magento\Review\Model\Review;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\WorkflowsTriggersCore\Observer\CustomerGroupChangeObserver;
use MageOS\WorkflowsTriggersCore\Observer\OrderStatusChangeObserver;
use MageOS\WorkflowsTriggersCore\Observer\ReviewSubmittedObserver;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Integration\_files\RecordingEventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Plan #22 (docs/20-integration-test-plan.md §6): the merged events.xml is the
 * subject. Real events are dispatched through the framework event manager with
 * fixture entities; a recording EventPublisher (OM preference) captures the
 * async-event name + payload each gap-fill observer produces, proving the
 * observer→publisher wiring and the documented payload shape (docs/05-triggers.md,
 * etc/async_events.xml).
 *
 * The exclusion contracts pinned here are the ones the observers actually
 * implement: new-entity suppression (no original status/group → the created.*
 * event already covers it) and no-change suppression (from == to). (The
 * import-suppression flag the plan alludes to lives in the separate
 * module-workflows-import-suppression, suite #30 — not these observers.)
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class TriggerWiringTest extends TestCase
{
    private \Magento\Framework\ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testOrderStatusChangePublishesWithFromToPayload(): void
    {
        $publisher = $this->configureRecordingPublisher();
        $order = $this->loadOrder();
        $order->setOrigData('status', 'pending');
        $order->setData('status', 'processing');

        $this->eventManager()->dispatch('sales_order_save_after', ['order' => $order]);

        $payloads = $publisher->payloadsFor(OrderStatusChangeObserver::EVENT_NAME);
        $this->assertCount(1, $payloads, 'A real status transition publishes exactly one event');
        $entityId = (int) $order->getEntityId();
        $this->assertSame($entityId, $payloads[0]['id'], "'id' hydrates via OrderRepositoryInterface::get");
        $this->assertSame($entityId, $payloads[0]['entity_id']);
        $this->assertSame('pending', $payloads[0]['from_status']);
        $this->assertSame('processing', $payloads[0]['to_status']);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testNewOrderAndUnchangedStatusAreExcluded(): void
    {
        $publisher = $this->configureRecordingPublisher();

        // New order: no original status → sales.order.created already covers it.
        $newOrder = $this->loadOrder();
        $newOrder->setOrigData('status', null);
        $newOrder->setData('status', 'pending');
        $this->eventManager()->dispatch('sales_order_save_after', ['order' => $newOrder]);
        $this->assertCount(
            0,
            $publisher->payloadsFor(OrderStatusChangeObserver::EVENT_NAME),
            'A newly created order must not publish a status-changed event'
        );

        // No change: from == to.
        $unchanged = $this->loadOrder();
        $unchanged->setOrigData('status', 'processing');
        $unchanged->setData('status', 'processing');
        $this->eventManager()->dispatch('sales_order_save_after', ['order' => $unchanged]);
        $this->assertCount(
            0,
            $publisher->payloadsFor(OrderStatusChangeObserver::EVENT_NAME),
            'An unchanged status must not publish'
        );
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testCustomerGroupChangePublishesWithFromToPayload(): void
    {
        $publisher = $this->configureRecordingPublisher();
        $customer = $this->loadCustomer();
        $customer->setOrigData('group_id', 1);
        $customer->setData('group_id', 2);

        $this->eventManager()->dispatch('customer_save_after', ['customer' => $customer]);

        $payloads = $publisher->payloadsFor(CustomerGroupChangeObserver::EVENT_NAME);
        $this->assertCount(1, $payloads);
        $customerId = (int) $customer->getId();
        $this->assertSame($customerId, $payloads[0]['customerId']);
        $this->assertSame($customerId, $payloads[0]['entity_id']);
        $this->assertSame(1, $payloads[0]['from_group_id']);
        $this->assertSame(2, $payloads[0]['to_group_id']);
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     */
    public function testNewCustomerAndUnchangedGroupAreExcluded(): void
    {
        $publisher = $this->configureRecordingPublisher();

        $newCustomer = $this->loadCustomer();
        $newCustomer->setOrigData('group_id', null);
        $newCustomer->setData('group_id', 1);
        $this->eventManager()->dispatch('customer_save_after', ['customer' => $newCustomer]);
        $this->assertCount(0, $publisher->payloadsFor(CustomerGroupChangeObserver::EVENT_NAME));

        $unchanged = $this->loadCustomer();
        $unchanged->setOrigData('group_id', 1);
        $unchanged->setData('group_id', 1);
        $this->eventManager()->dispatch('customer_save_after', ['customer' => $unchanged]);
        $this->assertCount(0, $publisher->payloadsFor(CustomerGroupChangeObserver::EVENT_NAME));
    }

    public function testReviewApprovalPublishesOnceAndAlreadyApprovedIsExcluded(): void
    {
        $publisher = $this->configureRecordingPublisher();

        // Newly approved product review (pending -> approved).
        $approved = $this->objectManager->create(Review::class);
        $approved->setId(4242);
        $approved->setData('entity_id', 1); // Review::ENTITY_PRODUCT_CODE row id
        $approved->setEntityPkValue(42);
        $approved->setData('status_id', Review::STATUS_APPROVED);
        $approved->setOrigData('status_id', Review::STATUS_PENDING);
        $approved->setData('title', 'Great product');
        $approved->setData('nickname', 'Reviewer');
        $approved->setData('store_id', 1);
        $this->eventManager()->dispatch('review_save_after', ['object' => $approved]);

        $payloads = $publisher->payloadsFor(ReviewSubmittedObserver::EVENT_NAME);
        $this->assertCount(1, $payloads, 'A pending→approved review publishes once');
        $this->assertSame(42, $payloads[0]['productId']);
        $this->assertSame(42, $payloads[0]['entity_id']);
        $this->assertSame(4242, $payloads[0]['review_id']);

        // Already approved: a re-save that keeps it approved must not re-publish.
        $stillApproved = $this->objectManager->create(Review::class);
        $stillApproved->setId(4343);
        $stillApproved->setData('entity_id', 1);
        $stillApproved->setEntityPkValue(42);
        $stillApproved->setData('status_id', Review::STATUS_APPROVED);
        $stillApproved->setOrigData('status_id', Review::STATUS_APPROVED);
        $this->eventManager()->dispatch('review_save_after', ['object' => $stillApproved]);
        $this->assertCount(
            1,
            $publisher->payloadsFor(ReviewSubmittedObserver::EVENT_NAME),
            'An already-approved review must not re-publish'
        );
    }

    private function configureRecordingPublisher(): RecordingEventPublisher
    {
        $this->objectManager->configure([
            'preferences' => [EventPublisher::class => RecordingEventPublisher::class],
        ]);
        /** @var RecordingEventPublisher $publisher */
        $publisher = $this->objectManager->get(EventPublisher::class);
        return $publisher;
    }

    private function eventManager(): ManagerInterface
    {
        return $this->objectManager->get(ManagerInterface::class);
    }

    private function loadOrder(): Order
    {
        $order = $this->objectManager->create(Order::class);
        $order->loadByIncrementId('100000001');
        return $order;
    }

    private function loadCustomer(): Customer
    {
        $customer = $this->objectManager->create(Customer::class);
        $customer->load(1);
        return $customer;
    }
}
