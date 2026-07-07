<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\_files;

use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * PublisherInterface double that always throws — poisons the publish step so
 * the rollback-on-publish-failure paths (Dispatcher::resumeWaiting,
 * ResumeSweeper) can be pinned: the atomic claim must be rolled back to
 * 'waiting' when the publish fails, leaving the timeout sweeper still owning
 * the execution (docs/08).
 */
class ThrowingPublisher implements PublisherInterface
{
    public int $attempts = 0;

    public function publish($topicName, $data)
    {
        $this->attempts++;
        throw new \RuntimeException('poisoned publisher: transport unavailable');
    }
}
