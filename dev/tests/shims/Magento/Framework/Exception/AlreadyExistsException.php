<?php
declare(strict_types=1);

namespace Magento\Framework\Exception;

use Magento\Framework\Phrase;

/**
 * Standalone-runner shim for Magento\Framework\Exception\AlreadyExistsException.
 */
class AlreadyExistsException extends LocalizedException
{
    public function __construct(?Phrase $phrase = null, ?\Throwable $cause = null, int $code = 0)
    {
        parent::__construct($phrase ?? new Phrase('Unique constraint violation found'), $cause, $code);
    }
}
