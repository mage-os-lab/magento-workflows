<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\WorkflowsScheduler\Model\QueryRunner;

/**
 * QueryRunner double that records every dispatch handed to it by the cron
 * (workflow id + the watermark it was resumed with) and returns a canned
 * new watermark. Bypasses the real constructor: none of the query/dispatch
 * dependencies exist in the standalone runner and none are needed here.
 */
class RecordingQueryRunner extends QueryRunner
{
    /** @var array<int, array{0:int, 1:?string}> [workflow id, previous watermark] */
    public array $calls = [];

    public function __construct(private readonly ?string $returnWatermark = null)
    {
    }

    public function run(WorkflowInterface $workflow, ?string $previousWatermark): ?string
    {
        $this->calls[] = [(int) $workflow->getWorkflowId(), $previousWatermark];
        return $this->returnWatermark;
    }
}
