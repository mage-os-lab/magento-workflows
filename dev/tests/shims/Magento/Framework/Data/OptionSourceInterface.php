<?php
declare(strict_types=1);

namespace Magento\Framework\Data;

/**
 * Standalone-runner shim: the option-source contract UI selects consume. Only
 * the single method the tested source classes implement is declared.
 */
interface OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray();
}
