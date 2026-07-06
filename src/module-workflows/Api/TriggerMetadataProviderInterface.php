<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * GET /V1/workflows/meta/triggers (F6, canvas stage 1): lists the
 * TriggerRegistry for the canvas palette trigger section. ACL
 * `MageOS_Workflows::view`.
 *
 * @api
 */
interface TriggerMetadataProviderInterface
{
    /**
     * @return \MageOS\Workflows\Api\Data\TriggerMetadataInterface[]
     */
    public function getTriggers(): array;
}
