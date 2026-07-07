<?php
declare(strict_types=1);

namespace Magento\Framework\Exception;

/**
 * Standalone-runner shim for Magento\Framework\Exception\MailException —
 * like the real class, a plain LocalizedException subtype (the transient
 * transport failure the notify.email action treats as retryable).
 */
class MailException extends LocalizedException
{
}
