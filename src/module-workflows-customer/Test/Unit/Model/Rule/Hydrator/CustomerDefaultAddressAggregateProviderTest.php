<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Model\Rule\Hydrator;

use Magento\Customer\Api\CustomerRepositoryInterface;
use MageOS\WorkflowsCustomer\Model\Rule\Hydrator\CustomerDefaultAddressAggregateProvider;
use PHPUnit\Framework\TestCase;

/**
 * CustomerDefaultAddressAggregateProvider (CUS-C2): contributes the default
 * billing/shipping country/region/postcode/city string leaves to the customer
 * root, reading them straight off the customer DTO's addresses. Keys are ABSENT
 * (not null/empty-set) when there is no default address or the field is empty,
 * so they fail toward false.
 */
class CustomerDefaultAddressAggregateProviderTest extends TestCase
{
    private function provider(?object $customer, ?\Throwable $throws = null): CustomerDefaultAddressAggregateProvider
    {
        $repository = new class($customer, $throws) implements CustomerRepositoryInterface {
            public function __construct(private ?object $customer, private ?\Throwable $throws)
            {
            }
            public function getById($customerId)
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                return $this->customer;
            }
            public function save($customer, $passwordHash = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };

        return new CustomerDefaultAddressAggregateProvider($repository);
    }

    private function customer(array $addresses): object
    {
        return new class($addresses) {
            public function __construct(private array $addresses)
            {
            }
            public function getAddresses()
            {
                return $this->addresses;
            }
        };
    }

    private function address(
        bool $billing,
        bool $shipping,
        ?string $country,
        ?string $region,
        ?string $postcode,
        ?string $city
    ): object {
        $regionObject = $region === null ? null : new class($region) {
            public function __construct(private string $name)
            {
            }
            public function getRegion()
            {
                return $this->name;
            }
        };

        return new class($billing, $shipping, $country, $regionObject, $postcode, $city) {
            public function __construct(
                private bool $billing,
                private bool $shipping,
                private ?string $country,
                private ?object $region,
                private ?string $postcode,
                private ?string $city
            ) {
            }
            public function isDefaultBilling()
            {
                return $this->billing;
            }
            public function isDefaultShipping()
            {
                return $this->shipping;
            }
            public function getCountryId()
            {
                return $this->country;
            }
            public function getRegion()
            {
                return $this->region;
            }
            public function getPostcode()
            {
                return $this->postcode;
            }
            public function getCity()
            {
                return $this->city;
            }
        };
    }

    public function testMetadataAdvertisesAllEightLeavesAsStrings(): void
    {
        $metadata = $this->provider($this->customer([]))->getAttributeMetadata();

        foreach ([
            'default_billing_country', 'default_billing_region', 'default_billing_postcode', 'default_billing_city',
            'default_shipping_country', 'default_shipping_region', 'default_shipping_postcode', 'default_shipping_city',
        ] as $code) {
            $this->assertArrayHasKey($code, $metadata);
            $this->assertSame('string', $metadata[$code]['input_type'], $code . ' is a string leaf');
        }
    }

    public function testSeparateBillingAndShippingDefaults(): void
    {
        $customer = $this->customer([
            $this->address(true, false, 'US', 'California', '94107', 'San Francisco'),
            $this->address(false, true, 'CA', 'Ontario', 'M5H 2N2', 'Toronto'),
        ]);

        $aggregates = $this->provider($customer)->getAggregates(7);

        $this->assertSame('US', $aggregates['default_billing_country']);
        $this->assertSame('California', $aggregates['default_billing_region']);
        $this->assertSame('94107', $aggregates['default_billing_postcode']);
        $this->assertSame('San Francisco', $aggregates['default_billing_city']);
        $this->assertSame('CA', $aggregates['default_shipping_country']);
        $this->assertSame('Ontario', $aggregates['default_shipping_region']);
        $this->assertSame('M5H 2N2', $aggregates['default_shipping_postcode']);
        $this->assertSame('Toronto', $aggregates['default_shipping_city']);
    }

    public function testOneAddressIsBothDefaults(): void
    {
        $customer = $this->customer([
            $this->address(true, true, 'GB', 'Greater London', 'EC1A 1BB', 'London'),
        ]);

        $aggregates = $this->provider($customer)->getAggregates(7);

        $this->assertSame('GB', $aggregates['default_billing_country']);
        $this->assertSame('GB', $aggregates['default_shipping_country']);
        $this->assertSame('London', $aggregates['default_shipping_city']);
    }

    public function testNoDefaultAddressYieldsNoAggregates(): void
    {
        // Addresses exist but none is flagged default.
        $customer = $this->customer([
            $this->address(false, false, 'US', 'Texas', '73301', 'Austin'),
        ]);

        $this->assertSame([], $this->provider($customer)->getAggregates(7));
    }

    public function testNoAddressesAtAllYieldsNoAggregates(): void
    {
        $this->assertSame([], $this->provider($this->customer([]))->getAggregates(7));
    }

    public function testOnlyBillingDefaultOmitsShippingKeys(): void
    {
        $customer = $this->customer([
            $this->address(true, false, 'US', 'California', '94107', 'San Francisco'),
        ]);

        $aggregates = $this->provider($customer)->getAggregates(7);

        $this->assertArrayHasKey('default_billing_country', $aggregates);
        $this->assertFalse(array_key_exists('default_shipping_country', $aggregates));
        $this->assertFalse(array_key_exists('default_shipping_region', $aggregates));
    }

    public function testEmptyFieldsAreOmitted(): void
    {
        // Default billing address with no region and no postcode.
        $customer = $this->customer([
            $this->address(true, false, 'US', null, '', 'San Francisco'),
        ]);

        $aggregates = $this->provider($customer)->getAggregates(7);

        $this->assertSame('US', $aggregates['default_billing_country']);
        $this->assertSame('San Francisco', $aggregates['default_billing_city']);
        $this->assertFalse(array_key_exists('default_billing_region', $aggregates));
        $this->assertFalse(array_key_exists('default_billing_postcode', $aggregates));
    }

    public function testUnloadableCustomerYieldsNoAggregates(): void
    {
        $provider = $this->provider(null, new \RuntimeException('No such entity'));

        $this->assertSame([], $provider->getAggregates(999));
    }
}
