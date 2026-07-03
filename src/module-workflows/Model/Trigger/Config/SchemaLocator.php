<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Trigger\Config;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;

/**
 * Locates the workflow_triggers.xsd schema shipped with MageOS_Workflows.
 *
 * The same schema validates both individual files and the merged document —
 * trigger declarations are self-contained, so no relaxed per-file variant is needed.
 */
class SchemaLocator implements SchemaLocatorInterface
{
    private string $schemaPath;

    public function __construct(ModuleDirReader $moduleDirReader)
    {
        $this->schemaPath = $moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, 'MageOS_Workflows')
            . '/workflow_triggers.xsd';
    }

    /**
     * @inheritDoc
     */
    public function getSchema(): string
    {
        return $this->schemaPath;
    }

    /**
     * @inheritDoc
     */
    public function getPerFileSchema(): string
    {
        return $this->schemaPath;
    }
}
