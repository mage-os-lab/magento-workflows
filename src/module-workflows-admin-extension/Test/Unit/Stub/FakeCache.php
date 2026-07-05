<?php
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

    public function load(string $identifier)
    {
        return $this->storage[$identifier] ?? false;
    }

    public function save(string $data, string $identifier, array $tags = [], ?int $lifeTime = null)
    {
        $this->storage[$identifier] = $data;
        $this->tagsByIdentifier[$identifier] = $tags;
        return true;
    }

    public function remove(string $identifier)
    {
        unset($this->storage[$identifier], $this->tagsByIdentifier[$identifier]);
        return true;
    }

    public function clean(array $tags = [])
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
