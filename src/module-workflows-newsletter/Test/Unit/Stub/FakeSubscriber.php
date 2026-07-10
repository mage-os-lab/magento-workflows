<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Test\Unit\Stub;

use Magento\Newsletter\Model\Subscriber;

/**
 * Subscriber stand-in for the newsletter observer / hydrator / aggregate tests.
 * Backed by a flat data array plus an original-status snapshot ($origStatus =
 * null models a brand-new subscriber, i.e. first save). load()/loadByCustomerId()
 * are no-ops returning $this, so a factory can hand back a pre-prepared subscriber.
 */
class FakeSubscriber extends Subscriber
{
    /**
     * @param array<string, mixed> $data
     * @param mixed $origStatus null = new subscriber (no original status)
     */
    public function __construct(
        private array $data = [],
        private $origStatus = null
    ) {
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->data['subscriber_id'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getSubscriberStatus()
    {
        return $this->data['subscriber_status'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getSubscriberEmail()
    {
        return $this->data['subscriber_email'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getStoreId()
    {
        return $this->data['store_id'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getCustomerId()
    {
        return $this->data['customer_id'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getChangeStatusAt()
    {
        return $this->data['change_status_at'] ?? null;
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getOrigData($key = null)
    {
        return $key === 'subscriber_status' ? $this->origStatus : null;
    }

    /**
     * Signature mirrors AbstractModel::load($modelId, $field = null) so the
     * override is declaration-compatible with the real parent.
     *
     * @param mixed $id
     * @param string|null $field
     * @return $this
     */
    public function load($id, $field = null)
    {
        return $this;
    }

    /**
     * @param mixed $customerId
     * @return $this
     */
    public function loadByCustomerId($customerId)
    {
        return $this;
    }
}
