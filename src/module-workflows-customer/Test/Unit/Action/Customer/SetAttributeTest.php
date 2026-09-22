<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsCustomer\Action\Customer\SetAttribute;
use PHPUnit\Framework\TestCase;

/**
 * Behavior tests for the customer.set_attribute security denylist
 * (docs/10-security.md, "Deferred privilege escalation"): system/ACL-relevant
 * attribute codes shipped in etc/di.xml are refused TERMINALLY, before any
 * EAV lookup and before any repository save. The shipped list is read from
 * the module's real etc/di.xml so config and behavior cannot drift apart.
 */
class SetAttributeTest extends TestCase
{
    /**
     * The denylist the module ships in etc/di.xml for this action,
     * parsed from the actual file.
     *
     * @return string[]
     */
    private function shippedDenylist(): array
    {
        $diXml = dirname(__DIR__, 4) . '/etc/di.xml';
        $xml = simplexml_load_file($diXml);
        $this->assertNotNull($xml === false ? null : $xml, 'etc/di.xml must parse');

        $items = $xml->xpath(
            '//type[@name="MageOS\WorkflowsCustomer\Action\Customer\SetAttribute"]'
            . '//argument[@name="deniedAttributes"]/item'
        );
        $codes = [];
        foreach ($items as $item) {
            $codes[] = (string)$item;
        }
        return $codes;
    }

    private function fakeCustomer(): object
    {
        return new class {
            /** @var array<string, mixed> */
            public array $attributes = [];
            public function setCustomAttribute($attributeCode, $attributeValue)
            {
                $this->attributes[$attributeCode] = $attributeValue;
                return $this;
            }
        };
    }

    private function fakeCustomerRepository(object $customer): CustomerRepositoryInterface
    {
        return new class($customer) implements CustomerRepositoryInterface {
            public int $saveCount = 0;
            public function __construct(public object $customer)
            {
            }
            public function getById($customerId)
            {
                return $this->customer;
            }
            public function save($customer, $passwordHash = null)
            {
                $this->saveCount++;
                return $customer;
            }
            public function get($email, $websiteId = null)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function getList($searchCriteria)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function delete($customer)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function deleteById($customerId)
            {
                throw new \BadMethodCallException(__METHOD__);
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

    private function ctx(int $entityId = 7): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: $entityId));
    }

    public function testShippedDenylistCoversTheDocumentedSystemAttributes(): void
    {
        $shipped = $this->shippedDenylist();
        foreach ([
            'password_hash',
            'rp_token',
            'rp_token_created_at',
            'is_active',
            'group_id',
            'website_id',
            'store_id',
            'confirmation',
            'email',
        ] as $documented) {
            $this->assertTrue(
                in_array($documented, $shipped, true),
                sprintf('di.xml denylist must ship "%s" (docs/10-security.md)', $documented)
            );
        }
    }

    public function testEveryShippedDeniedAttributeIsRefusedTerminallyWithoutAnySave(): void
    {
        $shipped = $this->shippedDenylist();
        $this->assertTrue($shipped !== [], 'shipped denylist must not be empty');

        foreach ($shipped as $deniedCode) {
            $customer = $this->fakeCustomer();
            $customerRepo = $this->fakeCustomerRepository($customer);
            $attributeRepo = $this->fakeAttributeRepository();
            $action = new SetAttribute($customerRepo, $attributeRepo, $shipped);

            $result = $action->execute($this->ctx(), ['attribute_code' => $deniedCode, 'value' => 'x']);

            $this->assertTrue($result->isFailure(), "denied code \"{$deniedCode}\" must fail");
            $this->assertFalse(
                $result->isRetryable(),
                "denied code \"{$deniedCode}\" must be a TERMINAL failure, never retried"
            );
            $this->assertStringContainsString('denylist', (string)$result->getError());
            $this->assertSame(0, $customerRepo->saveCount, "save() must never run for \"{$deniedCode}\"");
            $this->assertCount(0, $customer->attributes, 'no attribute may be written on the entity');
            $this->assertCount(
                0,
                $attributeRepo->lookedUp,
                'the denylist gate must fire before any EAV metadata lookup'
            );
        }
    }

    public function testDeniedCodeIsRefusedRegardlessOfCase(): void
    {
        foreach (['PASSWORD_HASH', 'Password_Hash', 'Email', 'EMAIL', 'Group_Id', 'IS_ACTIVE'] as $variant) {
            $customer = $this->fakeCustomer();
            $customerRepo = $this->fakeCustomerRepository($customer);
            $action = new SetAttribute($customerRepo, $this->fakeAttributeRepository(), $this->shippedDenylist());

            $result = $action->execute($this->ctx(), ['attribute_code' => $variant, 'value' => 'x']);

            $this->assertTrue($result->isFailure(), "case variant \"{$variant}\" must still be denied");
            $this->assertFalse($result->isRetryable());
            $this->assertStringContainsString('denylist', (string)$result->getError());
            $this->assertSame(0, $customerRepo->saveCount);
        }
    }

