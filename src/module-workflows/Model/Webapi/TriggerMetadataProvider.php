<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\TriggerMetadataProviderInterface;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;

/**
 * GET /V1/workflows/meta/triggers (F6, canvas stage 1): projects the merged
 * workflow_triggers.xml metadata (TriggerRegistry) to DTOs. Read-only.
 */
class TriggerMetadataProvider implements TriggerMetadataProviderInterface
{
    public function __construct(
        private readonly TriggerRegistry $triggerRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTriggers(): array
    {
        $triggers = [];
        foreach ($this->triggerRegistry->getAll() as $event => $trigger) {
            $group = $trigger['group'] ?? null;
            $triggers[] = new TriggerMetadata(
                (string) ($trigger['event'] ?? $event),
                (string) ($trigger['entity'] ?? ''),
                (string) ($trigger['label'] ?? $event),
                $group !== null ? (string) $group : null
            );
        }
        return $triggers;
    }
}
