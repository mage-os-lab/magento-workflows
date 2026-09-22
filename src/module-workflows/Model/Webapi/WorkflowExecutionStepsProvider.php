<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowExecutionStepsProviderInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep\CollectionFactory;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;

/**
 * GET /V1/workflow-executions/:executionId/steps (07): reads the step-execution
 * rows for one execution and projects them to SAFE ExecutionStepState DTOs.
 *
 * The raw step `result` blob (written by the live executor, potentially
 * secret-bearing) is NEVER returned. Only whitelisted routing keys are read to
 * derive `edge_taken`, and any error is reduced to a redacted, truncated first
 * line. See ExecutionStepStateInterface for the rationale.
 */
class WorkflowExecutionStepsProvider implements WorkflowExecutionStepsProviderInterface
{
    private const ERROR_SUMMARY_MAX = 160;

    public function __construct(
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly CollectionFactory $stepCollectionFactory,
        private readonly SecretsProviderInterface $secretsProvider,
        private readonly StepDetailRedactor $redactor
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSteps(int $executionId): array
    {
        $this->requireExecution($executionId);

        $secretValues = $this->secretValues();
        $redactor = $this->getRedactor();

        $steps = [];
        foreach ($this->loadStepModels($executionId) as $step) {
            $steps[] = new ExecutionStepState(
                $step->getStepKey(),
                $step->getStatus(),
                $step->getStartedAt(),
                $step->getFinishedAt(),
                self::deriveEdgeTaken($step->getResult()),
                self::summarizeError($step->getError(), $redactor, $secretValues)
            );
        }
        return $steps;
    }

    /**
     * The routing outcome, read from a WHITELIST of safe result keys only. An
     * action step's arbitrary `output` (which may hold secrets) is never read.
     */
    public static function deriveEdgeTaken(?string $resultJson): ?string
    {
        if ($resultJson === null || $resultJson === '') {
            return null;
        }
        $decoded = json_decode($resultJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        // branch: {"result": bool}
        if (array_key_exists('result', $decoded) && is_bool($decoded['result'])) {
            return $decoded['result'] ? 'on_true' : 'on_false';
        }
        // switch: {"matched": "<caseKey>"|null}
        if (array_key_exists('matched', $decoded)) {
            $matched = $decoded['matched'];
            if ($matched === null) {
                return 'default';
            }
            $matched = (string) $matched;
            // case keys are definition-authored and charset-validated; re-check.
            return preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $matched) ? 'case:' . $matched : null;
        }
        // wait: {"resolution": "event"|"timeout", ...}
        if (array_key_exists('resolution', $decoded)) {
            return match ($decoded['resolution']) {
                'event' => 'on_event',
                'timeout' => 'on_timeout',
                default => null,
            };
        }

        return null;
    }

    /**
     * First line of an error, truncated then run through the secret-redaction
     * filter. Never the raw blob.
     *
     * @param array<string, string> $secretValues
     */
    public static function summarizeError(
        ?string $error,
        StepDetailRedactor $redactor,
        array $secretValues
    ): ?string {
        if ($error === null || trim($error) === '') {
            return null;
        }
        $normalized = str_replace(["\r\n", "\r"], "\n", $error);
        $firstLine = strtok($normalized, "\n");
        $firstLine = $firstLine === false ? '' : $firstLine;
        if (mb_strlen($firstLine) > self::ERROR_SUMMARY_MAX) {
            $firstLine = mb_substr($firstLine, 0, self::ERROR_SUMMARY_MAX) . '…';
        }
        return $redactor->redact($firstLine, $secretValues);
    }

    /**
     * Resolved name => plaintext secret map, for exact redaction. Read
     * server-side only; never returned. (Test seam.)
     *
     * @return array<string, string>
     */
    protected function secretValues(): array
    {
        $map = [];
        foreach ($this->secretsProvider->listKeys() as $name) {
            $value = $this->secretsProvider->get($name);
            if ($value !== null && $value !== '') {
                $map[(string) $name] = $value;
            }
        }
        return $map;
    }

    protected function getRedactor(): StepDetailRedactor
    {
        return $this->redactor;
    }

    /**
     * Existence check: NoSuchEntityException -> 404 for an unknown execution.
     * (Test seam.)
     */
    protected function requireExecution(int $executionId): void
    {
        $this->executionRepository->getById($executionId);
    }

    /**
     * The step-execution rows for one execution, in execution order.
     * (Test seam.)
     *
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface[]
     */
    protected function loadStepModels(int $executionId): array
    {
        $collection = $this->stepCollectionFactory->create();
        $collection->addFieldToFilter('execution_id', $executionId);
        $collection->setOrder('step_execution_id', 'ASC');

        return $collection->getItems();
    }
}
