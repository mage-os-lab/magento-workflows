<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Trigger;

use MageOS\Workflows\Model\Trigger\Config\Data;

/**
 * Facade over the merged workflow_triggers.xml metadata.
 *
 * Each trigger record is:
 * ['event' => string, 'entity' => string, 'label' => string,
 *  'group' => ?string, 'resolver' => ?string]
 */
class TriggerRegistry
{
    public function __construct(
        private readonly Data $configData
    ) {
    }

    /**
     * All declared triggers, keyed by event name.
     *
     * @return array<string, array<string, string|null>>
     */
    public function getAll(): array
    {
        $triggers = $this->configData->get();

        return is_array($triggers) ? $triggers : [];
    }

    /**
     * Metadata for a single async event, or null when the event is not declared.
     *
     * @return array<string, string|null>|null
     */
    public function getByEvent(string $event): ?array
    {
        if ($event === '') {
            return null;
        }
        $trigger = $this->configData->get($event);

        return is_array($trigger) ? $trigger : null;
    }

}
