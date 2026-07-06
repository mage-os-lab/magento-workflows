<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use MageOS\Workflows\Api\EntityTypeMetadataProviderInterface;
use MageOS\WorkflowsAdminExtension\Model\WorkflowCountProvider;

/**
 * View model behind the grid strip (docs/discovery/entity-grid-visibility.md
 * §4). Each native grid layout hands it the page's entity type; it answers
 * whether to render, the counts and label to show, and the two deep links.
 *
 * $gridHandleMap (layout handle => entity type) is the DI-registered extension
 * surface: a third party widens grid coverage by adding a layout file plus one
 * entry here. The strip layouts pass entity_type explicitly, so the map is
 * documentation-of-record rather than a lookup the render path depends on —
 * both are kept consistent.
 */
class GridStrip implements ArgumentInterface
{
    private const ACL_VIEW = 'MageOS_Workflows::view';
    private const ACL_MANAGE = 'MageOS_Workflows::manage';
    private const CONFIG_EXPOSE = 'mageos_workflows/general/expose_on_entity_grids';

    /** @var array<string, array{enabled:int, total:int}> per-request count memo */
    private array $countsByEntityType = [];

    /**
     * @param array<string, string> $gridHandleMap layout handle => entity type
     */
    public function __construct(
        private readonly array $gridHandleMap,
        private readonly WorkflowCountProvider $countProvider,
        private readonly EntityTypeMetadataProviderInterface $entityTypeMetadata,
        private readonly AuthorizationInterface $authorization,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * Renders nothing when the toggle is off, the admin lacks view access, or
     * there is nothing to show (no workflows and no ability to create one).
     */
    public function shouldRender(string $entityType): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_EXPOSE)) {
            return false;
        }
        if (!$this->authorization->isAllowed(self::ACL_VIEW)) {
            return false;
        }
        if ($this->getTotalCount($entityType) === 0 && !$this->canManage()) {
            return false;
        }
        return true;
    }

    public function canManage(): bool
    {
        return $this->authorization->isAllowed(self::ACL_MANAGE);
    }

    public function getEnabledCount(string $entityType): int
    {
        return $this->counts($entityType)['enabled'];
    }

    public function getTotalCount(string $entityType): int
    {
        return $this->counts($entityType)['total'];
    }

    /**
     * Merchant-facing label for the entity type, from the authoritative catalogue
     * (no duplicated label list). Falls back to the raw code for a type the
     * catalogue does not know.
     */
    public function getEntityLabel(string $entityType): string
    {
        foreach ($this->entityTypeMetadata->getEntityTypes() as $metadata) {
            if ($metadata->getCode() === $entityType) {
                return $metadata->getLabel();
            }
        }
        return $entityType;
    }

    /**
     * Deep link to the workflow grid pre-filtered to this entity type via the
     * listing's server-side filters_modifier mechanism (the same one core modal
     * grids use; no listing changes needed).
     */
    public function getViewUrl(string $entityType): string
    {
        return $this->urlBuilder->getUrl(
            'mageos_workflows/workflow/index',
            [
                'filters_modifier' => [
                    'entity_type' => ['condition_type' => 'eq', 'value' => $entityType],
                ],
            ]
        );
    }

    /**
     * Deep link to the new-workflow form seeded with this entity type
     * (admin-ui's DataProvider validates the param against the catalogue).
     */
    public function getCreateUrl(string $entityType): string
    {
        return $this->urlBuilder->getUrl(
            'mageos_workflows/workflow/edit',
            ['entity_type' => $entityType]
        );
    }

    /**
     * @return array<string, string> layout handle => entity type
     */
    public function getGridHandleMap(): array
    {
        return $this->gridHandleMap;
    }

    /**
     * @return array{enabled:int, total:int}
     */
    private function counts(string $entityType): array
    {
        return $this->countsByEntityType[$entityType]
            ??= $this->countProvider->getCounts($entityType);
    }
}
