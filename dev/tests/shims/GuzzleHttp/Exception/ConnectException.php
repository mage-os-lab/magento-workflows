<?php
declare(strict_types=1);

namespace GuzzleHttp\Exception;

use Psr\Http\Message\RequestInterface;

/**
 * Standalone-runner shim for GuzzleHttp\Exception\ConnectException (Guzzle 7
 * constructor signature; the real class additionally implements the PSR-18
 * NetworkExceptionInterface, which nothing in the tests relies on). Loaded via
 * guarded require_once from WebhookExecuteTest only when real Guzzle is absent.
 */
class ConnectException extends TransferException
{
    private RequestInterface $request;

    /** @var array */
    private $handlerContext;

    public function __construct(
        string $message,
        RequestInterface $request,
        ?\Throwable $previous = null,
        array $handlerContext = []
    ) {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
        $this->handlerContext = $handlerContext;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getHandlerContext(): array
    {
        return $this->handlerContext;
    }
}
