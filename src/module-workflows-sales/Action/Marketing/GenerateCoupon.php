<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Action\Marketing;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Api\Data\CouponInterfaceFactory;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * marketing.generate_coupon — mints a single coupon code from a cart price
 * rule (the rule must allow auto-generated specific coupons). The code lands
 * in the step output so later steps can reference
 * {{ steps.<key>.coupon_code }} (e.g. in a notify.email template).
 *
 * Redelivery guard — the code is DERIVED from the step's dedupe key, which
 * makes creation naturally idempotent. This action used to call
 * CouponGenerator unconditionally, and under at-least-once delivery (docs/08)
 * a redelivery after a crash INSIDE the step minted a SECOND live code while
 * the first stayed valid and unattributed: a real discount leak that also
 * burned the rule's generation budget. Now:
 *
 *  1. the code is a deterministic function of (execution UUID + step key) —
 *     see deterministicCode();
 *  2. before creating, the action looks the code up through the coupon
 *     repository and hands back the existing coupon as `skipped` if it is
 *     already there (with coupon_code in the output, so downstream steps
 *     interpolate exactly what the first delivery produced);
 *  3. if two consumers race past that read, `salesrule_coupon`'s UNIQUE index
 *     on `code` decides it — the loser catches the duplicate-key error and
 *     recovers the winner's coupon instead of minting a second.
 *
 * That is why a deterministic code beats "generate, then record the
 * association": the association IS the code, so it cannot be lost in the crash
 * window between creating the coupon and writing a marker. (A cache entry or a
 * context-only note would be worse still — neither survives the crash this
 * guard exists for.)
 *
 * Recoverability of the code depends on the dedupe key being stable across
 * redeliveries, which it is: the execution UUID is pinned at trigger time and
 * the step key comes from the definition snapshot.
 *
 * CouponGenerator is no longer used, deliberately — its whole job is to invent
 * a RANDOM code, which is exactly the property that made this action unsafe.
 * The coupon is created through the same public contracts the generator ends
 * up calling (CouponRepositoryInterface + the coupon data interface), and the
 * repository still blends the rule's usage limits into the row on save.
 */
class GenerateCoupon extends AbstractAction implements SimulateableActionInterface
{
    /**
     * Prefix so a workflow-minted coupon is recognizable in the admin grid and
     * in a merchant's support conversation. Uppercase alphanumeric, like the
     * body: coupon codes are typed by humans and read over the phone.
     */
    private const CODE_PREFIX = 'WF';

    /**
     * Body length in base32 characters. 12 chars of base32 is 60 bits taken
     * from a SHA-256 of the dedupe key, whose entropy source is the execution
     * UUID (a random v4, never exposed to storefront customers) — so codes
     * stay unguessable while being reproducible by the redelivery that needs
     * to find them. Collisions across distinct steps are not a practical
     * concern at 2^60, and if one ever happened the UNIQUE index turns it into
     * an honest failure rather than a silently shared discount.
     */
    private const CODE_BODY_LENGTH = 12;

