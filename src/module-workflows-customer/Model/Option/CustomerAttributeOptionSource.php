<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model\Option;

use Magento\Customer\Api\CustomerMetadataInterface;
use MageOS\Workflows\Model\Option\AbstractOptionSource;
use MageOS\WorkflowsCustomer\Action\Customer\SetAttribute;

/**
 * Search-typed option source: writable customer attribute codes (F6), labelled
 * "Attribute Label (code)". Discovery goes through the customer metadata
 * service, the same introspection the customer condition uses, so custom EAV
 * attributes appear without further registration; customer.set_attribute
 * references it with min_chars: 0 (AbstractOptionSource filters + caps).
 *
 * The security denylist is NOT restated here: the picker asks the action that
 * enforces it, so the list stays declared exactly once — in di.xml, on
 * customer.set_attribute — and a merchant extending it there also shrinks the
 * picker. Deny-listed codes would only ever be offered to be refused.
 */
class CustomerAttributeOptionSource extends AbstractOptionSource
{
    public function __construct(
        private readonly CustomerMetadataInterface $customerMetadata,
        private readonly SetAttribute $setAttributeAction
    ) {
    }

    public function getCode(): string
    {
        return 'customer_attributes';
    }

    /**
     * @inheritDoc
     */
    protected function loadOptions(): array
    {
        $options = [];
        foreach ($this->customerMetadata->getAllAttributesMetadata() as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if ($code === '' || $this->setAttributeAction->isDenied($code)) {
                continue;
            }
            $label = trim((string) $attribute->getFrontendLabel());
            $options[] = [
                'value' => $code,
                'label' => $label !== '' ? sprintf('%s (%s)', $label, $code) : $code,
            ];
        }
        return $options;
    }
}
