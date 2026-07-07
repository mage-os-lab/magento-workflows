<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Engine;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Notification\NotifierInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Engine\CircuitBreaker;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Per-workflow consecutive-failure circuit breaker (docs/07-actions.md):
 * N consecutive step failures auto-pauses the workflow (status = suspended)
 * and raises an admin notification; a success before the threshold resets the
 * counter; and the breaker itself must never crash the executing consumer —
 * a failing suspend write or notifier is logged, not rethrown.
 */
class CircuitBreakerTest extends TestCase
{
    private const WORKFLOW_ID = 42;

    private BreakerFakeCache $cache;

    private BreakerRecordingNotifier $notifier;

    private BreakerFakeWorkflowRepository $repository;

    private WorkflowStub $workflow;

    public function setUp(): void
    {
        $this->cache = new BreakerFakeCache();
        $this->notifier = new BreakerRecordingNotifier();
        $this->workflow = (new WorkflowStub(self::WORKFLOW_ID))
            ->setStatus(WorkflowInterface::STATUS_ENABLED)
            ->setName('Refund follow-up');
        $this->repository = new BreakerFakeWorkflowRepository($this->workflow);
    }

    private function breaker(int $threshold = 3): CircuitBreaker
    {
        $config = $threshold > 0
            ? new StubScopeConfig([CircuitBreaker::CONFIG_THRESHOLD => $threshold])
            : new StubScopeConfig();

        return new CircuitBreaker(
            $this->cache,
            $config,
            $this->repository,
            $this->notifier,
            new NullLogger()
        );
    }

    public function testFailuresBelowTheThresholdDoNotSuspend(): void
    {
        $breaker = $this->breaker(3);

        $breaker->recordFailure(self::WORKFLOW_ID);
        $breaker->recordFailure(self::WORKFLOW_ID);

        $this->assertSame(WorkflowInterface::STATUS_ENABLED, $this->workflow->getStatus());
        $this->assertCount(0, $this->notifier->critical);
        $this->assertSame(2, $breaker->getFailureCount(self::WORKFLOW_ID));
    }

    public function testThresholdConsecutiveFailuresSuspendTheWorkflow(): void
    {
        $breaker = $this->breaker(3);

        $breaker->recordFailure(self::WORKFLOW_ID);
        $breaker->recordFailure(self::WORKFLOW_ID);
        $breaker->recordFailure(self::WORKFLOW_ID);

        $this->assertSame(WorkflowInterface::STATUS_SUSPENDED, $this->workflow->getStatus());
        $this->assertCount(1, $this->repository->saved);
    }

    public function testTrippingNotifiesTheAdminWithTheWorkflowName(): void
    {
        $breaker = $this->breaker(3);

        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure(self::WORKFLOW_ID);
        }

