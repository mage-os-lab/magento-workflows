<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model\Notification;

use MageOS\WorkflowsApprovals\Model\Notification\NotificationComposer;
use PHPUnit\Framework\TestCase;

class NotificationComposerTest extends TestCase
{
    public function testComposesNoticeAndEmailWithDeepLink(): void
    {
        $content = (new NotificationComposer())->compose(
            'Approve goodwill credit',
            'Customer requested outside window.',
            'https://admin.test/mageos_workflows_approvals/approval/view/uuid/abc'
        );

        $this->assertSame('Approval needed: Approve goodwill credit', $content->getAdminNoticeTitle());
        $this->assertStringContainsString('Customer requested outside window.', $content->getAdminNoticeDescription());
        $this->assertStringContainsString('https://admin.test/mageos_workflows_approvals/approval/view/uuid/abc', $content->getAdminNoticeDescription());
        $this->assertSame('Approval needed: Approve goodwill credit', $content->getEmailSubject());
        $this->assertStringContainsString('Approve goodwill credit', $content->getEmailBody());
        $this->assertStringContainsString('Customer requested outside window.', $content->getEmailBody());
        $this->assertStringContainsString('Decide: https://admin.test/mageos_workflows_approvals/approval/view/uuid/abc', $content->getEmailBody());
    }

    public function testEmptyInstructionsOmittedFromBody(): void
    {
        $content = (new NotificationComposer())->compose('Title only', '', 'https://admin.test/link');

        $this->assertSame('https://admin.test/link', $content->getAdminNoticeDescription());
        $this->assertStringNotContainsString("\n\n\n", $content->getEmailBody());
    }
}
