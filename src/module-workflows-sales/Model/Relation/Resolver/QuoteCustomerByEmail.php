<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Relation\Resolver;

use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * `quote.customer_by_email` — the customer whose account email matches a
 * quote's `customer_email` (docs/discovery/entity-cross-referencing.md §4).
 * Powers guest abandoned-cart flows on `quote.abandoned` (known email, no
 * account): identical scoping to the order twin, different source entity.
 */
class QuoteCustomerByEmail extends AbstractCustomerByEmail
{
    public function getCode(): string
    {
        return 'quote.customer_by_email';
    }

    public function getLabel(): string
    {
        return (string) __('a customer account matching the cart email');
    }

    public function getSourceEntityType(): string
    {
        return HydrationProviderInterface::TYPE_QUOTE;
    }
}
