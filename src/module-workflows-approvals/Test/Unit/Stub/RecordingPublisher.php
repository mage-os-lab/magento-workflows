<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Stub;

use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Capturing publisher; optionally throws to exercise the publish-failure
 * rollback (docs/discovery/approval-gate.md §4).
 */
class RecordingPublisher implements PublisherInterface
{
    /** @var array<int, array{0: string, 1: mixed}> */
    public array $published = [];

    public bool $throwOnPublish = false;

    public function __construct(private readonly ?CallLog $log = null)
    {
    }

    public function publish($topicName, $data)
    {
        if ($this->log !== null) {
            $this->log->add('publish');
        }
        if ($this->throwOnPublish) {
            throw new \RuntimeException('queue unavailable');
        }
        $this->published[] = [(string) $topicName, $data];
        return null;
    }
}
