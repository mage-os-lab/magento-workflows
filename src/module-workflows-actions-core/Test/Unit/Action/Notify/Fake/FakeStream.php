<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake;

use Psr\Http\Message\StreamInterface;

/**
 * In-memory PSR-7 stream over a fixed string. Params are widened (untyped)
 * and return types match psr/http-message 2.0, so the class loads against
 * psr/http-message ^1.0, ^2.0, and the dev/tests/shims interfaces alike.
 */
class FakeStream implements StreamInterface
{
    private string $content;

    private int $pointer = 0;

    private bool $closed = false;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        $this->closed = true;
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return $this->pointer;
    }

    public function eof(): bool
    {
        return $this->pointer >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->pointer = $whence === SEEK_CUR ? $this->pointer + (int)$offset : (int)$offset;
    }

    public function rewind(): void
    {
        $this->pointer = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('FakeStream is read-only');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function read($length): string
    {
        if ($this->closed) {
            throw new \RuntimeException('FakeStream is closed');
        }
        $chunk = substr($this->content, $this->pointer, max(0, (int)$length));
        $this->pointer += strlen($chunk);
        return $chunk;
    }

    public function getContents(): string
    {
        $rest = substr($this->content, $this->pointer);
        $this->pointer = strlen($this->content);
        return $rest;
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }
}
