<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One entry of GET /V1/workflows/meta/actions (F6): the canvas palette and
 * config-panel generator read this to place a node and build its form. Projects
 * an {@see \MageOS\Workflows\Api\ActionMetadataInterface} SPI implementation to
 * a flat, transport-safe DTO.
 *
 * getConfigForm() is arbitrarily shaped (declarative field defs, now optionally
 * carrying the F6 option-source union `options` / `options_search`), so it
 * travels as a JSON string — the same flat-contract posture as the dry-run
 * trace DTO. The client parses it back to the field-def array.
 *
 * @api
 */
interface ActionMetadataItemInterface
{
    /**
     * Stable action code, e.g. "order.add_comment"
     */
    public function getCode(): string;

    /**
     * Merchant-facing label
     */
    public function getLabel(): string;

    /**
     * Sales / Customer / Catalog / Marketing / Notify / Flow
     */
    public function getGroup(): string;

    /**
     * Entity types this action applies to; empty = all
     *
     * @return string[]
     */
    public function getApplicableEntities(): array;

    /**
     * getConfigForm() field definitions, JSON-encoded.
     */
    public function getConfigForm(): string;

    /**
     * ACL resource gating authoring of this action, or null for the base ACL.
     */
    public function getAclResource(): ?string;
}
