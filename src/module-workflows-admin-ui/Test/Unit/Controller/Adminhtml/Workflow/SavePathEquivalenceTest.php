<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Controller\Adminhtml\Workflow;

use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Save;
use PHPUnit\Framework\TestCase;

/**
 * Save-path equivalence (implementation plan 07, stage 4; discovery canvas.md
 * §7): the visual canvas posts the mapped definition through the SAME admin
 * Save controller the classic form's JSON textarea uses — it has no save
 * endpoint of its own. This pins the reliability contract: for an identical
 * logical definition, a canvas save and a textarea save produce byte-identical
 * stored definition bytes.
 *
 * Both surfaces post a `definition` string field (the canvas builds it with
 * saveClient.buildSavePayload, the textarea with the raw editor content). This
 * test proves (a) the controller resolves both to that same `definition` param
 * — the canvas never reaches the dynamicRows assembler — and (b) whatever key
 * ordering / whitespace the canvas emits, Definition::fromJson()->toJson()
 * normalizes both to identical stored bytes. The vitest half asserts the
 * canvas mapping produces a semantically identical definition on every fixture.
 */
class SavePathEquivalenceTest extends TestCase
{
    /**
     * @param array<string, mixed> $postData
     */
    private function resolveDefinitionJson(array $postData): string
    {
        $controller = (new \ReflectionClass(Save::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Save::class, 'resolveDefinitionJson');
        return $method->invoke($controller, $postData);
    }

    /** The exact normalization every save performs (Save::execute line: fromJson->toJson). */
    private function normalize(string $definitionJson): string
    {
        return Definition::fromJson($definitionJson)->toJson();
    }

    public function testCanvasAndTextareaBothReachTheDefinitionParam(): void
    {
        // The textarea posts pretty JSON in schema/steps/entry order.
        $textareaJson = <<<JSON
{
    "schema": 3,
    "steps": {
        "s1": {"type": "action", "action": "order.add_comment", "config": {"comment": "hi"}, "next": null}
    },
    "entry": "s1"
}
JSON;
        // The canvas posts compact JSON in schema/entry/steps order (its emit order).
        $canvasJson = '{"schema":3,"entry":"s1","steps":{"s1":{"type":"action","action":"order.add_comment","config":{"comment":"hi"},"next":null}}}';

        $resolvedTextarea = $this->resolveDefinitionJson(['definition' => $textareaJson]);
        $resolvedCanvas = $this->resolveDefinitionJson(['definition' => $canvasJson]);

        // Neither path fell through to the dynamicRows assembler (no `steps` rows).
        $this->assertSame($textareaJson, $resolvedTextarea);
        $this->assertSame($canvasJson, $resolvedCanvas);
    }

    public function testIdenticalDefinitionYieldsByteIdenticalStoredBytes(): void
    {
        $textareaJson = <<<JSON
{
    "schema": 3,
    "steps": {
        "s1": {"type": "action", "action": "order.add_comment", "config": {"comment": "hi"}, "next": "s2"},
        "s2": {"type": "stop"}
    },
    "entry": "s1"
}
JSON;
        // Same definition, canvas emit order (schema, entry, steps) + compact.
        $canvasJson = '{"schema":3,"entry":"s1","steps":'
            . '{"s1":{"type":"action","action":"order.add_comment","config":{"comment":"hi"},"next":"s2"},'
            . '"s2":{"type":"stop"}}}';

        $storedFromTextarea = $this->normalize($this->resolveDefinitionJson(['definition' => $textareaJson]));
        $storedFromCanvas = $this->normalize($this->resolveDefinitionJson(['definition' => $canvasJson]));

        $this->assertSame(
            $storedFromTextarea,
            $storedFromCanvas,
            'Canvas-save and textarea-save must store byte-identical definition JSON'
        );
    }

    public function testCanvasLayoutBlockSurvivesTheSavePathVerbatim(): void
    {
        // Layout persistence (Phase-A gate #1): the canvas writes node positions
        // into the non-semantic `ui` block; the save path preserves it verbatim.
        $canvasJson = '{"schema":3,"entry":"s1",'
            . '"steps":{"s1":{"type":"stop"}},'
            . '"ui":{"nodes":{"s1":{"x":120,"y":40}}}}';

        $stored = $this->normalize($this->resolveDefinitionJson(['definition' => $canvasJson]));
        $decoded = json_decode($stored, true);

        $this->assertSame(120, $decoded['ui']['nodes']['s1']['x']);
        $this->assertSame(40, $decoded['ui']['nodes']['s1']['y']);
    }

    public function testTextareaWithoutUiStaysUiFreeThroughTheSavePath(): void
    {
        $json = '{"schema":3,"entry":"s1","steps":{"s1":{"type":"stop"}}}';
        $stored = $this->normalize($this->resolveDefinitionJson(['definition' => $json]));
        $this->assertFalse(
            array_key_exists('ui', json_decode($stored, true)),
            'A ui-free definition must stay ui-free through the save path'
        );
    }

    // ---------------------------------------------------------------------
    // Where a successful save lands (work package P1).
    //
    // Sharing the controller means sharing its redirect, so the canvas passes
    // back=canvas to come back to itself instead of the classic form -- the
    // only way canvas-first authoring of a NEW workflow can continue editing,
    // since the id exists only after this save. Same reflection posture as the
    // assembler above: resolveSuccessRedirect() touches one injected
    // collaborator, set directly on an un-constructed instance.
    // ---------------------------------------------------------------------

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resolveSuccessRedirect(mixed $back, int $workflowId, bool $canvasEnabled = true): array
    {
        $controller = (new \ReflectionClass(Save::class))->newInstanceWithoutConstructor();
        $moduleManager = new class ($canvasEnabled) extends ModuleManager {
            public function __construct(private readonly bool $canvasEnabled)
            {
                // No parent call: the real Manager wants module-list
                // collaborators this test never exercises.
            }

            public function isEnabled($moduleName)
            {
                return $moduleName === 'MageOS_WorkflowsCanvas' && $this->canvasEnabled;
            }
        };
        (new \ReflectionProperty(Save::class, 'moduleManager'))->setValue($controller, $moduleManager);

        return (new \ReflectionMethod(Save::class, 'resolveSuccessRedirect'))
            ->invoke($controller, $back, $workflowId);
    }

    public function testCanvasBackReturnsToTheCanvasWithTheSavedId(): void
    {
        $this->assertSame(
            ['mageos_workflows_canvas/canvas/edit', ['workflow_id' => 42]],
            $this->resolveSuccessRedirect('canvas', 42)
        );
    }

    public function testCanvasBackDegradesToTheClassicFormWhenTheCanvasIsDisabled(): void
    {
        // The canvas package is optional; a stale/forged flag must never
        // redirect to a route the installation does not have.
        $this->assertSame(
            ['mageos_workflows/workflow/edit', ['workflow_id' => 42]],
            $this->resolveSuccessRedirect('canvas', 42, false)
        );
    }

    public function testOrdinaryBackStillReturnsToTheClassicForm(): void
    {
        $this->assertSame(
            ['mageos_workflows/workflow/edit', ['workflow_id' => 42]],
            $this->resolveSuccessRedirect('1', 42)
        );
    }

    public function testNoBackStillReturnsToTheGrid(): void
    {
        $this->assertSame(
            ['mageos_workflows/workflow/index', []],
            $this->resolveSuccessRedirect(null, 42)
        );
    }
}
