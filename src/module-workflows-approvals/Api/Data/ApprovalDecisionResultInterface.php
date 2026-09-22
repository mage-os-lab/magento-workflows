<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Api\Data;

/**
 * The serializable outcome of ApprovalManagementInterface::decide — the task's
 * uuid, its final status, and the execution the decision resumed.
 */
interface ApprovalDecisionResultInterface
{
    public function getUuid(): string;

    public function getStatus(): string;

    public function getExecutionId(): int;
}
