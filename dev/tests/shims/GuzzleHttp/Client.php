<?php
declare(strict_types=1);

namespace GuzzleHttp;

use Psr\Http\Message\ResponseInterface;

/**
 * Standalone-runner shim for GuzzleHttp\Client (Guzzle 7). Only the request()
 * signature the Webhook action calls is declared, matching the real
 * `Client::request(string $method, $uri = '', array $options = []): ResponseInterface`
 * so test doubles that `extends` + override it load under both this shim and
 * a real Guzzle install.
 *
 * NOTE: the runner's shim autoloader only covers Magento\ and Psr\Log\, so
 * this file is require_once'd (guarded by class_exists) from
 * WebhookExecuteTest; a real Composer install of guzzlehttp/guzzle always wins.
 */
class Client
{
    public function __construct(array $config = [])
    {
    }

    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        throw new \RuntimeException(
            'GuzzleHttp\Client shim: request() must be overridden by a test double — no real HTTP in unit tests'
        );
    }
}
