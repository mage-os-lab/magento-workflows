<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Test\Unit\Action\Review;

use Magento\Review\Model\Review;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\ReviewFactory;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsReview\Action\Review\SetStatus;
use PHPUnit\Framework\TestCase;

/**
 * REV-A1: review.set_status resolves the review from the TRIGGER CONTEXT
 * (review_id), not the product entity — so it works only on review-triggered
 * workflows and fails clearly otherwise. Approves/holds/rejects via the review
 * resource model; idempotent when already at the target (no save, no REV-T1
 * re-trigger); unknown/missing review_id and unknown row are terminal failures.
 */
class SetStatusTest extends TestCase
{
    /**
     * @param array<int, int> $rows reviewId => current status_id
     */
    private function resource(array $rows, ?\Throwable $throwOnSave = null): ReviewResource
    {
        return new class($rows, $throwOnSave) extends ReviewResource {
            public int $saveCalls = 0;
            public ?int $savedStatus = null;

            /** @param array<int, int> $rows */
            public function __construct(private array $rows, private readonly ?\Throwable $throwOnSave)
            {
            }

            // Params are UNTYPED: the real parent is AbstractDb, whose
            // load()/save() type the model as AbstractModel — hinting the
            // narrower Review here would narrow the param (contravariance
            // violation → fatal) under real Magento.
            public function load($object, $value, $field = null): self
            {
                if (isset($this->rows[(int) $value])) {
                    $object->setData('review_id', (int) $value);
                    $object->setData('status_id', $this->rows[(int) $value]);
                }
                return $this;
            }

            public function save($object): self
            {
                $this->saveCalls++;
                if ($this->throwOnSave !== null) {
                    throw $this->throwOnSave;
                }
                $this->savedStatus = (int) $object->getStatusId();
                return $this;
            }
        };
    }

    private function factory(): ReviewFactory
    {
        return new class extends ReviewFactory {
            // Bypass the generated factory's DI constructor (ObjectManager).
            public function __construct()
            {
            }
            public function create(array $data = []): Review
            {
                return new Review();
            }
        };
    }

    private function context(array $trigger): ExecutionContext
    {
        // Entity id is a PRODUCT id (review triggers ride the product); the
        // review is taken from the trigger snapshot, not the entity.
        return new ExecutionContext(new WorkflowExecutionStub(entityId: 55), $trigger);
    }

    public function testApprovesReviewFromTriggerContext(): void
    {
        $resource = $this->resource([21 => Review::STATUS_PENDING]);
        $action = new SetStatus($this->factory(), $resource);

        $result = $action->execute($this->context(['review_id' => 21]), ['status' => 'approved']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['changed']);
        $this->assertSame(21, $result->getOutput()['review_id']);
        $this->assertSame(Review::STATUS_APPROVED, $result->getOutput()['status_id']);
        $this->assertSame(1, $resource->saveCalls);
        $this->assertSame(Review::STATUS_APPROVED, $resource->savedStatus);
    }

    public function testRejectMapsToNotApproved(): void
    {
        $resource = $this->resource([21 => Review::STATUS_PENDING]);
        $action = new SetStatus($this->factory(), $resource);

        $result = $action->execute($this->context(['review_id' => 21]), ['status' => 'not_approved']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(Review::STATUS_NOT_APPROVED, $resource->savedStatus);
    }

    public function testIdempotentSameStatusIsNoOpSuccess(): void
    {
        $resource = $this->resource([21 => Review::STATUS_APPROVED]);
        $action = new SetStatus($this->factory(), $resource);

        $result = $action->execute($this->context(['review_id' => 21]), ['status' => 'approved']);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->getOutput()['changed']);
        $this->assertSame(0, $resource->saveCalls, 'already-approved review must not be re-saved');
    }

    public function testMissingReviewIdInContextFailsClearly(): void
    {
        $resource = $this->resource([21 => Review::STATUS_PENDING]);
        $action = new SetStatus($this->factory(), $resource);

        $result = $action->execute($this->context([]), ['status' => 'approved']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('review-triggered', (string) $result->getError());
        $this->assertSame(0, $resource->saveCalls);
    }

    public function testUnknownReviewIdIsNotFound(): void
    {
        $resource = $this->resource([]);
        $action = new SetStatus($this->factory(), $resource);

        $result = $action->execute($this->context(['review_id' => 999]), ['status' => 'approved']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('999', (string) $result->getError());
        $this->assertSame(0, $resource->saveCalls);
    }

    public function testMissingStatusConfigIsTerminal(): void
    {
        $action = new SetStatus($this->factory(), $this->resource([21 => Review::STATUS_PENDING]));

        $result = $action->execute($this->context(['review_id' => 21]), []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('status', (string) $result->getError());
    }

    public function testInvalidStatusConfigIsTerminal(): void
    {
        $action = new SetStatus($this->factory(), $this->resource([21 => Review::STATUS_PENDING]));

        $result = $action->execute($this->context(['review_id' => 21]), ['status' => 'bogus']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('bogus', (string) $result->getError());
    }

    public function testSaveFailureIsRetryable(): void
    {
        $resource = $this->resource([21 => Review::STATUS_PENDING], new \RuntimeException('MySQL server has gone away'));
        $action = new SetStatus($this->factory(), $resource);

        $result = $action->execute($this->context(['review_id' => 21]), ['status' => 'approved']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('gone away', (string) $result->getError());
    }

    public function testSimulateNeverTouchesTheReview(): void
    {
        $resource = $this->resource([21 => Review::STATUS_PENDING]);
        $action = new SetStatus($this->factory(), $resource);

        $result = $action->simulate($this->context(['review_id' => 21]), ['status' => 'approved']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame(0, $resource->saveCalls);
    }

    public function testSimulateWithoutReviewIdFails(): void
    {
        $action = new SetStatus($this->factory(), $this->resource([]));

        $result = $action->simulate($this->context([]), ['status' => 'approved']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('review-triggered', (string) $result->getError());
    }

    public function testMetadataAdvertisesCatalogEntityAndAcl(): void
    {
        $action = new SetStatus($this->factory(), $this->resource([]));

        $this->assertSame('review.set_status', $action->getCode());
        $this->assertSame(['catalog_product'], $action->getApplicableEntities());
        $this->assertSame('MageOS_Workflows::action_catalog', $action->getAclResource());
        $form = $action->getConfigForm();
        $this->assertSame('status', $form[0]['name']);
    }
}