    public function testMalformedAttributeCodeSyntaxIsRefusedWithoutAnySave(): void
    {
        foreach (['profile-color', 'profile.color', 'profile color', 'code;drop', '{{steps.x.attr}}', 'päss'] as $bad) {
            $customer = $this->fakeCustomer();
            $customerRepo = $this->fakeCustomerRepository($customer);
            $attributeRepo = $this->fakeAttributeRepository();
            $action = new SetAttribute($customerRepo, $attributeRepo, $this->shippedDenylist());

            $result = $action->execute($this->ctx(), ['attribute_code' => $bad, 'value' => 'x']);

            $this->assertTrue($result->isFailure(), "malformed code \"{$bad}\" must fail");
            $this->assertFalse($result->isRetryable());
            $this->assertStringContainsString('Invalid attribute code', (string)$result->getError());
            $this->assertSame(0, $customerRepo->saveCount);
            $this->assertCount(0, $attributeRepo->lookedUp);
        }
    }

    public function testMissingAttributeCodeFailsWithoutAnySave(): void
    {
        $customerRepo = $this->fakeCustomerRepository($this->fakeCustomer());
        $action = new SetAttribute($customerRepo, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->execute($this->ctx(), ['value' => 'x']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('attribute_code', (string)$result->getError());
        $this->assertSame(0, $customerRepo->saveCount);
    }

    public function testMissingValueFailsWithoutAnySave(): void
    {
        $customerRepo = $this->fakeCustomerRepository($this->fakeCustomer());
        $action = new SetAttribute($customerRepo, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->execute($this->ctx(), ['attribute_code' => 'loyalty_tier']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('value', (string)$result->getError());
        $this->assertSame(0, $customerRepo->saveCount);
    }

    public function testUnknownAttributeIsATerminalFailureWithoutSave(): void
    {
        $customerRepo = $this->fakeCustomerRepository($this->fakeCustomer());
        $action = new SetAttribute(
            $customerRepo,
            $this->fakeAttributeRepository(attributeExists: false),
            $this->shippedDenylist()
        );

        $result = $action->execute($this->ctx(), ['attribute_code' => 'loyalty_tier', 'value' => 'gold']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('does not exist', (string)$result->getError());
        $this->assertSame(0, $customerRepo->saveCount);
    }

    public function testWritableCustomAttributeIsSaved(): void
    {
        $customer = $this->fakeCustomer();
        $customerRepo = $this->fakeCustomerRepository($customer);
        $action = new SetAttribute($customerRepo, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->execute($this->ctx(), ['attribute_code' => 'loyalty_tier', 'value' => 'gold']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $customerRepo->saveCount);
        $this->assertSame('gold', $customer->attributes['loyalty_tier']);
        $this->assertSame('loyalty_tier', $result->getOutput()['attribute_code']);
        $this->assertSame('gold', $result->getOutput()['value']);
    }

    public function testSimulateEnforcesTheDenylistToo(): void
    {
        $customerRepo = $this->fakeCustomerRepository($this->fakeCustomer());
        $action = new SetAttribute($customerRepo, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->simulate($this->ctx(), ['attribute_code' => 'email', 'value' => 'a@b.c']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('denylist', (string)$result->getError());
        $this->assertSame(0, $customerRepo->saveCount);
    }

    public function testSimulateOfWritableAttributeMutatesNothing(): void
    {
        $customer = $this->fakeCustomer();
        $customerRepo = $this->fakeCustomerRepository($customer);
        $action = new SetAttribute($customerRepo, $this->fakeAttributeRepository(), $this->shippedDenylist());

        $result = $action->simulate($this->ctx(), ['attribute_code' => 'loyalty_tier', 'value' => 'gold']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame(0, $customerRepo->saveCount);
        $this->assertCount(0, $customer->attributes);
    }

    public function testConfigFormAttributeCodeSearchesTheCustomerAttributeSource(): void
    {
        $action = new SetAttribute(
            $this->fakeCustomerRepository($this->fakeCustomer()),
            $this->fakeAttributeRepository(),
            $this->shippedDenylist()
        );

        $field = $action->getConfigForm()[0];

        $this->assertSame('attribute_code', $field['name']);
        $this->assertSame('select', $field['type']);
        $this->assertSame(['source' => 'customer_attributes', 'min_chars' => 0], $field['options_search']);
        $this->assertTrue($field['required']);
    }

    public function testIsDeniedExposesTheShippedDenylistToThePicker(): void
    {
        $action = new SetAttribute(
            $this->fakeCustomerRepository($this->fakeCustomer()),
            $this->fakeAttributeRepository(),
            $this->shippedDenylist()
        );

        foreach ($this->shippedDenylist() as $code) {
            $this->assertTrue($action->isDenied($code), "\"{$code}\" is on the shipped denylist");
            $this->assertTrue($action->isDenied(strtoupper($code)), 'the denylist is case-insensitive');
        }
        $this->assertFalse($action->isDenied('loyalty_tier'));
    }
}
