<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Model\Option;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use MageOS\WorkflowsCustomer\Action\Customer\SetAttribute;
use MageOS\WorkflowsCustomer\Model\Option\CustomerAttributeOptionSource;
use PHPUnit\Framework\TestCase;

/**
 * The customer_attributes option source (F6) backing customer.set_attribute's
 * attribute_code picker: "Label (code)" rows, the action's security denylist
 * excluded (read from the module's real etc/di.xml so config and picker cannot
 * drift apart), plus the inherited substring filter and 50-row cap.
 */
class CustomerAttributeOptionSourceTest extends TestCase
{
    /**
     * @return string[] denylist shipped in etc/di.xml for customer.set_attribute
     */
    private function shippedDenylist(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/di.xml');
        $this->assertNotNull($xml === false ? null : $xml, 'etc/di.xml must parse');

        $codes = [];
        foreach ($xml->xpath(
            '//type[@name="MageOS\WorkflowsCustomer\Action\Customer\SetAttribute"]'
            . '//argument[@name="deniedAttributes"]/item'
        ) as $item) {
            $codes[] = (string)$item;
        }
        return $codes;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $attributes code, label pairs
     */
    private function metadata(array $attributes): CustomerMetadataInterface
    {
        $rows = [];
        foreach ($attributes as [$code, $label]) {
            $rows[] = new class($code, $label) {
                public function __construct(private readonly string $code, private readonly string $label)
                {
                }
                public function getAttributeCode()
                {
                    return $this->code;
                }
                public function getFrontendLabel()
                {
                    return $this->label;
                }
            };
        }

        return new class($rows) implements CustomerMetadataInterface {
            public function __construct(private readonly array $rows)
            {
            }
            public function getAllAttributesMetadata()
            {
                return $this->rows;
            }
            public function getAttributes($formCode) { throw new \BadMethodCallException(__METHOD__); }
            public function getAttributeMetadata($attributeCode) { throw new \BadMethodCallException(__METHOD__); }
            public function getCustomAttributesMetadata($dataObjectClassName = self::DATA_INTERFACE_NAME)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };
    }

    /**
     * @param string[] $denied
     */
    private function setAttributeAction(array $denied): SetAttribute
    {
        $customerRepository = new class implements CustomerRepositoryInterface {
            public function save($customer, $passwordHash = null) { throw new \BadMethodCallException(__METHOD__); }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getById($customerId) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
        $attributeRepository = new class implements AttributeRepositoryInterface {
            public function get($entityTypeCode, $attributeCode) { throw new \BadMethodCallException(__METHOD__); }
            public function save($attribute) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($entityTypeCode, $searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($attribute) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($attributeId) { throw new \BadMethodCallException(__METHOD__); }
        };

        return new SetAttribute($customerRepository, $attributeRepository, $denied);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $attributes
     * @param string[]|null $denied
     */
    private function source(array $attributes, ?array $denied = null): CustomerAttributeOptionSource
    {
        return new CustomerAttributeOptionSource(
            $this->metadata($attributes),
            $this->setAttributeAction($denied ?? $this->shippedDenylist())
        );
    }

    public function testCodeIsTheRegisteredSourceCode(): void
    {
        $this->assertSame('customer_attributes', $this->source([])->getCode());
    }

    public function testLabelCarriesBothTheLabelAndTheCode(): void
    {
        $options = $this->source([['loyalty_tier', 'Loyalty Tier'], ['kvk_number', '  ']])->fetch();

        $this->assertSame([
            ['value' => 'loyalty_tier', 'label' => 'Loyalty Tier (loyalty_tier)'],
            // No frontend label on this custom attribute: the code alone.
            ['value' => 'kvk_number', 'label' => 'kvk_number'],
        ], $options);
    }

    public function testShippedDenylistIsExcludedFromThePicker(): void
    {
        $denied = $this->shippedDenylist();
        $this->assertTrue($denied !== [], 'shipped denylist must not be empty');

        $rows = [['loyalty_tier', 'Loyalty Tier']];
        foreach ($denied as $code) {
            $rows[] = [$code, ucfirst($code)];
        }
        $values = array_column($this->source($rows)->all(), 'value');

        $this->assertSame(['loyalty_tier'], $values, 'a code the action would refuse must never be offered');
    }

    public function testDenylistExclusionIsCaseInsensitive(): void
    {
        $values = array_column($this->source([['Password_Hash', 'Password'], ['EMAIL', 'Email']])->all(), 'value');

        $this->assertSame([], $values);
    }

    public function testQueryFiltersOverCodeAndLabel(): void
    {
        $source = $this->source([['loyalty_tier', 'Loyalty Tier'], ['taxvat', 'VAT Number']], denied: []);

        $this->assertSame('taxvat', $source->fetch('vat num')[0]['value'], 'label match');
        $this->assertSame('loyalty_tier', $source->fetch('LOYALTY')[0]['value'], 'code match, case-insensitive');
        $this->assertSame([], $source->fetch('no_such_attribute'));
    }

    public function testResultsAreCappedWhileAllStaysComplete(): void
    {
        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = ['attr_' . $i, 'Attribute ' . $i];
        }
        $source = $this->source($rows, denied: []);

        $this->assertCount(50, $source->fetch(), 'the type-ahead endpoint stays capped');
        $this->assertCount(120, $source->all());
        $this->assertTrue($source->hasValue('attr_119'));
    }
}
