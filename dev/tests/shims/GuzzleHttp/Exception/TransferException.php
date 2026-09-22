<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace GuzzleHttp\Exception;

/**
 * Standalone-runner shim for GuzzleHttp\Exception\TransferException, matching
 * the real class hierarchy (\RuntimeException + GuzzleException). Loaded via
 * guarded require_once from WebhookExecuteTest only when real Guzzle is absent.
 */
class TransferException extends \RuntimeException implements GuzzleException
{
}
