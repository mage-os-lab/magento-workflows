<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Stub;

use Magento\Framework\Serialize\SerializerInterface;

/**
 * JSON-backed SerializerInterface stand-in (Magento's Json serializer is
 * unavailable outside an install).
 */
class JsonSerializer implements SerializerInterface
{
    public function serialize($data)
    {
        return json_encode($data);
    }

    public function unserialize($string)
    {
        return json_decode((string) $string, true);
    }
}
