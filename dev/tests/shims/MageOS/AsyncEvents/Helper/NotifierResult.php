<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\AsyncEvents\Helper;

use Magento\Framework\DataObject;
use MageOS\AsyncEvents\Api\Data\ResultInterface;

/**
 * Standalone-runner shim for mage-os/mageos-async-events (4.x)
 * Helper/NotifierResult — faithful port (DataObject-backed accessors) so
 * assertions on getIsSuccessful()/getIsRetryable()/getResponseData() observe
 * the same behavior the real class exhibits in CI.
 */
class NotifierResult extends DataObject implements ResultInterface
{
    private const SUCCESS = 'success';
    private const SUBSCRIPTION_ID = 'subscription_id';
    private const RESPONSE_DATA = 'response_data';
    private const UUID = 'uuid';
    private const DATA = 'data';
    private const IS_RETRYABLE = 'is_retryable';
    private const RETRY_AFTER = 'retry_after';

    public function getSuccess(): bool
    {
        return (bool) $this->getData(self::SUCCESS);
    }

    public function setSuccess(bool $success): void
    {
        $this->setData(self::SUCCESS, $success);
    }

    public function getSubscriptionId(): int
    {
        return (int) $this->getData(self::SUBSCRIPTION_ID);
    }

    public function setSubscriptionId(int $subscriptionId): void
    {
        $this->setData(self::SUBSCRIPTION_ID, $subscriptionId);
    }

    public function getResponseData(): string
    {
        return (string) $this->getData(self::RESPONSE_DATA);
    }

    public function setResponseData(string $responseData): void
    {
        $this->setData(self::RESPONSE_DATA, $responseData);
    }

    public function getUuid(): string
    {
        return (string) $this->getData(self::UUID);
    }

    public function setUuid(string $uuid): void
    {
        $this->setData(self::UUID, $uuid);
    }

    /**
     * @return array
     */
    public function getAsyncEventData(): array
    {
        return $this->getData(self::DATA);
    }

    /**
     * @param array $eventData
     */
    public function setAsyncEventData(array $eventData): void
    {
        $this->setData(self::DATA, $eventData);
    }

    public function getIsSuccessful(): bool
    {
        return $this->getSuccess();
    }

    public function setIsSuccessful(bool $isSuccessful): void
    {
        $this->setSuccess($isSuccessful);
    }

    public function getIsRetryable(): bool
    {
        return (bool) $this->getData(self::IS_RETRYABLE);
    }

    public function setIsRetryable(bool $isRetryable): void
    {
        $this->setData(self::IS_RETRYABLE, $isRetryable);
    }

    public function getRetryAfter(): ?int
    {
        return $this->getData(self::RETRY_AFTER);
    }

    public function setRetryAfter(int $retryAfter): void
    {
        $this->setData(self::RETRY_AFTER, $retryAfter);
    }
}
