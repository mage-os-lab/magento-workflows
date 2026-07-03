<?php
declare(strict_types=1);

namespace Magento\Sales\Model;

class Order
{
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

    public function canShip(): bool
    {
        throw new \RuntimeException('canShip() not implemented in shim');
    }

    public function canCreditmemo(): bool
    {
        throw new \RuntimeException('canCreditmemo() not implemented in shim');
    }
}
