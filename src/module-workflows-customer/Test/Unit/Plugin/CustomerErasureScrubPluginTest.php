<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Plugin;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\WorkflowsCustomer\Model\ExecutionPiiScrubber;
use MageOS\WorkflowsCustomer\Plugin\CustomerErasureScrubPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * GDPR erasure hook wiring (docs/10-security.md "PII containment" #3): the
 * scrub runs exactly once after a SUCCESSFUL repository deletion, with the
 * customer's id and email; deleteById captures the email BEFORE the row is
 * gone; a failed/refused deletion scrubs nothing; and a scrub failure is
 * logged loudly but never breaks the already-committed deletion.
 */
class CustomerErasureScrubPluginTest extends TestCase
{
    public function testAfterDeleteScrubsWithTheDeletedCustomersIdAndEmail(): void
    {
        $scrubber = new RecordingScrubber();
        $plugin = new CustomerErasureScrubPlugin($scrubber, new NullLogger());

        $result = $plugin->afterDelete(
            $this->repository(null),
            true,
            $this->customer(42, 'john.doe@example.com')
        );

        $this->assertTrue($result);
        $this->assertSame([[42, 'john.doe@example.com']], $scrubber->calls);
    }

    public function testAfterDeleteWithAFalseResultScrubsNothing(): void
    {
        $scrubber = new RecordingScrubber();
        $plugin = new CustomerErasureScrubPlugin($scrubber, new NullLogger());

        $plugin->afterDelete($this->repository(null), false, $this->customer(42, 'a@b.test'));

        $this->assertSame([], $scrubber->calls);
    }

    public function testDeleteByIdCapturesTheEmailBeforeTheRowIsGone(): void
    {
        $scrubber = new RecordingScrubber();
        $plugin = new CustomerErasureScrubPlugin($scrubber, new NullLogger());
        $repository = $this->repository($this->customer(42, 'john.doe@example.com'));

        $log = [];
        $result = $plugin->aroundDeleteById(
            $repository,
            function ($customerId) use (&$log, $repository): bool {
                $log[] = 'delete';
                // After deletion the row is unloadable — the plugin must
                // already hold the email at this point.
                $repository->customer = null;
                return true;
            },
            42
        );

        $this->assertTrue($result);
        $this->assertSame(['getById', 'delete'], array_merge($repository->log, $log));
        $this->assertSame([[42, 'john.doe@example.com']], $scrubber->calls);
    }

    public function testDeleteByIdOfAMissingCustomerPropagatesTheCanonicalExceptionAndScrubsNothing(): void
    {
        $scrubber = new RecordingScrubber();
        $plugin = new CustomerErasureScrubPlugin($scrubber, new NullLogger());

        $this->expectException(NoSuchEntityException::class);
        try {
            $plugin->aroundDeleteById(
                $this->repository(null),
                function ($customerId): bool {
                    throw new NoSuchEntityException(__('No such entity'));
                },
                999
            );
        } finally {
            $this->assertSame([], $scrubber->calls);
        }
    }

    public function testDeleteByIdStillScrubsByIdWhenTheEmailCannotBeCaptured(): void
    {
        // Pre-delete load failing must not veto the erasure hook: the
        // id-rooted scrub pass needs no email.
        $scrubber = new RecordingScrubber();
        $plugin = new CustomerErasureScrubPlugin($scrubber, new NullLogger());

        $result = $plugin->aroundDeleteById($this->repository(null), fn ($id): bool => true, 42);

        $this->assertTrue($result);
        $this->assertSame([[42, '']], $scrubber->calls);
    }

    public function testAScrubFailureIsLoggedButNeverBreaksTheCommittedDeletion(): void
    {
        $scrubber = new RecordingScrubber();
        $scrubber->throw = true;
        $logger = new ErrorSpyLogger();
        $plugin = new CustomerErasureScrubPlugin($scrubber, $logger);

        $result = $plugin->afterDelete(
            $this->repository(null),
            true,
            $this->customer(42, 'john.doe@example.com')
        );

        $this->assertTrue($result, 'The deletion already committed; the scrub failure must not mask it');
        $this->assertCount(1, $logger->errors);
        $this->assertStringContainsString('customer 42', $logger->errors[0]);
    }

    /**
     * A deleted-customer stand-in. The plugin reads only id and email, but the
     * fake must implement the FULL real CustomerInterface surface: the
     * standalone shim declares just the two getters, while a real install
     * declares ~48 methods and fatals on a partial implementation. The
     * standalone runner's TestCase has no mock generator, so the fake is a
     * literal class (extra public methods are harmless against the shim).
     */
    private function customer(int $id, string $email): CustomerInterface
    {
        return new FakeDeletedCustomer($id, $email);
    }

    private function repository(?CustomerInterface $customer): CustomerRepositoryInterface
    {
        return new class ($customer) implements CustomerRepositoryInterface {
            /** @var string[] */
            public array $log = [];

            public function __construct(public ?CustomerInterface $customer)
            {
            }

            public function getById($customerId)
            {
                $this->log[] = 'getById';
                if ($this->customer === null) {
                    throw new NoSuchEntityException(__('No such entity'));
                }
                return $this->customer;
            }

            public function save($customer, $passwordHash = null)
            {
                throw new \LogicException('save() is not part of the erasure flow');
            }

            public function get($email, $websiteId = null)
            {
                throw new \LogicException('get() is not part of the erasure flow');
            }

            public function getList($searchCriteria)
            {
                throw new \LogicException('getList() is not part of the erasure flow');
            }

            public function delete($customer)
            {
                throw new \LogicException('the plugin wraps delete(); the fake never receives it');
            }

            public function deleteById($customerId)
            {
                throw new \LogicException('the plugin wraps deleteById(); the fake never receives it');
            }
        };
    }
}

/**
 * Implements the complete real 2.4.x CustomerInterface (including the
 * custom-attribute methods it inherits), untyped signatures matching core's
 * published API, so loading it never fatals on a full install. Only getId()
 * and getEmail() carry data — everything else is inert.
 *
 * phpcs:disable Magento2.Annotation -- inert stubs, documented above.
 */
class FakeDeletedCustomer implements CustomerInterface
{
    public function __construct(
        private readonly int $id,
        private readonly string $email
    ) {
    }

    public function getId()
    {
        return $this->id;
    }

    public function getEmail()
    {
        return $this->email;
    }

    // @codingStandardsIgnoreStart -- inert interface-completeness stubs.
    public function setId($id)
    {
        return $this;
    }

    public function getGroupId()
    {
        return null;
    }

    public function setGroupId($groupId)
    {
        return $this;
    }

    public function getDefaultBilling()
    {
        return null;
    }

    public function setDefaultBilling($defaultBilling)
    {
        return $this;
    }

    public function getDefaultShipping()
    {
        return null;
    }

    public function setDefaultShipping($defaultShipping)
    {
        return $this;
    }

    public function getConfirmation()
    {
        return null;
    }

    public function setConfirmation($confirmation)
    {
        return $this;
    }

    public function getCreatedAt()
    {
        return null;
    }

    public function setCreatedAt($createdAt)
    {
        return $this;
    }

    public function getUpdatedAt()
    {
        return null;
    }

    public function setUpdatedAt($updatedAt)
    {
        return $this;
    }

    public function getCreatedIn()
    {
        return null;
    }

    public function setCreatedIn($createdIn)
    {
        return $this;
    }

    public function getDob()
    {
        return null;
    }

    public function setDob($dob)
    {
        return $this;
    }

    public function setEmail($email)
    {
        return $this;
    }

    public function getFirstname()
    {
        return null;
    }

    public function setFirstname($firstname)
    {
        return $this;
    }

    public function getLastname()
    {
        return null;
    }

    public function setLastname($lastname)
    {
        return $this;
    }

    public function getMiddlename()
    {
        return null;
    }

    public function setMiddlename($middlename)
    {
        return $this;
    }

    public function getPrefix()
    {
        return null;
    }

    public function setPrefix($prefix)
    {
        return $this;
    }

    public function getSuffix()
    {
        return null;
    }

    public function setSuffix($suffix)
    {
        return $this;
    }

    public function getGender()
    {
        return null;
    }

    public function setGender($gender)
    {
        return $this;
    }

    public function getStoreId()
    {
        return null;
    }

    public function setStoreId($storeId)
    {
        return $this;
    }

    public function getTaxvat()
    {
        return null;
    }

    public function setTaxvat($taxvat)
    {
        return $this;
    }

    public function getWebsiteId()
    {
        return null;
    }

    public function setWebsiteId($websiteId)
    {
        return $this;
    }

    public function getAddresses()
    {
        return null;
    }

    public function setAddresses(?array $addresses = null)
    {
        return $this;
    }

    public function getDisableAutoGroupChange()
    {
        return null;
    }

    public function setDisableAutoGroupChange($disableAutoGroupChange)
    {
        return $this;
    }

    public function getExtensionAttributes()
    {
        return null;
    }

    public function setExtensionAttributes(
        \Magento\Customer\Api\Data\CustomerExtensionInterface $extensionAttributes
    ) {
        return $this;
    }

    public function getCustomAttribute($attributeCode)
    {
        return null;
    }

    public function setCustomAttribute($attributeCode, $attributeValue)
    {
        return $this;
    }

    public function getCustomAttributes()
    {
        return [];
    }

    public function setCustomAttributes(array $attributes)
    {
        return $this;
    }
    // @codingStandardsIgnoreEnd
}

class RecordingScrubber extends ExecutionPiiScrubber
{
    /** @var array<int, array{0: int, 1: string}> */
    public array $calls = [];

    public bool $throw = false;

    public function __construct()
    {
        // Deliberately NO parent::__construct(): ExecutionPiiScrubber takes a
        // real Magento\Framework\App\ResourceConnection, and on a full install
        // that class needs three DI collaborators
        // (ConfigInterface/ConnectionFactoryInterface/DeploymentConfig) — `new
        // ResourceConnection()` is an ArgumentCountError there, and only the
        // standalone runner's shim tolerates it. Bypassing the parent
        // constructor is safe because scrubForCustomer() below is the only
        // method this double is ever asked for and it never reads the parent's
        // (therefore uninitialized) properties.
    }

    public function scrubForCustomer(int $customerId, string $email): void
    {
        if ($this->throw) {
            throw new \RuntimeException('connection lost');
        }
        $this->calls[] = [$customerId, $email];
    }
}

class ErrorSpyLogger extends NullLogger
{
    /** @var string[] */
    public array $errors = [];

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->errors[] = (string) $message;
    }
}
