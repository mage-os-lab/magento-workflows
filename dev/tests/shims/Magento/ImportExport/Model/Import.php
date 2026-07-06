<?php
declare(strict_types=1);

namespace Magento\ImportExport\Model;

/**
 * Minimal stand-in for the plugin target of
 * MageOS\Workflows\Plugin\SuppressWorkflowsDuringImport. The real class
 * parses/validates/persists the uploaded CSV; the shim only needs to exist
 * so the around-plugin's type-hinted $subject argument resolves under the
 * standalone runner (see shims/README.md).
 */
class Import
{
    public function importSource(): bool
    {
        return true;
    }
}
