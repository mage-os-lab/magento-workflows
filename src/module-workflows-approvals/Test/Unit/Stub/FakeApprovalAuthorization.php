<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Stub;

use MageOS\WorkflowsApprovals\Api\ApprovalAuthorizationInterface;

/**
 * Configurable role-check double for the decision-path authorization seam.
 */
class FakeApprovalAuthorization implements ApprovalAuthorizationInterface
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    public array $calls = [];

    public function __construct(private readonly bool $allow)
    {
    }

    public function actorHoldsRole(string $actorType, string $actorId, string $role): bool
    {
        $this->calls[] = [$actorType, $actorId, $role];
        return $this->allow;
    }
}
