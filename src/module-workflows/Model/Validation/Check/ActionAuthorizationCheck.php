<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use Magento\Framework\AuthorizationInterface;
use MageOS\Workflows\Api\ActionMetadataInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Per-action ACL re-authorization (docs/09 — authoring gates, not just
 * execution), relocated here from the admin Save controller so every
 * authoring path (admin Save, REST save, gallery install) runs it.
 *
 * No-ops when the context auth mode is SYSTEM (CLI import, data patches —
 * the documented loud-warning path) or when the context is a dry-run
 * validate pass rather than an authoring save.
 *
 * Unknown action codes are ActionCodesCheck's finding; this check only
 * authorizes codes that resolve.
 */
class ActionAuthorizationCheck implements CheckInterface
{
    public const CODE_ACTION_UNAUTHORIZED = 'ACTION_UNAUTHORIZED';

    private const DEFAULT_ACL_RESOURCE = 'MageOS_Workflows::manage';

    public function __construct(
        private readonly ActionPool $actionPool,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        if ($context->getAuthMode() === ValidationContext::MODE_SYSTEM || $context->isDryRun()) {
            return [];
        }
        $definition = $subject->getDefinition();
        if ($definition === null) {
            return [];
        }
        $messages = [];
        foreach ($definition->getSteps() as $stepKey => $step) {
            if (($step['type'] ?? null) !== Definition::STEP_ACTION) {
                continue;
            }
            $code = (string) ($step['action'] ?? '');
            if (!$this->actionPool->has($code)) {
                continue;
            }
            $action = $this->actionPool->get($code);
            $resource = ($action instanceof ActionMetadataInterface ? $action->getAclResource() : null)
                ?? self::DEFAULT_ACL_RESOURCE;
            if (!$this->authorization->isAllowed($resource)) {
                $messages[] = ValidationMessage::error(
                    self::CODE_ACTION_UNAUTHORIZED,
                    (string) __(
                        'You are not authorized to author the "%1" action into a workflow (step "%2").',
                        $code,
                        (string) $stepKey
                    ),
                    (string) $stepKey
                );
            }
        }
        return $messages;
    }
}
