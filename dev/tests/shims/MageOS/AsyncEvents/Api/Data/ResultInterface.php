<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\AsyncEvents\Api\Data;

/**
 * Standalone-runner shim for mage-os/mageos-async-events (4.x)
 * Api/Data/ResultInterface (the NotifierInterface::notify() return contract).
 */
interface ResultInterface
{
    public function getIsSuccessful(): bool;

    public function setIsSuccessful(bool $isSuccessful): void;

    public function getIsRetryable(): bool;

    public function setIsRetryable(bool $isRetryable): void;

    public function getRetryAfter(): ?int;

    public function setRetryAfter(int $retryAfter): void;
}
