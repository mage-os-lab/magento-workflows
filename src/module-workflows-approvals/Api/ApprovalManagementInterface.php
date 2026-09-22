<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Api;

use MageOS\WorkflowsApprovals\Api\Data\ApprovalDecisionResultInterface;

/**
 * The REST decision entry point (§5, POST /V1/workflow-approvals/:uuid/decision).
 * The implementation resolves the actor (admin user vs integration) from the
 * web-api context and delegates to the single decision path
 * (MageOS\WorkflowsApprovals\Model\ApprovalService::decide) — the admin UI
 * (Stage 3) posts through the same service, so races, validation, and audit are
 * identical across surfaces.
 */
interface ApprovalManagementInterface
{
    /**
     * Record a decision on the task and resume the parked execution.
     *
     * @param string $uuid the task handle
     * @param string $decision 'approved' | 'rejected'
     * @param string|null $note free-text note (capped)
     * @param mixed[] $payload flat scalar payload, allowlisted/coerced against
     *                         the gate's declared payload_fields (§5)
     * @return ApprovalDecisionResultInterface task uuid, final status, execution id
     */
    public function decide(
        string $uuid,
        string $decision,
        ?string $note = null,
        array $payload = []
    ): ApprovalDecisionResultInterface;
}
