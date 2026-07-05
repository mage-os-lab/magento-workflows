<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Plugin\Notification;

use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsApprovals\Model\GateConfigReader;
use MageOS\WorkflowsApprovals\Model\Notification\ParkNotifier;
use MageOS\WorkflowsApprovals\Plugin\Notification\NotifyOnTaskCreation;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeWorkflowExecutionRepository;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\InMemoryConnection;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The park-notification hook (docs/discovery/approval-gate.md §6): fires on
 * genuine task creation, but NOT on an idempotent re-attach to an already-open
 * task (a redelivered park calling createTask() again must not re-notify).
 */
class NotifyOnTaskCreationTest extends TestCase
{
    private function plugin(InMemoryConnection $connection, RecordingParkNotifier $notifier): NotifyOnTaskCreation
    {
        $executionRepository = new FakeWorkflowExecutionRepository();
        return new NotifyOnTaskCreation(
            new FakeResourceConnection($connection),
            new GateConfigReader($executionRepository),
            $notifier,
            new NullLogger()
        );
    }

    public function testNotifiesOnGenuineCreation(): void
    {
        $connection = new InMemoryConnection();
        $notifier = new RecordingParkNotifier();
        $plugin = $this->plugin($connection, $notifier);

        $subject = new class implements ApprovalTaskManagerInterface {
            public function createTask($execution, string $stepKey, string $title, string $instructions, string $dueAt, ?string $assigneeRole): string
            {
                return 'new-uuid';
            }

            public function expireTask(int $executionId, string $stepKey): void
            {
            }

            public function orphanTasks(int $executionId): void
            {
            }
        };

        $uuid = $plugin->aroundCreateTask(
            $subject,
            $subject->createTask(...),
            (new WorkflowExecutionStub())->setExecutionId(1),
            'gate',
            'Approve me',
            'Instructions',
            '2026-01-01 00:00:00',
            null
        );

        $this->assertSame('new-uuid', $uuid);
        $this->assertCount(1, $notifier->calls);
        $this->assertSame('new-uuid', $notifier->calls[0]['uuid']);
    }

    public function testDoesNotRenotifyOnIdempotentReattach(): void
    {
        $connection = new InMemoryConnection();
        // A task for (execution 1, "gate") already exists — the redelivery case.
        $connection->seedApproval(['uuid' => 'existing-uuid', 'execution_id' => 1, 'step_key' => 'gate', 'status' => 'open']);
        $notifier = new RecordingParkNotifier();
        $plugin = $this->plugin($connection, $notifier);

        $subject = new class implements ApprovalTaskManagerInterface {
            public function createTask($execution, string $stepKey, string $title, string $instructions, string $dueAt, ?string $assigneeRole): string
            {
                return 'existing-uuid';
            }

            public function expireTask(int $executionId, string $stepKey): void
            {
            }

            public function orphanTasks(int $executionId): void
            {
            }
        };

        $plugin->aroundCreateTask(
            $subject,
            $subject->createTask(...),
            (new WorkflowExecutionStub())->setExecutionId(1),
            'gate',
            'Approve me',
            'Instructions',
            '2026-01-01 00:00:00',
            null
        );

        $this->assertSame([], $notifier->calls);
    }

    public function testNotificationSetupFailureNeverEscapes(): void
    {
        $connection = new InMemoryConnection();
        $notifier = new RecordingParkNotifier(true);
        $plugin = $this->plugin($connection, $notifier);

        $subject = new class implements ApprovalTaskManagerInterface {
            public function createTask($execution, string $stepKey, string $title, string $instructions, string $dueAt, ?string $assigneeRole): string
            {
                return 'new-uuid';
            }

            public function expireTask(int $executionId, string $stepKey): void
            {
            }

            public function orphanTasks(int $executionId): void
            {
            }
        };

        $uuid = $plugin->aroundCreateTask(
            $subject,
            $subject->createTask(...),
            (new WorkflowExecutionStub())->setExecutionId(1),
            'gate',
            'Approve me',
            '',
            '2026-01-01 00:00:00',
            null
        );

        $this->assertSame('new-uuid', $uuid);
    }
}

/**
 * @internal records notify() calls; can be forced to throw to exercise the
 * plugin's "never let notification failure fail the park" guard.
 */
class RecordingParkNotifier extends ParkNotifier
{
    public array $calls = [];

    public function __construct(private readonly bool $throws = false)
    {
    }

    public function notify(string $uuid, string $title, string $instructions, array $notifyEmails, int $storeId = 0): void
    {
        if ($this->throws) {
            throw new \RuntimeException('composition failed');
        }
        $this->calls[] = ['uuid' => $uuid, 'title' => $title];
    }
}
