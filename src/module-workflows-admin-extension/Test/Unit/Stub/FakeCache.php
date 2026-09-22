<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Stub;

use Magento\Framework\App\CacheInterface;

/**
 * In-memory CacheInterface stand-in that records tags per entry so clean() can
 * evict by tag (the real backend behavior the invalidation plugin relies on).
 * load() returns false on a miss, matching Magento's frontend contract.
 */
class FakeCache implements CacheInterface
{
    /** @var array<string, string> identifier => serialized data */
    public array $storage = [];

    /** @var array<string, string[]> identifier => tags */
    public array $tagsByIdentifier = [];

    /** @var array<int, string[]> every clean() invocation's tag list */
    public array $cleanCalls = [];

    public function getFrontend()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function load($identifier)
    {
        return $this->storage[$identifier] ?? false;
    }

    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->storage[$identifier] = $data;
        $this->tagsByIdentifier[$identifier] = $tags;
        return true;
    }

    public function remove($identifier)
    {
        unset($this->storage[$identifier], $this->tagsByIdentifier[$identifier]);
        return true;
    }

    public function clean($tags = [])
    {
        $this->cleanCalls[] = $tags;
        foreach ($this->tagsByIdentifier as $identifier => $identifierTags) {
            if (array_intersect($tags, $identifierTags) !== []) {
                unset($this->storage[$identifier], $this->tagsByIdentifier[$identifier]);
            }
        }
        return true;
    }
}
