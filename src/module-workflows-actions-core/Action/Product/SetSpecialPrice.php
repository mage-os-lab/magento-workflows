<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Product;

use Magento\Catalog\Model\Product\Action as ProductAction;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * product.set_special_price — sets (or clears, with clear: true) the special
 * price and its optional from/to dates, scoped to the execution's store, via
 * Magento\Catalog\Model\Product\Action so only the touched attributes are
 * reindexed (same mechanism as product.set_attribute). All three attributes
 * are always written together: omitted dates are cleared so a previous
 * schedule can't linger. Writing the same values twice is a no-op, so the
 * action is idempotent under redelivery.
 */
class SetSpecialPrice extends AbstractAction implements SimulateableActionInterface
{
    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly ProductAction $productAction
    ) {
    }

    public function getCode(): string
    {
        return 'product.set_special_price';
    }

    public function getLabel(): string
    {
        return (string)__('Set Special Price');
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
            ['name' => 'price', 'label' => 'Special Price', 'type' => 'text', 'required' => false,
                'notice' => 'Required unless "clear" is enabled. Must be zero or greater.'],
            ['name' => 'from_date', 'label' => 'From Date (Y-m-d)', 'type' => 'text', 'required' => false],
            ['name' => 'to_date', 'label' => 'To Date (Y-m-d)', 'type' => 'text', 'required' => false],
            ['name' => 'clear', 'label' => 'Clear Special Price', 'type' => 'boolean', 'required' => false,
                'notice' => 'Removes the special price and dates instead of setting them.'],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $attributes = $this->buildAttributes($config);
        if ($attributes instanceof ActionResult) {
            return $attributes;
        }

        try {
            $this->productAction->updateAttributes(
                [$ctx->getEntityId()],
                $attributes,
                $ctx->getStoreId()
            );
        } catch (\Exception $e) {
            return ActionResult::failure('Could not update special price: ' . $e->getMessage(), true);
        }

        return ActionResult::success($attributes + ['store_id' => $ctx->getStoreId()]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $attributes = $this->buildAttributes($config);
        if ($attributes instanceof ActionResult) {
            return $attributes;
        }
        if ($attributes['special_price'] === null) {
            return $this->simulated(sprintf(
                'Clear special price on product %d (store %d)',
                $ctx->getEntityId(),
                $ctx->getStoreId()
            ));
        }
        return $this->simulated(sprintf(
            'Set special price %s on product %d (store %d, from %s to %s)',
            $attributes['special_price'],
            $ctx->getEntityId(),
            $ctx->getStoreId(),
            $attributes['special_from_date'] ?? 'now',
            $attributes['special_to_date'] ?? 'open end'
        ));
    }

    /**
     * @return array{special_price: float|null, special_from_date: string|null, special_to_date: string|null}|ActionResult
     */
    private function buildAttributes(array $config): array|ActionResult
    {
        if ($this->boolConfig($config, 'clear')) {
            return [
                'special_price' => null,
                'special_from_date' => null,
                'special_to_date' => null,
            ];
        }

        $price = $this->stringConfig($config, 'price');
        if ($price === null) {
            return $this->missingConfig('price');
        }
        if (!is_numeric($price)) {
            return ActionResult::failure((string)__('Invalid price "%1"', $price));
        }
        if ((float)$price < 0) {
            return ActionResult::failure((string)__('Special price must be zero or greater'));
        }

        $from = $this->stringConfig($config, 'from_date');
        if ($from !== null && !$this->isValidDate($from)) {
            return ActionResult::failure((string)__('Invalid date "%1" (expected Y-m-d)', $from));
        }
        $to = $this->stringConfig($config, 'to_date');
        if ($to !== null && !$this->isValidDate($to)) {
            return ActionResult::failure((string)__('Invalid date "%1" (expected Y-m-d)', $to));
        }
        if ($from !== null && $to !== null && $from > $to) {
            return ActionResult::failure((string)__('"from_date" must be on or before "to_date"'));
        }

        return [
            'special_price' => (float)$price,
            'special_from_date' => $from,
            'special_to_date' => $to,
        ];
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat(self::DATE_FORMAT, $value);
        return $date !== false && $date->format(self::DATE_FORMAT) === $value;
    }
}
