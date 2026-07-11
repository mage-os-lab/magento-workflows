<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Notify\Fake;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Recording double for the concrete GuzzleHttp\Client the Webhook action
 * type-hints. Every request() call is captured (method, uri, options) and
 * answered by the injected handler closure — a test proves "no HTTP request
 * was issued" by asserting $requests stays empty. Overrides the exact
 * Guzzle 7 request() signature so it loads under both real Guzzle and the
 * dev/tests/shims stand-in; the parent constructor is deliberately not
 * invoked (nothing of the real client is used).
 */
class FakeHttpClient extends Client
{
    /** @var array<int, array{method: string, uri: string, options: array}> */
    public array $requests = [];

    /** @var \Closure|null fn (string $method, string $uri, array $options): ResponseInterface */
    private ?\Closure $handler;

    public function __construct(?\Closure $handler = null)
    {
        $this->handler = $handler;
    }

    public function setHandler(\Closure $handler): void
    {
        $this->handler = $handler;
    }

    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'uri' => (string)$uri, 'options' => $options];
        if ($this->handler === null) {
            throw new \LogicException('FakeHttpClient: no handler configured but a request was issued');
        }
        return ($this->handler)($method, (string)$uri, $options);
    }
}
