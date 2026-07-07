<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

use Psr\Log\LoggerInterface;

/**
 * PSR-3 logger that records every entry as [level, message] so tests can
 * assert on what was logged (and at which severity) without string output.
 */
class RecordingLogger implements LoggerInterface
{
    /** @var array<int, array{level: string, message: string}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /**
     * @return string[] messages recorded at $level
     */
    public function messagesAt(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }
        return $messages;
    }

    public function hasMessageContaining(string $level, string $needle): bool
    {
        foreach ($this->messagesAt($level) as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }
}
