<?php
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
