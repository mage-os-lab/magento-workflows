<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCanvas\Test\Unit\Block\Adminhtml\Canvas;

use MageOS\WorkflowsCanvas\Block\Adminhtml\Canvas\Mount;
use PHPUnit\Framework\TestCase;

/**
 * The `approvalsAvailable` bootstrap flag gates the canvas palette's
 * "Approval gate" entry (docs/discovery/approval-gate.md §7: renders only
 * when both optional packages are present). It must key off Module\Manager
 * module presence — NOT a nullable DI seam: the addon's di.xml injects the
 * ApprovalTaskManagerInterface argument explicitly for the core consumers
 * only, so a nullable optional ctor arg here would silently stay null even
 * with the addon installed, and the addon may not wire a canvas class itself
 * (neither optional package depends on the other).
 *
 * The block is assembled by MountBuilder (see its docblock for why the block
 * under test is an anonymous subclass with reflection-injected dependencies).
 */
class MountApprovalsAvailableTest extends TestCase
{
    public function testFlagTrueWhenApprovalsModuleEnabled(): void
    {
        $config = $this->configFor($this->mount(['MageOS_WorkflowsApprovals']));

        $this->assertTrue($config['approvalsAvailable']);
    }

    public function testFlagFalseWhenApprovalsModuleAbsent(): void
    {
        $config = $this->configFor($this->mount([]));

        $this->assertFalse($config['approvalsAvailable']);
    }

    public function testFlagKeysOffTheApprovalsModuleSpecifically(): void
    {
        // Some other module being enabled must not light the flag up.
        $config = $this->configFor($this->mount(['MageOS_Workflows', 'MageOS_WorkflowsCanvas']));

        $this->assertFalse($config['approvalsAvailable']);
    }

    /**
     * @return array<string, mixed>
     */
    private function configFor(Mount $mount): array
    {
        $config = json_decode($mount->getConfigJson(), true);
        $this->assertTrue(is_array($config), 'getConfigJson must emit a JSON object');
        $this->assertArrayHasKey('approvalsAvailable', $config);
        return $config;
    }

    /**
     * @param string[] $enabledModules module names Module\Manager reports enabled
     */
    private function mount(array $enabledModules): Mount
    {
        return MountBuilder::create()->withEnabledModules($enabledModules)->build();
    }
}
