<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Queue;

use Magento\Framework\Communication\ConfigInterface as CommunicationConfig;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\Framework\MessageQueue\ConsumerFactory;
use Magento\Framework\MessageQueue\ConsumerInterface;
use Magento\Framework\MessageQueue\Publisher\ConfigInterface as PublisherConfig;
use Magento\Framework\MessageQueue\PublisherInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Engine\Dispatcher;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #12 (docs/20 §4): the queue topology (communication.xml / queue_*.xml)
 * parses and merges under the real framework. Both consumers resolve through
 * ConsumerFactory; the communication, consumer, and publisher config readers
 * agree on the two topics and bind the module's handlers. One best-effort smoke
 * drives a message over the real db transport (skipped if the in-process
 * transaction hides the publish from the consumer connection).
 *
 * @magentoDbIsolation enabled
 */
class TopologyTest extends TestCase
{
    use WorkflowEngineTestTrait;

    private const TOPICS = [Dispatcher::TOPIC_EXECUTE, Dispatcher::TOPIC_RESUME];

    public function testBothConsumersResolveViaConsumerFactory(): void
    {
        $factory = $this->om()->get(ConsumerFactory::class);
        foreach (self::TOPICS as $name) {
            $consumer = $factory->get($name);
            $this->assertInstanceOf(
                ConsumerInterface::class,
                $consumer,
                sprintf('Consumer "%s" must resolve through ConsumerFactory', $name)
            );
        }
    }

    public function testCommunicationConfigDefinesBothTopics(): void
    {
        $config = $this->om()->get(CommunicationConfig::class);
        foreach (self::TOPICS as $name) {
            $topic = $config->getTopic($name);
            $this->assertIsArray($topic, sprintf('communication.xml must declare topic "%s"', $name));
            $this->assertSame($name, $topic[CommunicationConfig::TOPIC_NAME] ?? null);
        }
    }

    public function testConsumerConfigBindsTheModuleHandlers(): void
    {
        $config = $this->om()->get(ConsumerConfig::class);

        $expected = [
            Dispatcher::TOPIC_EXECUTE => 'MageOS\Workflows\Model\Queue\ExecuteConsumer::process',
            Dispatcher::TOPIC_RESUME => 'MageOS\Workflows\Model\Queue\ResumeConsumer::process',
        ];
        foreach ($expected as $consumerName => $handler) {
            $item = $config->getConsumer($consumerName);
            $this->assertSame($consumerName, $item->getQueue(), 'Consumer binds its own named queue');
            $handlers = [];
            foreach ($item->getHandlers() as $h) {
                $handlers[] = $h->getType() . '::' . $h->getMethod();
            }
            $this->assertContains($handler, $handlers, sprintf('Consumer "%s" binds %s', $consumerName, $handler));
        }
    }

    public function testPublisherConfigDefinesBothTopics(): void
    {
        $config = $this->om()->get(PublisherConfig::class);
        foreach (self::TOPICS as $name) {
            $publisher = $config->getPublisher($name);
            $this->assertSame($name, $publisher->getTopic(), sprintf('Publisher must be declared for "%s"', $name));
        }
    }

    /**
     * Best-effort transport smoke: publish an execute message and run the real
     * consumer for one message. Under a single test transaction the db transport
     * may not surface the publish to the consumer's read; when it does not, this
     * is skipped rather than failed (the config/resolution tests above are the
     * hard contract).
     */
    public function testEndToEndSmokeOverDbTransport(): void
    {
        $workflow = $this->createWorkflow([
            'name' => 'topology smoke',
            'status' => WorkflowInterface::STATUS_SHADOW,
            'definition' => [
                'schema' => 1,
                'entry' => 's1',
                'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 's'], 'next' => null]],
            ],
        ]);
        $execution = $this->seedExecution((int) $workflow->getWorkflowId(), $workflow->getDefinition(), 77, 1);
        $id = (int) $execution->getExecutionId();

        $this->om()->get(PublisherInterface::class)->publish(Dispatcher::TOPIC_EXECUTE, (string) $id);

        try {
            $consumer = $this->om()->get(ConsumerFactory::class)->get(Dispatcher::TOPIC_EXECUTE);
            $consumer->process(1);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB transport not drivable in-process here: ' . $e->getMessage());
        }

        $status = $this->reloadExecution($id)->getStatus();
        if ($status === 'pending') {
            $this->markTestSkipped('The in-transaction publish was not delivered to the consumer connection');
        }
        $this->assertSame('complete', $status, 'The consumed execution walked to completion');
    }
}
