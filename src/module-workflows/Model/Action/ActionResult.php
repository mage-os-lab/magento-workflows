<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Action;

class ActionResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array $output Merged into execution context as steps.<step_key>
     * @param bool $retryable Failure only: true = redeliver via queue, false = terminal step failure
     */
    public function __construct(
        private readonly string $status,
        private readonly array $output = [],
        private readonly bool $retryable = false,
        private readonly ?string $error = null
    ) {
    }

    public static function success(array $output = []): self
    {
        return new self(self::STATUS_SUCCESS, $output);
    }

    public static function skipped(?string $reason = null): self
    {
        return new self(self::STATUS_SKIPPED, $reason !== null ? ['reason' => $reason] : []);
    }

    public static function failure(string $error, bool $retryable = false, array $output = []): self
    {
        return new self(self::STATUS_FAILURE, $output, $retryable, $error);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailure(): bool
    {
        return $this->status === self::STATUS_FAILURE;
    }

    public function getOutput(): array
    {
        return $this->output;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
