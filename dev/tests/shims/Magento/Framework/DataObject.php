<?php
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Standalone-runner shim for Magento\Framework\DataObject. Faithful minimal
 * implementation of the pieces unit tests rely on: array constructor,
 * getData/setData/unsetData/hasData, getDataByPath ('/'-separated), and the
 * magic getX()/setX()/unsX()/hasX() accessors.
 */
class DataObject
{
    /** @var array */
    protected $_data = [];

    public function __construct(array $data = [])
    {
        $this->_data = $data;
    }

    /**
     * @param string|array $key
     * @param mixed $value
     * @return $this
     */
    public function setData($key, $value = null)
    {
        if (is_array($key)) {
            $this->_data = $key;
        } else {
            $this->_data[$key] = $value;
        }
        return $this;
    }

    /**
     * @param string $key '' returns the whole bag; 'a/b/c' walks nested data
     * @param string|int|null $index
     * @return mixed
     */
    public function getData($key = '', $index = null)
    {
        if ($key === '') {
            return $this->_data;
        }
        if (strpos((string)$key, '/') !== false) {
            $data = $this->getDataByPath((string)$key);
        } else {
            $data = $this->_data[$key] ?? null;
        }
        if ($index !== null) {
            if (is_array($data)) {
                $data = $data[$index] ?? null;
            } elseif (is_string($data)) {
                $data = explode(PHP_EOL, $data);
                $data = $data[$index] ?? null;
            } elseif ($data instanceof self) {
                $data = $data->getData($index);
            } else {
                $data = null;
            }
        }
        return $data;
    }

    /**
     * @return mixed
     */
    public function getDataByPath(string $path)
    {
        $keys = explode('/', $path);
        $data = $this->_data;
        foreach ($keys as $key) {
            if (is_array($data) && isset($data[$key])) {
                $data = $data[$key];
            } elseif ($data instanceof self) {
                $data = $data->getDataByKey($key);
            } else {
                return null;
            }
        }
        return $data;
    }

    /**
     * @return mixed
     */
    public function getDataByKey(string $key)
    {
        return $this->_data[$key] ?? null;
    }

    /**
     * @param string|null $key
     * @return $this
     */
    public function unsetData($key = null)
    {
        if ($key === null) {
            $this->_data = [];
        } else {
            unset($this->_data[$key]);
        }
        return $this;
    }

    public function hasData(string $key = ''): bool
    {
        if ($key === '') {
            return !empty($this->_data);
        }
        return array_key_exists($key, $this->_data);
    }

    public function toArray(array $keys = []): array
    {
        if (empty($keys)) {
            return $this->_data;
        }
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->_data[$key] ?? null;
        }
        return $result;
    }

    /**
     * Magic getX()/setX()/unsX()/hasX() accessors, mirroring the real
     * DataObject's camelCase-to-snake_case conversion.
     *
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        switch (substr($method, 0, 3)) {
            case 'get':
                return $this->getData(self::underscore(substr($method, 3)), $args[0] ?? null);
            case 'set':
                return $this->setData(self::underscore(substr($method, 3)), $args[0] ?? null);
            case 'uns':
                return $this->unsetData(self::underscore(substr($method, 3)));
            case 'has':
                return $this->hasData(self::underscore(substr($method, 3)));
        }
        throw new \BadMethodCallException(sprintf('Invalid method %s::%s', static::class, $method));
    }

    private static function underscore(string $name): string
    {
        return strtolower(trim((string)preg_replace('/([A-Z]|[0-9]+)/', '_$1', $name), '_'));
    }
}
