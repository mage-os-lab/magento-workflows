<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Engine;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Model\Engine\Dispatcher;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #6 (docs/20 §4): real mageos_workflow_debounce semantics (docs/08). A
 * first dispatch inserts and passes; a second within the window returns null
 * and creates no execution; keying is per (workflow, entity); advancing the
 * stored time_bucket past the window lets the next dispatch pass. The
 * two-connection race is pinned instead by asserting the unique key that makes
 * the insert atomic actually exists on (workflow_id, entity_id, time_bucket) —
 * the guard is a unique key, not a conditioned UPDATE, so its existence is the
 * contract (docs/20 §2.4).
 *
 * @magentoDbIsolation enabled
 * @magentoConfigFixture mageos_workflows/guards/debounce_window_seconds 60
 */
class DispatcherDebounceTest extends TestCase
{
    use WorkflowEngineTestTrait;

    private DispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = $this->om()->get(DispatcherInterface::class);
    }

    public function testFirstDispatchPassesSecondWithinWindowIsDebounced(): void
    {
        $id = $this->enabledWorkflowId();

        $first = $this->dispatcher->dispatch($id, ['entity_id' => 700, 'store_id' => 1]);
        $second = $this->dispatcher->dispatch($id, ['entity_id' => 700, 'store_id' => 1]);

        $this->assertNotNull($first, 'The first dispatch in the window passes');
        $this->assertNull($second, 'A second dispatch for the same (workflow, entity) within the window is debounced');
        $this->assertSame(1, $this->executionCount($id), 'Exactly one execution was created');
    }

    public function testDebounceIsKeyedPerWorkflowAndEntity(): void
    {
        $id = $this->enabledWorkflowId();
        $other = $this->enabledWorkflowId();

        $this->assertNotNull($this->dispatcher->dispatch($id, ['entity_id' => 710, 'store_id' => 1]));
        // Same workflow, different entity: not debounced.
        $this->assertNotNull($this->dispatcher->dispatch($id, ['entity_id' => 711, 'store_id' => 1]));
        // Different workflow, same entity: not debounced.
        $this->assertNotNull($this->dispatcher->dispatch($other, ['entity_id' => 710, 'store_id' => 1]));
    }

    public function testAdvancingThePersistedBucketPastTheWindowAllowsTheNextDispatch(): void
    {
        $id = $this->enabledWorkflowId();
        $this->assertNotNull($this->dispatcher->dispatch($id, ['entity_id' => 720, 'store_id' => 1]));

        // Rewind the persisted slot into a PRIOR window bucket. The next
        // dispatch computes the current bucket, which now differs, so the
        // unique key no longer collides.
        $connection = $this->db();
        $table = $this->table('mageos_workflow_debounce');
        $currentBucket = (string) $connection->fetchOne(
            $connection->select()->from($table, 'time_bucket')
                ->where('workflow_id = ?', $id)->where('entity_id = ?', 720)
        );
        $connection->update(
            $table,
            ['time_bucket' => (string) ((int) $currentBucket - 5)],
            ['workflow_id = ?' => $id, 'entity_id = ?' => 720]
        );

        $this->assertNotNull(
            $this->dispatcher->dispatch($id, ['entity_id' => 720, 'store_id' => 1]),
            'Once the window advances, the same (workflow, entity) dispatches again'
        );
        $this->assertSame(2, $this->executionCount($id));
    }

    public function testDebounceTableHasUniqueKeyOnWorkflowEntityBucket(): void
    {
        $connection = $this->db();
        $indexes = $connection->getIndexList($this->table('mageos_workflow_debounce'));

        $found = false;
        foreach ($indexes as $index) {
            $columns = array_map('strtolower', $index['COLUMNS_LIST'] ?? []);
            $type = strtolower((string) ($index['INDEX_TYPE'] ?? $index['TYPE'] ?? ''));
            if ($type === 'unique' && $columns === ['workflow_id', 'entity_id', 'time_bucket']) {
                $found = true;
                break;
            }
        }
        $this->assertTrue(
            $found,
            'The atomic debounce relies on a UNIQUE key over (workflow_id, entity_id, time_bucket)'
        );
    }

    /**
     * The config path the dispatcher reads for the window is the one exercised
     * by this suite's config fixture annotations.
     */
    public function testDebounceWindowConfigPathIsStable(): void
    {
        $this->assertSame('mageos_workflows/guards/debounce_window_seconds', Dispatcher::CONFIG_DEBOUNCE_WINDOW);
    }

    private function enabledWorkflowId(): int
    {
        return (int) $this->createWorkflow([
            'name' => 'debounce ' . uniqid('', true),
            'status' => WorkflowInterface::STATUS_ENABLED,
            'definition' => [
                'schema' => 1,
                'entry' => 's1',
                'steps' => [
                    's1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'd'], 'next' => null],
                ],
            ],
        ])->getWorkflowId();
    }

    private function executionCount(int $workflowId): int
    {
        $connection = $this->db();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->table('mageos_workflow_execution'), 'COUNT(*)')
                ->where('workflow_id = ?', $workflowId)
        );
    }
}
