<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\_files;

use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Capturing PublisherInterface double: records every (topic, message) so the
 * "publishes exactly once" claim of the sweeper / wait-resume paths (docs/08)
 * can be asserted without draining a real transport.
 */
class RecordingPublisher implements PublisherInterface
{
    /**
     * @var array<int, array{topic: string, message: string}>
     */
    public array $published = [];

    public function publish($topicName, $data)
    {
        $this->published[] = ['topic' => (string) $topicName, 'message' => (string) $data];
        return null;
    }

    /**
     * @return string[] messages published to the given topic
     */
    public function messagesFor(string $topic): array
    {
        $out = [];
        foreach ($this->published as $entry) {
            if ($entry['topic'] === $topic) {
                $out[] = $entry['message'];
            }
        }
        return $out;
    }

    public function count(): int
    {
        return count($this->published);
    }
}
