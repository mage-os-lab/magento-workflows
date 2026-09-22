<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsImportSuppression\Test\Unit\Plugin;

use Magento\ImportExport\Model\Import;
use MageOS\Workflows\Model\Suppression\WorkflowSuppression;
use MageOS\WorkflowsImportSuppression\Plugin\SuppressWorkflowsDuringImport;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use PHPUnit\Framework\TestCase;

/**
 * Proof that the known-bulk-path wiring (docs/07-actions.md
 * "Bulk-operation suppression") actually raises the suppression flag around
 * Magento\ImportExport\Model\Import::importSource() — the API and the
 * Dispatcher's consulting side already existed (issue #1); nothing
 * first-party ever called scope() for this path before this plugin.
 *
 * Import is constructed via ReflectionClass::newInstanceWithoutConstructor()
 * so this test neither depends on the real class's constructor
 * dependencies (irrelevant here) nor on the standalone shim doing anything
 * beyond satisfying the plugin's type hint.
 */
class SuppressWorkflowsDuringImportTest extends TestCase
{
    private WorkflowSuppression $suppressionChecker;

    public function setUp(): void
    {
        // A config that never raises the global kill switch, so isSuppressed()
        // here reflects only the nesting depth this plugin manipulates.
        $this->suppressionChecker = new WorkflowSuppression(new StubScopeConfig());
    }

    public function tearDown(): void
    {
        // Guard against a failing assertion leaking suppression depth into
        // the next test (static state is shared for the process lifetime).
        while ($this->suppressionChecker->isSuppressed()) {
            WorkflowSuppression::restore();
        }
    }

    private function subject(): Import
    {
        return (new \ReflectionClass(Import::class))->newInstanceWithoutConstructor();
    }

    public function testToggleOnSuppressesForTheDurationOfProceedOnly(): void
    {
        $plugin = new SuppressWorkflowsDuringImport(
            new StubScopeConfig([SuppressWorkflowsDuringImport::CONFIG_SUPPRESS_BULK_IMPORTS => true])
        );

        $this->assertFalse($this->suppressionChecker->isSuppressed(), 'Precondition: not suppressed before the plugin runs');

        $capturedDuring = null;
        $proceed = function () use (&$capturedDuring): bool {
            $capturedDuring = $this->suppressionChecker->isSuppressed();
            return true;
        };

        $result = $plugin->aroundImportSource($this->subject(), $proceed);

        $this->assertTrue($result);
        $this->assertTrue($capturedDuring, 'importSource() must run inside the suppression scope when the toggle is on');
        $this->assertFalse($this->suppressionChecker->isSuppressed(), 'Suppression must be lowered once importSource() returns');
    }

    public function testToggleOffRunsProceedWithoutSuppressing(): void
    {
        $plugin = new SuppressWorkflowsDuringImport(
            new StubScopeConfig([SuppressWorkflowsDuringImport::CONFIG_SUPPRESS_BULK_IMPORTS => false])
        );

        $capturedDuring = null;
        $proceed = function () use (&$capturedDuring): bool {
            $capturedDuring = $this->suppressionChecker->isSuppressed();
            return true;
        };

        $result = $plugin->aroundImportSource($this->subject(), $proceed);

        $this->assertTrue($result);
        $this->assertFalse($capturedDuring, 'The toggle is off: importSource() must run unsuppressed');
        $this->assertFalse($this->suppressionChecker->isSuppressed());
    }

    public function testExceptionFromProceedStillRestoresSuppressionDepth(): void
    {
        $plugin = new SuppressWorkflowsDuringImport(
            new StubScopeConfig([SuppressWorkflowsDuringImport::CONFIG_SUPPRESS_BULK_IMPORTS => true])
        );

        $proceed = static function (): bool {
            throw new \RuntimeException('CSV parse failure');
        };

        try {
            $plugin->aroundImportSource($this->subject(), $proceed);
            $this->fail('Expected the proceed() exception to propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('CSV parse failure', $exception->getMessage());
        }

        $this->assertFalse(
            $this->suppressionChecker->isSuppressed(),
            'A thrown exception must not leave suppression depth raised'
        );
    }
}
