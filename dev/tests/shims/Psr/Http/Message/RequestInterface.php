<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Psr\Http\Message;

/**
 * Standalone-runner shim for psr/http-message RequestInterface (psr-7 2.0
 * signatures). Loaded via guarded require_once from WebhookExecuteTest only
 * when the real package is absent — see MessageInterface shim header.
 */
interface RequestInterface extends MessageInterface
{
    public function getRequestTarget(): string;

    public function withRequestTarget(string $requestTarget): RequestInterface;

    public function getMethod(): string;

    public function withMethod(string $method): RequestInterface;

    public function getUri(): UriInterface;

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface;
}
