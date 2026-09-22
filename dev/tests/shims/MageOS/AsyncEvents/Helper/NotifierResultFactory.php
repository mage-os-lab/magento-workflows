<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\AsyncEvents\Helper;

/**
 * Standalone-runner shim for the object-manager-GENERATED
 * MageOS\AsyncEvents\Helper\NotifierResultFactory. Under a real Magento
 * install the unit-test bootstrap's generated-classes autoloader supplies
 * this class instead; test doubles therefore extend it and override
 * __construct()/create() so neither parent implementation is ever exercised.
 */
class NotifierResultFactory
{
    /**
     * @param mixed $objectManager unused in the shim (OM in the generated class)
     * @param mixed $instanceName
     */
    public function __construct($objectManager = null, $instanceName = NotifierResult::class)
    {
    }

    /**
     * @param array $data
     * @return NotifierResult
     */
    public function create(array $data = [])
    {
        return new NotifierResult($data);
    }
}
