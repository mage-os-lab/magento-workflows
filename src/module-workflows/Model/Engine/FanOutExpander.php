<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Engine;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Trigger-level fan-out (F1, discovery/fan-out.md §2 · implementation/
 * 04-fan-out.md): when a workflow declares a `fan_out` clause, one triggering
 * event expands into N ordinary single-entity executions — one per member of
 * the declared relation resolved against the source entity.
 *
 * Home rationale: the expander lives in the core engine, but is invoked from
 * the WorkflowNotifier (triggers-core), the only place the source event object
 * and its async-events trace UUID are in hand. It stays free of async-events
 * types — the notifier extracts the event name and trace UUID and passes them
 * as primitives — so the core module keeps its dependency direction.
 *
 * Resolution goes through {@see RelationContext::resolve()} (F5 invariant),
 * never RelationInterface::resolveIds() directly — that is what keeps website
 * scoping, memoization and the fail-toward-false rule centralized. Each target
 * is hydrated through the existing hydrators into the same flat snapshot shape
 * the target's own event would carry, an `origin` key is injected, and the
 * existing {@see DispatcherInterface::dispatch()} is called once per target —
 * so every child carries the full guard stack (status, suppression, scope,
 * per-child debounce) unchanged and is indistinguishable downstream.
 *
 * Mid-expansion failure policy: each child's hydrate+dispatch is individually
 * try/caught. A child that throws is logged and skipped; expansion continues
 * (fail-open per child). The notifier reports SUCCESS once the relation
 * resolved — redelivery is reserved for pre-expansion failure (resolution
 * itself threw), where re-expansion is safe because per-child debounce
 * collapses the already-dispatched.
 */
class FanOutExpander
{
    public const CONFIG_FAN_OUT_CAP = 'mageos_workflows/guards/fan_out_cap';
    public const DEFAULT_FAN_OUT_CAP = 100;

