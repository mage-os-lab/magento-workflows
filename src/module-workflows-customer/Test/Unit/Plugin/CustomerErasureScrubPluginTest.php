<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Plugin;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
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
            new FakeDeletedCustomer(42, 'john.doe@example.com')
        );

        $this->assertTrue($result);
        $this->assertSame([[42, 'john.doe@example.com']], $scrubber->calls);
    }

    public function testAfterDeleteWithAFalseResultScrubsNothing(): void
    {
        $scrubber = new RecordingScrubber();
        $plugin = new CustomerErasureScrubPlugin($scrubber, new NullLogger());

        $plugin->afterDelete($this->repository(null), false, new FakeDeletedCustomer(42, 'a@b.test'));

        $this->assertSame([], $scrubber->calls);
    }

    public function testDeleteByIdCapturesTheEmailBeforeTheRowIsGone(): void
    {
        $scrubber = new RecordingScrubber();
        $plugin = new CustomerErasureScrubPlugin($scrubber, new NullLogger());
        $repository = $this->repository(new FakeDeletedCustomer(42, 'john.doe@example.com'));

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
            new FakeDeletedCustomer(42, 'john.doe@example.com')
        );

        $this->assertTrue($result, 'The deletion already committed; the scrub failure must not mask it');
        $this->assertCount(1, $logger->errors);
        $this->assertStringContainsString('customer 42', $logger->errors[0]);
    }

    private function repository(?FakeDeletedCustomer $customer): CustomerRepositoryInterface
    {
        return new class ($customer) implements CustomerRepositoryInterface {
            /** @var string[] */
            public array $log = [];

            public function __construct(public ?FakeDeletedCustomer $customer)
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
}

class RecordingScrubber extends ExecutionPiiScrubber
{
    /** @var array<int, array{0: int, 1: string}> */
    public array $calls = [];

    public bool $throw = false;

    public function __construct()
    {
        parent::__construct(new ResourceConnection(), new NullLogger());
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
