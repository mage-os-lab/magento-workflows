<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsImportSuppression\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\ImportExport\Model\Import;
use MageOS\Workflows\Model\Suppression\WorkflowSuppression;

/**
 * Bulk-operation suppression, wired to a known bulk path (docs/07-actions.md
 * "Loop prevention, storms, and circuit breaking" — Bulk-operation
 * suppression): Magento\ImportExport\Model\Import::importSource() is the
 * chokepoint every admin/CLI CSV import (catalog_product, customer, etc.)
 * funnels through. A 100k-row product import fires 100k product.saved
 * events; without this plugin each one detonates the per-entity engine —
 * 100k dispatch evaluations, debounce inserts, and potentially 100k
 * executions for a single operator action. WorkflowSuppression already
 * exposes the scope() API and the Dispatcher already honors isSuppressed(),
 * but nothing first-party ever raised the flag for this path.
 *
 * Around, not before/after: the flag must be raised before importSource()
 * starts validating/persisting rows and lowered once it returns (or throws),
 * which only an around-plugin's try/finally shape gives us. WorkflowSuppression
 * itself is a static nesting counter, so an import nested inside another
 * suppressed bulk operation (or inside bin/magento's own suppression) composes
 * safely.
 *
 * Config toggle (mageos_workflows/general/suppress_bulk_imports, default
 * enabled): imports are the common case this plugin exists for, so the safe
 * default suppresses them. Disable it for a store that deliberately wants
 * per-row workflow reactions during import (accepting the storm risk).
 *
 * Ships as its own module (MageOS_WorkflowsImportSuppression) so the core
 * engine carries no hard dependency on Magento_ImportExport: a plugin target
 * must exist at di:compile time, and packaging the wiring separately keeps
 * that requirement scoped to installs that actually have ImportExport.
 */
class SuppressWorkflowsDuringImport
{
    public const CONFIG_SUPPRESS_BULK_IMPORTS = 'mageos_workflows/general/suppress_bulk_imports';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function aroundImportSource(Import $subject, callable $proceed): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_SUPPRESS_BULK_IMPORTS)) {
            return $proceed();
        }

        return WorkflowSuppression::scope($proceed);
    }
}
