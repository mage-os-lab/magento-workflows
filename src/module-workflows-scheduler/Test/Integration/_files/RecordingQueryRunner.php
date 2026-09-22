<?php
/**
 * Test-only recording double for the scheduler's QueryRunner
 * (docs/20-integration-test-plan.md §6, suite #24a). Extends the production
 * QueryRunner but replaces its constructor and run() so it needs none of the
 * real repository/dispatcher collaborators: it simply counts how many times
 * RunScheduledWorkflows hands it a due workflow. Wired via an object-manager
 * preference under @magentoAppIsolation so the cron's double-fire guard can be
 * observed as "run() invoked exactly once per due minute".
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Integration\_files;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\WorkflowsScheduler\Model\QueryRunner;

class RecordingQueryRunner extends QueryRunner
{
    /**
     * @var array<int, int>
     */
    private array $runWorkflowIds = [];

    /**
     * Intentionally omits parent::__construct(): the production constructor
     * pulls the entity repositories/dispatcher this double does not need.
     *
     * @SuppressWarnings(PHPMD.MissingParentConstructorCall)
     */
    public function __construct()
    {
    }

    public function run(WorkflowInterface $workflow, ?string $previousWatermark): ?string
    {
        $this->runWorkflowIds[] = (int) $workflow->getWorkflowId();
        // Return the previous watermark unchanged: no entities matched.
        return $previousWatermark;
    }

    public function runCount(): int
    {
        return count($this->runWorkflowIds);
    }

    /**
     * @return int[]
     */
    public function runWorkflowIds(): array
    {
        return $this->runWorkflowIds;
    }
}
