<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Controller\Adminhtml\Workflow;

use MageOS\Workflows\Model\Definition\Definition;
use MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow\Save;
use PHPUnit\Framework\TestCase;

/**
 * Covers buildDefinitionFromRows()' post-delay revalidate_entity default flip
 * (implementation plan 01, stage 3): a branch row directly after a delay row
 * re-hydrates by default, aligning the form assembler with
 * docs/06-conditions.md#delay-semantics so it stops emitting the
 * GRAPH_POST_DELAY_STALE warning on every form-built delay->branch. An explicit
 * value in the posted row always wins; branches not after a delay keep the
 * historical false default.
 *
 * The controller has a heavy Action\Context constructor, so the pure assembler
 * is exercised through reflection on an un-constructed instance -- the method
 * touches no injected dependency.
 */
class SaveAssemblerTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function assemble(array $rows): array
    {
        $controller = (new \ReflectionClass(Save::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Save::class, 'buildDefinitionFromRows');
        $method->setAccessible(true);
        return $method->invoke($controller, $rows);
    }

    public function testBranchAfterDelayDefaultsToRevalidateTrue(): void
    {
        $definition = $this->assemble([
            ['type' => Definition::STEP_DELAY, 'delay_duration' => 'PT1H'],
            ['type' => Definition::STEP_BRANCH, 'conditions_serialized' => null],
        ]);

        $this->assertTrue($definition['steps']['s2']['revalidate_entity']);
    }

    public function testBranchNotAfterDelayDefaultsToRevalidateFalse(): void
    {
        $definition = $this->assemble([
            ['type' => Definition::STEP_ACTION, 'action' => 'a.b'],
            ['type' => Definition::STEP_BRANCH, 'conditions_serialized' => null],
        ]);

        $this->assertFalse($definition['steps']['s2']['revalidate_entity']);
    }

    public function testFirstRowBranchDefaultsToRevalidateFalse(): void
    {
        $definition = $this->assemble([
            ['type' => Definition::STEP_BRANCH, 'conditions_serialized' => null],
        ]);

        $this->assertFalse($definition['steps']['s1']['revalidate_entity']);
    }

    public function testExplicitFalseAfterDelayIsRespected(): void
    {
        $definition = $this->assemble([
            ['type' => Definition::STEP_DELAY, 'delay_duration' => 'PT1H'],
            ['type' => Definition::STEP_BRANCH, 'revalidate_entity' => '0'],
        ]);

        $this->assertFalse($definition['steps']['s2']['revalidate_entity']);
    }

    public function testExplicitTrueNotAfterDelayIsRespected(): void
    {
        $definition = $this->assemble([
            ['type' => Definition::STEP_ACTION, 'action' => 'a.b'],
            ['type' => Definition::STEP_BRANCH, 'revalidate_entity' => '1'],
        ]);

        $this->assertTrue($definition['steps']['s2']['revalidate_entity']);
    }
}
