<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
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
