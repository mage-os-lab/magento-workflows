<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Notification;

/**
 * Composed park-notification content (docs/discovery/approval-gate.md §6): the
 * admin-inbox notice and the optional direct-email text, both carrying the
 * deep link to the decision view.
 */
class ParkNotificationContent
{
    public function __construct(
        private readonly string $adminNoticeTitle,
        private readonly string $adminNoticeDescription,
        private readonly string $emailSubject,
        private readonly string $emailBody
    ) {
    }

    public function getAdminNoticeTitle(): string
    {
        return $this->adminNoticeTitle;
    }

    public function getAdminNoticeDescription(): string
    {
        return $this->adminNoticeDescription;
    }

    public function getEmailSubject(): string
    {
        return $this->emailSubject;
    }

    public function getEmailBody(): string
    {
        return $this->emailBody;
    }
}
