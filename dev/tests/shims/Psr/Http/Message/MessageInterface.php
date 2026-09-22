<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Psr\Http\Message;

/**
 * Standalone-runner shim for psr/http-message MessageInterface (psr-7 2.0
 * signatures; implementations widening params / adding these return types are
 * compatible with psr/http-message ^1.0 too).
 *
 * NOTE: the runner's shim autoloader only covers Magento\ and Psr\Log\, so
 * this file is require_once'd (guarded by interface_exists) from
 * WebhookExecuteTest; a real Composer install of psr/http-message always wins.
 */
interface MessageInterface
{
    public function getProtocolVersion(): string;

    public function withProtocolVersion(string $version): MessageInterface;

    public function getHeaders(): array;

    public function hasHeader(string $name): bool;

    public function getHeader(string $name): array;

    public function getHeaderLine(string $name): string;

    public function withHeader(string $name, $value): MessageInterface;

    public function withAddedHeader(string $name, $value): MessageInterface;

    public function withoutHeader(string $name): MessageInterface;

    public function getBody(): StreamInterface;

    public function withBody(StreamInterface $body): MessageInterface;
}
