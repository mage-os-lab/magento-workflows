<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Marketing;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Model\CouponGenerator;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * marketing.generate_coupon — generates a single coupon code from a cart price
 * rule (rule must allow auto-generated specific coupons). The generated code
 * lands in the step output so later steps can reference
 * {{ steps.<key>.coupon_code }} (e.g. in a notify.email template).
 */
class GenerateCoupon extends AbstractAction implements SimulateableActionInterface
{
    private const CODE_LENGTH = 12;
    private const CODE_FORMAT = 'alphanum';

    public function __construct(
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly CouponGenerator $couponGenerator
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
            ],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $ruleId = $this->intConfig($config, 'rule_id');
        if ($ruleId === null) {
            return $this->missingConfig('rule_id');
        }

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

        try {
            $codes = $this->couponGenerator->generateCodes([
                'rule_id' => $ruleId,
                // Must be 'qty', not 'quantity': CouponGenerator::convertCouponSpecData()
                // sources the spec's quantity FROM the legacy 'qty' key
                // (keyMap ['quantity' => 'qty']). Passing 'quantity' here leaves 'qty'
                // unset, so the spec quantity resolves to null and CouponManagementService
                // fails validateData() with a bare InputException ("One or more input
                // exceptions have occurred").
                'qty' => 1,
                'length' => self::CODE_LENGTH,
                'format' => self::CODE_FORMAT,
            ]);
        } catch (\Exception $e) {
            return ActionResult::failure('Coupon generation failed: ' . $e->getMessage(), true);
        }

        if ($codes === []) {
            return ActionResult::failure((string)__('Coupon generation returned no codes'), true);
        }

        return ActionResult::success([
            'coupon_code' => (string)reset($codes),
            'rule_id' => $ruleId,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $ruleId = $this->intConfig($config, 'rule_id');
        if ($ruleId === null) {
            return $this->missingConfig('rule_id');
        }
        try {
            $rule = $this->ruleRepository->getById($ruleId);
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Cart price rule %1 does not exist', $ruleId));
        }
        if (!$rule->getUseAutoGeneration()) {
            return ActionResult::failure((string)__('Cart price rule %1 does not allow auto-generated coupons', $ruleId));
        }
        return $this->simulated(
            sprintf('Generate one coupon code from rule "%s" (%d)', (string)$rule->getName(), $ruleId),
            // Placeholder so downstream shadow steps interpolating coupon_code do not break
            ['coupon_code' => 'SIMULATED-COUPON', 'rule_id' => $ruleId]
        );
    }
}
