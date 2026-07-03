<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Condition\Customer;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;

/**
 * Customer attribute condition with EAV auto-discovery.
 *
 * loadAttributeOptions() introspects the full customer attribute metadata via
 * CustomerMetadataInterface::getAllAttributesMetadata() — custom EAV
 * attributes (loyalty tier, sales rep, KVK number, ...) appear as condition
 * targets automatically, merged over a guaranteed set of flat basics. Input
 * types and value select options are likewise derived from the metadata.
 */
class Attribute extends AbstractWorkflowCondition
{
    /**
     * Flat basics always offered, even if metadata discovery is unavailable
     */
    private const FLAT_ATTRIBUTES = [
        'group_id' => 'Customer Group',
        'email' => 'Email',
        'created_at' => 'Created At',
        'store_id' => 'Created In Store',
        'dob' => 'Date of Birth',
        'gender' => 'Gender',
    ];

    /**
     * System/credential attributes never exposed as condition targets
     */
    private const EXCLUDED_ATTRIBUTES = [
        'password_hash',
        'rp_token',
        'rp_token_created_at',
        'confirmation',
        'default_billing',
        'default_shipping',
        'first_failure',
        'failures_num',
        'lock_expires',
        'session_cutoff',
    ];

    /**
     * @var array<string, AttributeMetadataInterface>
     */
    private array $attributeMetadata = [];

    public function __construct(
        Context $context,
        private readonly CustomerMetadataInterface $customerMetadata,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    /**
     * Flat basics + every customer EAV attribute from metadata introspection
     *
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $attributes = [];
        foreach (self::FLAT_ATTRIBUTES as $code => $label) {
            $attributes[$code] = __($label);
        }
        try {
            foreach ($this->customerMetadata->getAllAttributesMetadata() as $metadata) {
                $code = (string)$metadata->getAttributeCode();
                if ($code === '' || in_array($code, self::EXCLUDED_ATTRIBUTES, true)) {
                    continue;
                }
                $this->attributeMetadata[$code] = $metadata;
                if (isset($attributes[$code])) {
                    continue;
                }
                $label = trim((string)$metadata->getFrontendLabel());
                $attributes[$code] = $label !== '' ? $label : $code;
            }
        } catch (\Exception $e) {
            // Metadata service unavailable (e.g. early bootstrap): flat basics remain usable
            unset($e);
        }
        $this->setAttributeOption($attributes);
        return $this;
    }

    /**
     * @return string
     */
    public function getInputType()
    {
        $metadata = $this->getMetadataForCurrentAttribute();
        if ($metadata !== null) {
            $mapped = match ((string)$metadata->getFrontendInput()) {
                'date', 'datetime' => 'date',
                'boolean' => 'boolean',
                'select' => 'select',
                'multiselect' => 'multiselect',
                default => null,
            };
            if ($mapped !== null) {
                return $mapped;
            }
        }
        return match ((string)$this->getAttribute()) {
            'created_at', 'dob' => 'date',
            'group_id', 'store_id', 'gender' => 'select',
            default => 'string',
        };
    }

    /**
     * @return string
     */
    public function getValueElementType()
    {
        return match ($this->getInputType()) {
            'date' => 'date',
            'select', 'boolean' => 'select',
            'multiselect' => 'multiselect',
            default => 'text',
        };
    }

    /**
     * @return array
     */
    public function getValueSelectOptions()
    {
        if (!$this->hasData('value_select_options')) {
            $options = [];
            $metadata = $this->getMetadataForCurrentAttribute();
            if ($metadata !== null) {
                foreach ((array)$metadata->getOptions() as $option) {
                    $nested = $option->getOptions();
                    if (is_array($nested) && $nested !== []) {
                        foreach ($nested as $child) {
                            $options[] = ['value' => $child->getValue(), 'label' => $child->getLabel()];
                        }
                        continue;
                    }
                    $options[] = ['value' => $option->getValue(), 'label' => $option->getLabel()];
                }
            }
            $this->setData('value_select_options', $options);
        }
        return $this->getData('value_select_options');
    }

    private function getMetadataForCurrentAttribute(): ?AttributeMetadataInterface
    {
        $code = (string)$this->getAttribute();
        if ($code === '') {
            return null;
        }
        if (!isset($this->attributeMetadata[$code])) {
            try {
                $this->attributeMetadata[$code] = $this->customerMetadata->getAttributeMetadata($code);
            } catch (\Exception $e) {
                unset($e);
                return null;
            }
        }
        return $this->attributeMetadata[$code];
    }
}
