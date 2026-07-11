<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

/**
 * Duck-typed EventPublisher double (the detectors call publish() via
 * method_exists(), never against a type). Records successful publishes and
 * can be told to fail, so tests can pin "publish failure => no dedupe flag,
 * retried next run".
 */
class RecordingPublisher
{
    /** @var array<int, array{0:string, 1:array}> [event name, payload] of SUCCESSFUL publishes */
    public array $published = [];

    /** @var int publish() invocations, including failed ones */
    public int $attempts = 0;

    /** @var \Throwable|null when set, every publish() throws it */
    public ?\Throwable $failWith = null;

    public function publish(string $event, array $payload): void
    {
        $this->attempts++;
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->published[] = [$event, $payload];
    }
}
