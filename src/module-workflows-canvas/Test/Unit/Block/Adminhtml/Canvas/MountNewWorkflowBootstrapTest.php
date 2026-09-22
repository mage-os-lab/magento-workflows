<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Unit\Block\Adminhtml\Canvas;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Definition\Definition;
use PHPUnit\Framework\TestCase;

/**
 * Canvas-first authoring of a NEW workflow (work package P1).
 *
 * With no workflow_id the mount used to bootstrap `workflow: null`, which the
 * React app reads as "nothing to edit" and falls back to a read-only empty
 * viewer (its editable gate is `workflow.definition !== null`). A manager now
 * gets a blank workflow document instead, so the canvas can author one from
 * scratch and post it through the admin Save controller — which creates the
 * record. A ::view-only admin keeps the old null: there is nothing to view and
 * nothing they may author.
 *
 * The JSON *string* is asserted for the steps map, not just the decoded array:
 * an empty PHP array would encode as `[]` and change the document's type on the
 * wire, so the blank definition must carry a stdClass.
 */
class MountNewWorkflowBootstrapTest extends TestCase
{
    public function testManagerWithNoWorkflowIdGetsABlankWorkflowDocument(): void
    {
        $workflow = $this->config($this->manageJson())['workflow'];

        $this->assertTrue(is_array($workflow), 'A manager must get a blank workflow, not null');
        $this->assertSame(0, $workflow['id']);
        $this->assertSame('', $workflow['name']);
        $this->assertSame(WorkflowInterface::STATUS_DISABLED, $workflow['status']);
        $this->assertSame('', $workflow['entityType']);
        $this->assertSame(WorkflowInterface::TRIGGER_TYPE_EVENT, $workflow['triggerType']);
        $this->assertSame('', $workflow['triggerRef']);
        $this->assertNull($workflow['conditionsSerialized']);
        $this->assertSame(1, $workflow['loopGuardDepth']);
        $this->assertSame([], $workflow['websiteIds']);
        $this->assertSame('', $workflow['fanOutRelation']);
        $this->assertSame('', $workflow['fanOutCap']);
    }

    /**
     * Same keys as the loaded-workflow branch, so the client has one shape to
     * consume whether it opened an existing workflow or a blank one.
     */
    public function testBlankWorkflowCarriesTheSameKeysAsALoadedOne(): void
    {
        $this->assertSame(
            [
                'id',
                'name',
                'status',
                'entityType',
                'triggerType',
                'triggerRef',
                'conditionsSerialized',
                'loopGuardDepth',
                'websiteIds',
                'fanOutRelation',
                'fanOutCap',
                'definition',
            ],
            array_keys($this->config($this->manageJson())['workflow'])
        );
    }

    public function testBlankDefinitionDeclaresTheCurrentSchemaAndNoEntry(): void
    {
        $definition = $this->config($this->manageJson())['workflow']['definition'];

        $this->assertSame(Definition::SCHEMA_VERSION, $definition['schema']);
        $this->assertNull($definition['entry']);
        $this->assertSame([], $definition['steps']);
    }

    public function testBlankDefinitionEncodesStepsAsAnObjectNotAnArray(): void
    {
        $this->assertStringContainsString(
            '"definition":{"schema":' . Definition::SCHEMA_VERSION . ',"steps":{},"entry":null}',
            $this->manageJson(),
            'steps is a MAP: an empty PHP array would emit [] and change the document type'
        );
    }

    public function testViewOnlyAdminWithNoWorkflowIdStillGetsNull(): void
    {
        $config = $this->config(
            MountBuilder::create()
                ->withGrants(['MageOS_Workflows::view' => true])
                ->build()
                ->getConfigJson()
        );

        $this->assertNull($config['workflow']);
        $this->assertFalse($config['grants']['manage']);
    }

    public function testWorkflowIdStaysNullSoTheSavePostCreatesARecord(): void
    {
        // The client posts workflow_id from `workflowId`, and the Save
        // controller creates a record only when that param is empty.
        $this->assertNull($this->config($this->manageJson())['workflowId']);
    }

    public function testWorkflowOptionsAreEmittedForEveryFormSelect(): void
    {
        $json = MountBuilder::create()
            ->withGrants(['MageOS_Workflows::manage' => true])
            ->withOptionSource('entityTypeSource', [['value' => 'order', 'label' => 'Order']])
            ->withOptionSource('triggerTypeSource', [['value' => 'event', 'label' => 'Event']])
            // An int value (the status column is an int) must reach the client
            // as a string so a <select> binds it without coercion bugs.
            ->withOptionSource('statusSource', [['value' => 0, 'label' => 'Disabled']])
            ->withOptionSource('websiteSource', [['value' => 1, 'label' => 'Main Website']])
            ->build()
            ->getConfigJson();

        $options = $this->config($json)['workflowOptions'];

        $this->assertSame(
            ['entityTypes', 'triggerTypes', 'statuses', 'websites'],
            array_keys($options)
        );
        $this->assertSame([['value' => 'order', 'label' => 'Order']], $options['entityTypes']);
        $this->assertSame([['value' => 'event', 'label' => 'Event']], $options['triggerTypes']);
        $this->assertSame([['value' => '0', 'label' => 'Disabled']], $options['statuses']);
        $this->assertSame([['value' => '1', 'label' => 'Main Website']], $options['websites']);
    }

    /**
     * Editing a saved workflow needs the same lists, and so does a ::view-only
     * admin's read-only panel — the key is unconditional.
     */
    public function testWorkflowOptionsAreEmittedEvenWithoutManage(): void
    {
        $config = $this->config(MountBuilder::create()->build()->getConfigJson());

        $this->assertArrayHasKey('workflowOptions', $config);
        $this->assertSame(
            ['entityTypes', 'triggerTypes', 'statuses', 'websites'],
            array_keys($config['workflowOptions'])
        );
    }

    /**
     * Nested optgroup rows have no scalar value; they are skipped rather than
     * cast to "Array".
     */
    public function testGroupedOptionRowsAreSkipped(): void
    {
        $json = MountBuilder::create()
            ->withOptionSource('websiteSource', [
                ['value' => 1, 'label' => 'Main Website'],
                ['label' => 'Group', 'value' => [['value' => 2, 'label' => 'Nested']]],
            ])
            ->build()
            ->getConfigJson();

        $this->assertSame(
            [['value' => '1', 'label' => 'Main Website']],
            $this->config($json)['workflowOptions']['websites']
        );
    }

    /**
     * The canvas UI phrase map rides in the bootstrap (`i18n`) so the React
     * bundle renders translated text; in en_US every value equals its key
     * (identity), which is also the client's per-phrase fallback.
     */
    public function testI18nPhraseMapIsEmittedWithIdentityEnglishRows(): void
    {
        $config = $this->config(MountBuilder::create()->build()->getConfigJson());

        $this->assertArrayHasKey('i18n', $config);
        $this->assertTrue(is_array($config['i18n']));
        $this->assertTrue(count($config['i18n']) > 0, 'The phrase map must not be empty');
        // A sample phrase round-trips: key present, value the __() rendering.
        $this->assertArrayHasKey('Workflow settings', $config['i18n']);
        $this->assertSame('Workflow settings', $config['i18n']['Workflow settings']);
    }

    private function manageJson(): string
    {
        return MountBuilder::create()
            ->withGrants(['MageOS_Workflows::manage' => true])
            ->build()
            ->getConfigJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $json): array
    {
        $config = json_decode($json, true);
        $this->assertTrue(is_array($config), 'getConfigJson must emit a JSON object');
        return $config;
    }
}
