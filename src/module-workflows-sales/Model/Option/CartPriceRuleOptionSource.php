<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Option;

use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use MageOS\Workflows\Model\Option\AbstractOptionSource;

/**
 * Search-typed option source: cart price rule id => name (F6). Potentially
 * large, so a field references it with min_chars: 2 and the client queries as
 * the user types (AbstractOptionSource applies the substring filter + cap).
 */
class CartPriceRuleOptionSource extends AbstractOptionSource
{
    public function __construct(
        private readonly CollectionFactory $ruleCollectionFactory
    ) {
    }

    public function getCode(): string
    {
        return 'cart_price_rules';
    }

    /**
     * @inheritDoc
     */
    protected function loadOptions(): array
    {
        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToSelect(['rule_id', 'name']);
        $options = [];
        foreach ($collection as $rule) {
            $options[] = [
                'value' => (string) $rule->getData('rule_id'),
                'label' => (string) $rule->getData('name'),
            ];
        }
        return $options;
    }
}
