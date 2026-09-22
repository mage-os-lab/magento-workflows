<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Template;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Validation\ValidationContext;

/**
 * Agency-deployment helper (06 §5): install a template from a data patch.
 *
 * Inject this into a Setup\Patch\Data\* patch and call forTemplate() in its
 * apply():
 *
 *   public function apply(): void
 *   {
 *       $this->installTemplatePatch->forTemplate('abandoned-cart-recovery-coupon', [
 *           'reminder_wait' => 'PT4H',
 *           'coupon_rule_id' => '7',
 *       ]);
 *   }
 *
 * Runs in SYSTEM mode (data patches run as system; agencies own that risk, like
 * CLI import) and installs disabled by default — never auto-enabled.
 */
class InstallTemplatePatch
{
    public function __construct(
        private readonly TemplateInstaller $templateInstaller
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function forTemplate(
        string $code,
        array $parameters = [],
        bool $shadow = false
    ): TemplateInstallResult {
        return $this->templateInstaller->install(new TemplateInstallRequest(
            $code,
            $parameters,
            $shadow ? WorkflowInterface::STATUS_SHADOW : WorkflowInterface::STATUS_DISABLED,
            ValidationContext::MODE_SYSTEM,
            'data-patch'
        ));
    }
}
