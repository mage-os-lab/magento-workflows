<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Exception;

/**
 * Thrown when a webhook destination (initial URL or redirect target) resolves
 * to a private/loopback/link-local/reserved/metadata address, or otherwise
 * violates the SSRF policy. Always a terminal (non-retryable) step failure.
 */
class BlockedHostException extends \RuntimeException
{
}
