<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * Capability marker: an action that is meaningful when run once over a whole
 * batch (an aggregated workflow's single execution), rather than per entity.
 *
 * Deliberately a SEPARATE interface rather than a method on
 * ActionMetadataInterface: PHP interfaces cannot carry a default body, so
 * adding supportsBatch() to the published Api/ contract would break every
 * third-party action implementor. Batch capability is therefore expressed by
 * implementing this empty marker (absence = not batch-capable), checked via
 * instanceof in the batch ProfileCheck.
 *
 * First-party actions extending AbstractAction also gain supportsBatch()
 * returning false by default; the four batch-safe core actions
 * (notify.email / notify.webhook / notify.admin / flow.set_variable) both
 * implement this marker and return true.
 *
 * Per-entity mutating actions (hold order, set attribute, …) deliberately do
 * NOT implement it — "mutate each item in a batch" is fan-out's territory,
 * not aggregation's.
 */
interface BatchCapableActionInterface
{
}
