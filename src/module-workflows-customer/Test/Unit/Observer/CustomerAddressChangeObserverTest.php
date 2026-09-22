<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\WorkflowsCustomer\Observer\CustomerAddressChangeObserver;
use MageOS\WorkflowsCustomer\Test\Unit\Stub\FakeCustomerAddress;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CUS-T2 gap-fill contract: customer.address_changed fires on the
 * save/delete AbstractModel seams of the customer address model with the right
 * change_type (created|updated|deleted), the owning customer id (for
 * hydration + as the condition entity id) and the address-snapshot payload
 * keys, including the default-billing/shipping flags. Deleted addresses carry
 * their last-known data captured from the model in flight. Publish failures are
 * logged, never rethrown into the address save/delete.
 */
class CustomerAddressChangeObserverTest extends TestCase
{
    private const SAVE_EVENT = 'customer_address_save_after';
    private const DELETE_EVENT = 'customer_address_delete_after';

    private function observer(RecordingEventPublisher $publisher, ?RecordingLogger $logger = null): CustomerAddressChangeObserver
    {
        return new CustomerAddressChangeObserver($publisher, $logger ?? new RecordingLogger());
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function event(string $name, array $eventData): Observer
    {
        return new Observer(['event' => new Event(['name' => $name] + $eventData)]);
    }

    private function address(mixed $origEntityId = null): FakeCustomerAddress
    {
        return new FakeCustomerAddress(
            [
                'entity_id' => 42,
                'customer_id' => 7,
                'country_id' => 'US',
                'region' => 'California',
                'postcode' => '94107',
                'city' => 'San Francisco',
                'is_default_billing' => true,
                'is_default_shipping' => false,
            ],
            $origEntityId
        );
    }

    public function testNewAddressPublishesCreated(): void
    {
        $publisher = new RecordingEventPublisher();
        // No orig entity_id => brand-new address.
        $this->observer($publisher)->execute(
            $this->event(self::SAVE_EVENT, ['data_object' => $this->address(null)])
        );

        $this->assertCount(1, $publisher->published);
        $payload = $publisher->published[0];
        $this->assertSame('customer.address_changed', $payload['event']);
        $this->assertSame('created', $payload['data']['change_type']);
        $this->assertSame(7, $payload['data']['customerId']);
        $this->assertSame(7, $payload['data']['entity_id']);
        $this->assertSame(42, $payload['data']['address_id']);
        $this->assertSame('US', $payload['data']['country_id']);
        $this->assertSame('California', $payload['data']['region']);
        $this->assertSame('94107', $payload['data']['postcode']);
        $this->assertSame('San Francisco', $payload['data']['city']);
    }

    public function testExistingAddressPublishesUpdated(): void
    {
        $publisher = new RecordingEventPublisher();
        // Orig entity_id present => an update of an existing address.
        $this->observer($publisher)->execute(
            $this->event(self::SAVE_EVENT, ['data_object' => $this->address(42)])
        );

        $this->assertCount(1, $publisher->published);
        $this->assertSame('updated', $publisher->published[0]['data']['change_type']);
    }

    public function testDeletePublishesDeletedWithLastKnownData(): void
    {
        $publisher = new RecordingEventPublisher();
        // On delete the row is gone; the in-flight model still carries its data.
        $this->observer($publisher)->execute(
            $this->event(self::DELETE_EVENT, ['data_object' => $this->address(42)])
        );

        $this->assertCount(1, $publisher->published);
        $payload = $publisher->published[0]['data'];
        $this->assertSame('deleted', $payload['change_type']);
        $this->assertSame(42, $payload['address_id']);
        $this->assertSame('US', $payload['country_id']);
        $this->assertSame('San Francisco', $payload['city']);
    }

    public function testReportsDefaultFlagsAsBooleans(): void
    {
        $publisher = new RecordingEventPublisher();
        $address = new FakeCustomerAddress([
            'entity_id' => 5,
            'customer_id' => 9,
            'is_default_billing' => true,
            'is_default_shipping' => true,
        ]);

        $this->observer($publisher)->execute(
            $this->event(self::SAVE_EVENT, ['data_object' => $address])
        );

        $payload = $publisher->published[0]['data'];
        $this->assertTrue($payload['is_default_billing']);
        $this->assertTrue($payload['is_default_shipping']);
    }

    public function testUnflaggedAddressReportsFalseDefaults(): void
    {
        $publisher = new RecordingEventPublisher();
        // A bare load-then-delete never carried the default flags.
        $address = new FakeCustomerAddress(['entity_id' => 5, 'customer_id' => 9]);

        $this->observer($publisher)->execute(
            $this->event(self::DELETE_EVENT, ['data_object' => $address])
        );

        $payload = $publisher->published[0]['data'];
        $this->assertFalse($payload['is_default_billing']);
        $this->assertFalse($payload['is_default_shipping']);
    }

    public function testReadsAddressFromObjectKeyFallback(): void
    {
        $publisher = new RecordingEventPublisher();
        $this->observer($publisher)->execute(
            $this->event(self::SAVE_EVENT, ['object' => $this->address(null)])
        );

        $this->assertCount(1, $publisher->published);
    }

    public function testDoesNotFireWithoutOwningCustomer(): void
    {
        $publisher = new RecordingEventPublisher();
        $address = new FakeCustomerAddress(['entity_id' => 5]); // no customer_id

        $this->observer($publisher)->execute(
            $this->event(self::SAVE_EVENT, ['data_object' => $address])
        );

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutAddressInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = $this->observer($publisher);

        $observer->execute($this->event(self::SAVE_EVENT, []));
        $observer->execute($this->event(self::SAVE_EVENT, ['data_object' => new \stdClass()]));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();

        // Must not throw.
        $this->observer($publisher, $logger)->execute(
            $this->event(self::SAVE_EVENT, ['data_object' => $this->address(null)])
        );

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('customer.address_changed', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
    }
}
