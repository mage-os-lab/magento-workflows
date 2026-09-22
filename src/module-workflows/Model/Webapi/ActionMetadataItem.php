<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\ActionMetadataItemInterface;

/**
 * Immutable webapi DTO for a GET /V1/workflows/meta/actions entry (F6).
 */
class ActionMetadataItem implements ActionMetadataItemInterface
{
    /**
     * @param string[] $applicableEntities
     */
    public function __construct(
        private readonly string $code,
        private readonly string $label,
        private readonly string $group,
        private readonly array $applicableEntities,
        private readonly string $configForm,
        private readonly ?string $aclResource
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * @inheritDoc
     */
    public function getApplicableEntities(): array
    {
        return $this->applicableEntities;
    }

    public function getConfigForm(): string
    {
        return $this->configForm;
    }

    public function getAclResource(): ?string
    {
        return $this->aclResource;
    }
}
