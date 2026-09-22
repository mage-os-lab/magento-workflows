<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Integration\Service;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AsyncEvents\Api\AsyncEventRepositoryInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterface;
use MageOS\AsyncEvents\Api\Data\AsyncEventInterfaceFactory;
use MageOS\AsyncEvents\Helper\NotifierResult;
use MageOS\AsyncEvents\Helper\NotifierResultFactory;
use MageOS\AsyncEvents\Service\AsyncEvent\NotifierFactory;
use MageOS\AsyncEvents\Service\AsyncEvent\NotifierInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowFactory;
use MageOS\WorkflowsTriggersCore\Model\SubscriptionManager;
use MageOS\WorkflowsTriggersCore\Model\WorkflowNotifier;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Plan #23 (docs/20-integration-test-plan.md §6): async-events class-name
 * fidelity. Runs against the REAL mage-os/mageos-async-events (>= 4.0)
 * package the integration job installs — the shims under dev/tests/shims are
 * never loaded here. This suite retires the README's "class-name fidelity …
 * needs verification" caveat and the docblock assumptions in
 * module-workflows-triggers-core/etc/di.xml: every assumption is asserted by
 * reflection against the resolved real classes and against the real DB, so a
 * drifted async-events API fails this suite loudly rather than silently
 * dropping trigger delivery.
 *
 * @magentoDbIsolation enabled
 */
class AsyncEventsFidelityTest extends TestCase
{
    private \Magento\Framework\ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    /**
     * The named async-events interfaces/classes the trigger layer binds
     * against must all resolve under the real package.
     */
    public function testRequiredAsyncEventsTypesExist(): void
    {
        $this->assertTrue(
            interface_exists(AsyncEventInterface::class),
            'AsyncEventInterface must exist in the installed async-events package'
        );
        $this->assertTrue(interface_exists(AsyncEventRepositoryInterface::class));
        $this->assertTrue(interface_exists(NotifierInterface::class));
        $this->assertTrue(class_exists(NotifierResult::class));
        $this->assertTrue(class_exists(NotifierResultFactory::class));
        $this->assertTrue(class_exists(NotifierFactory::class));
        $this->assertTrue(
            class_exists(AsyncEventInterfaceFactory::class),
            'The generated AsyncEventInterfaceFactory must be resolvable'
        );
        $this->assertTrue(
            class_exists(\CloudEvents\V1\CloudEventImmutable::class),
            'WorkflowNotifier::notify() types the delivered event as CloudEventImmutable'
        );
    }

    /**
     * WorkflowNotifier must implement the REAL notifier contract and narrow
     * its return to NotifierResult (a legal covariant return over
     * ResultInterface).
     */
    public function testWorkflowNotifierImplementsTheRealNotifierContract(): void
    {
        $notifier = $this->objectManager->get(WorkflowNotifier::class);
        $this->assertInstanceOf(
            NotifierInterface::class,
            $notifier,
            'WorkflowNotifier must implement the async-events NotifierInterface'
        );

        $method = new \ReflectionMethod(WorkflowNotifier::class, 'notify');
        $params = $method->getParameters();
        $this->assertCount(2, $params, 'notify() takes (AsyncEventInterface, CloudEventImmutable)');
        $this->assertSame(
            AsyncEventInterface::class,
            $this->paramType($params[0]),
            'First notify() parameter must be the real AsyncEventInterface'
        );
        $this->assertSame(
            \CloudEvents\V1\CloudEventImmutable::class,
            $this->paramType($params[1]),
            'Second notify() parameter must be the real CloudEventImmutable'
        );

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame(NotifierResult::class, $returnType->getName());
    }

