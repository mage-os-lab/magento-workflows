<?php
declare(strict_types=1);

namespace MageOS\AsyncEvents\Api\Data;

/**
 * Standalone-runner shim for mage-os/mageos-async-events (4.x)
 * Api/Data/AsyncEventInterface. Signature-faithful to the real interface so
 * test doubles written against this shim also satisfy the real package when
 * the suite runs under a full Magento install (where this file never loads).
 */
interface AsyncEventInterface
{
    public function getSubscriptionId(): int;

    public function setSubscriptionId(int $id): void;

    public function getEventName(): string;

    public function setEventName(string $eventName): void;

    public function getRecipientUrl(): string;

    public function setRecipientUrl(string $recipientURL): void;

    public function getVerificationToken(): string;

    public function setVerificationToken(string $verificationToken): void;

    public function getStatus(): bool;

    public function setStatus(bool $status): void;

    public function getSubscribedAt(): string;

    public function setSubscribedAt(string $subscribedAt): void;

    public function getMetadata(): string;

    public function setMetadata(string $metadata): void;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): void;
}
