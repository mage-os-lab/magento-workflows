<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use Magento\Framework\Api\SearchCriteriaInterface;
use MageOS\AsyncEvents\Api\AsyncEventRepositoryInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventDisplayInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventSearchResultsInterface;
use MageOS\WorkflowsTriggersCore\Plugin\SubscriptionOwnershipPlugin;

/**
 * Repository decorated by the REAL SubscriptionOwnershipPlugin, standing in
 * for Magento's interceptor wiring (di.xml plugs the plugin onto
 * AsyncEventRepositoryInterface). Lets behavior tests prove the whole loop:
 * out-of-band saves of workflow-owned rows are refused, while
 * SubscriptionManager's bypass-wrapped writes pass through.
 */
class GuardedAsyncEventRepository implements AsyncEventRepositoryInterface
{
    public function __construct(
        private readonly InMemoryAsyncEventRepository $inner,
        private readonly SubscriptionOwnershipPlugin $plugin
    ) {
    }

    public function get(int $subscriptionId): AsyncEventDisplayInterface
    {
        return $this->inner->get($subscriptionId);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): AsyncEventSearchResultsInterface
    {
        return $this->inner->getList($searchCriteria);
    }

    public function save(AsyncEventInterface $asyncEvent, bool $checkResources = true): AsyncEventDisplayInterface
    {
        return $this->plugin->aroundSave(
            $this->inner,
            fn (AsyncEventInterface $entity, ...$args) => $this->inner->save($entity, ...$args),
            $asyncEvent,
            $checkResources
        );
    }
}
