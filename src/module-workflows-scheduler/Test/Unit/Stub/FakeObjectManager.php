<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

use Magento\Framework\ObjectManagerInterface;

/**
 * ObjectManager double for the detectors' soft-dependency EventPublisher
 * probe: get() returns the configured instance, or throws the configured
 * failure (simulating "class present but not instantiable", the degraded
 * path the detector docblocks promise to survive).
 */
class FakeObjectManager implements ObjectManagerInterface
{
    public int $getCalls = 0;

    public function __construct(
        private readonly ?object $instance = null,
        private readonly ?\Throwable $getFailure = null
    ) {
    }

    public function create($type, array $arguments = [])
    {
        throw new \BadMethodCallException(__METHOD__ . ' not expected in these tests');
    }

    public function get($type)
    {
        $this->getCalls++;
        if ($this->getFailure !== null) {
            throw $this->getFailure;
        }
        if ($this->instance === null) {
            throw new \BadMethodCallException('no instance configured for ' . (string) $type);
        }
        return $this->instance;
    }

    public function configure(array $configuration)
    {
        throw new \BadMethodCallException(__METHOD__ . ' not expected in these tests');
    }
}
