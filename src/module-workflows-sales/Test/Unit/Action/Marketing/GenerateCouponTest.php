<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Marketing;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\SalesRule\Api\CouponRepositoryInterface;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Api\Data\CouponInterfaceFactory;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Marketing\GenerateCoupon;
use PHPUnit\Framework\TestCase;

/**
 * marketing.generate_coupon (finding 8): the code is DERIVED from the step's
 * dedupe key, so a redelivery recovers the coupon the first delivery minted
 * instead of leaking a second live discount and burning the rule's generation
 * budget.
 *
 * Pinned here: determinism (same execution+step => same code, different
 * execution or step => different code), the recovery read, the UNIQUE(code)
 * race recovery, the "code belongs to another rule" collision, the
 * unanswered-lookup park, and the rule preconditions.
 */
class GenerateCouponTest extends TestCase
{
    private function context(string $uuid = 'exec-uuid-1', ?string $stepKey = 'coupon'): ExecutionContext
    {
        $execution = new WorkflowExecutionStub(uuid: $uuid);
        if ($stepKey !== null) {
            $execution->setCurrentStep($stepKey);
        }
        return new ExecutionContext($execution);
    }

    private function rule(
        int $ruleId = 7,
        bool $autoGeneration = true,
        string $name = 'Loyalty 10%',
        ?string $toDate = '2026-12-31'
    ): RuleInterface {
        return new class ($ruleId, $autoGeneration, $name, $toDate) implements RuleInterface {
            public function __construct(
                private readonly int $ruleId,
                private readonly bool $autoGeneration,
                private readonly string $name,
                private readonly ?string $toDate
            ) {
            }
            public function getRuleId()
            {
                return $this->ruleId;
            }
            public function getName()
            {
                return $this->name;
            }
            public function getUseAutoGeneration()
            {
                return $this->autoGeneration;
            }
            public function getToDate()
            {
                return $this->toDate;
            }
            public function getUsesPerCoupon()
            {
                return 1;
            }
            public function getUsesPerCustomer()
            {
                return 1;
            }
        };
    }

    private function ruleRepository(?RuleInterface $rule): RuleRepositoryInterface
    {
        return new class ($rule) implements RuleRepositoryInterface {
            public function __construct(private readonly ?RuleInterface $rule)
            {
            }
            public function getById($ruleId)
            {
                if ($this->rule === null) {
                    throw new NoSuchEntityException(__('No such rule %1', $ruleId));
                }
                return $this->rule;
            }
            public function save(RuleInterface $rule)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function getList(SearchCriteriaInterface $searchCriteria)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function deleteById($ruleId)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };
    }

    private function coupon(int $couponId, int $ruleId, string $code): CouponInterface
    {
        return new class ($couponId, $ruleId, $code) implements CouponInterface {
            public ?string $expirationDate = null;
            public ?string $createdAt = null;
            public $type = null;
            public function __construct(
                private ?int $couponId,
                private ?int $ruleId,
                private ?string $code
            ) {
            }
            public function getCouponId()
            {
                return $this->couponId;
            }
            public function setCouponId($couponId)
            {
                $this->couponId = (int)$couponId;
                return $this;
            }
            public function getRuleId()
            {
                return $this->ruleId;
            }
            public function setRuleId($ruleId)
            {
                $this->ruleId = (int)$ruleId;
                return $this;
            }
            public function getCode()
            {
                return $this->code;
            }
            public function setCode($code)
            {
                $this->code = (string)$code;
                return $this;
            }
            public function getExpirationDate()
            {
                return $this->expirationDate;
            }
            public function setExpirationDate($expirationDate)
            {
                $this->expirationDate = (string)$expirationDate;
                return $this;
            }
            public function getCreatedAt()
            {
                return $this->createdAt;
            }
            public function setCreatedAt($createdAt)
            {
                $this->createdAt = (string)$createdAt;
                return $this;
            }
            public function getType()
            {
                return $this->type;
            }
            public function setType($type)
            {
                $this->type = $type;
                return $this;
            }
        };
    }

    private function couponFactory(GenerateCouponTest $test): CouponInterfaceFactory
    {
        return new class ($test) extends CouponInterfaceFactory {
            public function __construct(private readonly GenerateCouponTest $test)
            {
            }
            public function create(array $data = [])
            {
                return $this->test->newBlankCoupon();
            }
        };
    }

    public function newBlankCoupon(): CouponInterface
    {
        return $this->coupon(0, 0, '');
    }

