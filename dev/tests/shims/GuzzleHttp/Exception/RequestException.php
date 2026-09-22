<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace GuzzleHttp\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Standalone-runner shim for GuzzleHttp\Exception\RequestException (Guzzle 7
 * constructor signature, minimal accessors). Loaded via guarded require_once
 * from WebhookExecuteTest only when real Guzzle is absent.
 */
class RequestException extends TransferException
{
    private RequestInterface $request;

    private ?ResponseInterface $response;

    /** @var array */
    private $handlerContext;

    public function __construct(
        string $message,
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?\Throwable $previous = null,
        array $handlerContext = []
    ) {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
        $this->response = $response;
        $this->handlerContext = $handlerContext;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function getHandlerContext(): array
    {
        return $this->handlerContext;
    }
}
