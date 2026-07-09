<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Stub;

use Psr\Log\LoggerInterface;

/**
 * Records every log call as "level: message" so tests can pin that failures
 * are logged (and at which level) without caring about context arrays.
 */
class RecordingLogger implements LoggerInterface
{
    /** @var string[] */
    public array $records = [];

    public function allMessages(): string
    {
        return implode("\n", $this->records);
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'emergency: ' . $message;
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'alert: ' . $message;
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'critical: ' . $message;
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'error: ' . $message;
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'warning: ' . $message;
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'notice: ' . $message;
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'info: ' . $message;
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = 'debug: ' . $message;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = $level . ': ' . $message;
    }
}