    /**
     * Coupon repository backed by an in-memory table with the real UNIQUE
     * constraint on `code` — the constraint IS the last-resort guard, so the
     * double must have it.
     */
    private function couponRepository(): CouponRepositoryInterface
    {
        return new class implements CouponRepositoryInterface {
            /** @var array<string, CouponInterface> code => coupon */
            public array $rows = [];
            public int $saves = 0;
            public int $nextId = 100;
            public ?\Exception $throwOnList = null;
            public ?\Exception $throwOnSave = null;
            /** @var CouponInterface|null planted by a "concurrent consumer" during save */
            public ?CouponInterface $raceWinner = null;

            public function save(CouponInterface $coupon)
            {
                $this->saves++;
                if ($this->raceWinner !== null) {
                    // Somebody committed between our read and our write.
                    $this->rows[(string)$this->raceWinner->getCode()] = $this->raceWinner;
                    $this->raceWinner = null;
                }
                if ($this->throwOnSave !== null) {
                    throw $this->throwOnSave;
                }
                $code = (string)$coupon->getCode();
                if (isset($this->rows[$code])) {
                    throw new \RuntimeException(
                        "SQLSTATE[23000]: Duplicate entry '$code' for key 'UNQ_SALESRULE_COUPON_CODE'"
                    );
                }
                $coupon->setCouponId($this->nextId++);
                $this->rows[$code] = $coupon;
                return $coupon;
            }

            public function getList(SearchCriteriaInterface $searchCriteria)
            {
                if ($this->throwOnList !== null) {
                    throw $this->throwOnList;
                }
                $code = $searchCriteria->code ?? null;
                $items = isset($this->rows[(string)$code]) ? [$this->rows[(string)$code]] : [];
                return new class ($items) {
                    public function __construct(private readonly array $items)
                    {
                    }
                    public function getItems(): array
                    {
                        return $this->items;
                    }
                };
            }

            public function getById($couponId)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function delete(CouponInterface $coupon)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
            public function deleteById($couponId)
            {
                throw new \BadMethodCallException(__METHOD__);
            }
        };
    }

    /**
     * Search-criteria builder that just carries the `code` filter through to
     * the repository double.
     */
    private function searchCriteriaBuilder(): SearchCriteriaBuilder
    {
        return new class extends SearchCriteriaBuilder {
            private ?string $code = null;
            public function addFilter($field, $value, $conditionType = 'eq')
            {
                if ((string)$field === 'code') {
                    $this->code = (string)$value;
                }
                return $this;
            }
            public function create()
            {
                $code = $this->code;
                $this->code = null;
                return new class ($code) implements SearchCriteriaInterface {
                    public function __construct(public readonly ?string $code)
                    {
                    }
                };
            }
        };
    }

    private function action(
        object $couponRepository,
        ?RuleInterface $rule = null
    ): GenerateCoupon {
        return new GenerateCoupon(
            $this->ruleRepository($rule ?? $this->rule()),
            $couponRepository,
            $this->couponFactory($this),
            $this->searchCriteriaBuilder()
        );
    }

    // -- deterministic, recoverable code -------------------------------------

    public function testMintsACouponWithADeterministicCodeAndReportsIt(): void
    {
        $repo = $this->couponRepository();
        $result = $this->action($repo)->execute($this->context(), ['rule_id' => 7]);

        $this->assertTrue($result->isSuccess(), (string)$result->getError());
        $code = (string)$result->getOutput()['coupon_code'];
        $this->assertSame(1, $repo->saves);
        $this->assertArrayHasKey($code, $repo->rows);
        $this->assertSame(7, (int)$repo->rows[$code]->getRuleId());
        $this->assertSame(CouponInterface::TYPE_GENERATED, $repo->rows[$code]->getType());
        $this->assertSame('2026-12-31', $repo->rows[$code]->getExpirationDate(), "the rule's to_date is copied");
        // Format: WF + 12 uppercase base32 chars (a subset of Magento alphanum).
        $this->assertSame(1, preg_match('/^WF[A-Z2-7]{12}$/', $code), "unexpected code shape: $code");
    }

    public function testTheSameExecutionAndStepAlwaysDeriveTheSameCode(): void
    {
        $first = $this->action($this->couponRepository())
            ->execute($this->context('exec-uuid-9', 'coupon'), ['rule_id' => 7]);
        // A completely separate action instance + empty table: the code is a
        // pure function of the dedupe key, not of anything stored.
        $second = $this->action($this->couponRepository())
            ->execute($this->context('exec-uuid-9', 'coupon'), ['rule_id' => 7]);

        $this->assertSame($first->getOutput()['coupon_code'], $second->getOutput()['coupon_code']);
    }

    public function testADifferentExecutionOrStepDerivesADifferentCode(): void
    {
        $repo = $this->couponRepository();
        $action = $this->action($repo);

        $a = $action->execute($this->context('exec-uuid-1', 'coupon'), ['rule_id' => 7]);
        $b = $action->execute($this->context('exec-uuid-2', 'coupon'), ['rule_id' => 7]);
        $c = $action->execute($this->context('exec-uuid-1', 'second_coupon'), ['rule_id' => 7]);

        $codes = [
            (string)$a->getOutput()['coupon_code'],
            (string)$b->getOutput()['coupon_code'],
            (string)$c->getOutput()['coupon_code'],
        ];
        $this->assertCount(3, array_unique($codes), 'each execution+step mints its own coupon');
        $this->assertSame(3, $repo->saves);
    }

