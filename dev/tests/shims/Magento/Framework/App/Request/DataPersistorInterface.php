<?php
declare(strict_types=1);

namespace Magento\Framework\App\Request;

/**
 * Standalone-runner shim: the cross-request form-data persistor contract the
 * Save controller writes and the form DataProvider reads back.
 */
interface DataPersistorInterface
{
    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set($key, $value);

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key);

    /**
     * @param string $key
     * @return void
     */
    public function clear($key);
}
