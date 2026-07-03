<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Notify;

use Magento\Framework\App\Area;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\TransportBuilder;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * notify.email — sends a transactional email template with the execution
 * context (trigger + steps + workflow) as template variables.
 *
 * Sending mail is NOT idempotent and cannot be rolled back, so under
 * at-least-once delivery a check-and-set cache guard on the execution dedupe
 * key runs BEFORE SMTP: a redelivered step that already attempted the send is
 * skipped instead of double-mailing the customer.
 */
class Email extends AbstractAction implements SimulateableActionInterface
{
    private const GUARD_CACHE_PREFIX = 'mageos_workflows_email_sent_';
    private const GUARD_LIFETIME_SECONDS = 604800; // 7 days, beyond any retry window

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly CacheInterface $cache
    ) {
    }

    public function getCode(): string
    {
        return 'notify.email';
    }

    public function getLabel(): string
    {
        return 'Send Email';
    }

    public function getGroup(): string
    {
        return 'Notify';
    }

    public function getApplicableEntities(): array
    {
        return [];
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'template_id', 'label' => 'Email Template', 'type' => 'text', 'required' => true],
            ['name' => 'to', 'label' => 'Recipient Email', 'type' => 'text', 'required' => true,
                'notice' => 'Interpolate e.g. {{ trigger.customer_email }}.'],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $templateId = $this->stringConfig($config, 'template_id');
        if ($templateId === null) {
            return $this->missingConfig('template_id');
        }
        $to = $this->stringConfig($config, 'to');
        if ($to === null) {
            return $this->missingConfig('to');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failure(sprintf('Invalid recipient email "%s"', $to));
        }

        // Check-and-set guard BEFORE the side effect (mail cannot be unsent)
        $guardKey = self::GUARD_CACHE_PREFIX . sha1($ctx->getDedupeKey($this->stepKey($ctx)));
        if ($this->cache->load($guardKey)) {
            return ActionResult::skipped('Duplicate delivery suppressed (email already attempted for this step)');
        }
        $this->cache->save('1', $guardKey, [], self::GUARD_LIFETIME_SECONDS);

        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $ctx->getStoreId(),
                ])
                ->setTemplateVars([
                    'trigger' => $ctx->getTrigger(),
                    'steps' => $ctx->getSteps(),
                    'workflow' => $ctx->getWorkflow(),
                ])
                ->setFromByScope('general', $ctx->getStoreId())
                ->addTo($to)
                ->getTransport();
            $transport->sendMessage();
        } catch (MailException $e) {
            // Transient transport failure: release the guard so a retry can send
            $this->cache->remove($guardKey);
            return ActionResult::failure('Email send failed: ' . $e->getMessage(), true);
        } catch (\Exception $e) {
            // Template/config errors will not resolve on redelivery
            return ActionResult::failure('Email send failed: ' . $e->getMessage());
        }

        return ActionResult::success([
            'template_id' => $templateId,
            'to' => $to,
            'store_id' => $ctx->getStoreId(),
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $templateId = $this->stringConfig($config, 'template_id');
        if ($templateId === null) {
            return $this->missingConfig('template_id');
        }
        $to = $this->stringConfig($config, 'to');
        if ($to === null) {
            return $this->missingConfig('to');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failure(sprintf('Invalid recipient email "%s"', $to));
        }
        return $this->simulated(sprintf('Send email template "%s" to %s', $templateId, $to));
    }
}
