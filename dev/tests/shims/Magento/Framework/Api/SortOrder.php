<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Api;

/**
 * Minimal shim for Magento\Framework\Api\SortOrder — the sort-direction
 * constants and fluent setters the workflow scheduler references.
 */
class SortOrder
{
    public const SORT_ASC = 'ASC';
    public const SORT_DESC = 'DESC';

    private array $data = [];

    public function setField($field): self
    {
        $this->data['field'] = $field;
        return $this;
    }

    public function getField()
    {
        return $this->data['field'] ?? null;
    }

    public function setDirection($direction): self
    {
        $this->data['direction'] = $direction;
        return $this;
    }

    public function getDirection()
    {
        return $this->data['direction'] ?? null;
    }
}
