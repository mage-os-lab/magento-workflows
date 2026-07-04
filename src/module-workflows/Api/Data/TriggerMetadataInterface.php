<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One entry of GET /V1/workflows/meta/triggers (F6): a declared async-event
 * trigger, for the canvas palette trigger section (grouped) and trigger picker.
 * Projects a TriggerRegistry record.
 *
 * @api
 */
interface TriggerMetadataInterface
{
    /**
     * Async event name, e.g. "sales.order.created"
     */
    public function getEvent(): string;

    /**
     * Workflow entity type the payload represents, e.g. "sales_order"
     */
    public function getEntity(): string;

    /**
     * Merchant-facing label
     */
    public function getLabel(): string;

    /**
     * Optional grouping bucket for the palette, or null.
     */
    public function getGroup(): ?string;
}
