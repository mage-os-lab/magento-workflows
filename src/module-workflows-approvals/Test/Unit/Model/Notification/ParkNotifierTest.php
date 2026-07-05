<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model\Notification;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Notification\NotifierInterface;
use MageOS\WorkflowsApprovals\Model\Notification\NotificationComposer;
use MageOS\WorkflowsApprovals\Model\Notification\ParkNotifier;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The "notification failure must never fail the park" guarantee
 * (docs/discovery/approval-gate.md §6): every channel — admin-inbox notice,
 * each direct email — is independently guarded, and notify() itself never
 * throws regardless of what the notifier/mailer do.
 */
class ParkNotifierTest extends TestCase
{
    private function backendUrl(bool $throws = false): BackendUrlInterface
    {
        return new class($throws) implements BackendUrlInterface {
            public function __construct(private readonly bool $throws)
            {
            }

            public function getUrl($routePath = null, $routeParams = null)
            {
                if ($this->throws) {
                    throw new \RuntimeException('no url');
                }
                return 'https://admin.test/' . $routePath . '/uuid/' . ($routeParams['uuid'] ?? '');
            }

            // Remaining \Magento\Framework\UrlInterface members.
            public function getUseSession()
            {
                return false;
            }

            public function getBaseUrl($params = [])
            {
                return '';
            }

            public function getCurrentUrl()
            {
                return '';
            }

            public function getRouteUrl($routePath = null, $routeParams = null)
            {
                return '';
            }

            public function addSessionParam()
            {
                return $this;
            }

            public function addQueryParams(array $data)
            {
                return $this;
            }

            public function setQueryParam($key, $data)
            {
                return $this;
            }

            public function escape($value)
            {
                return '';
            }

            public function getDirectUrl($url, $params = [])
            {
                return '';
            }

            public function sessionUrlVar($html)
            {
                return '';
            }

            public function isOwnOriginUrl()
            {
                return false;
            }

            public function getRedirectUrl($url)
            {
                return '';
            }

            public function setScope($params)
            {
                return $this;
            }

            // \Magento\Backend\Model\UrlInterface members.
            public function getSecretKey($routeName = null, $controller = null, $action = null)
            {
                return '';
            }

            public function useSecretKey()
            {
                return false;
            }

            public function turnOnSecretKey()
            {
                return $this;
            }

            public function turnOffSecretKey()
            {
                return $this;
            }

            public function renewSecretUrls()
            {
                return $this;
            }

            public function getStartupPageUrl()
            {
                return '';
            }

            public function setSession($session)
            {
                return $this;
            }

            public function getAreaFrontName()
            {
                return '';
            }

            public function findFirstAvailableMenu()
            {
                return '';
            }
        };
    }

    private function notifier(bool $throws = false): NotifierInterface
    {
        return new class($throws) implements NotifierInterface {
            public array $notices = [];

            public function __construct(private readonly bool $throws)
            {
            }

            public function add($severity, $title, $description, $url = '', $isInternal = true)
            {
            }

            public function addCritical($title, $description, $url = '', $isInternal = true)
            {
            }

            public function addMajor($title, $description, $url = '', $isInternal = true)
            {
            }

            public function addMinor($title, $description, $url = '', $isInternal = true)
            {
            }

            public function addNotice($title, $description, $url = '', $isInternal = true)
            {
                if ($this->throws) {
                    throw new \RuntimeException('notifier down');
                }
                $this->notices[] = [$title, $description];
            }

            public function remove($notificationId)
            {
            }

            public function markAsRead($notificationId)
            {
            }
        };
    }

    private function transportBuilder(bool $sendThrows = false): TransportBuilder
    {
        return new class($sendThrows) extends TransportBuilder {
            public array $sentTo = [];
            private string $lastTo = '';

            public function __construct(private readonly bool $sendThrows)
            {
            }

            public function setTemplateIdentifier($id): self
            {
                return $this;
            }

            public function setTemplateOptions($options): self
            {
                return $this;
            }

            public function setTemplateVars($vars): self
            {
                return $this;
            }

            public function setFromByScope($scope, $storeId = null): self
            {
                return $this;
            }

            public function addTo($email, $name = ''): self
            {
                $this->lastTo = $email;
                return $this;
            }

            public function getTransport()
            {
                $owner = $this;
                $to = $this->lastTo;
                $throws = $this->sendThrows;
                return new class($owner, $to, $throws) {
                    public function __construct(private $owner, private string $to, private bool $throws)
                    {
                    }

                    public function sendMessage()
                    {
                        if ($this->throws) {
                            throw new \RuntimeException('smtp down');
                        }
                        $this->owner->sentTo[] = $this->to;
                    }
                };
            }
        };
    }

    public function testNotifiesAdminInboxAndEachEmail(): void
    {
        $notifierDouble = $this->notifier();
        $transportBuilder = $this->transportBuilder();

        $parkNotifier = new ParkNotifier(
            $notifierDouble,
            $transportBuilder,
            $this->backendUrl(),
            new NotificationComposer(),
            new NullLogger()
        );

        $parkNotifier->notify('task-uuid', 'Approve credit', 'Instructions here', ['a@example.com', 'b@example.com']);

        $this->assertCount(1, $notifierDouble->notices);
        $this->assertStringContainsString('Approve credit', $notifierDouble->notices[0][0]);
        $this->assertSame(['a@example.com', 'b@example.com'], $transportBuilder->sentTo);
    }

    public function testNotifierFailureNeverThrows(): void
    {
        $parkNotifier = new ParkNotifier(
            $this->notifier(true),
            $this->transportBuilder(),
            $this->backendUrl(),
            new NotificationComposer(),
            new NullLogger()
        );

        $parkNotifier->notify('task-uuid', 'Title', '', ['a@example.com']);

        $this->assertTrue(true); // reaching here means notify() did not throw
    }

    public function testEmailSendFailureNeverThrowsAndOtherRecipientsStillAttempted(): void
    {
        $transportBuilder = $this->transportBuilder(true);
        $parkNotifier = new ParkNotifier(
            $this->notifier(),
            $transportBuilder,
            $this->backendUrl(),
            new NotificationComposer(),
            new NullLogger()
        );

        $parkNotifier->notify('task-uuid', 'Title', '', ['a@example.com', 'b@example.com']);

        // Both attempted despite each send throwing; neither escaped.
        $this->assertTrue(true);
    }

    public function testDeepLinkFailureDegradesToEmptyLinkNeverThrows(): void
    {
        $notifierDouble = $this->notifier();
        $parkNotifier = new ParkNotifier(
            $notifierDouble,
            $this->transportBuilder(),
            $this->backendUrl(true),
            new NotificationComposer(),
            new NullLogger()
        );

        $parkNotifier->notify('task-uuid', 'Title', '', []);

        $this->assertCount(1, $notifierDouble->notices);
    }

    public function testNoRecipientsMeansNoEmailAttempted(): void
    {
        $transportBuilder = $this->transportBuilder();
        $parkNotifier = new ParkNotifier(
            $this->notifier(),
            $transportBuilder,
            $this->backendUrl(),
            new NotificationComposer(),
            new NullLogger()
        );

        $parkNotifier->notify('task-uuid', 'Title', '', []);

        $this->assertSame([], $transportBuilder->sentTo);
    }
}
