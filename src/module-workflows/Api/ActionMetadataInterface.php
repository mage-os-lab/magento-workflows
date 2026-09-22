<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * Drives admin UI form generation and authoring-time authorization.
 * Implemented by the same class as ActionInterface.
 */
interface ActionMetadataInterface
{
    /**
     * Stable action code, e.g. "order.add_comment"
     */
    public function getCode(): string;

    public function getLabel(): string;

    /**
     * Sales / Customer / Catalog / Marketing / Notify / Flow
     */
    public function getGroup(): string;

    /**
     * Entity types this action applies to, e.g. ['sales_order']; empty = all
     *
     * @return string[]
     */
    public function getApplicableEntities(): array;

    /**
     * Declarative field definitions rendered as a dynamicRows fieldset:
     * [['name' => 'comment', 'label' => 'Comment', 'type' => 'textarea', 'required' => true], ...]
     * Supported types: text, textarea, select, multiselect, boolean, integer, secret
     */
    public function getConfigForm(): array;

    /**
     * ACL resource gating who may AUTHOR this action into a workflow; null = base manage ACL
     */
    public function getAclResource(): ?string;
}
