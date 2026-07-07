<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Engine;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\MessageQueue\PublisherInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Engine\Dispatcher;
use MageOS\Workflows\Model\Suppression\WorkflowSuppression;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\StubStoreManager;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Test\Unit\Stub\WorkflowStub;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Dispatch-layer guards (docs/07-actions.md "Loop prevention, storms, and
 * circuit breaking", docs/08-execution-model.md):
 *
 *  - Loop guard: chain_depth beyond loop_guard_depth skips dispatch and logs
 *    "loop_suppressed"; a depth AT the limit still dispatches.
 *  - Atomic debounce: the unique-key INSERT on (workflow_id, entity_id,
 *    time_bucket) treats duplicate-key as debounced — cleanly, whether the
 *    driver surfaces AlreadyExistsException or a raw "Duplicate entry" error.
 *    Any other DB error still escapes (a broken DB must not look like a
 *    debounce).
 *  - Website scope: an entity whose store maps to a website outside the
 *    workflow's website_ids is skipped before the debounce write / execution
 *    creation (condition evaluation happens later, in the async executor).
 *  - Status gate: disabled/suspended workflows never dispatch; shadow-status
 *    workflows still dispatch (side effects are simulated at execute time,
 *    keyed off workflow status — the dispatcher must let them through).
 */
class DispatcherGuardsTest extends TestCase
{
    private WorkflowSuppression $suppression;

    private GuardRecordingLogger $logger;

    private GuardFakeConnection $connection;

    /** @var object publisher fake exposing $published */
    private object $publisherFake;

    /** @var object execution repository fake exposing $saved */
    private object $executionRepositoryFake;

    public function setUp(): void
    {
        // The generated WorkflowExecutionInterfaceFactory stand-in is declared
        // inside DispatcherTest.php (bracketed multi-namespace technique, see
        // the NOTE there); force that file to load before we type against it.
        \class_exists(\MageOS\Workflows\Test\Unit\Model\Engine\DispatcherTest::class);

        $this->suppression = new WorkflowSuppression(new StubScopeConfig());
        $this->logger = new GuardRecordingLogger();
        $this->connection = new GuardFakeConnection();
    }

    public function tearDown(): void
    {
        // Defensive: never let a leftover suppression depth leak into the
        // next test file (mirrors DispatcherTest's cleanup).
        while ($this->suppression->isSuppressed()) {
            WorkflowSuppression::restore();
        }
    }

    private function workflow(): WorkflowStub
    {
        return (new WorkflowStub(42))
            ->setStatus(WorkflowInterface::STATUS_ENABLED)
            ->setEntityType('sales_order')
            ->setName('Order follow-up')
            ->setLoopGuardDepth(1);
    }

    private function dispatcher(WorkflowStub $workflow, int $websiteOfEveryStore = 1): Dispatcher
    {
        $workflowRepository = new class ($workflow) implements WorkflowRepositoryInterface {
            public function __construct(private readonly WorkflowInterface $workflow)
            {
            }

            public function save(WorkflowInterface $workflow): WorkflowInterface
            {
                return $workflow;
            }

            public function getById(int $workflowId): WorkflowInterface
            {
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
        };

        $this->executionRepositoryFake = new class implements WorkflowExecutionRepositoryInterface {
            /** @var WorkflowExecutionInterface[] */
            public array $saved = [];

            public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
            {
                $this->saved[] = $execution;
                return $execution;
            }

            public function getById(int $executionId): WorkflowExecutionInterface
            {
                throw new \RuntimeException('not used');
            }

            public function getByUuid(string $uuid): WorkflowExecutionInterface
            {
                throw new \RuntimeException('not used');
            }

            public function getList(
                \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
            ): \Magento\Framework\Api\SearchResultsInterface {
                throw new \RuntimeException('not used');
            }
        };

        $this->publisherFake = new class implements PublisherInterface {
            /** @var array<int, array{topic: string, data: mixed}> */
            public array $published = [];

            public function publish($topicName, $data)
            {
                $this->published[] = ['topic' => $topicName, 'data' => $data];
                return null;
            }
        };

        return new Dispatcher(
            $workflowRepository,
            $this->executionRepositoryFake,
            new WorkflowExecutionInterfaceFactory(
                static fn (array $data = []): WorkflowExecutionInterface => new WorkflowExecutionStub()
            ),
            $this->suppression,
            new StubStoreManager($websiteOfEveryStore),
            new StubScopeConfig(),
            new GuardFakeResourceConnection($this->connection),
            $this->publisherFake,
            $this->logger
        );
    }

    // ------------------------------------------------------------------
    // Loop guard
    // ------------------------------------------------------------------

    public function testChainDepthBeyondLoopGuardDepthIsNotDispatched(): void
    {
        $dispatcher = $this->dispatcher($this->workflow());

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5], 'event', 2);

        $this->assertNull($execution);
        $this->assertCount(0, $this->executionRepositoryFake->saved);
        $this->assertCount(0, $this->publisherFake->published);
    }

    public function testLoopSuppressionProducesALoopSuppressedLogRecord(): void
    {
        $this->dispatcher($this->workflow())->dispatch(42, ['entity_id' => 5], 'event', 2);

        $this->assertStringContainsString('loop_suppressed', $this->logger->allMessages());
    }

    public function testChainDepthAtTheLoopGuardDepthStillDispatches(): void
    {
        // The guard is "exceeding loop_guard_depth" (docs/07): equal depth passes.
        $execution = $this->dispatcher($this->workflow())->dispatch(42, ['entity_id' => 5], 'event', 1);

        $this->assertNotNull($execution);
        $this->assertCount(1, $this->publisherFake->published);
        $this->assertStringNotContainsString('loop_suppressed', $this->logger->allMessages());
    }

    // ------------------------------------------------------------------
    // Atomic debounce
    // ------------------------------------------------------------------

    public function testDuplicateKeyExceptionOnDebounceInsertDebouncesCleanly(): void
    {
        $this->connection->insertException = new AlreadyExistsException();
        $dispatcher = $this->dispatcher($this->workflow());

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5]);

        $this->assertNull($execution);
        $this->assertCount(0, $this->executionRepositoryFake->saved);
        $this->assertCount(0, $this->publisherFake->published);
    }

    public function testRawDriverDuplicateEntryErrorAlsoDebouncesCleanly(): void
    {
        // Not every adapter wraps the unique-constraint violation nicely; the
        // debounce must still swallow a raw SQLSTATE 23000 "Duplicate entry".
        $this->connection->insertException = new \Exception(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 "
            . "Duplicate entry '42-5-29123456' for key 'MAGEOS_WORKFLOW_DEBOUNCE_WF_ENTITY_BUCKET'"
        );
        $dispatcher = $this->dispatcher($this->workflow());

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5]);

        $this->assertNull($execution);
        $this->assertCount(0, $this->executionRepositoryFake->saved);
        $this->assertCount(0, $this->publisherFake->published);
    }

    public function testNonDuplicateDatabaseErrorIsNotSwallowedAsADebounce(): void
    {
        $this->connection->insertException = new \RuntimeException('MySQL server has gone away');
        $dispatcher = $this->dispatcher($this->workflow());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MySQL server has gone away');
        $dispatcher->dispatch(42, ['entity_id' => 5]);
    }

    public function testSurvivingDispatchWritesOneDebounceRowAndPublishes(): void
    {
        $execution = $this->dispatcher($this->workflow())->dispatch(42, ['entity_id' => 5]);

        $this->assertNotNull($execution);
        $this->assertCount(1, $this->connection->inserted);
        $this->assertSame(42, $this->connection->inserted[0]['bind']['workflow_id']);
        $this->assertSame(5, $this->connection->inserted[0]['bind']['entity_id']);
        $this->assertArrayHasKey('time_bucket', $this->connection->inserted[0]['bind']);
        $this->assertCount(1, $this->publisherFake->published);
        $this->assertSame(Dispatcher::TOPIC_EXECUTE, $this->publisherFake->published[0]['topic']);
    }

    // ------------------------------------------------------------------
    // Website scope
    // ------------------------------------------------------------------

    public function testEntityOutsideTheWorkflowWebsitesIsSkippedBeforeDebounce(): void
    {
        $workflow = $this->workflow()->setWebsiteIds([2]);
        // Every store resolves to website 1; workflow is scoped to website 2.
        $dispatcher = $this->dispatcher($workflow, 1);

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5, 'store_id' => 3]);

        $this->assertNull($execution);
        // Skipped BEFORE the debounce write and execution creation: no
        // debounce row was even attempted, nothing saved, nothing published.
        $this->assertCount(0, $this->connection->inserted);
        $this->assertCount(0, $this->executionRepositoryFake->saved);
        $this->assertCount(0, $this->publisherFake->published);
    }

    public function testEntityInsideTheWorkflowWebsitesDispatches(): void
    {
        $workflow = $this->workflow()->setWebsiteIds([1, 2]);
        $dispatcher = $this->dispatcher($workflow, 2);

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5, 'store_id' => 3]);

        $this->assertNotNull($execution);
        $this->assertCount(1, $this->publisherFake->published);
    }

    public function testPayloadWithoutStoreIdSkipsTheScopeCheck(): void
    {
        // Documented: no store_id in the payload = the website check is skipped.
        $workflow = $this->workflow()->setWebsiteIds([2]);
        $dispatcher = $this->dispatcher($workflow, 1);

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5]);

        $this->assertNotNull($execution);
    }

    // ------------------------------------------------------------------
    // Status gate
    // ------------------------------------------------------------------

    public function testDisabledWorkflowNeverDispatches(): void
    {
        $workflow = $this->workflow()->setStatus(WorkflowInterface::STATUS_DISABLED);
        $dispatcher = $this->dispatcher($workflow);

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5]);

        $this->assertNull($execution);
        $this->assertCount(0, $this->connection->inserted);
        $this->assertCount(0, $this->executionRepositoryFake->saved);
        $this->assertCount(0, $this->publisherFake->published);
    }

    public function testSuspendedWorkflowNeverDispatches(): void
    {
        // The circuit breaker parks workflows as suspended (docs/07); the
        // dispatcher must honor that status until an explicit re-enable.
        $workflow = $this->workflow()->setStatus(WorkflowInterface::STATUS_SUSPENDED);
        $dispatcher = $this->dispatcher($workflow);

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5]);

        $this->assertNull($execution);
        $this->assertCount(0, $this->executionRepositoryFake->saved);
    }

    public function testShadowWorkflowStillDispatchesForSimulation(): void
    {
        // Shadow status simulates side effects at execute time (keyed off the
        // workflow's status in the Executor); dispatch must let it through so
        // the simulated run exists at all.
        $workflow = $this->workflow()->setStatus(WorkflowInterface::STATUS_SHADOW);
        $dispatcher = $this->dispatcher($workflow);

        $execution = $dispatcher->dispatch(42, ['entity_id' => 5]);

        $this->assertNotNull($execution);
        $this->assertSame(WorkflowExecutionInterface::STATUS_PENDING, $execution->getStatus());
        $this->assertCount(1, $this->publisherFake->published);
        $this->assertSame(Dispatcher::TOPIC_EXECUTE, $this->publisherFake->published[0]['topic']);
    }
}

