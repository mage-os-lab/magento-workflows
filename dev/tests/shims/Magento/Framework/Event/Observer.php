<?php
declare(strict_types=1);

namespace Magento\Framework\Event;

use Magento\Framework\DataObject;
use Magento\Framework\Event;

/**
 * Standalone-runner shim for Magento\Framework\Event\Observer. Tests
 * construct it with ['event' => new Event([...])], mirroring how the real
 * event manager hands observers their event.
 */
class Observer extends DataObject
{
    /**
     * @return Event|null
     */
    public function getEvent()
    {
        return $this->getData('event');
    }

    /**
     * @param mixed $event
     * @return $this
     */
    public function setEvent($event)
    {
        return $this->setData('event', $event);
    }
}
