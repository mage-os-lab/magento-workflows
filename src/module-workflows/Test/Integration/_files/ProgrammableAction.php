<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\_files;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * Test action injected under a real action code (via ActionPool overrides) so
 * the executor's persist-before-side-effect discipline and failure semantics
 * (docs/08) can be observed without a test-scoped di.xml.
 *
 * Outcome is steered per step from the resolved config key `__outcome`
 * (success|skip|fail_terminal|fail_retryable). Every call records the step it
 * ran for AND the persisted step-row status it observed at call time — the
 * executor is contract-bound to have written the row (status 'running') BEFORE
 * invoking the action.
 */
class ProgrammableAction implements ActionInterface
{
    /**
     * @var array<int, array{step: ?string, observed_status: ?string, simulation: bool}>
     */
    public array $calls = [];

    private const STEP_TABLE = 'mageos_workflow_execution_step';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $execution = $ctx->getExecution();
        $stepKey = $execution->getCurrentStep();

        $connection = $this->resourceConnection->getConnection();
        $observed = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::STEP_TABLE), ['status'])
                ->where('execution_id = ?', (int) $execution->getExecutionId())
                ->where('step_key = ?', (string) $stepKey)
                ->limit(1)
        );

        $this->calls[] = [
            'step' => $stepKey,
            'observed_status' => $observed === false ? null : (string) $observed,
            'simulation' => $ctx->isSimulation(),
        ];

        return match ($config['__outcome'] ?? 'success') {
            'skip' => ActionResult::skipped('programmed skip'),
            'fail_terminal' => ActionResult::failure('programmed terminal failure', false),
            'fail_retryable' => ActionResult::failure('programmed retryable failure', true),
            default => ActionResult::success(['ran' => $stepKey]),
        };
    }

    /**
     * @return string[] the step keys this action ran for, in call order
     */
    public function ranSteps(): array
    {
        return array_map(static fn (array $c): ?string => $c['step'], $this->calls);
    }
}
