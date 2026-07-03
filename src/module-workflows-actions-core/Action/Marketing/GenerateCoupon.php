<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Marketing;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Model\CouponGenerator;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsActionsCore\Action\AbstractAction;

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
        return 'Generate Coupon Code';
    }

    public function getGroup(): string
    {
        return 'Marketing';
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

    public function execute(ExecutionContext $ctx, array $config): ActionResult
    {
        $ruleId = $this->intConfig($config, 'rule_id');
        if ($ruleId === null) {
            return $this->missingConfig('rule_id');
        }

        try {
            $rule = $this->ruleRepository->getById($ruleId);
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure(sprintf('Cart price rule %d does not exist', $ruleId));
        }

        if (!$rule->getUseAutoGeneration()) {
            return ActionResult::failure(sprintf(
                'Cart price rule %d ("%s") does not allow auto-generated coupons',
                $ruleId,
                (string)$rule->getName()
            ));
        }

        try {
            $codes = $this->couponGenerator->generateCodes([
                'rule_id' => $ruleId,
                'quantity' => 1,
                'length' => self::CODE_LENGTH,
                'format' => self::CODE_FORMAT,
            ]);
        } catch (\Exception $e) {
            return ActionResult::failure('Coupon generation failed: ' . $e->getMessage(), true);
        }

        if ($codes === []) {
            return ActionResult::failure('Coupon generation returned no codes', true);
        }

        return ActionResult::success([
            'coupon_code' => (string)reset($codes),
            'rule_id' => $ruleId,
        ]);
    }

    public function simulate(ExecutionContext $ctx, array $config): ActionResult
    {
        $ruleId = $this->intConfig($config, 'rule_id');
        if ($ruleId === null) {
            return $this->missingConfig('rule_id');
        }
        try {
            $rule = $this->ruleRepository->getById($ruleId);
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure(sprintf('Cart price rule %d does not exist', $ruleId));
        }
        if (!$rule->getUseAutoGeneration()) {
            return ActionResult::failure(sprintf('Cart price rule %d does not allow auto-generated coupons', $ruleId));
        }
        return $this->simulated(
            sprintf('Generate one coupon code from rule "%s" (%d)', (string)$rule->getName(), $ruleId),
            // Placeholder so downstream shadow steps interpolating coupon_code do not break
            ['coupon_code' => 'SIMULATED-COUPON', 'rule_id' => $ruleId]
        );
    }
}
