<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Model\DryRun;

use Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory;
use Magento\Newsletter\Model\Subscriber;
use MageOS\Workflows\Api\RecentEntityProviderInterface;
use MageOS\WorkflowsNewsletter\Model\SubscriberStatus;

/**
 * Recent newsletter subscribers for the dry-run entity picker (03): newest
 * first, labelled by email and status word. v1 does no condition filtering — it
 * is a convenience shortcut, and manual id entry remains available for anything
 * not in the list (same contract as the sales_order provider).
 *
 * Collection-backed rather than repository-backed: core ships no
 * SubscriberRepositoryInterface / SearchCriteria surface for subscribers (see
 * SubscriberHydrator's KNOWN LIMITATION docblock), so "newest first" is
 * `subscriber_id DESC` on the resource collection — subscriber rows carry no
 * created_at column.
 */
class SubscriberRecentEntityProvider implements RecentEntityProviderInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getEntityType(): string
    {
        return 'newsletter_subscriber';
    }

    public function getRecent(int $limit): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder('subscriber_id', 'DESC');
        $collection->setPageSize($limit);
        $collection->setCurPage(1);

        $rows = [];
        foreach ($collection->getItems() as $subscriber) {
            /** @var Subscriber $subscriber */
            $rows[] = [
                'id' => (int) $subscriber->getId(),
                'label' => sprintf(
                    '%s — %s',
                    (string) $subscriber->getSubscriberEmail(),
                    SubscriberStatus::label((int) $subscriber->getSubscriberStatus())
                ),
            ];
        }
        return $rows;
    }
}
