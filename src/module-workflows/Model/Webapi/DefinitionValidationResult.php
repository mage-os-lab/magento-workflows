<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\DefinitionValidationResultInterface;

/**
 * Immutable webapi DTO for POST /V1/workflows/validate (F6).
 */
class DefinitionValidationResult implements DefinitionValidationResultInterface
{
    /**
     * @param \MageOS\Workflows\Api\Data\ValidationMessageInterface[] $messages
     */
    public function __construct(
        private readonly bool $valid,
        private readonly array $messages,
        private readonly string $plainLanguage
    ) {
    }

    public function getValid(): bool
    {
        return $this->valid;
    }

    /**
     * @inheritDoc
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getPlainLanguage(): string
    {
        return $this->plainLanguage;
    }
}
