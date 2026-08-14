<?php
declare(strict_types=1);

namespace Magento\Sales\Api;

/**
 * Standalone-runner shim for Magento\Sales\Api\RefundOrderInterface. Mirrors
 * the FULL real signature (6 params, all UNTYPED past the array) so a test
 * double must satisfy the same contract it faces under real Magento —
 * order.create_creditmemo passes the last three (appendComment, the dedupe
 * comment, and the partial-refund creation arguments), and a double that only
 * accepted the first three would pass here while fataling under Magento.
 */
interface RefundOrderInterface
{
    public function execute(
        $orderId,
        array $items = [],
        $notify = false,
        $appendComment = false,
        $comment = null,
        $arguments = null
    );
}
