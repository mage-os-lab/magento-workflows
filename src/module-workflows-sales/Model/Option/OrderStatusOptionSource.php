<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Option;

use Magento\Sales\Model\Order\Config as OrderConfig;
use MageOS\Workflows\Model\Option\AbstractOptionSource;

/**
 * Bounded option source: every order status code => label (F6). Small and
 * fully enumerable, so a field may inline these or reference this source with
 * min_chars: 0.
 */
class OrderStatusOptionSource extends AbstractOptionSource
{
    public function __construct(
        private readonly OrderConfig $orderConfig
    ) {
    }

    public function getCode(): string
    {
        return 'order_statuses';
    }

    /**
     * @inheritDoc
     */
    protected function loadOptions(): array
    {
        $options = [];
        foreach ($this->orderConfig->getStatuses() as $code => $label) {
            $options[] = ['value' => (string) $code, 'label' => (string) $label];
        }
        return $options;
    }
}
