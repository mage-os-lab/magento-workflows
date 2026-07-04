<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Relation\Resolver;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Shared email → customer lookup (docs/discovery/entity-cross-referencing.md
 * §4–5) behind `order.customer_by_email` and `quote.customer_by_email`: the
 * only difference between the two is the source entity type, so they subclass
 * this.
 *
 * Website scoping is decided ONCE, here, and honored via the $websiteId the
 * RelationContext derives from the source entity's own store columns:
 *  - per-website account sharing → $websiteId is set → the lookup is scoped
 *    to that website (getting this wrong produces false "customer exists" hits
 *    on multi-site installs);
 *  - global account sharing → RelationContext passes null → the lookup is
 *    global.
 * Email comparison follows Magento's own semantics (accounts are stored
 * lower-cased), so the value is lower-cased before matching.
 *
 * A missing/blank email resolves to none (the guest never entered one); a
 * repository failure surfaces as a thrown exception that RelationContext
 * catches and turns into "not related" (fail-toward-false).
 */
abstract class AbstractCustomerByEmail implements RelationInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function getTargetEntityType(): string
    {
        return HydrationProviderInterface::TYPE_CUSTOMER;
    }

    public function getCardinality(): string
    {
        return self::CARDINALITY_ONE;
    }

    public function resolveIds(DataObject $source, ?int $websiteId): array
    {
        $email = trim((string) $source->getData('customer_email'));
        if ($email === '') {
            return [];
        }

        $this->searchCriteriaBuilder->addFilter('email', strtolower($email), 'eq');
        if ($websiteId !== null) {
            $this->searchCriteriaBuilder->addFilter('website_id', $websiteId, 'eq');
        }
        $result = $this->customerRepository->getList($this->searchCriteriaBuilder->create());

        $ids = [];
        foreach ($result->getItems() as $customer) {
            $id = (int) $customer->getId();
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
