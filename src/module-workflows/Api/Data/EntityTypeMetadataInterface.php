<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One entry of GET /V1/workflows/meta/entity-types (F6): a workflow entity type
 * for config-panel selects and the trigger picker.
 *
 * @api
 */
interface EntityTypeMetadataInterface
{
    /**
     * Stable entity type code, e.g. "sales_order"
     */
    public function getCode(): string;

    /**
     * Merchant-facing label
     */
    public function getLabel(): string;
}
