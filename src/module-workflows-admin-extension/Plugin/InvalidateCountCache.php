<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Plugin;

use Magento\Framework\App\CacheInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\WorkflowsAdminExtension\Model\WorkflowCountProvider;

/**
 * Drops every cached grid count whenever a workflow is written or removed, so
 * the strip's "N active for Orders" number never lags the actual set. The
 * repository is the single chokepoint every authoring path funnels through
 * (admin Save, REST, CLI import, gallery), so an after-plugin here covers them
 * all. Cleaning by tag (not by key) is deliberate: a save can change a
 * workflow's entity_type, which would otherwise strand a stale count under the
 * old type's key.
 */
class InvalidateCountCache
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public function afterSave(
        WorkflowRepositoryInterface $subject,
        WorkflowInterface $result
    ): WorkflowInterface {
        $this->invalidate();
        return $result;
    }

    public function afterDelete(WorkflowRepositoryInterface $subject, bool $result): bool
    {
        $this->invalidate();
        return $result;
    }

    public function afterDeleteById(WorkflowRepositoryInterface $subject, bool $result): bool
    {
        $this->invalidate();
        return $result;
    }

    private function invalidate(): void
    {
        $this->cache->clean([WorkflowCountProvider::CACHE_TAG]);
    }
}
