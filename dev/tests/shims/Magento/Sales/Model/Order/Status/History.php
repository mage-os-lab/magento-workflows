<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Status;

/**
 * Standalone-runner shim for Magento\Sales\Model\Order\Status\History (an
 * AbstractModel in core, whose save dispatches sales_order_status_history_save_after).
 * OrderCommentAddedObserver (ORD-T2) reads these getters off the event's
 * data_object; test doubles subclass and override them.
 */
class History
{
    public function getEntityId()
    {
        return null;
    }

    public function getParentId()
    {
        return null;
    }

    public function getComment()
    {
        return null;
    }

    public function getStatus()
    {
        return null;
    }

    public function getIsCustomerNotified()
    {
        return null;
    }

    public function getIsVisibleOnFront()
    {
        return null;
    }
}
