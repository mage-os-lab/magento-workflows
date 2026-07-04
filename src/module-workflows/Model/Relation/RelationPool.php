<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Relation;

use MageOS\Workflows\Api\RelationInterface;

/**
 * DI-registered pool of entity relations, keyed by relation code (F5).
 * Mirrors Model/Action/ActionPool — this IS the extension surface: one
 * class + one di.xml entry. Seed resolvers arrive with entity
 * cross-referencing (docs/discovery/implementation/02-*.md).
 */
class RelationPool
{
    /**
     * @param RelationInterface[] $relations code => instance
     */
    public function __construct(
        private readonly array $relations = []
    ) {
        foreach ($this->relations as $code => $relation) {
            if (!$relation instanceof RelationInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Workflow relation "%s" must implement %s', $code, RelationInterface::class)
                );
            }
        }
    }

    public function has(string $code): bool
    {
        return isset($this->relations[$code]);
    }

    public function get(string $code): RelationInterface
    {
        if (!isset($this->relations[$code])) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow relation "%s"', $code));
        }
        return $this->relations[$code];
    }

    /**
     * @return RelationInterface[] code => instance
     */
    public function getAll(): array
    {
        return $this->relations;
    }

    /**
     * Relations applicable to a source entity type (metadata endpoints, UI)
     *
     * @return RelationInterface[] code => instance
     */
    public function getBySourceEntityType(string $entityType): array
    {
        return array_filter(
            $this->relations,
            static fn (RelationInterface $relation): bool => $relation->getSourceEntityType() === $entityType
        );
    }
}
