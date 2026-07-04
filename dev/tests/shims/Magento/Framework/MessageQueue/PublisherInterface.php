<?php
declare(strict_types=1);

namespace Magento\Framework\MessageQueue;

/**
 * Minimal shim for Magento\Framework\MessageQueue\PublisherInterface — the
 * single publish() method the workflow engine calls. Tests supply a capturing
 * or throwing double.
 */
interface PublisherInterface
{
    /**
     * @param string $topicName
     * @param mixed $data
     * @return mixed
     */
    public function publish($topicName, $data);
}
