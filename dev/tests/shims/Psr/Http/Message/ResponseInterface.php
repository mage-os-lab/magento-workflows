<?php
declare(strict_types=1);

namespace Psr\Http\Message;

/**
 * Standalone-runner shim for psr/http-message ResponseInterface (psr-7 2.0
 * signatures). Loaded via guarded require_once from WebhookExecuteTest only
 * when the real package is absent — see MessageInterface shim header.
 */
interface ResponseInterface extends MessageInterface
{
    public function getStatusCode(): int;

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface;

    public function getReasonPhrase(): string;
}
