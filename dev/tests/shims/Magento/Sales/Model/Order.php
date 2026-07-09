<?php
declare(strict_types=1);

namespace Magento\Sales\Model;

class Order
{
    public const STATE_NEW = 'new';
    public const STATE_PENDING_PAYMENT = 'pending_payment';
    public const STATE_PROCESSING = 'processing';
    public const STATE_COMPLETE = 'complete';
    public const STATE_CLOSED = 'closed';
    public const STATE_CANCELED = 'canceled';
    public const STATE_HOLDED = 'holded';
    public const STATE_PAYMENT_REVIEW = 'payment_review';

    private int $entityId = 0;
    private string $incrementId = '';
    private string $state = '';

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function setEntityId(int $id): self
    {
        $this->entityId = $id;
        return $this;
    }

    public function getIncrementId(): string
    {
        return $this->incrementId;
    }

    public function setIncrementId(string $id): self
    {
        $this->incrementId = $id;
        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = $state;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStatus()
    {
        return null;
    }

    /**
     * @return string|null
     */
    public function getCreatedAt()
    {
        return null;
    }

    /**
     * @return array
     */
    public function getStatusHistories()
    {
        return [];
    }

    /**
     * @return float|string|null
     */
    public function getTotalPaid()
    {
        return null;
    }

    /**
     * @return float|string|null
     */
    public function getTotalRefunded()
    {
        return null;
    }

    public function canShip(): bool
    {
        throw new \RuntimeException('canShip() not implemented in shim');
    }

    public function canCreditmemo(): bool
    {
        throw new \RuntimeException('canCreditmemo() not implemented in shim');
    }
}
