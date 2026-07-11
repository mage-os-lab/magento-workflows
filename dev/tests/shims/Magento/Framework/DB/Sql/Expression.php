<?php
declare(strict_types=1);

namespace Magento\Framework\DB\Sql;

/**
 * Standalone-runner shim for Magento\Framework\DB\Sql\Expression — the
 * framework wrapper around \Zend_Db_Expr for unquoted SQL fragments (e.g.
 * COUNT(*)). Real installs load the framework class (which extends
 * \Zend_Db_Expr); here a minimal literal holder is enough, since the fake
 * adapters interpret the recorded WHERE fragments, not the selected columns.
 */
class Expression
{
    public function __construct(private readonly string $expression = '')
    {
    }

    public function __toString(): string
    {
        return $this->expression;
    }
}
