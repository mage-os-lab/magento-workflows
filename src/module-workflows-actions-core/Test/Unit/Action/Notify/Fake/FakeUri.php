<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake;

use Psr\Http\Message\UriInterface;

/**
 * Minimal PSR-7 URI: only getHost() matters to the Webhook redirect
 * re-validation callback. Signature-compatible with psr/http-message
 * ^1.0/^2.0 and the dev/tests/shims interfaces (widened params, 2.0 returns).
 */
class FakeUri implements UriInterface
{
    private string $host;

    private string $scheme;

    public function __construct(string $host, string $scheme = 'https')
    {
        $this->host = $host;
        $this->scheme = $scheme;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        return $this->host;
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return null;
    }

    public function getPath(): string
    {
        return '/';
    }

    public function getQuery(): string
    {
        return '';
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme($scheme): UriInterface
    {
        return new self($this->host, (string)$scheme);
    }

    public function withUserInfo($user, $password = null): UriInterface
    {
        return $this;
    }

    public function withHost($host): UriInterface
    {
        return new self((string)$host, $this->scheme);
    }

    public function withPort($port): UriInterface
    {
        return $this;
    }

    public function withPath($path): UriInterface
    {
        return $this;
    }

    public function withQuery($query): UriInterface
    {
        return $this;
    }

    public function withFragment($fragment): UriInterface
    {
        return $this;
    }

    public function __toString(): string
    {
        return $this->scheme . '://' . $this->host . '/';
    }
}