/**
 * Debounce-table connection fake: records inserts, optionally throws the
 * configured exception to simulate a unique-key collision (or any DB error).
 */
class GuardFakeConnection
{
    /** @var array<int, array{table: string, bind: array}> */
    public array $inserted = [];

    public ?\Exception $insertException = null;

    public function insert($table, array $bind)
    {
        if ($this->insertException !== null) {
            throw $this->insertException;
        }
        $this->inserted[] = ['table' => (string) $table, 'bind' => $bind];
        return 1;
    }
}

class GuardFakeResourceConnection extends ResourceConnection
{
    public function __construct(private readonly GuardFakeConnection $connection)
    {
    }

    public function getConnection($resourceName = self::DEFAULT_CONNECTION)
    {
        return $this->connection;
    }

    public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION)
    {
        return (string) $modelEntity;
    }
}

/**
 * Records every log call as "level: message" so tests can pin documented log
 * markers (e.g. "loop_suppressed") without caring about the exact level API.
 */
class GuardRecordingLogger implements LoggerInterface
{
    /** @var array<int, string> */
    public array $records = [];

    public function allMessages(): string
    {
        return implode("\n", $this->records);
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'emergency: ' . $message;
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'alert: ' . $message;
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'critical: ' . $message;
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'error: ' . $message;
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'warning: ' . $message;
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'notice: ' . $message;
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'info: ' . $message;
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'debug: ' . $message;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = $level . ': ' . $message;
    }
}
