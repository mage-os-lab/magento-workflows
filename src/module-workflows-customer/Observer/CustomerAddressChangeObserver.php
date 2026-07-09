<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Observer;

use Magento\Customer\Model\Address;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher for CUS-T2: publishes 'customer.address_changed' (declared
 * in etc/async_events.xml) on create / update / delete of a customer address,
 * with a 'change_type' payload key (created|updated|deleted) plus a snapshot of
 * the address in flight.
 *
 * VER-1 reconciliation (July 2026, audited against mageos-common-async-events):
 * upstream declares customer.address.created / customer.address.updated
 * (address-entity payload via AsyncCustomerAddressManagement::getById) but NO
 * delete event. This observer deliberately coexists rather than being dropped:
 * different event name (customer.address_changed — no declaration collision),
 * CUSTOMER-entity payload so conditions author against the owner, and unified
 * coverage including change_type=deleted, which upstream cannot express.
 *
 * One observer class, two seams — both AbstractModel events dispatched by
 * Magento\Customer\Model\Address ('_eventPrefix = customer_address'):
 *   - customer_address_save_after   -> created (no orig entity_id) | updated
 *   - customer_address_delete_after -> deleted
 * The seam is read from the dispatched event name ($observer->getEvent()
 * ->getName()); created-vs-updated is read from getOrigData('entity_id'), the
 * pre-save snapshot AbstractModel::afterSave() still holds when the event fires
 * (updateStoredData() runs after the dispatch) — the same convention
 * CustomerGroupChangeObserver / OrderStatusChangeObserver use.
 *
 * entity = customer: the payload hydrates through
 * CustomerRepositoryInterface::getById($customerId) and conditions author
 * against the address's OWNER (the customer), not the address. The address
 * fields ride along as extra payload keys for Trigger Data conditions.
 *
 * Deleted-address caveat: on delete the row is already gone, so the payload
 * carries the address's LAST-KNOWN data captured from the model still populated
 * in memory at delete_after; hydration then returns the customer, whose default
 * address list no longer contains the deleted address. Author deleted-address
 * conditions on the Trigger Data payload keys (address_id, country_id, ...),
 * not on the hydrated customer's CUS-C2 default-address leaves.
 *
 * Default-flag caveat: is_default_billing / is_default_shipping report what the
 * saving operation set on the model in flight (the address form / repository
 * flags them); a bare load-then-delete that never carried the flags reports
 * false. Best-effort, last-known — consistent with the deleted-address framing.
 *
 * Boundary: this covers customer-account addresses only. Guest / sales_order
 * addresses live in a different table (sales_order_address) and a different
 * model — they are NOT customer addresses and are out of scope here.
 *
 * Loop-guard / debounce: this is a plain gap-fill publisher; it emits one event
 * per address save/delete and holds no state. It does not itself mutate the
 * customer, so it cannot self-trigger; standard engine debounce applies to the
 * published event like any other trigger. Publishing failures are logged, never
 * allowed to break the address save/delete.
 */
class CustomerAddressChangeObserver implements ObserverInterface
{
    public const EVENT_NAME = 'customer.address_changed';

    private const CHANGE_CREATED = 'created';
    private const CHANGE_UPDATED = 'updated';
    private const CHANGE_DELETED = 'deleted';

    private const DELETE_EVENT = 'customer_address_delete_after';

    public function __construct(
        private readonly EventPublisher $eventPublisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        // AbstractModel::_getEventData() always carries the model under
        // 'data_object' regardless of _eventObject, so it is the robust key;
        // 'address'/'object' are read as belt-and-braces fallbacks.
        $address = $event->getData('data_object')
            ?? $event->getData('address')
            ?? $event->getData('object');
        if (!$address instanceof Address || !$address->getId()) {
            return;
        }

        $customerId = (int) $address->getCustomerId();
        if ($customerId === 0) {
            // No owning customer to hydrate/condition against (should not happen
            // for a customer_address row, but fail safe rather than publish an
            // unhydratable event).
            return;
        }

        $changeType = $this->resolveChangeType((string) $event->getName(), $address);

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'customerId' hydrates via CustomerRepositoryInterface::getById($customerId)
                'customerId' => $customerId,
                // entity_id = the OWNER customer id (the condition entity)
                'entity_id' => $customerId,
                'change_type' => $changeType,
                'address_id' => (int) $address->getId(),
                'country_id' => $address->getCountryId(),
                'region' => $address->getRegion(),
                'postcode' => $address->getPostcode(),
                'city' => $address->getCity(),
                'is_default_billing' => (bool) $address->getIsDefaultBilling(),
                'is_default_shipping' => (bool) $address->getIsDefaultShipping(),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s (%s) for customer #%d address #%d: %s',
                    self::EVENT_NAME,
                    $changeType,
                    $customerId,
                    (int) $address->getId(),
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }

    private function resolveChangeType(string $eventName, Address $address): string
    {
        if ($eventName === self::DELETE_EVENT) {
            return self::CHANGE_DELETED;
        }
        // save_after: a brand-new address has no pre-save entity_id snapshot.
        $origId = $address->getOrigData('entity_id');
        return ($origId === null || $origId === '' || (int) $origId === 0)
            ? self::CHANGE_CREATED
            : self::CHANGE_UPDATED;
    }
}
