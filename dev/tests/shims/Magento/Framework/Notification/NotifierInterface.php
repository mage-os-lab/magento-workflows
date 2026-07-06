<?php
declare(strict_types=1);

namespace Magento\Framework\Notification;

/**
 * Minimal shim for Magento\Framework\Notification\NotifierInterface. The
 * workflow CircuitBreaker type-hints it but tests that never trip the breaker
 * only need the interface to exist to satisfy construction.
 */
interface NotifierInterface
{
    public function add($severity, $title, $description, $url = '', $isInternal = true);

    public function addCritical($title, $description, $url = '');

    public function addMajor($title, $description, $url = '');

    public function addMinor($title, $description, $url = '');

    public function addNotice($title, $description, $url = '');

    public function remove($notificationId);

    public function markAsRead($notificationId);
}
