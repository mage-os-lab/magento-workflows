<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Standalone-runner shim for Magento\Framework\Registry: an in-memory key/value
 * bag, which is all the blocks reading 'mageos_current_workflow' need. The real
 * class additionally guards re-registration and unregisters on destruct; no test
 * exercises either.
 */
class Registry
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param string $key
     * @return mixed
     */
    public function registry($key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param bool $graceful
     * @return void
     */
    public function register($key, $value, $graceful = false)
    {
        if (isset($this->data[$key]) && !$graceful) {
            throw new \RuntimeException('Registry key "' . $key . '" already exists');
        }
        $this->data[$key] = $value;
    }

    /**
     * @param string $key
     * @return void
     */
    public function unregister($key)
    {
        unset($this->data[$key]);
    }
}
