<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Exception;

use Magento\Framework\Phrase;

/**
 * Standalone-runner shim for Magento\Framework\Exception\LocalizedException.
 */
class LocalizedException extends \Exception
{
    protected Phrase $phrase;

    public function __construct(Phrase $phrase, ?\Throwable $cause = null, int $code = 0)
    {
        $this->phrase = $phrase;
        parent::__construct($phrase->render(), $code, $cause);
    }

    public function getRawMessage(): string
    {
        return $this->phrase->getText();
    }

    public function getParameters(): array
    {
        return $this->phrase->getArguments();
    }

    public function getLogMessage(): string
    {
        return $this->phrase->render();
    }
}
