<?php
declare(strict_types=1);

namespace Magento\Framework\App;

interface CacheInterface
{
    public function load(string $identifier);
    public function save(string $data, string $identifier, array $tags = [], ?int $lifeTime = null);
    public function remove(string $identifier);
}
