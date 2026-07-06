<?php
declare(strict_types=1);

namespace Magento\Framework\Api;

/**
 * Minimal shim for Magento\Framework\Api\FilterBuilder.
 *
 * Real Magento declares this as a concrete CLASS (extends
 * AbstractSimpleObjectBuilder), so the shim is a class too — doubles `extends`
 * it. Inert methods; the no-argument constructor lets anonymous-class doubles
 * instantiate without the real builder's DI dependencies.
 */
class FilterBuilder
{
    public function create()
    {
        return null;
    }

    public function setField($field)
    {
        return $this;
    }

    public function setValue($value)
    {
        return $this;
    }

    public function setConditionType($type)
    {
        return $this;
    }
}
