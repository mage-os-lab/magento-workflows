<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\AsyncEvents\Api\Data;

/**
 * Standalone-runner shim for mage-os/mageos-async-events (4.x)
 * Api/Data/AsyncEventDisplayInterface — the read-model interface the real
 * repository returns from get()/save(). Signature-faithful.
 */
interface AsyncEventDisplayInterface
{
    public function getSubscriptionId(): int;

    public function getEventName(): string;

    public function getRecipientUrl(): string;

    public function getStatus(): bool;

    public function getSubscribedAt(): string;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): void;
}
