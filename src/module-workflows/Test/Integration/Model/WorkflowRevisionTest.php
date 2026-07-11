<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Model;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\Workflow as WorkflowResource;
use MageOS\Workflows\Model\ResourceModel\WorkflowRevision as WorkflowRevisionResource;
use MageOS\Workflows\Model\WorkflowRepository;
use MageOS\Workflows\Test\Integration\_files\PoisonedWorkflowRevisionResource;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #3 (docs/20 §4): the repository's revision transaction (docs/03
 * versioning semantics). A definition-changing save archives the PRIOR
 * definition + conditions and bumps version by exactly 1, atomically; a
 * status-only save neither archives nor bumps; sequential definition saves
 * build a contiguous revision history. Lost-update behavior is pinned as
 * documented (version bump on save, no optimistic concurrency in docs/03 =>
 * last-write-wins).
 *
 * @magentoDbIsolation enabled
 */
class WorkflowRevisionTest extends TestCase
{
    use WorkflowEngineTestTrait;

    private WorkflowRepositoryInterface $repository;

    protected function setUp(): void
    {
        $this->repository = $this->om()->get(WorkflowRepositoryInterface::class);
    }

    public function testDefinitionChangeArchivesPriorAndBumpsVersionByOne(): void
    {
        $workflow = $this->createWorkflow([
            'name' => 'revision v1',
            'definition' => $this->linear('one'),
        ]);
        $id = (int) $workflow->getWorkflowId();
        $this->assertSame(1, $this->reloadWorkflow($id)->getVersion());

        $priorDefinition = $this->reloadWorkflow($id)->getDefinition();

        $edit = $this->reloadWorkflow($id);
        $edit->setDefinition(json_encode($this->linear('two'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->repository->save($edit);

        $reloaded = $this->reloadWorkflow($id);
        $this->assertSame(2, $reloaded->getVersion(), 'A definition change bumps version by exactly 1');
        $this->assertSame('two', json_decode($reloaded->getDefinition(), true)['steps']['s1']['config']['comment']);

        $revisions = $this->om()->get(WorkflowRevisionResource::class)->getRevisions($id);
        $this->assertCount(1, $revisions, 'Exactly the prior definition is archived');
        $this->assertSame(1, (int) $revisions[0]['version']);
        $this->assertSame(
            json_decode($priorDefinition, true),
            json_decode((string) $revisions[0]['definition'], true),
            'The archived revision holds the PRIOR definition (decoded-JSON equality; json column normalizes)'
        );
    }

    public function testStatusOnlySaveDoesNotArchiveAndKeepsVersion(): void
    {
        $workflow = $this->createWorkflow([
            'name' => 'status only',
            'definition' => $this->linear('one'),
            'status' => WorkflowInterface::STATUS_ENABLED,
        ]);
        $id = (int) $workflow->getWorkflowId();

        $edit = $this->reloadWorkflow($id);
        $edit->setStatus(WorkflowInterface::STATUS_DISABLED);
        $this->repository->save($edit);

        $reloaded = $this->reloadWorkflow($id);
        $this->assertSame(1, $reloaded->getVersion(), 'A status-only save must not bump version');
        $this->assertSame(WorkflowInterface::STATUS_DISABLED, $reloaded->getStatus());
        $this->assertSame([], $this->om()->get(WorkflowRevisionResource::class)->getRevisions($id));
    }

    public function testSequentialDefinitionSavesProduceContiguousHistory(): void
    {
        $workflow = $this->createWorkflow(['name' => 'history', 'definition' => $this->linear('v1')]);
        $id = (int) $workflow->getWorkflowId();

        foreach (['v2', 'v3', 'v4'] as $comment) {
            $edit = $this->reloadWorkflow($id);
            $edit->setDefinition(json_encode($this->linear($comment), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->repository->save($edit);
        }

        $this->assertSame(4, $this->reloadWorkflow($id)->getVersion());
        $revisions = $this->om()->get(WorkflowRevisionResource::class)->getRevisions($id);
        // getRevisions returns newest-first; versions 1..3 archived contiguously
        $versions = array_map(static fn (array $r): int => (int) $r['version'], $revisions);
        $this->assertSame([3, 2, 1], $versions, 'Revision history is contiguous with no gaps');
    }

    /**
     * The revision archive and the workflow update share one transaction: a
     * failing archive must roll the definition change and version bump back.
     */
    public function testFailedRevisionArchiveRollsTheWholeSaveBack(): void
    {
        $workflow = $this->createWorkflow(['name' => 'atomic', 'definition' => $this->linear('original')]);
        $id = (int) $workflow->getWorkflowId();

        $objectManager = Bootstrap::getObjectManager();
        $poisonedRepository = $objectManager->create(WorkflowRepository::class, [
            'revisionResource' => $objectManager->create(PoisonedWorkflowRevisionResource::class),
        ]);

        $edit = $this->reloadWorkflow($id);
        $edit->setDefinition(json_encode($this->linear('changed'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $poisonedRepository->save($edit);
            $this->fail('Expected CouldNotSaveException when the revision archive fails');
        } catch (CouldNotSaveException $e) {
            $this->assertStringContainsString('Could not save workflow', $e->getMessage());
        }

        $reloaded = $this->reloadWorkflow($id);
        $this->assertSame(1, $reloaded->getVersion(), 'Version bump rolled back with the failed archive');
        $this->assertSame(
            'original',
            json_decode($reloaded->getDefinition(), true)['steps']['s1']['config']['comment'],
            'The definition change rolled back with the failed archive'
        );
        $this->assertSame([], $this->om()->get(WorkflowRevisionResource::class)->getRevisions($id));
    }

    /**
     * Lost-update pin (docs/03): version-on-save with no documented optimistic
     * concurrency => last-write-wins. Two edits loaded from the same base; the
     * later save overwrites the earlier and archives it — B is NOT rejected.
     */
    public function testConcurrentEditsAreLastWriteWins(): void
    {
        $workflow = $this->createWorkflow(['name' => 'lost update', 'definition' => $this->linear('base')]);
        $id = (int) $workflow->getWorkflowId();

        $copyA = $this->reloadWorkflow($id);
        $copyB = $this->reloadWorkflow($id);
        $this->assertSame(1, $copyA->getVersion());
        $this->assertSame(1, $copyB->getVersion());

        $copyA->setDefinition(json_encode($this->linear('from-A'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->repository->save($copyA);

        // Stale copy B saved after A: no optimistic-concurrency rejection in the
        // documented model, so B wins and A's write is archived.
        $copyB->setDefinition(json_encode($this->linear('from-B'), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $saved = $this->repository->save($copyB);

        $reloaded = $this->reloadWorkflow($id);
        $this->assertSame(3, $reloaded->getVersion(), 'Both saves bumped the version: base->A(2)->B(3)');
        $this->assertSame('from-B', json_decode($reloaded->getDefinition(), true)['steps']['s1']['config']['comment']);
        $this->assertSame(3, $saved->getVersion());

        $versions = array_map(
            static fn (array $r): int => (int) $r['version'],
            $this->om()->get(WorkflowRevisionResource::class)->getRevisions($id)
        );
        $this->assertSame([2, 1], $versions, 'A (v2) and base (v1) are both archived');
    }

    /**
     * @return array a minimal valid linear definition whose only variable is the comment text
     */
    private function linear(string $comment): array
    {
        return [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => $comment],
                    'next' => null,
                ],
            ],
        ];
    }
}
