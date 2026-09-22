<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Mail\Template;

class TransportBuilder
{
    public function setTemplateIdentifier(string $id): self
    {
        return $this;
    }

    public function setTemplateOptions(array $options): self
    {
        return $this;
    }

    public function setTemplateVars(array $vars): self
    {
        return $this;
    }

    public function setFromByScope(string $scope, int $storeId): self
    {
        return $this;
    }

    public function addTo(string $email): self
    {
        return $this;
    }

    public function getTransport()
    {
        throw new \RuntimeException('TransportBuilder shim: getTransport() should only be called with real TransportBuilder');
    }
}
