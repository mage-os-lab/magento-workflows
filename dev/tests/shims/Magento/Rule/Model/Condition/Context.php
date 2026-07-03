<?php
declare(strict_types=1);

namespace Magento\Rule\Model\Condition;

/**
 * Minimal shim for Magento\Rule\Model\Condition\Context.
 * Only provides constructor; methods throw since tests stub those.
 */
class Context
{
    public function __construct() {}

    public function getBuilderFactory() { throw new \RuntimeException('Not implemented in test shim'); }
    public function getRuleFactory() { throw new \RuntimeException('Not implemented in test shim'); }
    public function getCustomerFactory() { throw new \RuntimeException('Not implemented in test shim'); }
}
