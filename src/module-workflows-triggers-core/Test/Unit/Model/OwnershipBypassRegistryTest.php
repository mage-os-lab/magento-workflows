<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Model;

use MageOS\WorkflowsTriggersCore\Model\OwnershipBypassRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The registry's docblock promises finally-semantics: the bypass flag is set
 * only inside the bypass() callback and ALWAYS restored afterwards — on
 * return, on exception, and correctly through nesting. If any of this broke,
 * either SubscriptionManager would be blocked by its own guard plugin, or a
 * leaked flag would let out-of-band code mutate workflow-owned subscriptions
 * for the rest of the request (docs/10-security.md#subscription-ownership).
 */
class OwnershipBypassRegistryTest extends TestCase
{
    public function testNotBypassedByDefault(): void
    {
        $registry = new OwnershipBypassRegistry();

        $this->assertFalse($registry->isBypassed());
    }

    public function testFlagIsSetInsideCallbackAndRestoredAfter(): void
    {
        $registry = new OwnershipBypassRegistry();
        $seenInside = null;

        $registry->bypass(function () use ($registry, &$seenInside) {
            $seenInside = $registry->isBypassed();
        });

        $this->assertTrue($seenInside);
        $this->assertFalse($registry->isBypassed());
    }

    public function testCallbackReturnValuePropagates(): void
    {
        $registry = new OwnershipBypassRegistry();

        $result = $registry->bypass(fn () => 'saved-subscription');

        $this->assertSame('saved-subscription', $result);
    }

    public function testFlagRestoredWhenCallbackThrows(): void
    {
        $registry = new OwnershipBypassRegistry();
        $caught = null;

        try {
            $registry->bypass(function (): void {
                throw new \RuntimeException('save failed');
            });
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'exception must propagate out of bypass()');
        $this->assertSame('save failed', $caught->getMessage());
        $this->assertFalse($registry->isBypassed(), 'flag must be restored even on exception');
    }

    public function testNestedBypassDoesNotClearOuterScope(): void
    {
        $registry = new OwnershipBypassRegistry();
        $afterInner = null;

        $registry->bypass(function () use ($registry, &$afterInner) {
            $registry->bypass(fn () => null);
            // The inner bypass ended, but we are still inside the outer one.
            $afterInner = $registry->isBypassed();
        });

        $this->assertTrue($afterInner, 'inner bypass must not clear the outer scope');
        $this->assertFalse($registry->isBypassed());
    }

    public function testNestedBypassRestoredEvenWhenInnerThrows(): void
    {
        $registry = new OwnershipBypassRegistry();
        $afterInnerFailure = null;

        $registry->bypass(function () use ($registry, &$afterInnerFailure) {
            try {
                $registry->bypass(function (): void {
                    throw new \RuntimeException('inner failure');
                });
            } catch (\RuntimeException $exception) {
                // swallowed: outer scope continues its own work
            }
            $afterInnerFailure = $registry->isBypassed();
        });

        $this->assertTrue($afterInnerFailure, 'outer scope must stay bypassed after inner failure');
        $this->assertFalse($registry->isBypassed());
    }
}
