<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use Magento\Framework\AuthorizationInterface;
use MageOS\Workflows\Api\ActionMetadataProviderInterface;
use MageOS\Workflows\Api\Data\ActionMetadataItemInterface;
use MageOS\Workflows\Model\Action\ActionPool;

/**
 * GET /V1/workflows/meta/actions (F6, canvas stage 1): projects the
 * DI-registered ActionPool to metadata DTOs for the canvas palette + config
 * panels. The display is ACL-filtered for convenience (an action whose
 * getAclResource() the current admin lacks is hidden), but this is never the
 * security gate — the save path re-authorizes every action code.
 */
class ActionMetadataProvider implements ActionMetadataProviderInterface
{
    public function __construct(
        private readonly ActionPool $actionPool,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getActions(?string $entityType = null): array
    {
        $entityType = $entityType !== null && $entityType !== '' ? $entityType : null;

        $items = [];
        foreach ($this->actionPool->getMetadata($entityType) as $action) {
            $aclResource = $action->getAclResource();
            if ($aclResource !== null && !$this->authorization->isAllowed($aclResource)) {
                continue;
            }
            $items[] = new ActionMetadataItem(
                $action->getCode(),
                $action->getLabel(),
                $action->getGroup(),
                array_values($action->getApplicableEntities()),
                (string) json_encode($action->getConfigForm(), JSON_UNESCAPED_SLASHES),
                $aclResource
            );
        }
        return $items;
    }
}
