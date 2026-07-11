<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use MageOS\AsyncEvents\Api\Data\AsyncEventDisplayInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;

/**
 * In-memory async-event subscription. Implements both the write model
 * (AsyncEventInterface) and the read model (AsyncEventDisplayInterface),
 * exactly like the real AsyncEvent resource model does — which is what lets
 * the repository fake return the same instance from get()/getList()/save().
 */
class FakeAsyncEvent implements AsyncEventInterface, AsyncEventDisplayInterface
{
    private int $subscriptionId = 0;
    private string $eventName = '';
    private string $recipientUrl = '';
    private string $verificationToken = '';
    private bool $status = false;
    private string $subscribedAt = '';
    private string $metadata = '';
    private int $storeId = 0;

    public function getSubscriptionId(): int
    {
        return $this->subscriptionId;
    }

    public function setSubscriptionId(int $id): void
    {
        $this->subscriptionId = $id;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function setEventName(string $eventName): void
    {
        $this->eventName = $eventName;
    }

    public function getRecipientUrl(): string
    {
        return $this->recipientUrl;
    }

    public function setRecipientUrl(string $recipientURL): void
    {
        $this->recipientUrl = $recipientURL;
    }

    public function getVerificationToken(): string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(string $verificationToken): void
    {
        $this->verificationToken = $verificationToken;
    }

    public function getStatus(): bool
    {
        return $this->status;
    }

    public function setStatus(bool $status): void
    {
        $this->status = $status;
    }

    public function getSubscribedAt(): string
    {
        return $this->subscribedAt;
    }

    public function setSubscribedAt(string $subscribedAt): void
    {
        $this->subscribedAt = $subscribedAt;
    }

    public function getMetadata(): string
    {
        return $this->metadata;
    }

    public function setMetadata(string $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function setStoreId(int $storeId): void
    {
        $this->storeId = $storeId;
    }
}
