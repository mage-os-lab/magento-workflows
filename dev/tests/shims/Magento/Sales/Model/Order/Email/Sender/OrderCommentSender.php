<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Email\Sender;

use Magento\Sales\Model\Order;

/**
 * Standalone-runner shim for
 * Magento\Sales\Model\Order\Email\Sender\OrderCommentSender. Concrete in core;
 * order.send_email (ORD-A2, comment mode) calls send() to email a customer the
 * latest visible order comment. Test doubles subclass and override send(); the
 * base throws so an unmocked use surfaces loudly.
 */
class OrderCommentSender
{
    /**
     * @param Order $order
     * @param bool $notify
     * @param string $comment
     * @return bool
     */
    public function send(Order $order, $notify = false, $comment = '')
    {
        throw new \RuntimeException('send() not implemented in shim');
    }
}
