<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Psr\Http\Message;

/**
 * Standalone-runner shim for psr/http-message StreamInterface (psr-7 2.0
 * signatures). Loaded via guarded require_once from WebhookExecuteTest only
 * when the real package is absent — see MessageInterface shim header.
 */
interface StreamInterface
{
    public function __toString(): string;

    public function close(): void;

    /**
     * @return resource|null
     */
    public function detach();

    public function getSize(): ?int;

    public function tell(): int;

    public function eof(): bool;

    public function isSeekable(): bool;

    public function seek(int $offset, int $whence = SEEK_SET): void;

    public function rewind(): void;

    public function isWritable(): bool;

    public function write(string $string): int;

    public function isReadable(): bool;

    public function read(int $length): string;

    public function getContents(): string;

    public function getMetadata(?string $key = null);
}
