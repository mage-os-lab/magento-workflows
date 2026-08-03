<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Model\Option;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use MageOS\WorkflowsCatalog\Action\Product\SetAttribute;
use MageOS\WorkflowsCatalog\Model\Option\ProductAttributeOptionSource;
use PHPUnit\Framework\TestCase;

/**
 * The product_attributes option source (F6) backing product.set_attribute's
 * attribute_code picker: "Label (code)" rows, the action's security denylist
 * excluded (read from the module's real etc/di.xml so config and picker cannot
 * drift apart), plus the inherited substring filter and 50-row cap.
 */
class ProductAttributeOptionSourceTest extends TestCase
{
    /**
     * @return string[] denylist shipped in etc/di.xml for product.set_attribute
     */
    private function shippedDenylist(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 4) . '/etc/di.xml');
        $this->assertNotNull($xml === false ? null : $xml, 'etc/di.xml must parse');

        $codes = [];
        foreach ($xml->xpath(
            '//type[@name="MageOS\WorkflowsCatalog\Action\Product\SetAttribute"]'
            . '//argument[@name="deniedAttributes"]/item'
        ) as $item) {
            $codes[] = (string)$item;
        }
        return $codes;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $attributes code, label pairs
     */
    private function collectionFactory(array $attributes): ProductAttributeCollectionFactory
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

        return new class($rows) extends ProductAttributeCollectionFactory {
            public function __construct(private readonly array $rows)
            {
            }
            public function create()
            {
                return $this->rows;
            }
        };
    }

    /**
     * @param string[] $denied
     */
    private function setAttributeAction(array $denied): SetAttribute
    {
        $productAction = new class extends ProductAction {
            public function __construct()
            {
            }
        };
        $attributeRepository = new class implements AttributeRepositoryInterface {
            public function get($entityTypeCode, $attributeCode) { throw new \BadMethodCallException(__METHOD__); }
            public function save($attribute) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($entityTypeCode, $searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($attribute) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($attributeId) { throw new \BadMethodCallException(__METHOD__); }
        };

        return new SetAttribute($productAction, $attributeRepository, $denied);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $attributes
     * @param string[]|null $denied
     */
    private function source(array $attributes, ?array $denied = null): ProductAttributeOptionSource
    {
        return new ProductAttributeOptionSource(
            $this->collectionFactory($attributes),
            $this->setAttributeAction($denied ?? $this->shippedDenylist())
        );
    }

    public function testCodeIsTheRegisteredSourceCode(): void
    {
        $this->assertSame('product_attributes', $this->source([])->getCode());
    }

    public function testLabelCarriesBothTheLabelAndTheCode(): void
    {
        $options = $this->source([['color', 'Color'], ['erp_ref', '  ']])->fetch();

        $this->assertSame([
            ['value' => 'color', 'label' => 'Color (color)'],
            // No frontend label (store-agnostic custom attribute): code alone.
            ['value' => 'erp_ref', 'label' => 'erp_ref'],
        ], $options);
    }

    public function testShippedDenylistIsExcludedFromThePicker(): void
    {
        $denied = $this->shippedDenylist();
        $this->assertTrue($denied !== [], 'shipped denylist must not be empty');

        $rows = [['color', 'Color']];
        foreach ($denied as $code) {
            $rows[] = [$code, ucfirst($code)];
        }
        $values = array_column($this->source($rows)->all(), 'value');

        $this->assertSame(['color'], $values, 'a code the action would refuse must never be offered');
    }

    public function testDenylistExclusionIsCaseInsensitive(): void
    {
        $values = array_column($this->source([['SKU', 'Sku'], ['Status', 'Status']])->all(), 'value');

        $this->assertSame([], $values);
    }

    public function testQueryFiltersOverCodeAndLabel(): void
    {
        $source = $this->source([['color', 'Color'], ['manufacturer', 'Brand']], denied: []);

        $this->assertSame('manufacturer', $source->fetch('bran')[0]['value'], 'label match');
        $this->assertSame('color', $source->fetch('COLO')[0]['value'], 'code match, case-insensitive');
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
