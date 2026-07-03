<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Product;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * product.set_attribute — writes one attribute value on the product, scoped to
 * the execution's store, via Magento\Catalog\Model\Product\Action so only the
 * touched attribute is reindexed (no full product save).
 *
 * Same deny-by-default posture as customer.set_attribute (docs/10-security.md):
 * denylist ships in di.xml with sku (identity) and status (owned by the
 * dedicated product.set_status action); url_key intentionally remains allowed.
 */
class SetAttribute extends AbstractAction implements SimulateableActionInterface
{
    private const CODE_PATTERN = '/^[a-zA-Z0-9_]+$/';

    /**
     * @param string[] $deniedAttributes lower-case attribute codes that may never be written
     */
    public function __construct(
        private readonly ProductAction $productAction,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly array $deniedAttributes = []
    ) {
    }

    public function getCode(): string
    {
        return 'product.set_attribute';
    }

    public function getLabel(): string
    {
        return (string)__('Set Product Attribute');
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
                'name' => 'attribute_code',
                'label' => 'Attribute Code',
                'type' => 'text',
                'required' => true,
                'notice' => 'sku and status are refused (use the dedicated status action).',
            ],
            ['name' => 'value', 'label' => 'Value', 'type' => 'text', 'required' => true],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $attributeCode = $this->stringConfig($config, 'attribute_code');
        if ($attributeCode === null) {
            return $this->missingConfig('attribute_code');
        }
        if (!array_key_exists('value', $config)) {
            return $this->missingConfig('value');
        }

        $denied = $this->checkDenied($attributeCode);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->attributeRepository->get('catalog_product', $attributeCode);
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Product attribute "%1" does not exist', $attributeCode));
        }

        try {
            $this->productAction->updateAttributes(
                [$ctx->getEntityId()],
                [$attributeCode => $config['value']],
                $ctx->getStoreId()
            );
        } catch (\Exception $e) {
            return ActionResult::failure('Could not update product attribute: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'attribute_code' => $attributeCode,
            'value' => $config['value'],
            'store_id' => $ctx->getStoreId(),
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $attributeCode = $this->stringConfig($config, 'attribute_code');
        if ($attributeCode === null) {
            return $this->missingConfig('attribute_code');
        }
        $denied = $this->checkDenied($attributeCode);
        if ($denied !== null) {
            return $denied;
        }
        return $this->simulated(sprintf(
            'Set product %d attribute "%s" on store %d',
            $ctx->getEntityId(),
            $attributeCode,
            $ctx->getStoreId()
        ));
    }

    private function checkDenied(string $attributeCode): ?ActionResult
    {
        if (!preg_match(self::CODE_PATTERN, $attributeCode)) {
            return ActionResult::failure((string)__('Invalid attribute code "%1"', $attributeCode));
        }
        if (in_array(strtolower($attributeCode), array_map('strtolower', $this->deniedAttributes), true)) {
            return ActionResult::failure((string)__(
                'Attribute "%1" is on the security denylist and cannot be written by workflows',
                $attributeCode
            ));
        }
        return null;
    }
}
