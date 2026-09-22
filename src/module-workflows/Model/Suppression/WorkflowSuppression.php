<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Suppression;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Bulk-operation suppression API (docs/07-actions.md — loop prevention / guards).
 *
 * Imports and mass-actions firing 100k product.saved events must not detonate
 * the per-entity engine. Wrap the bulk path:
 *
 *     WorkflowSuppression::scope(function () use ($import) { $import->run(); });
 *
 * The dispatcher consults isSuppressed() and silently drops dispatches while
 * the flag is raised. Suppression state is process-static (spans the whole
 * request/CLI run, nests safely); the config flag
 * mageos_workflows/general/suppress_all provides a global kill switch.
 */
class WorkflowSuppression
{
    public const CONFIG_SUPPRESS_ALL = 'mageos_workflows/general/suppress_all';

    /**
     * Nesting counter: suppress() increments, restore() decrements
     */
    private static int $suppressionDepth = 0;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Run $fn with workflow dispatch suppressed for its duration; returns $fn's result.
     */
    public static function scope(callable $fn): mixed
    {
        self::suppress();
        try {
            return $fn();
        } finally {
            self::restore();
        }
    }

    /**
     * Raise the suppression flag. Pair every call with restore(); prefer scope().
     */
    public static function suppress(): void
    {
        self::$suppressionDepth++;
    }

    /**
     * Lower the suppression flag raised by the matching suppress() call.
     */
    public static function restore(): void
    {
        if (self::$suppressionDepth > 0) {
            self::$suppressionDepth--;
        }
    }

    /**
     * True while inside a suppression scope OR when the global config
     * kill switch (mageos_workflows/general/suppress_all) is enabled.
     */
    public function isSuppressed(): bool
    {
        if (self::$suppressionDepth > 0) {
            return true;
        }
        return $this->scopeConfig->isSetFlag(self::CONFIG_SUPPRESS_ALL);
    }
}