    public function testRedeliveryRecoversTheExistingCouponInsteadOfMintingASecond(): void
    {
        $repo = $this->couponRepository();
        $action = $this->action($repo);
        $ctx = $this->context('exec-uuid-1', 'coupon');

        $first = $action->execute($ctx, ['rule_id' => 7]);
        $second = $action->execute($ctx, ['rule_id' => 7]);

        $this->assertTrue($first->isSuccess());
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
        $this->assertSame(1, $repo->saves, 'a redelivery must not mint a second live code');
        $this->assertCount(1, $repo->rows);
        // The recovered code is still published for downstream interpolation.
        $this->assertSame($first->getOutput()['coupon_code'], $second->getOutput()['coupon_code']);
        $this->assertSame($first->getOutput()['coupon_id'], $second->getOutput()['coupon_id']);
        $this->assertStringContainsString('already generated', (string)$second->getOutput()['reason']);
    }

    public function testAConcurrentWinnerIsRecoveredWhenTheUniqueIndexRejectsOurInsert(): void
    {
        // Both consumers read "no such code", then one commits first: the
        // loser's save hits UNIQUE(code) and must recover, not fail.
        $repo = $this->couponRepository();
        $ctx = $this->context('exec-uuid-1', 'coupon');
        $expectedCode = (string)$this->action($this->couponRepository())
            ->execute($ctx, ['rule_id' => 7])->getOutput()['coupon_code'];
        $repo->raceWinner = $this->coupon(555, 7, $expectedCode);

        $result = $this->action($repo)->execute($ctx, ['rule_id' => 7]);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame($expectedCode, $result->getOutput()['coupon_code']);
        $this->assertSame(555, $result->getOutput()['coupon_id'], "the winner's coupon is handed back");
        $this->assertCount(1, $repo->rows);
    }

    public function testACodeCollidingOnAnotherRuleIsATerminalFailure(): void
    {
        // Never hand back somebody else's discount, however unlikely 2^60 is.
        $repo = $this->couponRepository();
        $ctx = $this->context('exec-uuid-1', 'coupon');
        $code = (string)$this->action($this->couponRepository())
            ->execute($ctx, ['rule_id' => 7])->getOutput()['coupon_code'];
        $repo->rows[$code] = $this->coupon(999, 42, $code);

        $result = $this->action($repo)->execute($ctx, ['rule_id' => 7]);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('already exists on cart price rule 42', (string)$result->getError());
        $this->assertSame(0, $repo->saves);
    }

    public function testAnUnanswerableLookupParksInsteadOfMinting(): void
    {
        $repo = $this->couponRepository();
        $repo->throwOnList = new \RuntimeException('SQLSTATE[HY000]: server has gone away');

        $result = $this->action($repo)->execute($this->context(), ['rule_id' => 7]);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('existing workflow coupon', (string)$result->getError());
        $this->assertSame(0, $repo->saves, 'no coupon may be minted on an unanswered dedupe question');
    }

    public function testARealSaveFailureIsRetryable(): void
    {
        $repo = $this->couponRepository();
        $repo->throwOnSave = new \RuntimeException('deadlock found when trying to get lock');

        $result = $this->action($repo)->execute($this->context(), ['rule_id' => 7]);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('Coupon generation failed', (string)$result->getError());
    }

    // -- rule preconditions ---------------------------------------------------

    public function testMissingRuleIdIsTerminalFailure(): void
    {
        $result = $this->action($this->couponRepository())->execute($this->context(), []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('rule_id', (string)$result->getError());
    }

    public function testUnknownRuleIsTerminalFailure(): void
    {
        $action = new GenerateCoupon(
            $this->ruleRepository(null),
            $this->couponRepository(),
            $this->couponFactory($this),
            $this->searchCriteriaBuilder()
        );

        $result = $action->execute($this->context(), ['rule_id' => 404]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('does not exist', (string)$result->getError());
    }

    public function testRuleWithoutAutoGenerationIsTerminalFailure(): void
    {
        // CouponRepository::save() would reject the generated coupon anyway;
        // failing here says why, in the merchant's language.
        $repo = $this->couponRepository();
        $result = $this->action($repo, $this->rule(autoGeneration: false))
            ->execute($this->context(), ['rule_id' => 7]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('does not allow auto-generated coupons', (string)$result->getError());
        $this->assertSame(0, $repo->saves);
    }

    public function testSimulateNeverCreatesACoupon(): void
    {
        $repo = $this->couponRepository();
        $result = $this->action($repo)->simulate($this->context(), ['rule_id' => 7]);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame('SIMULATED-COUPON', $result->getOutput()['coupon_code']);
        $this->assertSame(0, $repo->saves);
        $this->assertCount(0, $repo->rows);
    }

    public function testConfigFormRuleIdSearchesTheCartPriceRuleSource(): void
    {
        $field = $this->action($this->couponRepository())->getConfigForm()[0];

        $this->assertSame('rule_id', $field['name']);
        $this->assertSame(['source' => 'cart_price_rules', 'min_chars' => 0], $field['options_search']);
        $this->assertTrue($field['required']);
    }
}
