<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Product;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsActionsCore\Action\Product\SetAttribute;
use PHPUnit\Framework\TestCase;

/**
 * Behavior tests for the product.set_attribute security denylist — the same
 * deny-by-default posture as customer.set_attribute (docs/10-security.md):
 * the codes shipped in etc/di.xml (sku: product identity; status: owned by
 * product.set_status) are refused TERMINALLY, before any EAV lookup and
 * before any attribute write. The shipped list is read from the module's
 * real etc/di.xml so config and behavior cannot drift apart.
 */
class SetAttributeTest extends TestCase
{
    /**
     * @return string[] denylist shipped in etc/di.xml for this action
     */
    private function shippedDenylist(): array
    {
        $diXml = dirname(__DIR__, 4) . '/etc/di.xml';
        $xml = simplexml_load_file($diXml);
        $this->assertNotNull($xml === false ? null : $xml, 'etc/di.xml must parse');

        $items = $xml->xpath(
            '//type[@name="MageOS\WorkflowsActionsCore\Action\Product\SetAttribute"]'
            . '//argument[@name="deniedAttributes"]/item'
        );
        $codes = [];
        foreach ($items as $item) {
            $codes[] = (string)$item;
        }
        return $codes;
    }

    private function fakeProductAction(): ProductAction
    {
        return new class extends ProductAction {
            /** @var array<int, array{0: array, 1: array, 2: int}> */
            public array $calls = [];
            // Bypass the real Product\Action DI constructor
            public function __construct()
            {
            }
            public function updateAttributes($productIds, $attrData, $storeId)
            {
                $this->calls[] = [$productIds, $attrData, $storeId];
                return $this;
            }
        };
    }

    private function fakeAttributeRepository(bool $attributeExists = true): AttributeRepositoryInterface
    {
        return new class($attributeExists) implements AttributeRepositoryInterface {
            /** @var string[] */
            public array $lookedUp = [];
            public function __construct(public bool $attributeExists)
            {
            }
            public function get($entityTypeCode, $attributeCode)
            {
                $this->lookedUp[] = $attributeCode;
                if (!$this->attributeExists) {
                    throw NoSuchEntityException::singleField('attribute_code', $attributeCode);
                }
                return new \stdClass();
            }
            public function save($attribute)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function getList($entityTypeCode, $searchCriteria)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function delete($attribute)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function deleteById($attributeId)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };
    }

    private function ctx(int $entityId = 42, int $storeId = 3): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: $entityId, storeId: $storeId));
    }

    public function testShippedDenylistCoversSkuAndStatus(): void
    {
        $shipped = $this->shippedDenylist();
        $this->assertTrue(in_array('sku', $shipped, true), 'di.xml must deny sku (product identity)');
        $this->assertTrue(in_array('status', $shipped, true), 'di.xml must deny status (owned by product.set_status)');
    }

    public function testEveryShippedDeniedAttributeIsRefusedTerminallyWithoutAnyWrite(): void
    {
        $shipped = $this->shippedDenylist();
        $this->assertTrue($shipped !== [], 'shipped denylist must not be empty');

        foreach ($shipped as $deniedCode) {
            $productAction = $this->fakeProductAction();
            $attributeRepo = $this->fakeAttributeRepository();
            $action = new SetAttribute($productAction, $attributeRepo, $shipped);

            $result = $action->execute($this->ctx(), ['attribute_code' => $deniedCode, 'value' => 'x']);

            $this->assertTrue($result->isFailure(), "denied code \"{$deniedCode}\" must fail");
            $this->assertFalse(
                $result->isRetryable(),
                "denied code \"{$deniedCode}\" must be a TERMINAL failure, never retried"
            );
            $this->assertStringContainsString('denylist', (string)$result->getError());
            $this->assertCount(0, $productAction->calls, "updateAttributes must never run for \"{$deniedCode}\"");
            $this->assertCount(
                0,
                $attributeRepo->lookedUp,
                'the denylist gate must fire before any EAV metadata lookup'
            );
        }
    }

    public function testDeniedCodeIsRefusedRegardlessOfCase(): void
    {
        foreach (['SKU', 'Sku', 'STATUS', 'Status'] as $variant) {
            $productAction = $this->fakeProductAction();
            $action = new SetAttribute($productAction, $this->fakeAttributeRepository(), $this->shippedDenylist());

            $result = $action->execute($this->ctx(), ['attribute_code' => $variant, 'value' => 'x']);

            $this->assertTrue($result->isFailure(), "case variant \"{$variant}\" must still be denied");
            $this->assertFalse($result->isRetryable());
            $this->assertStringContainsString('denylist', (string)$result->getError());
            $this->assertCount(0, $productAction->calls);
        }
    }

    public function testMalformedAttributeCodeSyntaxIsRefusedWithoutAnyWrite(): void
    {
        foreach (['erp-code', 'erp.code', 'erp code', '{{steps.x.attr}}', 'sku;--'] as $bad) {
            $productAction = $this->fakeProductAction();
            $attributeRepo = $this->fakeAttributeRepository();
            $action = new SetAttribute($productAction, $attributeRepo, $this->shippedDenylist());

            $result = $action->execute($this->ctx(), ['attribute_code' => $bad, 'value' => 'x']);

            $this->assertTrue($result->isFailure(), "malformed code \"{$bad}\" must fail");
            $this->assertFalse($result->isRetryable());
            $this->assertStringContainsString('Invalid attribute code', (string)$result->getError());
            $this->assertCount(0, $productAction->calls);
            $this->assertCount(0, $attributeRepo->lookedUp);
        }
    }

    public function testMissingAttributeCodeFailsWithoutAnyWrite(): void
    {
        $productAction = $this->fakeProductAction();
        $action = new SetAttribute($productAction, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->execute($this->ctx(), ['value' => 'x']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('attribute_code', (string)$result->getError());
        $this->assertCount(0, $productAction->calls);
    }

    public function testMissingValueFailsWithoutAnyWrite(): void
    {
        $productAction = $this->fakeProductAction();
        $action = new SetAttribute($productAction, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->execute($this->ctx(), ['attribute_code' => 'color']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('value', (string)$result->getError());
        $this->assertCount(0, $productAction->calls);
    }

    public function testUnknownAttributeIsATerminalFailureWithoutWrite(): void
    {
        $productAction = $this->fakeProductAction();
        $action = new SetAttribute(
            $productAction,
            $this->fakeAttributeRepository(attributeExists: false),
            $this->shippedDenylist()
        );

        $result = $action->execute($this->ctx(), ['attribute_code' => 'color', 'value' => 'red']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('does not exist', (string)$result->getError());
        $this->assertCount(0, $productAction->calls);
    }

    public function testWritableAttributeIsUpdatedScopedToTheExecutionStore(): void
    {
        $productAction = $this->fakeProductAction();
        $action = new SetAttribute($productAction, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->execute($this->ctx(entityId: 42, storeId: 3), [
            'attribute_code' => 'color',
            'value' => 'red',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $productAction->calls);
        $this->assertSame([[42], ['color' => 'red'], 3], $productAction->calls[0]);
        $this->assertSame(3, $result->getOutput()['store_id']);
        $this->assertSame('color', $result->getOutput()['attribute_code']);
    }

    public function testSimulateEnforcesTheDenylistToo(): void
    {
        $productAction = $this->fakeProductAction();
        $action = new SetAttribute($productAction, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->simulate($this->ctx(), ['attribute_code' => 'sku', 'value' => 'NEW-SKU']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('denylist', (string)$result->getError());
        $this->assertCount(0, $productAction->calls);
    }
}
