<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\UrlInterface;

/**
 * Deep link from an approval task's (entity_type, entity_id) to the entity's
 * own admin edit page (docs/discovery/approval-gate.md §6 grid column, decision
 * view entity summary). No such native-entity URL catalogue exists elsewhere in
 * this codebase (the executions grid's ExecutionEntity column renders text
 * only), so this is Stage 3's own small map — same DI-registered-array
 * extensibility convention as MageOS\WorkflowsAdminExtension\ViewModel\GridStrip's
 * $gridHandleMap: a third party widens coverage with one di.xml entry, no code
 * change. Unknown entity types (or entity_id = 0, e.g. a batch execution) yield
 * null — the caller renders plain text instead of a broken link.
 */
class EntityUrlResolver
{
    /**
     * @param array<string, array{route: string, param: string}> $entityRouteMap entity_type => [route path, request param name]
     */
    public function __construct(
        private readonly array $entityRouteMap,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getUrl(string $entityType, int $entityId): ?string
    {
        if ($entityId <= 0 || !isset($this->entityRouteMap[$entityType])) {
            return null;
        }
        $route = $this->entityRouteMap[$entityType];
        if (!isset($route['route'], $route['param'])) {
            return null;
        }
        return $this->urlBuilder->getUrl($route['route'], [$route['param'] => $entityId]);
    }
}