        $this->assertCount(1, $this->notifier->critical);
        $this->assertStringContainsString('Refund follow-up', $this->notifier->critical[0]['title']);
        $this->assertStringContainsString('suspended', $this->notifier->critical[0]['description']);
    }

    public function testTripResetsTheFailureCounter(): void
    {
        // A re-enabled workflow starts with a clean slate, not one failure
        // away from instant re-suspension.
        $breaker = $this->breaker(3);

        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure(self::WORKFLOW_ID);
        }

        $this->assertSame(0, $breaker->getFailureCount(self::WORKFLOW_ID));
    }

    public function testSuccessBeforeTheThresholdResetsTheCounter(): void
    {
        // "Consecutive" means consecutive: 2 failures + success + 2 failures
        // never reaches a threshold of 3.
        $breaker = $this->breaker(3);

        $breaker->recordFailure(self::WORKFLOW_ID);
        $breaker->recordFailure(self::WORKFLOW_ID);
        $breaker->recordSuccess(self::WORKFLOW_ID);

        $this->assertSame(0, $breaker->getFailureCount(self::WORKFLOW_ID));

        $breaker->recordFailure(self::WORKFLOW_ID);
        $breaker->recordFailure(self::WORKFLOW_ID);

        $this->assertSame(WorkflowInterface::STATUS_ENABLED, $this->workflow->getStatus());
        $this->assertCount(0, $this->notifier->critical);
        $this->assertSame(2, $breaker->getFailureCount(self::WORKFLOW_ID));
    }

    public function testDefaultThresholdIsTenWhenUnconfigured(): void
    {
        $breaker = $this->breaker(0); // empty scope config => default applies

        for ($i = 0; $i < 9; $i++) {
            $breaker->recordFailure(self::WORKFLOW_ID);
        }
        $this->assertSame(WorkflowInterface::STATUS_ENABLED, $this->workflow->getStatus());

        $breaker->recordFailure(self::WORKFLOW_ID);
        $this->assertSame(WorkflowInterface::STATUS_SUSPENDED, $this->workflow->getStatus());
    }

    public function testFailingSuspendWriteDoesNotCrashTheCallerAndStillNotifies(): void
    {
        // The breaker runs inside the executing consumer; a DB error while
        // suspending must not take the step-failure handling down with it.
        $this->repository->throwOnSave = true;
        $breaker = $this->breaker(3);

        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure(self::WORKFLOW_ID);
        }

        // No exception escaped, and the admin still learned about the trip.
        $this->assertCount(1, $this->notifier->critical);
    }

    public function testFailingWorkflowLoadDoesNotCrashTheCaller(): void
    {
        $this->repository->throwOnGetById = true;
        $breaker = $this->breaker(3);

        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure(self::WORKFLOW_ID);
        }

        $this->assertCount(1, $this->notifier->critical);
        // Falls back to the id when the name is unloadable.
        $this->assertStringContainsString((string) self::WORKFLOW_ID, $this->notifier->critical[0]['title']);
    }

    public function testFailingNotifierDoesNotCrashTheCaller(): void
    {
        $this->notifier->throwOnCritical = true;
        $breaker = $this->breaker(3);

        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure(self::WORKFLOW_ID);
        }

        // The suspension itself still landed.
        $this->assertSame(WorkflowInterface::STATUS_SUSPENDED, $this->workflow->getStatus());
    }

    public function testAlreadySuspendedWorkflowIsNotSavedAgain(): void
    {
        $this->workflow->setStatus(WorkflowInterface::STATUS_SUSPENDED);
        $breaker = $this->breaker(3);

        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure(self::WORKFLOW_ID);
        }

        $this->assertCount(0, $this->repository->saved);
        $this->assertSame(WorkflowInterface::STATUS_SUSPENDED, $this->workflow->getStatus());
    }
}

/**
 * In-memory CacheInterface: identifier => string, false on miss (untyped
 * signatures matching the real Magento interface, like the admin-extension
 * FakeCache stub).
 */
class BreakerFakeCache implements CacheInterface
{
    /** @var array<string, string> */
    public array $data = [];

    public function getFrontend()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function load($identifier)
    {
        return $this->data[$identifier] ?? false;
    }

    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->data[$identifier] = $data;
        return true;
    }

    public function remove($identifier)
    {
        unset($this->data[$identifier]);
        return true;
    }

    public function clean($tags = [])
    {
        return true;
    }
}

/**
 * Records addCritical calls; optionally throws to simulate a broken inbox.
 */
class BreakerRecordingNotifier implements NotifierInterface
{
    /** @var array<int, array{title: string, description: string}> */
    public array $critical = [];

    public bool $throwOnCritical = false;

    public function add($severity, $title, $description, $url = '', $isInternal = true)
    {
        return $this;
    }

    public function addCritical($title, $description, $url = '')
    {
        if ($this->throwOnCritical) {
            throw new \RuntimeException('inbox table gone');
        }
        $this->critical[] = ['title' => (string) $title, 'description' => (string) $description];
        return $this;
    }

    public function addMajor($title, $description, $url = '')
    {
        return $this;
    }

    public function addMinor($title, $description, $url = '')
    {
        return $this;
    }

    public function addNotice($title, $description, $url = '')
    {
        return $this;
    }

    public function remove($notificationId)
    {
        return $this;
    }

    public function markAsRead($notificationId)
    {
        return $this;
    }
}

/**
 * Single-workflow repository fake with switchable failure modes for the
 * suspend write path.
 */
class BreakerFakeWorkflowRepository implements WorkflowRepositoryInterface
{
    /** @var WorkflowInterface[] */
    public array $saved = [];

    public bool $throwOnSave = false;

    public bool $throwOnGetById = false;

    public function __construct(private readonly WorkflowInterface $workflow)
    {
    }

    public function save(WorkflowInterface $workflow): WorkflowInterface
    {
        if ($this->throwOnSave) {
            throw new \RuntimeException('deadlock while suspending');
        }
        $this->saved[] = $workflow;
        return $workflow;
    }

    public function getById(int $workflowId): WorkflowInterface
    {
        if ($this->throwOnGetById) {
            throw new \RuntimeException('workflow table unavailable');
        }
        return $this->workflow;
    }

    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    ): \Magento\Framework\Api\SearchResultsInterface {
        throw new \RuntimeException('not used');
    }

    public function delete(WorkflowInterface $workflow): bool
    {
        return true;
    }

    public function deleteById(int $workflowId): bool
    {
        return true;
    }
}
