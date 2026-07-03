<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * Optional companion to ActionInterface. Implementations render their
 * would-be effect into the result output without side effects.
 * Powers shadow mode (v1) and dry-run (v2). Actions not implementing this
 * are recorded as "would run <code>" in shadow executions.
 *
 * @api
 */
interface SimulateableActionInterface
{
    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface;
}
