<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One entry of GET /V1/workflows/meta/relations (F6): the enumerable shape of
 * a registered relation, for the canvas relation picker, the template gallery,
 * and CI linting. Mirrors RelationInterface's metadata (never resolveIds — the
 * endpoint lists, it does not resolve).
 *
 * @api
 */
interface RelationMetadataInterface
{
    /**
     * Stable relation code, e.g. "order.customer_by_email"
     */
    public function getCode(): string;

    /**
     * Merchant-facing label
     */
    public function getLabel(): string;

    /**
     * Source workflow entity type, e.g. "sales_order"
     */
    public function getSourceEntityType(): string;

    /**
     * Target workflow entity type, e.g. "customer"
     */
    public function getTargetEntityType(): string;

    /**
     * "one" | "many"
     */
    public function getCardinality(): string;
}
