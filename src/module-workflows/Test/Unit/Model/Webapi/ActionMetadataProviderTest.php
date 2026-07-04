<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use Magento\Framework\AuthorizationInterface;
use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionMetadataInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Webapi\ActionMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /V1/workflows/meta/actions projects the ActionPool, ACL-filters the
 * display, JSON-encodes the (now option-bearing) config form, and honours the
 * entity-type filter.
 */
class ActionMetadataProviderTest extends TestCase
{
    private function action(
        string $code,
        string $group,
        array $entities,
        array $configForm,
        ?string $acl
    ): ActionInterface {
        return new class ($code, $group, $entities, $configForm, $acl)
            implements ActionInterface, ActionMetadataInterface {
            public function __construct(
                private readonly string $code,
                private readonly string $group,
                private readonly array $entities,
                private readonly array $configForm,
                private readonly ?string $acl
            ) {
            }

            public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
            {
                throw new \LogicException('not exercised by metadata');
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function getLabel(): string
            {
                return 'Label ' . $this->code;
            }

            public function getGroup(): string
            {
                return $this->group;
            }

            public function getApplicableEntities(): array
            {
                return $this->entities;
            }

            public function getConfigForm(): array
            {
                return $this->configForm;
            }

            public function getAclResource(): ?string
            {
                return $this->acl;
            }
        };
    }

    private function authorization(array $allowed): AuthorizationInterface
    {
        return new class ($allowed) implements AuthorizationInterface {
            public function __construct(private readonly array $allowed)
            {
            }

            public function isAllowed($resource, $privilege = null): bool
            {
                return in_array($resource, $this->allowed, true);
            }
        };
    }

    public function testProjectsPoolAndEncodesConfigFormWithOptions(): void
    {
        $pool = new ActionPool([
            'order.change_status' => $this->action(
                'order.change_status',
                'Sales',
                ['sales_order'],
                [['name' => 'status', 'type' => 'select', 'options' => [['value' => 'processing', 'label' => 'Processing']]]],
                null
            ),
        ]);

        $items = (new ActionMetadataProvider($pool, $this->authorization([])))->getActions();

        $this->assertCount(1, $items);
        $this->assertSame('order.change_status', $items[0]->getCode());
        $this->assertSame('Label order.change_status', $items[0]->getLabel());
        $this->assertSame('Sales', $items[0]->getGroup());
        $this->assertSame(['sales_order'], $items[0]->getApplicableEntities());
        $decoded = json_decode($items[0]->getConfigForm(), true);
        $this->assertSame('processing', $decoded[0]['options'][0]['value']);
    }

    public function testHidesActionsWhoseAclTheAdminLacks(): void
    {
        $pool = new ActionPool([
            'a.allowed' => $this->action('a.allowed', 'Sales', [], [], 'MageOS_Workflows::action_sales'),
            'a.denied' => $this->action('a.denied', 'Sales', [], [], 'MageOS_Workflows::action_catalog'),
            'a.nullacl' => $this->action('a.nullacl', 'Flow', [], [], null),
        ]);

        $items = (new ActionMetadataProvider($pool, $this->authorization(['MageOS_Workflows::action_sales'])))
            ->getActions();

        $codes = array_map(static fn ($i) => $i->getCode(), $items);
        sort($codes);
        $this->assertSame(['a.allowed', 'a.nullacl'], $codes);
    }

    public function testEntityTypeFilterNarrowsApplicableActions(): void
    {
        $pool = new ActionPool([
            'order.only' => $this->action('order.only', 'Sales', ['sales_order'], [], null),
            'cust.only' => $this->action('cust.only', 'Customer', ['customer'], [], null),
            'any' => $this->action('any', 'Flow', [], [], null),
        ]);

        $items = (new ActionMetadataProvider($pool, $this->authorization([])))->getActions('customer');
        $codes = array_map(static fn ($i) => $i->getCode(), $items);
        sort($codes);
        $this->assertSame(['any', 'cust.only'], $codes);
    }
}
