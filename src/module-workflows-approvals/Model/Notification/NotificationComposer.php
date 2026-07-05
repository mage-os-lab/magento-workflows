<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Notification;

/**
 * Pure composition of the park-notification text (docs/discovery/approval-gate.md
 * §6): "Approval needed: <title>" + a deep link to the decision view, for both
 * the admin-inbox notice and the optional direct email. No I/O — every side
 * effect (notifier, mailer) lives in ParkNotifier, which is what carries the
 * "never fails the park" guarantee.
 */
class NotificationComposer
{
    public function compose(string $title, string $instructions, string $deepLinkUrl): ParkNotificationContent
    {
        $noticeTitle = sprintf('Approval needed: %s', $title);
        $noticeDescription = $instructions !== ''
            ? sprintf('%s (%s)', $instructions, $deepLinkUrl)
            : $deepLinkUrl;

        $emailSubject = $noticeTitle;
        $bodyLines = [$title];
        if ($instructions !== '') {
            $bodyLines[] = $instructions;
        }
        $bodyLines[] = '';
        $bodyLines[] = sprintf('Decide: %s', $deepLinkUrl);
        $emailBody = implode("\n", $bodyLines);

        return new ParkNotificationContent($noticeTitle, $noticeDescription, $emailSubject, $emailBody);
    }
}