    /**
     * NotifierResult must expose exactly the setters WorkflowNotifier::buildResult
     * calls; a missing one is the drift the fidelity suite exists to catch.
     */
    public function testNotifierResultExposesTheSettersWorkflowNotifierCalls(): void
    {
        foreach (['setSubscriptionId', 'setIsSuccessful', 'setIsRetryable', 'setResponseData'] as $method) {
            $this->assertTrue(
                method_exists(NotifierResult::class, $method),
                sprintf('NotifierResult::%s() is required by WorkflowNotifier', $method)
            );
        }
        $result = $this->objectManager->get(NotifierResultFactory::class)->create();
        $this->assertInstanceOf(NotifierResult::class, $result);
    }

    /**
     * AsyncEventInterface must expose the get/set pairs SubscriptionManager
     * drives.
     */
    public function testAsyncEventInterfaceExposesTheAccessorsSubscriptionManagerDrives(): void
    {
        foreach ([
            'getSubscriptionId', 'setSubscriptionId',
            'getEventName', 'setEventName',
            'getRecipientUrl', 'setRecipientUrl',
            'getVerificationToken', 'setVerificationToken',
            'getMetadata', 'setMetadata',
            'getStatus', 'setStatus',
        ] as $method) {
            $this->assertTrue(
                method_exists(AsyncEventInterface::class, $method),
                sprintf('AsyncEventInterface::%s() is required by SubscriptionManager', $method)
            );
        }
    }

    /**
     * The repository must carry get()/getList()/save(), and save() must accept
     * the $checkResources flag SubscriptionManager passes false for.
     */
    public function testRepositorySaveAcceptsTheCheckResourcesFlag(): void
    {
        $this->assertTrue(method_exists(AsyncEventRepositoryInterface::class, 'get'));
        $this->assertTrue(method_exists(AsyncEventRepositoryInterface::class, 'getList'));
        $this->assertTrue(method_exists(AsyncEventRepositoryInterface::class, 'save'));

        $save = new \ReflectionMethod(AsyncEventRepositoryInterface::class, 'save');
        $this->assertGreaterThanOrEqual(
            2,
            $save->getNumberOfParameters(),
            'save() must accept a second ($checkResources) argument'
        );
        $second = $save->getParameters()[1];
        $this->assertSame('checkResources', $second->getName());
    }

