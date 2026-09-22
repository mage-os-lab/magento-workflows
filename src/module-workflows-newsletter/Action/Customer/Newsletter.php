<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Action\Customer;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * customer.newsletter — subscribes or unsubscribes the customer on the
 * execution's store. Idempotent by nature of the subscription manager
 * (re-subscribing a subscriber is a no-op write).
 */
class Newsletter extends AbstractAction implements SimulateableActionInterface
{
    private const ACTION_SUBSCRIBE = 'subscribe';
    private const ACTION_UNSUBSCRIBE = 'unsubscribe';

    public function __construct(
        private readonly SubscriptionManagerInterface $subscriptionManager
    ) {
    }

    public function getCode(): string
    {
        return 'customer.newsletter';
    }

    public function getLabel(): string
    {
        return (string)__('Newsletter Subscribe/Unsubscribe');
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
        return [
            [
                'name' => 'action',
                'label' => 'Action',
                'type' => 'select',
                'required' => true,
                'options' => [
                    ['value' => self::ACTION_SUBSCRIBE, 'label' => 'Subscribe'],
                    ['value' => self::ACTION_UNSUBSCRIBE, 'label' => 'Unsubscribe'],
                ],
            ],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $action = $this->stringConfig($config, 'action');
        if ($action === null) {
            return $this->missingConfig('action');
        }
        if (!in_array($action, [self::ACTION_SUBSCRIBE, self::ACTION_UNSUBSCRIBE], true)) {
            return ActionResult::failure((string)__('Invalid newsletter action "%1" (subscribe|unsubscribe)', $action));
        }

        try {
            $subscriber = $action === self::ACTION_SUBSCRIBE
                ? $this->subscriptionManager->subscribeCustomer($ctx->getEntityId(), $ctx->getStoreId())
                : $this->subscriptionManager->unsubscribeCustomer($ctx->getEntityId(), $ctx->getStoreId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Customer %1 not found', $ctx->getEntityId()));
        } catch (\Exception $e) {
            return ActionResult::failure('Newsletter update failed: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'action' => $action,
            'subscriber_status' => (int)$subscriber->getSubscriberStatus(),
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $action = $this->stringConfig($config, 'action');
        if ($action === null) {
            return $this->missingConfig('action');
        }
        if (!in_array($action, [self::ACTION_SUBSCRIBE, self::ACTION_UNSUBSCRIBE], true)) {
            return ActionResult::failure((string)__('Invalid newsletter action "%1" (subscribe|unsubscribe)', $action));
        }
        return $this->simulated(sprintf(
            '%s customer %d on store %d',
            ucfirst($action),
            $ctx->getEntityId(),
            $ctx->getStoreId()
        ));
    }
}
