<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

/**
 * Derives the default ValidationContext for the repository save plugin from
 * the application area: admin sessions and webapi tokens carry an
 * authorization context (per-action ACL runs); CLI, cron, and everything
 * area-less runs as SYSTEM (per-action ACL is skipped with the documented
 * loud warning — see WorkflowImporter / ImportCommand).
 *
 * Callers with better knowledge (WorkflowImporter, the validate endpoint)
 * build their ValidationContext explicitly instead of resolving it.
 */
class ValidationContextResolver
{
    private const ADMIN_AREAS = ['adminhtml', 'webapi_rest', 'webapi_soap'];

    public function __construct(
        private readonly State $appState
    ) {
    }

    public function resolve(): ValidationContext
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            $area = null;
        }
        $mode = in_array($area, self::ADMIN_AREAS, true)
            ? ValidationContext::MODE_ADMIN_CONTEXT
            : ValidationContext::MODE_SYSTEM;

        return new ValidationContext($mode);
    }
}
