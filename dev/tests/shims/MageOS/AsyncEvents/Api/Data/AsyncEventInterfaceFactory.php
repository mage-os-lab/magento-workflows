<?php
declare(strict_types=1);

namespace MageOS\AsyncEvents\Api\Data;

/**
 * Standalone-runner shim for the object-manager-GENERATED
 * MageOS\AsyncEvents\Api\Data\AsyncEventInterfaceFactory. Under a real
 * Magento install the unit-test bootstrap's generated-classes autoloader
 * supplies this class instead; test doubles extend it and override
 * __construct()/create(). The shim's own create() throws — there is no
 * concrete AsyncEvent model in the standalone environment.
 */
class AsyncEventInterfaceFactory
{
    /**
     * @param mixed $objectManager unused in the shim (OM in the generated class)
     * @param mixed $instanceName
     */
    public function __construct($objectManager = null, $instanceName = AsyncEventInterface::class)
    {
    }

    /**
     * @param array $data
     * @return AsyncEventInterface
     */
    public function create(array $data = [])
    {
        throw new \RuntimeException('AsyncEventInterfaceFactory shim: override create() in a test double');
    }
}
