<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised when a template code is not present in any registered source.
 */
class TemplateNotFoundException extends LocalizedException
{
}
