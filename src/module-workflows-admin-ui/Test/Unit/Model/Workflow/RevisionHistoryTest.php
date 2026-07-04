<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Model\Workflow;

use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\PlainLanguageRenderer;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\WorkflowsAdminUi\Model\Workflow\RevisionHistory;
use MageOS\WorkflowsAdminUi\Test\Unit\Stub\WorkflowStub;
use PHPUnit\Framework\TestCase;

/**
 * RevisionHistory turns WorkflowRevision::getRevisions() rows into the
 * change-history list entries (docs/11-admin-ui.md): version, archived-at
 * timestamp, and a plain-language sentence for that revision's archived
 * definition/conditions, scene-set with the *current* workflow's
 * trigger/entity-type fields (the revision table doesn't version those).
 *
 * Backend\Block\Template\Context and the resource model are out of scope for
 * this harness (no mocking framework, no Magento/Backend shims) -- this class
 * is the DB-free assembly logic the History block delegates to, so it is
 * exercised directly here with a real PlainLanguageRenderer (same
 * construction as PlainLanguageRendererTest in module-workflows) and a
 * WorkflowStub in place of the ORM model.
 */
class RevisionHistoryTest extends TestCase
{
    private function renderer(): PlainLanguageRenderer
    {
        $registry = new class extends TriggerRegistry {
            public function __construct()
            {
            }

            public function getAll(): array
            {
                return [
                    'sales.order.created' => [
                        'event' => 'sales.order.created',
                        'entity' => 'sales_order',
                        'label' => 'Order Created',
                    ],
                ];
            }
        };

        return new PlainLanguageRenderer(
            new ActionPool([]),
            $registry,
            new RelationPool([])
        );
    }

    private function workflow(): WorkflowStub
    {
        return new WorkflowStub([
            'workflow_id' => 7,
            'name' => 'Notify on order',
            'trigger_type' => 'event',
            'trigger_ref' => 'sales.order.created',
            'entity_type' => 'sales_order',
            'version' => 3,
        ]);
    }

    public function testGetCurrentSentenceRendersTheLiveWorkflow(): void
    {
        $workflow = $this->workflow();
        $workflow->setDefinition((string) json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'stop']],
        ]));

        $history = new RevisionHistory($this->renderer());

        $this->assertSame('When Order Created, then: stop.', $history->getCurrentSentence($workflow));
    }

    public function testBuildEntriesRendersEachRevisionNewestFirst(): void
    {
        $history = new RevisionHistory($this->renderer());

        $rows = [
            [
                'version' => 3,
                'created_at' => '2026-07-01 10:00:00',
                'definition' => json_encode([
                    'schema' => 1,
                    'entry' => 's1',
                    'steps' => ['s1' => ['type' => 'stop']],
                ]),
                'conditions_serialized' => null,
            ],
            [
                'version' => 2,
                'created_at' => '2026-06-15 09:30:00',
                'definition' => json_encode([
                    'schema' => 1,
                    'entry' => null,
                    'steps' => [],
                ]),
                'conditions_serialized' => null,
            ],
        ];

        $entries = $history->buildEntries($this->workflow(), $rows);

        $this->assertCount(2, $entries);
        $this->assertSame(3, $entries[0]['version']);
        $this->assertSame('2026-07-01 10:00:00', $entries[0]['created_at']);
        $this->assertSame('When Order Created, then: stop.', $entries[0]['sentence']);

        $this->assertSame(2, $entries[1]['version']);
        $this->assertSame('When Order Created.', $entries[1]['sentence']);
    }

    public function testMissingArchivedDefinitionDegradesToSteplessSentence(): void
    {
        $history = new RevisionHistory($this->renderer());

        $rows = [
            [
                'version' => 1,
                'created_at' => '2026-05-01 00:00:00',
                // A row with no definition (null): PlainLanguageRenderer's own
                // Definition::fromJson guard rejects '' and renderSteps()
                // degrades to no steps, so this exercises the row -> sentence
                // path end-to-end without needing to force an exception.
                'definition' => null,
                'conditions_serialized' => null,
            ],
        ];

        $entries = $history->buildEntries($this->workflow(), $rows);

        $this->assertCount(1, $entries);
        $this->assertSame('When Order Created.', $entries[0]['sentence']);
    }

    public function testRevisionRendererThrowingIsCaughtAndFallsBackToPlaceholder(): void
    {
        $throwingRenderer = new class(new ActionPool([]), new class extends TriggerRegistry {
            public function __construct()
            {
            }
        }, new RelationPool([])) extends PlainLanguageRenderer {
            public function renderFromFields(
                string $triggerType,
                string $triggerRef,
                string $entityType,
                ?string $conditionsSerialized,
                string $definitionJson,
                ?string $fanOut = null,
                ?string $aggregationJson = null
            ): string {
                throw new \RuntimeException('boom');
            }
        };

        $history = new RevisionHistory($throwingRenderer);

        $entries = $history->buildEntries($this->workflow(), [
            ['version' => 1, 'created_at' => null, 'definition' => '{}', 'conditions_serialized' => null],
        ]);

        $this->assertSame('(unrenderable revision)', $entries[0]['sentence']);
    }
}
