<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Api\ActionInterface;
use MageOS\Workflows\Api\ActionMetadataInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * Minimal action for pool-dependent tests (validation checks, renderer):
 * configurable code/label/ACL resource, no-op execute.
 */
class StubAction implements ActionInterface, ActionMetadataInterface
{
    public function __construct(
        private readonly string $code = 'stub.action',
        private readonly string $label = 'Stub Action',
        private readonly ?string $aclResource = null
    ) {
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        return ActionResult::success([]);
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getGroup(): string
    {
        return 'Flow';
    }

    public function getApplicableEntities(): array
    {
        return [];
    }

    public function getConfigForm(): array
    {
        return [];
    }

    public function getAclResource(): ?string
    {
        return $this->aclResource;
    }
}
