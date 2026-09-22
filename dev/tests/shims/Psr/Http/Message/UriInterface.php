<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Psr\Http\Message;

/**
 * Standalone-runner shim for psr/http-message UriInterface (psr-7 2.0
 * signatures). Loaded via guarded require_once from WebhookExecuteTest only
 * when the real package is absent — see MessageInterface shim header.
 */
interface UriInterface
{
    public function getScheme(): string;

    public function getAuthority(): string;

    public function getUserInfo(): string;

    public function getHost(): string;

    public function getPort(): ?int;

    public function getPath(): string;

    public function getQuery(): string;

    public function getFragment(): string;

    public function withScheme(string $scheme): UriInterface;

    public function withUserInfo(string $user, ?string $password = null): UriInterface;

    public function withHost(string $host): UriInterface;

    public function withPort(?int $port): UriInterface;

    public function withPath(string $path): UriInterface;

    public function withQuery(string $query): UriInterface;

    public function withFragment(string $fragment): UriInterface;

    public function __toString(): string;
}
