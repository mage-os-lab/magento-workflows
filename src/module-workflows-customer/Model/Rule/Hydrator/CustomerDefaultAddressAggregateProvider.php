<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model\Rule\Hydrator;

use Magento\Customer\Api\CustomerRepositoryInterface;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;

/**
 * Default billing / shipping address leaves for the customer condition root
 * (CUS-C2), contributed through AggregateProviderPool under entity type
 * 'customer' — registered ALONGSIDE the order-history (sales), birthday and
 * newsletter providers (the pool merges every provider's aggregates for an
 * entity type).
 *
 *   - default_billing_country / _region / _postcode / _city
 *   - default_shipping_country / _region / _postcode / _city
 *
 * Data route: CustomerRepositoryInterface::getById($entityId) — the SAME
 * repository CustomerHydrator uses, request-cached by Magento's CustomerRegistry
 * (getById returns the already-materialized DTO within a request), so this is
 * not a meaningfully extra query. The customer DTO carries its addresses with
 * isDefaultBilling()/isDefaultShipping() flags and each address's country/
 * region/postcode/city, so the default addresses are read straight off the DTO
 * rather than issuing separate AddressRepository lookups.
 *
 * Absent when there is no default address (fail-toward-false): if the customer
 * has no default billing address, ALL default_billing_* keys are omitted (and
 * likewise for shipping); an omitted aggregate only matches the negative
 * operators (AbstractWorkflowCondition::validateAttribute()), consistent with
 * every other aggregate provider. Individual empty fields on a present default
 * address are omitted the same way.
 *
 * Country/region/postcode/city are STRING leaves — mirroring the sales pack's
 * order billing_country/shipping_country conditions, which are also plain
 * string inputs (Order\Attribute::getInputType() default => 'string'). Magento
 * exposes no cleanly-poolable workflow option source for ISO country codes
 * here, so an honest string input beats a half-wired select; conditions match
 * on the stored country_id / region name, e.g. country_id = "US".
 *
 * Hydration-time only — never part of a trigger snapshot — so conditions on
 * these leaves always classify as needs_hydration and resolve in phase 2.
 */
class CustomerDefaultAddressAggregateProvider implements AggregateProviderInterface
{
    /**
     * Attribute code => [label, workflow input type]. Labels are raw strings
     * (__()-wrapped by the consuming condition root).
     */
    private const ATTRIBUTE_METADATA = [
        'default_billing_country' => ['label' => 'Default Billing Country', 'input_type' => 'string'],
        'default_billing_region' => ['label' => 'Default Billing State/Province', 'input_type' => 'string'],
        'default_billing_postcode' => ['label' => 'Default Billing Postcode', 'input_type' => 'string'],
        'default_billing_city' => ['label' => 'Default Billing City', 'input_type' => 'string'],
        'default_shipping_country' => ['label' => 'Default Shipping Country', 'input_type' => 'string'],
        'default_shipping_region' => ['label' => 'Default Shipping State/Province', 'input_type' => 'string'],
        'default_shipping_postcode' => ['label' => 'Default Shipping Postcode', 'input_type' => 'string'],
        'default_shipping_city' => ['label' => 'Default Shipping City', 'input_type' => 'string'],
    ];

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * @return array<string, array{label: string, input_type: string}>
     */
    public function getAttributeMetadata(): array
    {
        return self::ATTRIBUTE_METADATA;
    }

    /**
     * @return array<string, string> present default-address fields only
     */
    public function getAggregates(int $entityId): array
    {
        try {
            $customer = $this->customerRepository->getById($entityId);
        } catch (\Throwable $e) {
            // Absent/unloadable customer: no default-address leaves (fail-toward-false).
            unset($e);
            return [];
        }

        $addresses = $customer->getAddresses() ?? [];
        $billing = null;
        $shipping = null;
        foreach ($addresses as $address) {
            if ($billing === null && $address->isDefaultBilling()) {
                $billing = $address;
            }
            if ($shipping === null && $address->isDefaultShipping()) {
                $shipping = $address;
            }
        }

        return array_merge(
            $this->addressFields('default_billing_', $billing),
            $this->addressFields('default_shipping_', $shipping)
        );
    }

    /**
     * Flatten one default address into prefixed string leaves, omitting empty
     * fields (and everything when the address itself is absent).
     *
     * @param object|null $address customer address DTO (AddressInterface)
     * @return array<string, string>
     */
    private function addressFields(string $prefix, ?object $address): array
    {
        if ($address === null) {
            return [];
        }

        $region = $address->getRegion();
        // AddressInterface::getRegion() returns a RegionInterface (or null); the
        // condition value is the human region NAME, mirroring what OrderHydrator
        // emits for billing_region/shipping_region.
        $regionName = ($region !== null && method_exists($region, 'getRegion'))
            ? $region->getRegion()
            : null;

        $fields = [
            $prefix . 'country' => $address->getCountryId(),
            $prefix . 'region' => $regionName,
            $prefix . 'postcode' => $address->getPostcode(),
            $prefix . 'city' => $address->getCity(),
        ];

        $result = [];
        foreach ($fields as $code => $value) {
            if ($value !== null && $value !== '') {
                $result[$code] = (string) $value;
            }
        }
        return $result;
    }
}
