<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Minimal PSR-7 request, used only as the constructor argument Guzzle
 * exception classes require and as the on_redirect callback's first argument.
 * Signature-compatible with psr/http-message ^1.0/^2.0 and the shims.
 */
class FakeRequest implements RequestInterface
{
    private UriInterface $uri;

    public function __construct(?UriInterface $uri = null)
    {
        $this->uri = $uri ?? new FakeUri('fake.invalid');
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
        return new FakeStream('');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        return '/';
    }

    public function withRequestTarget($requestTarget): RequestInterface
    {
        return $this;
    }

    public function getMethod(): string
    {
        return 'POST';
    }

    public function withMethod($method): RequestInterface
    {
        return $this;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface
    {
        return new self($uri);
    }
}
