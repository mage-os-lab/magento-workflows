<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use MageOS\AsyncEvents\Api\AsyncEventRepositoryInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventDisplayInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventSearchResultsInterface;

/**
 * In-memory async-event subscription store. Understands the recipient_url
 * eq/like filters SubscriptionManager builds (via RecordedSearchCriteria),
 * assigns auto-increment subscription ids on first save, and records every
 * save so tests can assert write counts and the $checkResources flag. An
 * optional onSave callback observes each save (used to prove writes happen
 * under the ownership bypass).
 */
class InMemoryAsyncEventRepository implements AsyncEventRepositoryInterface
{
    /** @var array<int, FakeAsyncEvent> subscription id => row */
    public array $items = [];

    /** @var int number of save() calls */
    public int $saveCount = 0;

    /** @var bool|null $checkResources value passed to the last save() */
    public ?bool $lastCheckResources = null;

    /** @var callable|null invoked with the entity before each save */
    public $onSave = null;

    private int $nextId = 1;

    public function seed(FakeAsyncEvent $asyncEvent): FakeAsyncEvent
    {
        if ($asyncEvent->getSubscriptionId() === 0) {
            $asyncEvent->setSubscriptionId($this->nextId);
        }
        $this->nextId = max($this->nextId, $asyncEvent->getSubscriptionId()) + 1;
        $this->items[$asyncEvent->getSubscriptionId()] = $asyncEvent;
        return $asyncEvent;
    }

    public function get(int $subscriptionId): AsyncEventDisplayInterface
    {
        if (!isset($this->items[$subscriptionId])) {
            throw new NoSuchEntityException(new Phrase('No such subscription %1', [$subscriptionId]));
        }
        return $this->items[$subscriptionId];
    }

    public function getList(SearchCriteriaInterface $searchCriteria): AsyncEventSearchResultsInterface
    {
        $matched = [];
        foreach ($this->items as $item) {
            if ($this->matches($item, $searchCriteria)) {
                $matched[] = $item;
            }
        }
        return new FakeAsyncEventSearchResults($matched);
    }

    public function save(AsyncEventInterface $asyncEvent, bool $checkResources = true): AsyncEventDisplayInterface
    {
        if ($this->onSave !== null) {
            ($this->onSave)($asyncEvent);
        }
        $this->saveCount++;
        $this->lastCheckResources = $checkResources;
        if ($asyncEvent->getSubscriptionId() === 0) {
            $asyncEvent->setSubscriptionId($this->nextId++);
        }
        if ($asyncEvent instanceof FakeAsyncEvent) {
            $this->items[$asyncEvent->getSubscriptionId()] = $asyncEvent;
        }
        return $asyncEvent instanceof FakeAsyncEvent ? $asyncEvent : $this->items[$asyncEvent->getSubscriptionId()];
    }

    public function findByRecipient(string $recipient): ?FakeAsyncEvent
    {
        foreach ($this->items as $item) {
            if ($item->getRecipientUrl() === $recipient) {
                return $item;
            }
        }
        return null;
    }

    private function matches(FakeAsyncEvent $item, SearchCriteriaInterface $searchCriteria): bool
    {
        if (!$searchCriteria instanceof RecordedSearchCriteria) {
            return true;
        }
        foreach ($searchCriteria->filters as $filter) {
            if ($filter['field'] !== 'recipient_url') {
                continue; // only recipient_url filtering is modelled
            }
            $value = (string) $filter['value'];
            $recipient = $item->getRecipientUrl();
            if ($filter['conditionType'] === 'like') {
                $prefix = rtrim($value, '%');
                if (!str_starts_with($recipient, $prefix)) {
                    return false;
                }
            } elseif ($recipient !== $value) {
                return false;
            }
        }
        return true;
    }
}
