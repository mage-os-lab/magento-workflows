<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Trigger\Config;

use Magento\Framework\Config\CacheInterface;
use Magento\Framework\Config\Data as ConfigData;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Cached provider of the merged workflow_triggers.xml data.
 *
 * Constructor defaults bind the concrete Reader and cache id, so no di.xml
 * virtualType wiring is required to instantiate this class.
 */
class Data extends ConfigData
{
    public const CACHE_ID = 'mageos_workflow_triggers';

    /**
     * @param Reader $reader
     * @param CacheInterface $cache
     * @param string $cacheId
     * @param SerializerInterface|null $serializer
     */
    public function __construct(
        Reader $reader,
        CacheInterface $cache,
        string $cacheId = self::CACHE_ID,
        ?SerializerInterface $serializer = null
    ) {
        parent::__construct($reader, $cache, $cacheId, $serializer);
    }
}
