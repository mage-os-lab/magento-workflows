<?php
declare(strict_types=1);

namespace Magento\Eav\Model\ResourceModel\Entity\Attribute\Set;

/**
 * Minimal shim for the attribute-set collection factory. Exists so conditions
 * type-hinting it can be constructed under the standalone runner; tests do not
 * exercise the attribute-set value-option path.
 */
class CollectionFactory
{
    public function create()
    {
        return null;
    }
}
