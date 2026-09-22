<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Code => label lookup over an existing option source, for the grid columns and
 * detail templates that persist a machine code but must SHOW a human label.
 *
 * Deliberately tiny and static (same posture as Template\LocalizedText::resolve):
 * every caller already injects the option source it needs, so a resolver service
 * would only add DI wiring around a three-line array scan.
 *
 * Contract: never blank. An unknown code — a status written by a newer module, an
 * entity type whose pack was uninstalled — renders as the raw code rather than an
 * empty cell, so the admin can still see (and search for) what is stored.
 */
class OptionLabel
{
    /**
     * @param OptionSourceInterface $source the option source owning the code set
     * @param string $value the stored code
     * @return string the matching label, or $value when the source does not know it
     */
    public static function resolve(OptionSourceInterface $source, string $value): string
    {
        if ($value === '') {
            return '';
        }

        foreach ($source->toOptionArray() as $option) {
            if (!is_array($option) || !array_key_exists('value', $option)) {
                continue;
            }
            if ((string) $option['value'] !== $value) {
                continue;
            }
            $label = (string) ($option['label'] ?? '');

            return $label !== '' ? $label : $value;
        }

        return $value;
    }
}
