<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * customer.assign_group — moves the customer into the configured group.
 * Idempotent: already-in-group skips.
 */
class AssignGroup extends AbstractAction implements SimulateableActionInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    public function getCode(): string
    {
        return 'customer.assign_group';
    }

    public function getLabel(): string
    {
        return 'Assign Customer Group';
    }

    public function getGroup(): string
    {
        return 'Customer';
    }

    public function getApplicableEntities(): array
    {
        return ['customer'];
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'group_id', 'label' => 'Customer Group ID', 'type' => 'integer', 'required' => true],
        ];
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
            return ActionResult::failure(sprintf('Customer group %d does not exist', $groupId));
        }

        try {
            $customer = $this->customerRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure(sprintf('Customer %d not found', $ctx->getEntityId()));
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
            return ActionResult::failure(sprintf('Customer group %d does not exist', $groupId));
        }
        return $this->simulated(sprintf(
            'Assign customer %d to group "%s" (%d)',
            $ctx->getEntityId(),
            $group->getCode(),
            $groupId
        ));
    }
}
