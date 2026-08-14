<?php
declare(strict_types=1);

namespace Magento\Sales\Api\Data;

/**
 * Standalone-runner shim for
 * Magento\Sales\Api\Data\CreditmemoCommentCreationInterface. The real interface
 * extends ExtensibleDataInterface + CommentInterface; the four inherited
 * accessors are declared inline here (the shim tree carries neither parent) so
 * a partial double is caught in this lane too. Note the real CommentInterface
 * has NO is_customer_notified accessor — customer notification is decided by
 * RefundOrder's own $appendComment/$notify flags, which order.create_creditmemo
 * leaves false for its dedupe comment.
 */
interface CreditmemoCommentCreationInterface
{
    public function getComment();

    public function setComment($comment);

    public function getIsVisibleOnFront();

    public function setIsVisibleOnFront($isVisibleOnFront);

    public function getExtensionAttributes();

    public function setExtensionAttributes($extensionAttributes);
}
