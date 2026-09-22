<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Model;

/**
 * Request-scoped flag allowing SubscriptionManager to mutate workflow-owned
 * async-event subscriptions while SubscriptionOwnershipPlugin refuses the
 * same mutation for everyone else (admin UI, REST, third-party code).
 *
 * Shared (default DI scope) so the plugin and the manager observe the same
 * instance. The callable wrapper guarantees the flag is always restored,
 * including on exceptions, and supports nesting.
 */
class OwnershipBypassRegistry
{
    private bool $bypassed = false;

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    /**
     * Runs $operation with ownership enforcement suspended.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function bypass(callable $operation): mixed
    {
        $previous = $this->bypassed;
        $this->bypassed = true;
        try {
            return $operation();
        } finally {
            $this->bypassed = $previous;
        }
    }
}
