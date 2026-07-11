<?php
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Standalone-runner shim for Magento\Framework\ObjectManagerInterface —
 * signature-faithful to the real interface. Used by the scheduler detectors
 * to resolve their soft-dependency EventPublisher at runtime.
 */
interface ObjectManagerInterface
{
    /**
     * Create a new object instance
     *
     * @param string $type
     * @param array $arguments
     * @return mixed
     */
    public function create($type, array $arguments = []);

    /**
     * Retrieve a cached (shared) object instance
     *
     * @param string $type
     * @return mixed
     */
    public function get($type);

    /**
     * Configure the object manager
     *
     * @param array $configuration
     * @return void
     */
    public function configure(array $configuration);
}
