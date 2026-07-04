<?php
declare(strict_types=1);

namespace Magento\Framework\Serialize;

interface SerializerInterface
{
    /**
     * @param mixed $data
     * @return string|bool
     */
    public function serialize($data);

    /**
     * @param string $string
     * @return mixed
     */
    public function unserialize($string);
}
