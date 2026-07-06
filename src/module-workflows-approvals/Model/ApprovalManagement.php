<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Authorization\Model\UserContextInterface;
use MageOS\WorkflowsApprovals\Api\ApprovalManagementInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalDecisionResultInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * REST entry point for the decision endpoint (§5). Resolves the acting actor
 * (admin user vs integration) from the web-api user context and delegates to
 * ApprovalService::decide — the single decision path shared with the admin UI
 * (Stage 3). The int task PK is never exposed; the uuid is the sole handle.
 */
class ApprovalManagement implements ApprovalManagementInterface
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly UserContextInterface $userContext
    ) {
    }

    /**
     * @inheritDoc
     */
    public function decide(
        string $uuid,
        string $decision,
        ?string $note = null,
        array $payload = []
    ): ApprovalDecisionResultInterface {
        [$actorType, $actorId] = $this->resolveActor();

        return $this->approvalService->decide($uuid, $decision, $note, $payload, $actorType, $actorId);
    }

    /**
     * @return array{0: string, 1: string} [actorType, actorId]
     */
    private function resolveActor(): array
    {
        $actorType = $this->userContext->getUserType() === UserContextInterface::USER_TYPE_INTEGRATION
            ? ApprovalInterface::DECIDER_INTEGRATION
            : ApprovalInterface::DECIDER_ADMIN;

        return [$actorType, (string) ($this->userContext->getUserId() ?? '')];
    }
}
