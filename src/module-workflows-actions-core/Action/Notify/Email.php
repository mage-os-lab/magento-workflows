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
 * notify.email — sends a transactional email with the execution context
 * (trigger + steps + workflow) as template variables. Two modes, exactly one
 * of which must be configured:
 *  - template_id: an existing transactional template
 *  - subject + body: ad-hoc mail through the bundled mageos_workflows_adhoc
 *    template. The body is escaped in PHP (nl2br(htmlspecialchars(...)))
 *    BEFORE being handed to the template's {{var body|raw}}, so config-borne
 *    markup can never inject live HTML/JS into the message.
 *
 * Sending mail is NOT idempotent and cannot be rolled back, so under
 * at-least-once delivery a check-and-set cache guard on the execution dedupe
 * key runs BEFORE SMTP (covering both modes): a redelivered step that already
 * attempted the send is skipped instead of double-mailing the customer.
 */
class Email extends AbstractAction implements SimulateableActionInterface
{
    private const GUARD_CACHE_PREFIX = 'mageos_workflows_email_sent_';
    private const GUARD_LIFETIME_SECONDS = 604800; // 7 days, beyond any retry window

    private const ADHOC_TEMPLATE_ID = 'mageos_workflows_adhoc';

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
        return (string)__('Send Email');
    }

    public function getGroup(): string
    {
        return (string)__('Notify');
    }

    public function getApplicableEntities(): array
    {
        return [];
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'template_id', 'label' => 'Email Template', 'type' => 'text', 'required' => false,
                'notice' => 'Configure EITHER a template id OR subject + body below, not both.'],
            ['name' => 'to', 'label' => 'Recipient Email', 'type' => 'text', 'required' => true,
                'notice' => 'Interpolate e.g. {{ trigger.customer_email }}.'],
            ['name' => 'subject', 'label' => 'Subject (ad-hoc)', 'type' => 'text', 'required' => false,
                'notice' => 'Used with Body instead of a template id.'],
            ['name' => 'body', 'label' => 'Body (ad-hoc)', 'type' => 'textarea', 'required' => false,
                'notice' => 'Plain text; line breaks are preserved. HTML tags are escaped, not rendered.'],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $templateId = $this->stringConfig($config, 'template_id');
        $subject = $this->stringConfig($config, 'subject');
        $body = $this->stringConfig($config, 'body');

        $modeError = $this->validateMode($templateId, $subject, $body);
        if ($modeError !== null) {
            return $modeError;
        }

        $to = $this->stringConfig($config, 'to');
        if ($to === null) {
            return $this->missingConfig('to');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failure((string)__('Invalid recipient email "%1"', $to));
        }

        // Check-and-set guard BEFORE the side effect (mail cannot be unsent);
        // one guard covers both the template and the ad-hoc path.
        $guardKey = self::GUARD_CACHE_PREFIX . sha1($ctx->getDedupeKey($this->stepKey($ctx)));
        if ($this->cache->load($guardKey)) {
            return ActionResult::skipped('Duplicate delivery suppressed (email already attempted for this step)');
        }
        $this->cache->save('1', $guardKey, [], self::GUARD_LIFETIME_SECONDS);

        $vars = [
            'trigger' => $ctx->getTrigger(),
            'steps' => $ctx->getSteps(),
            'workflow' => $ctx->getWorkflow(),
        ];
        $adhoc = $templateId === null;
        if ($adhoc) {
            $templateId = self::ADHOC_TEMPLATE_ID;
            $vars['subject'] = $subject;
            // Escape in PHP: the bundled template renders {{var body|raw}},
            // so what we pass here must already be safe HTML.
            $vars['body'] = nl2br(htmlspecialchars((string)$body, ENT_QUOTES, 'UTF-8'));
        }

        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $ctx->getStoreId(),
                ])
                ->setTemplateVars($vars)
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

        $output = [
            'template_id' => $templateId,
            'to' => $to,
            'store_id' => $ctx->getStoreId(),
        ];
        if ($adhoc) {
            $output['mode'] = 'adhoc';
            $output['subject'] = $subject;
        }
        return ActionResult::success($output);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $templateId = $this->stringConfig($config, 'template_id');
        $subject = $this->stringConfig($config, 'subject');
        $body = $this->stringConfig($config, 'body');

        $modeError = $this->validateMode($templateId, $subject, $body);
        if ($modeError !== null) {
            return $modeError;
        }

        $to = $this->stringConfig($config, 'to');
        if ($to === null) {
            return $this->missingConfig('to');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failure((string)__('Invalid recipient email "%1"', $to));
        }
        if ($templateId !== null) {
            return $this->simulated(sprintf('Send email template "%s" to %s', $templateId, $to));
        }
        return $this->simulated(sprintf('Send ad-hoc email "%s" to %s', $subject, $to));
    }

    /**
     * Exactly one of template_id or (subject AND body) must be configured
     */
    private function validateMode(?string $templateId, ?string $subject, ?string $body): ?ActionResult
    {
        if ($templateId !== null && ($subject !== null || $body !== null)) {
            return ActionResult::failure(
                (string)__('Configure either "template_id" or "subject" + "body", not both')
            );
        }
        if ($templateId !== null) {
            return null;
        }
        if ($subject === null && $body === null) {
            return $this->missingConfig('template_id');
        }
        if ($subject === null) {
            return $this->missingConfig('subject');
        }
        if ($body === null) {
            return $this->missingConfig('body');
        }
        return null;
    }
}
