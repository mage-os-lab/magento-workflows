<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Action\Review;

use Magento\Review\Model\Review;
use Magento\Review\Model\ResourceModel\Review as ReviewResource;
use Magento\Review\Model\ReviewFactory;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * REV-A1 — review.set_status: approve / reject / hold (pending) the review that
 * a review trigger is acting on. Closes the auto-moderation loop with REV-T1
 * ("5-star from a repeat buyer -> auto-approve; 1-star -> notify").
 *
 * ENTITY / CONTEXT CONSTRAINT (read before using):
 * Review triggers run with entity = catalog_product (conditions evaluate the
 * REVIEWED PRODUCT), so the execution entity id is a PRODUCT id, NOT a review
 * id. This action therefore locates the review from the TRIGGER CONTEXT, not
 * from the entity: it reads 'review_id' out of $ctx->getTrigger(), the extra
 * key both review triggers publish (catalog.product.review_submitted and
 * review.status_changed). Consequently this action is ONLY usable on workflows
 * started by a review trigger; on any other workflow the trigger snapshot has
 * no review_id and the action fails clearly (terminal, non-retryable). This is
 * a deliberate constraint of the "review trigger rides the product entity"
 * design (a review condition root is intentionally not offered — see
 * core-coverage §8), documented here and in the action description/metadata.
 *
 * PERSISTENCE: core exposes no ReviewRepositoryInterface, so the review is
 * loaded and saved through ReviewFactory + the Review resource model — the same
 * "no repository, use the factory" pattern the newsletter pack uses for
 * Subscriber. getApplicableEntities() is ['catalog_product'] (the trigger's
 * entity) with the context constraint above.
 *
 * GUARDS:
 *   - missing/unknown review_id in the trigger snapshot -> terminal failure;
 *   - a review row that no longer loads -> terminal failure;
 *   - idempotent: setting the status the review already has performs NO save
 *     and returns success as a no-op (also avoids re-triggering REV-T1).
 */
class SetStatus extends AbstractAction implements SimulateableActionInterface
{
    private const STATUS_APPROVED = 'approved';
    private const STATUS_PENDING = 'pending';
    private const STATUS_NOT_APPROVED = 'not_approved';

    /**
     * Config value => Review status_id.
     */
    private const STATUS_MAP = [
        self::STATUS_APPROVED => Review::STATUS_APPROVED,
        self::STATUS_PENDING => Review::STATUS_PENDING,
        self::STATUS_NOT_APPROVED => Review::STATUS_NOT_APPROVED,
    ];

    public function __construct(
        private readonly ReviewFactory $reviewFactory,
        private readonly ReviewResource $reviewResource
    ) {
    }

    public function getCode(): string
    {
        return 'review.set_status';
    }

    public function getLabel(): string
    {
        return (string) __('Set Review Status');
    }

    public function getGroup(): string
    {
        return (string) __('Catalog');
    }

    /**
     * catalog_product: review triggers ride the reviewed-product entity. The
     * review itself is resolved from the trigger snapshot (see class docblock);
     * this action is meaningful ONLY on review-triggered workflows.
     */
    public function getApplicableEntities(): array
    {
        return ['catalog_product'];
    }

    public function getConfigForm(): array
    {
        return [
            [
                'name' => 'status',
                'label' => 'Review Status',
                'type' => 'select',
                'required' => true,
                'note' => 'Only usable on workflows started by a review trigger '
                    . '(the review is taken from the trigger, not the product entity).',
                'options' => [
                    ['value' => self::STATUS_APPROVED, 'label' => 'Approved'],
                    ['value' => self::STATUS_PENDING, 'label' => 'Pending'],
                    ['value' => self::STATUS_NOT_APPROVED, 'label' => 'Not Approved'],
                ],
            ],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $statusId = $this->resolveStatusId($config);
        if ($statusId instanceof ActionResult) {
            return $statusId;
        }

        $reviewId = $this->resolveReviewId($ctx);
        if ($reviewId === null) {
            return ActionResult::failure((string) __(
                'review.set_status requires a review-triggered workflow: no review_id in the '
                . 'trigger context (this action reads the review from the trigger, not the product entity)'
            ));
        }

        $review = $this->reviewFactory->create();
        $this->reviewResource->load($review, $reviewId);
        if (!$review->getId()) {
            return ActionResult::failure((string) __('Review %1 not found', $reviewId));
        }

        if ((int) $review->getStatusId() === $statusId) {
            // Idempotent no-op: already at the target status. No save -> no
            // re-trigger of review.status_changed (REV-T1 loop guard).
            return ActionResult::success([
                'review_id' => $reviewId,
                'status_id' => $statusId,
                'changed' => false,
            ]);
        }

        try {
            $review->setStatusId($statusId);
            $this->reviewResource->save($review);
        } catch (\Exception $e) {
            return ActionResult::failure(
                'Review status update failed: ' . $e->getMessage(),
                true
            );
        }

        return ActionResult::success([
            'review_id' => $reviewId,
            'status_id' => $statusId,
            'changed' => true,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $statusId = $this->resolveStatusId($config);
        if ($statusId instanceof ActionResult) {
            return $statusId;
        }

        $reviewId = $this->resolveReviewId($ctx);
        if ($reviewId === null) {
            return ActionResult::failure((string) __(
                'review.set_status requires a review-triggered workflow: no review_id in the '
                . 'trigger context (this action reads the review from the trigger, not the product entity)'
            ));
        }

        return $this->simulated(sprintf(
            'Set review %d to status %d',
            $reviewId,
            $statusId
        ));
    }

    /**
     * Target status_id from config, or a terminal failure ActionResult.
     *
     * @return int|ActionResult
     */
    private function resolveStatusId(array $config)
    {
        $status = $this->stringConfig($config, 'status');
        if ($status === null) {
            return $this->missingConfig('status');
        }
        $status = strtolower($status);
        if (!isset(self::STATUS_MAP[$status])) {
            return ActionResult::failure((string) __(
                'Invalid review status "%1" (approved|pending|not_approved)',
                $status
            ));
        }
        return self::STATUS_MAP[$status];
    }

    /**
     * The review id from the trigger snapshot, or null when absent/invalid
     * (i.e. not a review-triggered workflow).
     */
    private function resolveReviewId(ExecutionContextInterface $ctx): ?int
    {
        $reviewId = $ctx->getTrigger()['review_id'] ?? null;
        if ($reviewId === null || !is_numeric($reviewId)) {
            return null;
        }
        $reviewId = (int) $reviewId;
        return $reviewId > 0 ? $reviewId : null;
    }
}