    public function __construct(
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly RelationPool $relationPool,
        private readonly RelationContext $relationContext,
        private readonly HydrationProviderInterface $hydrationProvider,
        private readonly DispatcherInterface $dispatcher,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Expand one event delivery into per-target executions.
     *
     * Returns null when the workflow does not fan out (unknown workflow, no
     * `fan_out` clause, or an unparseable one) — the caller then takes its
     * ordinary single-dispatch branch, so a non-fan-out workflow pays only for
     * this one config read.
     *
     * @param array<string, mixed> $sourceData the trigger snapshot of the source entity
     * @throws \Throwable only for a pre-expansion failure (relation resolution
     *         threw before any child dispatched); per-child failures never escape
     */
    public function expand(
        int $workflowId,
        array $sourceData,
        string $eventName,
        ?string $traceUuid,
        int $chainDepth = 0
    ): ?FanOutResult {
        try {
            $workflow = $this->workflowRepository->getById($workflowId);
        } catch (NoSuchEntityException) {
            return null;
        }

        $config = $this->parseFanOut($workflow->getFanOut());
        if ($config === null) {
            return null;
        }

        $relationCode = $config['relation'];
        if (!$this->relationPool->has($relationCode)) {
            // Unknown relation resolves toward "not related": zero children,
            // logged (RelationContext logs too, but the source-type read below
            // needs a registered relation). Never a hard failure.
            $this->logger->error(sprintf(
                'Workflow #%d fan-out relation "%s" is not registered; expanded to zero children',
                $workflowId,
                $relationCode
            ));
            return new FanOutResult(0, 0, false);
        }

        $relation = $this->relationPool->get($relationCode);
        $source = $this->buildSource($sourceData, $relation->getSourceEntityType());

        $targetIds = $this->relationContext->resolve($relationCode, $source);

        $cap = $this->effectiveCap($config['cap']);
        $truncated = false;
        if (count($targetIds) > $cap) {
            $this->logger->warning('fan_out_truncated', [
                'workflow_id' => $workflowId,
                'relation' => $relationCode,
                'resolved' => count($targetIds),
                'cap' => $cap,
                'config_path' => self::CONFIG_FAN_OUT_CAP,
            ]);
            $targetIds = array_slice($targetIds, 0, $cap);
            $truncated = true;
        }

        if ($targetIds === []) {
            $this->logger->info('fan_out_empty', [
                'workflow_id' => $workflowId,
                'relation' => $relationCode,
                'entity_id' => (int) $source->getData('entity_id'),
            ]);
            return new FanOutResult(0, 0, $truncated);
        }

        $origin = $this->buildOrigin($eventName, $relation->getSourceEntityType(), $source, $traceUuid);

        $dispatched = 0;
        $skipped = 0;
        foreach ($targetIds as $targetId) {
            if ($this->dispatchChild($workflowId, $relation->getTargetEntityType(), (int) $targetId, $origin, $chainDepth)) {
                $dispatched++;
            } else {
                $skipped++;
            }
        }

        return new FanOutResult($dispatched, $skipped, $truncated);
    }

    /**
     * Hydrate one target and dispatch its execution. Individually guarded so a
     * throwing child is logged and skipped without stopping the expansion.
     *
     * @param array<string, mixed> $origin
     * @return bool true when an execution row was created; false when skipped
     *         (missing entity, a per-child guard, or an exception)
     */
    private function dispatchChild(
        int $workflowId,
        string $targetType,
        int $targetId,
        array $origin,
        int $chainDepth
    ): bool
    {
        try {
            $entity = $this->hydrationProvider->getEntity($targetType, $targetId, false);
            if ($entity === null) {
                $this->logger->info('fan_out_child_skipped', [
                    'workflow_id' => $workflowId,
                    'entity_type' => $targetType,
                    'entity_id' => $targetId,
                    'reason' => 'entity_not_found',
                ]);
                return false;
            }

            $childSnapshot = $entity->getData();
            $childSnapshot['origin'] = $origin;

            $execution = $this->dispatcher->dispatch(
                $workflowId,
                $childSnapshot,
                WorkflowInterface::TRIGGER_TYPE_EVENT,
                $chainDepth
            );

            return $execution !== null;
        } catch (\Throwable $e) {
            $this->logger->error('fan_out_child_failed', [
                'workflow_id' => $workflowId,
                'entity_type' => $targetType,
                'entity_id' => $targetId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * @return array{event: string, entity_type: string, entity_id: int, via: string, trace_uuid?: string}
     */
    private function buildOrigin(
        string $eventName,
        string $sourceType,
        \Magento\Framework\DataObject $source,
        ?string $traceUuid
    ): array {
        $origin = [
            'event' => $eventName,
            'entity_type' => $sourceType,
            'entity_id' => (int) $source->getData('entity_id'),
            'via' => 'fan_out',
        ];
        // Trace UUID is optional: the async-events accessor may be absent on the
        // installed version, in which case origin_uuid is simply omitted and the
        // rest of origin still travels (see WorkflowNotifier's pinned assumption).
        if ($traceUuid !== null && $traceUuid !== '') {
            $origin['trace_uuid'] = $traceUuid;
        }
        return $origin;
    }

    private function buildSource(array $sourceData, string $sourceType): \Magento\Framework\DataObject
    {
        if (!isset($sourceData['entity_id']) && isset($sourceData['id'])) {
            $sourceData['entity_id'] = $sourceData['id'];
        }
        // The source-entity type drives RelationContext's website-scope rules
        // (customer/account_share handling) exactly as the condition engine sets it.
        $sourceData[HydrationProviderInterface::KEY_ENTITY_TYPE] = $sourceType;

        return $this->dataObjectFactory->create(['data' => $sourceData]);
    }

    /**
     * Per-workflow cap clamped to the global ceiling (never above it); a missing
     * or non-positive per-workflow cap falls back to the global ceiling.
     */
    private function effectiveCap(?int $perWorkflowCap): int
    {
        $global = (int) $this->scopeConfig->getValue(self::CONFIG_FAN_OUT_CAP);
        if ($global <= 0) {
            $global = self::DEFAULT_FAN_OUT_CAP;
        }
        if ($perWorkflowCap === null || $perWorkflowCap <= 0) {
            return $global;
        }
        return min($perWorkflowCap, $global);
    }

    /**
     * @return array{relation: string, cap: ?int}|null null when the workflow
     *         carries no usable fan-out clause
     */
    private function parseFanOut(?string $fanOutJson): ?array
    {
        if ($fanOutJson === null || trim($fanOutJson) === '') {
            return null;
        }
        $decoded = json_decode($fanOutJson, true);
        if (!is_array($decoded)) {
            return null;
        }
        $relation = $decoded['relation'] ?? null;
        if (!is_string($relation) || $relation === '') {
            return null;
        }
        $cap = $decoded['cap'] ?? null;
        return [
            'relation' => $relation,
            'cap' => is_numeric($cap) ? (int) $cap : null,
        ];
    }
}
