<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\SecretMetadataProviderInterface;
use MageOS\Workflows\Model\Variable\SecretsProviderInterface;

/**
 * GET /V1/workflows/meta/secrets (F6, canvas stage 1): exposes secret NAMES
 * only (SecretsProviderInterface::listKeys), never values.
 */
class SecretMetadataProvider implements SecretMetadataProviderInterface
{
    public function __construct(
        private readonly SecretsProviderInterface $secretsProvider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSecretNames(): array
    {
        return array_values($this->secretsProvider->listKeys());
    }
}
