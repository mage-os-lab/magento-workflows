<?php
declare(strict_types=1);

namespace Magento\Framework\Module\Dir;

/**
 * Standalone-runner shim for Magento\Framework\Module\Dir\Reader. Tests inject a
 * subclass overriding getModuleDir() to map a module name to a fixture path.
 */
class Reader
{
    /**
     * @param string $type
     * @param string $moduleName
     * @return string
     */
    public function getModuleDir($type, $moduleName)
    {
        return '';
    }
}
