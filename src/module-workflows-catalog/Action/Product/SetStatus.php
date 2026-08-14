<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Action\Product;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * product.set_status — enables or disables the product on the execution's
 * store scope via mass attribute update (no full product save).
 *
 * Trigger-chaining boundary: this path skips catalog_product_save_after, so
 * a status change made here never raises catalog.product.status_changed
 * (docs/07-actions.md, footnote 11).
 */
class SetStatus extends AbstractAction implements SimulateableActionInterface
{
    private const STATUS_ENABLED = 'enabled';
    private const STATUS_DISABLED = 'disabled';

    public function __construct(
        private readonly ProductAction $productAction
    ) {
    }

    public function getCode(): string
    {
        return 'product.set_status';
    }

    public function getLabel(): string
    {
        return (string)__('Enable/Disable Product');
    }

    public function getGroup(): string
    {
        return (string)__('Catalog');
    }

    public function getApplicableEntities(): array
    {
        return ['catalog_product'];
    }

    public function getConfigForm(): array
    {
        return [
            [
                'name' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'required' => true,
                'options' => [
                    ['value' => self::STATUS_ENABLED, 'label' => 'Enabled'],
                    ['value' => self::STATUS_DISABLED, 'label' => 'Disabled'],
                ],
            ],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $status = $this->stringConfig($config, 'status');
        if ($status === null) {
            return $this->missingConfig('status');
        }
        if (!in_array($status, [self::STATUS_ENABLED, self::STATUS_DISABLED], true)) {
            return ActionResult::failure((string)__('Invalid status "%1" (enabled|disabled)', $status));
        }

        $statusValue = $status === self::STATUS_ENABLED ? Status::STATUS_ENABLED : Status::STATUS_DISABLED;

        try {
            $this->productAction->updateAttributes(
                [$ctx->getEntityId()],
                ['status' => $statusValue],
                $ctx->getStoreId()
            );
        } catch (\Exception $e) {
            return ActionResult::failure('Could not update product status: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'status' => $status,
            'status_value' => $statusValue,
            'store_id' => $ctx->getStoreId(),
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $status = $this->stringConfig($config, 'status');
        if ($status === null) {
            return $this->missingConfig('status');
        }
        if (!in_array($status, [self::STATUS_ENABLED, self::STATUS_DISABLED], true)) {
            return ActionResult::failure((string)__('Invalid status "%1" (enabled|disabled)', $status));
        }
        return $this->simulated(sprintf(
            'Set product %d to %s on store %d',
            $ctx->getEntityId(),
            $status,
            $ctx->getStoreId()
        ));
    }
}
