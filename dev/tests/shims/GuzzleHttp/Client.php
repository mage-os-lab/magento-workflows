<?php
declare(strict_types=1);

namespace GuzzleHttp;

class Client
{
    public function request(string $method, string $uri, array $options = [])
    {
        throw new \RuntimeException('Client shim: request() should not be called in unit tests');
    }
}
