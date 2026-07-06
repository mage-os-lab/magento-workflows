<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * GET /V1/workflows/meta/secrets (F6, canvas stage 1): the NAMES of configured
 * secrets, for the config-panel `{{ secrets.<name> }}` picker. Values are
 * write-only and never leave the server — this endpoint returns names only.
 * ACL `MageOS_Workflows::view`.
 *
 * @api
 */
interface SecretMetadataProviderInterface
{
    /**
     * @return string[] secret names, sorted
     */
    public function getSecretNames(): array;
}
