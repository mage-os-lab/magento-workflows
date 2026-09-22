<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * Simulateable action stand-in for dry-run walker tests: a configurable
 * simulate() result (or throw), plus capture of the interpolated config it
 * received so redaction can be asserted against what actually reached the
 * action.
 */
class StubSimulateableAction implements ActionInterface, SimulateableActionInterface
{
    /** @var array|null */
    public ?array $lastConfig = null;

    public function __construct(
        private readonly string $code = 'stub.simulateable',
        private readonly ?ActionResultInterface $simulateResult = null,
        private readonly ?\Throwable $throw = null
    ) {
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        return ActionResult::success([]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $this->lastConfig = $config;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->simulateResult ?? ActionResult::success(['would' => 'would run ' . $this->code]);
    }
}