    /**
     * The di.xml assumption "NotifierFactory takes a `notifierClasses` array
     * argument" must hold, or the WorkflowNotifier is never registered under
     * the "workflow" metadata and event delivery silently no-ops.
     */
    public function testNotifierFactoryTakesTheNotifierClassesArgument(): void
    {
        $constructor = new \ReflectionMethod(NotifierFactory::class, '__construct');
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters()
        );
        $this->assertContains(
            'notifierClasses',
            $names,
            'etc/di.xml wires NotifierFactory argument "notifierClasses"; it must be a real constructor param'
        );
    }

    /**
     * EventPublisher's single async-events seam: the real EventDispatcher
     * exposes dispatch(string $eventName, mixed $output, int $storeId = 0)
     * (verified against mageos-async-events @ b249976), so our two-arg
     * dispatch($eventName, $data) call is signature-valid. See the seam
     * divergence note in the EventPublisher docblock / docs/14-risks.md.
     */
    public function testEventDispatcherExposesDispatch(): void
    {
        $publisherCtor = new \ReflectionMethod(EventPublisher::class, '__construct');
        $dispatcherType = $this->paramType($publisherCtor->getParameters()[0]);
        $this->assertTrue(
            class_exists($dispatcherType),
            'EventPublisher depends on the real async-events EventDispatcher'
        );
        $dispatch = new \ReflectionMethod($dispatcherType, 'dispatch');
        $this->assertGreaterThanOrEqual(
            2,
            $dispatch->getNumberOfParameters(),
            'EventPublisher::publish delegates to EventDispatcher::dispatch(string $eventName, mixed $output, ...)'
        );
    }

    /**
     * Subscription lifecycle against the real async-events DB: an
     * event-triggered workflow gets exactly one active subscription whose
     * recipient URL is the "workflow:<id>" ownership marker, event_name is the
     * trigger ref, and metadata selects the workflow notifier.
     *
     * @magentoAppIsolation enabled
     */
    public function testSubscriptionLifecycleRoundTripsTheWorkflowRecipientMarker(): void
    {
        $workflow = $this->newEventWorkflow(WorkflowInterface::STATUS_ENABLED, 'sales.order.created');
        $saved = $this->objectManager->get(WorkflowRepositoryInterface::class)->save($workflow);
        $workflowId = (int) $saved->getWorkflowId();

        $manager = $this->objectManager->get(SubscriptionManager::class);
        $manager->ensureSubscription($saved);

        $recipient = WorkflowNotifier::RECIPIENT_PREFIX . $workflowId;
        $subscription = $this->findByRecipient($recipient);
        $this->assertNotNull($subscription, 'ensureSubscription must create a workflow:<id> subscription');
        $this->assertTrue((bool) $subscription->getStatus(), 'A fresh subscription is active');
        $this->assertSame('sales.order.created', (string) $subscription->getEventName());
        $this->assertSame(WorkflowNotifier::NOTIFIER_NAME, (string) $subscription->getMetadata());

        // Disabling the workflow deactivates the same subscription row.
        $manager->disableSubscription($saved);
        $disabled = $this->findByRecipient($recipient);
        $this->assertNotNull($disabled, 'The row is kept, only deactivated');
        $this->assertFalse((bool) $disabled->getStatus(), 'disableSubscription flips status to inactive');
    }

    /**
     * Ownership refusal (docs/10-security.md#subscription-ownership): the merged
     * di.xml plugin refuses a direct repository save of a workflow-owned
     * subscription (recipient "workflow:*"), while a foreign subscription saves
     * freely.
     *
     * @magentoAppIsolation enabled
     */
    public function testOwnershipPluginRefusesDirectSaveOfOwnedSubscriptions(): void
    {
        $repository = $this->objectManager->get(AsyncEventRepositoryInterface::class);
        $factory = $this->objectManager->get(AsyncEventInterfaceFactory::class);

        // A foreign subscription (not workflow-owned) saves fine.
        $foreign = $factory->create();
        $foreign->setEventName('sales.order.created');
        $foreign->setRecipientUrl('https://example.com/hooks/orders');
        $foreign->setVerificationToken('tok-foreign');
        $foreign->setMetadata('http');
        $foreign->setStatus(true);
        $foreign->setStoreId(0);
        $savedForeign = $repository->save($foreign, false);
        $this->assertNotEmpty($savedForeign->getSubscriptionId());

        // A workflow-owned recipient is refused out-of-band.
        $owned = $factory->create();
        $owned->setEventName('sales.order.created');
        $owned->setRecipientUrl(WorkflowNotifier::RECIPIENT_PREFIX . '999');
        $owned->setVerificationToken('tok-owned');
        $owned->setMetadata(WorkflowNotifier::NOTIFIER_NAME);
        $owned->setStatus(true);
        $owned->setStoreId(0);

        $this->expectException(LocalizedException::class);
        $repository->save($owned, false);
    }

    private function findByRecipient(string $recipient): ?AsyncEventInterface
    {
        $searchCriteria = $this->objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('recipient_url', $recipient)
            ->create();
        $items = $this->objectManager->get(AsyncEventRepositoryInterface::class)
            ->getList($searchCriteria)
            ->getItems();
        foreach ($items as $item) {
            return $item;
        }
        return null;
    }

    private function newEventWorkflow(int $status, string $triggerRef): WorkflowInterface
    {
        $workflow = $this->objectManager->get(WorkflowFactory::class)->create();
        $workflow->setName('fidelity ' . $triggerRef . ' ' . $status);
        $workflow->setStatus($status);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef($triggerRef);
        $workflow->setEntityType('sales_order');
        $workflow->setDefinition((string) json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'fidelity'],
                    'next' => null,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $workflow;
    }

    private function paramType(\ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        return $type instanceof \ReflectionNamedType ? $type->getName() : '';
    }
}
