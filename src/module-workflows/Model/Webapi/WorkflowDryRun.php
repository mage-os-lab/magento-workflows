<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\DryRunResultInterface;
use MageOS\Workflows\Api\WorkflowDryRunInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\DryRun\DryRunRequest;
use MageOS\Workflows\Model\DryRun\DryRunService;

/**
 * REST facade for the dry-run service (03). The saved-workflow entry loads the
 * pinned definition/conditions/entity-type through the repository — a NoSuchEntity
 * (including the getById('dry-run') fall-through of a mistaken GET) surfaces as
 * a 404, which is exactly why the route shapes are non-colliding.
 */
class WorkflowDryRun implements WorkflowDryRunInterface
{
    public function __construct(
        private readonly DryRunService $dryRunService,
        private readonly WorkflowRepositoryInterface $workflowRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function runOnDefinition(
        string $definition,
        string $entityType,
        ?string $conditionsSerialized = null,
        ?int $entityId = null,
        ?array $triggerPayload = null
    ): DryRunResultInterface {
        return DryRunResult::fromTrace(
            $this->dryRunService->run(new DryRunRequest(
                $definition,
                $conditionsSerialized,
                $entityType,
                $entityId,
                $triggerPayload
            ))
        );
    }

    /**
     * @inheritDoc
     */
    public function runOnSaved(
        int $workflowId,
        ?int $entityId = null,
        ?array $triggerPayload = null
    ): DryRunResultInterface {
        $workflow = $this->workflowRepository->getById($workflowId);

        return DryRunResult::fromTrace(
            $this->dryRunService->run(new DryRunRequest(
                $workflow->getDefinition(),
                $workflow->getConditionsSerialized(),
                $workflow->getEntityType(),
                $entityId,
                $triggerPayload,
                (int) $workflow->getWorkflowId(),
                $workflow->getName()
            ))
        );
    }
}
