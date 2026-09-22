<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Minimal PSR-7 response: status code + string body (streamed via
 * FakeStream, matching the Webhook action's chunked capped read).
 * Signature-compatible with psr/http-message ^1.0/^2.0 and the shims.
 */
class FakeResponse implements ResponseInterface
{
    private int $statusCode;

    private FakeStream $body;

    public function __construct(int $statusCode = 200, string $body = '')
    {
        $this->statusCode = $statusCode;
        $this->body = new FakeStream($body);
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion($version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader($name): bool
    {
        return false;
    }

    public function getHeader($name): array
    {
        return [];
    }

    public function getHeaderLine($name): string
    {
        return '';
    }

    public function withHeader($name, $value): MessageInterface
    {
        return $this;
    }

    public function withAddedHeader($name, $value): MessageInterface
    {
        return $this;
    }

    public function withoutHeader($name): MessageInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus($code, $reasonPhrase = ''): ResponseInterface
    {
        return new self((int)$code, (string)$this->body);
    }

    public function getReasonPhrase(): string
    {
        return '';
    }
}
