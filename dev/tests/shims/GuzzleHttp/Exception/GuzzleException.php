<?php
declare(strict_types=1);

namespace GuzzleHttp\Exception;

/**
 * Standalone-runner shim for GuzzleHttp\Exception\GuzzleException. The real
 * interface extends Psr\Http\Client\ClientExceptionInterface (which extends
 * \Throwable); the shim collapses that to \Throwable — the only contract the
 * tests and the Webhook action rely on. Loaded via guarded require_once from
 * WebhookExecuteTest only when real Guzzle is absent.
 */
interface GuzzleException extends \Throwable
{
}
