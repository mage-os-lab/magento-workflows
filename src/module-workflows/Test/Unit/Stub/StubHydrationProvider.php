<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use Magento\Framework\DataObject;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * In-memory HydrationProviderInterface stand-in: returns a registered entity
 * for a given "type:id", or null (vanished entity) otherwise.
 */
class StubHydrationProvider implements HydrationProviderInterface
{
    /** @var array<string, DataObject> */
    private array $entities;

    /**
     * @param array<string, DataObject> $entities keyed by "<type>:<id>"
     */
    public function __construct(array $entities = [])
    {
        $this->entities = $entities;
    }

    public function getEntity(string $entityType, int $entityId, bool $fresh = false): ?DataObject
    {
        return $this->entities[$entityType . ':' . $entityId] ?? null;
    }
}
