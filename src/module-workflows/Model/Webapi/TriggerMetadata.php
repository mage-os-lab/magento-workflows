<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\TriggerMetadataInterface;

/**
 * Immutable webapi DTO for a GET /V1/workflows/meta/triggers entry (F6).
 */
class TriggerMetadata implements TriggerMetadataInterface
{
    public function __construct(
        private readonly string $event,
        private readonly string $entity,
        private readonly string $label,
        private readonly ?string $group
    ) {
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function getEntity(): string
    {
        return $this->entity;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }
}
