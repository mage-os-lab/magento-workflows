<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Test\Unit\Block\Adminhtml\Template;

use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Test\Unit\Stub\StubAction;
use MageOS\WorkflowsAdminUi\Block\Adminhtml\Template\View;
use PHPUnit\Framework\TestCase;

/**
 * The template detail's Requirements list used to print raw codes ("Trigger:
 * sales.order.created"). It now projects the SAME label sources the canvas
 * palette and the trigger select read, with the raw code as fallback — which is
 * precisely the not-installed case the compatibility panel is about to flag, so
 * hiding the code there would be actively unhelpful.
 *
 * Built as an anonymous subclass with a no-op constructor plus reflection
 * injection (see InstallWidgetMappingTest's docblock).
 */
class RequiresLabelsTest extends TestCase
{
    public function testDeclaredTriggerRendersItsLabel(): void
    {
        $this->assertSame('Order Created', $this->block()->triggerLabel('sales.order.created'));
    }

    public function testUndeclaredTriggerRendersItsRawEventName(): void
    {
        $this->assertSame('b2b.quote.submitted', $this->block()->triggerLabel('b2b.quote.submitted'));
    }

    public function testDeclaredTriggerWithoutALabelRendersItsRawEventName(): void
    {
        $this->assertSame('labelless.event', $this->block()->triggerLabel('labelless.event'));
    }

    public function testInstalledActionRendersItsMetadataLabel(): void
    {
        $this->assertSame('Add Order Comment', $this->block()->actionLabel('order.add_comment'));
    }

    public function testUninstalledActionRendersItsRawCode(): void
    {
        $this->assertSame('b2b.quote.approve', $this->block()->actionLabel('b2b.quote.approve'));
    }

    /**
     * An action may implement ActionInterface without the optional metadata
     * interface (no palette entry): the code is all there is to show.
     */
    public function testActionWithoutMetadataRendersItsRawCode(): void
    {
        $this->assertSame('internal.noop', $this->block()->actionLabel('internal.noop'));
    }

    private function block(): View
    {
        $block = new class extends View {
            public function __construct()
            {
            }
        };

        $dependencies = [
            'triggerRegistry' => new class extends TriggerRegistry {
                public function __construct()
                {
                }

                public function getByEvent(string $event): ?array
                {
                    $triggers = [
                        'sales.order.created' => ['label' => 'Order Created'],
                        'labelless.event' => ['label' => ''],
                    ];

                    return $triggers[$event] ?? null;
                }
            },
            'actionPool' => new ActionPool([
                'order.add_comment' => new StubAction('order.add_comment', 'Add Order Comment'),
                // Registered, but exposing no palette metadata at all.
                'internal.noop' => $this->plainAction(),
            ]),
        ];

        foreach ($dependencies as $name => $value) {
            $property = new \ReflectionProperty(View::class, $name);
            $property->setValue($block, $value);
        }

        return $block;
    }

    private function plainAction(): ActionInterface
    {
        return new class implements ActionInterface {
            public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
            {
                return ActionResult::success([]);
            }
        };
    }
}
