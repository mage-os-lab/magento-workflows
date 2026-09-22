<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\_files;

use MageOS\Workflows\Model\ResourceModel\WorkflowRevision;

/**
 * A WorkflowRevision resource whose archive() always throws, to prove the
 * repository save wraps the revision archive and the workflow update in ONE
 * transaction (docs/03 versioning): if the archive fails, the version bump and
 * the definition change must roll back together.
 */
class PoisonedWorkflowRevisionResource extends WorkflowRevision
{
    public function archive(int $workflowId, int $version, string $definition, ?string $conditionsSerialized): void
    {
        throw new \RuntimeException('poisoned revision archive');
    }
}
