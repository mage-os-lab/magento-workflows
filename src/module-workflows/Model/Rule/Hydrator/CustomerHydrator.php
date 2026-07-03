<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Hydrator;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * customer hydrator: flat customer DTO data with custom/extension attributes
 * lifted to top-level keys.
 */
class CustomerHydrator implements EntityHydratorInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly EntityDataConverter $dataConverter,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    public function hydrate(int $entityId): ?DataObject
    {
        try {
            $customer = $this->customerRepository->getById($entityId);
        } catch (NoSuchEntityException | LocalizedException) {
            return null;
        }

        return $this->dataObjectFactory->create([
            'data' => $this->dataConverter->toFlatArray($customer, CustomerInterface::class),
        ]);
    }
}
