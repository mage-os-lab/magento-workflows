<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * Optional companion to ActionInterface. Implementations render their
 * would-be effect into the ActionResult output without side effects.
 * Powers shadow mode (v1) and dry-run (v2). Actions not implementing this
 * are recorded as "would run <code>" in shadow executions.
 */
interface SimulateableActionInterface
{
    public function simulate(ExecutionContext $ctx, array $config): ActionResult;
}
