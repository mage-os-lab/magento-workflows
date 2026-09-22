<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\WorkflowsCustomer\Model\Option\CustomerGroupOptionSource;

/**
 * customer.assign_group — moves the customer into the configured group.
 * Idempotent: already-in-group skips.
 */
class AssignGroup extends AbstractAction implements SimulateableActionInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly CustomerGroupOptionSource $groupOptionSource
    ) {
    }

    public function getCode(): string
    {
        return 'customer.assign_group';
    }

    public function getLabel(): string
    {
        return (string)__('Assign Customer Group');
    }

    public function getGroup(): string
    {
        return (string)__('Customer');
    }

    public function getApplicableEntities(): array
    {
        return ['customer'];
    }

    public function getConfigForm(): array
    {
        // Bounded option source (F6): customer groups are small and fully
        // enumerable, so inline the options. Degrades to a bare integer field
        // if the source is unavailable at metadata-list time.
        $field = ['name' => 'group_id', 'label' => 'Customer Group', 'type' => 'select', 'required' => true];
        try {
            $options = $this->groupOptionSource->fetch();
            if ($options !== []) {
                $field['options'] = $options;
            }
        } catch (\Throwable $e) {
            $field['type'] = 'integer';
        }
        return [$field];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $groupId = $this->intConfig($config, 'group_id');
        if ($groupId === null) {
            return $this->missingConfig('group_id');
        }

        try {
            $group = $this->groupRepository->getById($groupId);
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Customer group %1 does not exist', $groupId));
        }

        try {
            $customer = $this->customerRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Customer %1 not found', $ctx->getEntityId()));
        }

        if ((int)$customer->getGroupId() === $groupId) {
            return ActionResult::skipped(sprintf('Customer already in group "%s"', $group->getCode()));
        }

        $previousGroupId = (int)$customer->getGroupId();
        try {
            $customer->setGroupId($groupId);
            $this->customerRepository->save($customer);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not assign customer group: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'previous_group_id' => $previousGroupId,
            'group_id' => $groupId,
            'group_code' => (string)$group->getCode(),
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $groupId = $this->intConfig($config, 'group_id');
        if ($groupId === null) {
            return $this->missingConfig('group_id');
        }
        try {
            $group = $this->groupRepository->getById($groupId);
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Customer group %1 does not exist', $groupId));
        }
        return $this->simulated(sprintf(
            'Assign customer %d to group "%s" (%d)',
            $ctx->getEntityId(),
            $group->getCode(),
            $groupId
        ));
    }
}
