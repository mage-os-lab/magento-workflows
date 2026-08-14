<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Notify;

use Magento\Framework\App\Area;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Template\TransportBuilder;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\BatchCapableActionInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Idempotency\SendOnceGuard;

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
 * Send-once guard — a DURABLE claim, not a cache entry. Mail cannot be
 * unsent, so under at-least-once delivery (docs/08) the action INSERTs a claim
 * row keyed on the execution+step dedupe key BEFORE handing anything to the
 * transport, and a duplicate key means somebody already claimed this send —
 * skip. The claim lives in mageos_workflow_send_log behind a UNIQUE index
 * (MageOS\Workflows\Model\Idempotency\SendClaimStoreInterface), which is what
 * makes it correct where the previous CacheInterface load-then-save was not:
 *  - the INSERT is atomic, so two concurrent consumers cannot both pass;
 *  - it is shared by every node, unlike the default per-node file cache;
 *  - `cache:flush` / a Redis restart / LRU eviction cannot erase it.
 *
 * The trade this encodes, deliberately: a crash between the claim and the
 * confirmation leaves a `claimed` row, so the redelivery SKIPS an email that
 * may never have gone out — and says exactly that in the skip reason
 * ("outcome unconfirmed") rather than pretending it was sent. For email that
 * is the right way round: a missing message an operator can resend beats a
 * duplicate one they cannot recall. The claim is released only for failures
 * that provably precede the send — bad config, or a transport that could not
 * even be built. Once sendMessage() has been entered, the claim stands even if
 * it throws: Magento wraps every transport error in one MailException, so
 * "connection refused before DATA" and "died after the MTA accepted it" are
 * indistinguishable here, and only one of those two guesses is safe.
 */
class Email extends AbstractAction implements SimulateableActionInterface, BatchCapableActionInterface
{
    private const ADHOC_TEMPLATE_ID = 'mageos_workflows_adhoc';

    /**
     * The send-once guard is REQUIRED, never a nullable convenience: an
     * optional dependency would arrive null in production (the ObjectManager
     * does not auto-inject a parameter that has a default value) and the send
     * would silently lose its only protection.
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly SendOnceGuard $sendOnceGuard
    ) {
    }

    public function getCode(): string
    {
        return 'notify.email';
    }

    public function supportsBatch(): bool
    {
        return true;
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
            ['name' => 'template_id', 'label' => 'Email Template', 'type' => 'select', 'required' => false,
                'notice' => 'Configure EITHER a template id OR subject + body below, not both.',
                'options_search' => ['source' => 'email_templates', 'min_chars' => 0]],
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

        // Durable claim BEFORE the side effect (mail cannot be unsent); one
        // claim covers both the template and the ad-hoc path.
        $dedupeKey = $ctx->getDedupeKey($this->stepKey($ctx));
        try {
            $claimed = $this->sendOnceGuard->claim($this->getCode(), $dedupeKey);
        } catch (\Exception $e) {
            // The claim question went unanswered (DB down, table missing).
            // Park the step: an unguarded send is exactly what must not happen.
            return ActionResult::failure(
                'Could not claim the email send: ' . $e->getMessage(),
                true
            );
        }
        if (!$claimed) {
            return ActionResult::skipped($this->sendOnceGuard->describeClaim($this->getCode(), $dedupeKey));
        }

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

        // Phase 1 — build the transport. Nothing has reached a mail server
        // yet, so any failure here provably sent nothing: release the claim so
        // a redelivery can genuinely retry.
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
        } catch (MailException $e) {
            $this->sendOnceGuard->release($this->getCode(), $dedupeKey);
            return ActionResult::failure('Email send failed: ' . $e->getMessage(), true);
        } catch (\Exception $e) {
            // Template/config errors will not resolve on redelivery
            $this->sendOnceGuard->release($this->getCode(), $dedupeKey);
            return ActionResult::failure('Email send failed: ' . $e->getMessage());
        }

        // Phase 2 — hand it to the transport. From here the claim STANDS
        // whatever happens: Magento funnels every transport error into one
        // MailException, so a refused connection and an MTA that accepted the
        // message before the link dropped look identical, and retrying on that
        // guess is how customers get two copies. Terminal, not retryable — a
        // redelivery could only skip on the retained claim, so failing loudly
        // tells the operator to check and resend instead of silently skipping.
        try {
            $transport->sendMessage();
        } catch (\Exception $e) {
            return ActionResult::failure(
                'Email send failed after the send was claimed, so it will NOT be retried automatically '
                . '(the message may or may not have left the mail server): ' . $e->getMessage()
            );
        }

        $this->sendOnceGuard->confirm($this->getCode(), $dedupeKey);

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
