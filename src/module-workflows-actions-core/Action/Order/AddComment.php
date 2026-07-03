<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Order;

use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * order.add_comment — appends a status-history comment to the order.
 *
 * Idempotent under at-least-once delivery: every comment carries an invisible
 * HTML-comment marker containing the execution dedupe key; a redelivery finds
 * the marker in an existing comment and skips.
 */
class AddComment extends AbstractOrderAction implements SimulateableActionInterface
{
    private const MARKER_FORMAT = '<!-- mageos-workflows:%s -->';

    public function getCode(): string
    {
        return 'order.add_comment';
    }

    public function getLabel(): string
    {
        return 'Add Order Comment';
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'comment', 'label' => 'Comment', 'type' => 'textarea', 'required' => true],
            ['name' => 'is_visible_on_front', 'label' => 'Visible on Storefront', 'type' => 'boolean', 'required' => false],
        ];
    }

    public function execute(ExecutionContext $ctx, array $config): ActionResult
    {
        $comment = $this->stringConfig($config, 'comment');
        if ($comment === null) {
            return $this->missingConfig('comment');
        }

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        $marker = sprintf(self::MARKER_FORMAT, $ctx->getDedupeKey($this->stepKey($ctx)));
        if ($this->hasMarker($order, $marker)) {
            return ActionResult::skipped('Comment already added (dedupe marker present)');
        }

        try {
            $history = $order->addCommentToStatusHistory(
                $comment . ' ' . $marker,
                false,
                $this->boolConfig($config, 'is_visible_on_front')
            );
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not add order comment: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'comment_id' => (int)$history->getEntityId(),
            'is_visible_on_front' => (bool)$history->getIsVisibleOnFront(),
        ]);
    }

    public function simulate(ExecutionContext $ctx, array $config): ActionResult
    {
        $comment = $this->stringConfig($config, 'comment');
        if ($comment === null) {
            return $this->missingConfig('comment');
        }
        return $this->simulated(
            sprintf('Add comment "%s" to order %d', $comment, $ctx->getEntityId())
        );
    }

    private function hasMarker(Order $order, string $marker): bool
    {
        foreach ($order->getStatusHistories() ?: [] as $history) {
            $existing = $history->getComment();
            if (is_string($existing) && str_contains($existing, $marker)) {
                return true;
            }
        }
        return false;
    }
}