    /**
     * RFC 4648 base32 alphabet: A-Z + 2-7, a strict subset of Magento's
     * `alphanum` coupon charset, so the generated code satisfies the same
     * format rule the auto-generation settings would have applied.
     */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * All four collaborators are REQUIRED constructor parameters: this guard
     * protects real discount money, and Magento's ObjectManager does not
     * auto-inject a parameter that has a default value — an "optional"
     * repository would arrive null in production and the action would be back
     * to minting a fresh code per redelivery.
     */
    public function __construct(
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly CouponRepositoryInterface $couponRepository,
        private readonly CouponInterfaceFactory $couponFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function getCode(): string
    {
        return 'marketing.generate_coupon';
    }

    public function getLabel(): string
    {
        return (string)__('Generate Coupon Code');
    }

    public function getGroup(): string
    {
        return (string)__('Marketing');
    }

    public function getApplicableEntities(): array
    {
        return [];
    }

    public function getConfigForm(): array
    {
        return [
            [
                'name' => 'rule_id',
                'label' => 'Cart Price Rule ID',
                'type' => 'integer',
                'required' => true,
                'notice' => 'The rule must use auto-generated specific coupon codes.',
                // Potentially large list, so the picker searches as the author
                // types; min_chars 0 loads the first page up front.
                'options_search' => ['source' => 'cart_price_rules', 'min_chars' => 0],
            ],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $ruleId = $this->intConfig($config, 'rule_id');
        if ($ruleId === null) {
            return $this->missingConfig('rule_id');
        }

        $rule = $this->loadRule($ruleId);
        if ($rule instanceof ActionResult) {
            return $rule;
        }

        $code = $this->deterministicCode($ctx->getDedupeKey($this->stepKey($ctx)));

        // Recovery read FIRST: a redelivery must find the code its earlier
        // delivery minted rather than mint a second one.
        try {
            $existing = $this->findCouponByCode($code);
        } catch (\Exception $e) {
            // Never mint on an unanswered dedupe question: park for retry.
            return ActionResult::failure(
                'Could not check for an existing workflow coupon: ' . $e->getMessage(),
                true
            );
        }
        if ($existing !== null) {
            return $this->recovered($existing, $ruleId, $code);
        }

        try {
            $coupon = $this->createCoupon($ruleId, $code, $rule);
        } catch (\Exception $e) {
            // A concurrent consumer may have won the UNIQUE(code) race between
            // our read and our write: recover ITS coupon instead of failing.
            $raced = $this->recoverAfterFailedCreate($code);
            if ($raced !== null) {
                return $this->recovered($raced, $ruleId, $code);
            }
            return ActionResult::failure('Coupon generation failed: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'coupon_code' => $code,
            'coupon_id' => (int)$coupon->getCouponId(),
            'rule_id' => $ruleId,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $ruleId = $this->intConfig($config, 'rule_id');
        if ($ruleId === null) {
            return $this->missingConfig('rule_id');
        }
        $rule = $this->loadRule($ruleId);
        if ($rule instanceof ActionResult) {
            return $rule;
        }
        return $this->simulated(
            sprintf('Generate one coupon code from rule "%s" (%d)', (string)$rule->getName(), $ruleId),
            // Placeholder so downstream shadow steps interpolating coupon_code do not break
            ['coupon_code' => 'SIMULATED-COUPON', 'rule_id' => $ruleId]
        );
    }

    /**
     * Load the rule and enforce the one precondition the coupon write has:
     * CouponRepository refuses an auto-generated coupon on a rule that does
     * not allow them (and refuses a manual one on a rule that requires them),
     * so a rule without auto-generation is a terminal authoring failure here
     * rather than an opaque exception from the save.
     *
     * @return RuleInterface|ActionResult
     */
    private function loadRule(int $ruleId)
    {
        try {
            $rule = $this->ruleRepository->getById($ruleId);
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Cart price rule %1 does not exist', $ruleId));
        }

        if (!$rule->getUseAutoGeneration()) {
            return ActionResult::failure((string)__(
                'Cart price rule %1 ("%2") does not allow auto-generated coupons',
                $ruleId,
                (string)$rule->getName()
            ));
        }
        return $rule;
    }

    /**
     * The coupon this step already created, expressed as a skip so the
     * execution reads honestly ("nothing new was minted") while still
     * publishing coupon_code for downstream steps — skipped step output is
     * merged into the context bag exactly like a success.
     *
     * A code that exists on a DIFFERENT rule is the one case that must not be
     * handed back: it would attach someone else's discount to this workflow.
     * At 60 bits it should never happen, and if it does it is a terminal
     * failure that says so.
     */
    private function recovered(CouponInterface $coupon, int $ruleId, string $code): ActionResultInterface
    {
        if ((int)$coupon->getRuleId() !== $ruleId) {
            return ActionResult::failure((string)__(
                'Coupon code %1 already exists on cart price rule %2, not %3',
                $code,
                (int)$coupon->getRuleId(),
                $ruleId
            ));
        }

        return ActionResult::skipped(
            sprintf('Coupon %s was already generated by this step', $code),
            [
                'coupon_code' => $code,
                'coupon_id' => (int)$coupon->getCouponId(),
                'rule_id' => $ruleId,
            ]
        );
    }

    /**
     * The coupon carrying this exact code, or null when none does.
     *
     * Read through the repository (service contract, mockable) with an
     * equality filter on the indexed, UNIQUE `code` column.
     */
    private function findCouponByCode(string $code): ?CouponInterface
    {
        $this->searchCriteriaBuilder->addFilter('code', $code, 'eq');
        $result = $this->couponRepository->getList($this->searchCriteriaBuilder->create());

        foreach ($result->getItems() as $coupon) {
            if ($coupon instanceof CouponInterface) {
                return $coupon;
            }
        }
        return null;
    }

    /**
     * Second-chance read after a failed create. Swallows its own errors on
     * purpose: the caller is deciding between "somebody beat me to it" and
     * "the write really failed", and a failed re-read simply means the latter.
     */
    private function recoverAfterFailedCreate(string $code): ?CouponInterface
    {
        try {
            return $this->findCouponByCode($code);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create the coupon with the deterministic code.
     *
     * Type is TYPE_GENERATED: an auto-generation rule accepts nothing else,
     * and it is what puts the coupon in the rule's "Manage Coupon Codes" grid.
     * Usage limits are NOT set here — CouponRepository::save() overwrites them
     * from the rule (uses_per_coupon / uses_per_customer) on every save, so
     * setting them would be a lie in the code; the expiration date has no such
     * treatment and is copied from the rule's to_date, matching what Magento's
     * own mass generator does.
     */
    private function createCoupon(int $ruleId, string $code, RuleInterface $rule): CouponInterface
    {
        $coupon = $this->couponFactory->create();
        $coupon->setRuleId($ruleId);
        $coupon->setCode($code);
        $coupon->setType(CouponInterface::TYPE_GENERATED);
        $coupon->setCreatedAt(gmdate('Y-m-d H:i:s'));
        $toDate = $rule->getToDate();
        if ($toDate !== null && $toDate !== '') {
            $coupon->setExpirationDate($toDate);
        }

        $saved = $this->couponRepository->save($coupon);
        return $saved instanceof CouponInterface ? $saved : $coupon;
    }

    /**
     * A stable, unguessable, format-legal code for one execution+step.
     *
     * SHA-256 of the dedupe key, re-encoded into the base32 alphabet (a subset
     * of Magento's `alphanum` charset) and truncated — deterministic, so the
     * redelivery derives the same string, and opaque, so nobody can derive it
     * without the execution UUID.
     */
    private function deterministicCode(string $dedupeKey): string
    {
        $digest = hash('sha256', $dedupeKey, true);
        $body = '';
        for ($i = 0; $i < self::CODE_BODY_LENGTH; $i++) {
            $body .= self::BASE32_ALPHABET[ord($digest[$i]) & 31];
        }
        return self::CODE_PREFIX . $body;
    }
}
