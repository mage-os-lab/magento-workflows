<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Notification;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;

/**
 * Park-time notification (docs/discovery/approval-gate.md §6): an admin-inbox
 * notice with the deep link (existing notifier machinery), plus optional
 * direct email(s) via config.notify_emails. Notification failure must NEVER
 * fail the park, so every channel is independently guarded — one broken
 * channel (bad SMTP, a notifier exception) never blocks the other, and nothing
 * here ever escapes to the caller (ApprovalTaskManager::createTask, called
 * from the executor's park path).
 */
class ParkNotifier
{
    private const ADHOC_TEMPLATE_ID = 'mageos_workflows_approvals_park_notification';
    private const ROUTE_DECISION_VIEW = 'mageos_workflows_approvals/approval/view';

    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly TransportBuilder $transportBuilder,
        private readonly BackendUrlInterface $backendUrlBuilder,
        private readonly NotificationComposer $composer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string[] $notifyEmails already-validated recipient addresses (§6: malformed/absent config degrades to empty)
     */
    public function notify(string $uuid, string $title, string $instructions, array $notifyEmails, int $storeId = 0): void
    {
        $deepLink = $this->deepLink($uuid);
        $content = $this->composer->compose($title, $instructions, $deepLink);

        $this->notifyAdminInbox($content);
        foreach ($notifyEmails as $email) {
            $this->notifyEmail($email, $content, $storeId);
        }
    }

    private function deepLink(string $uuid): string
    {
        try {
            return $this->backendUrlBuilder->getUrl(self::ROUTE_DECISION_VIEW, ['uuid' => $uuid]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Approval park notification could not build a deep link for task %s: %s',
                $uuid,
                $e->getMessage()
            ), ['exception' => $e]);
            return '';
        }
    }

    private function notifyAdminInbox(ParkNotificationContent $content): void
    {
        try {
            $this->notifier->addNotice($content->getAdminNoticeTitle(), $content->getAdminNoticeDescription());
        } catch (\Throwable $e) {
            $this->logger->error('Approval park admin-inbox notice failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function notifyEmail(string $email, ParkNotificationContent $content, int $storeId): void
    {
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier(self::ADHOC_TEMPLATE_ID)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'subject' => $content->getEmailSubject(),
                    // Escaped in PHP before reaching the template's {{var body|raw}},
                    // same convention as notify.email's ad-hoc mode.
                    'body' => nl2br(htmlspecialchars($content->getEmailBody(), ENT_QUOTES, 'UTF-8')),
                ])
                ->setFromByScope('general', $storeId)
                ->addTo($email)
                ->getTransport();
            $transport->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Approval park notification email to %s failed: %s',
                $email,
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }
}
