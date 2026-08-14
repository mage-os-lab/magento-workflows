<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Service;

use MageOS\AsyncEvents\Service\AsyncEvent\EventDispatcher;
use MageOS\Workflows\Model\Engine\ChainDepthContext;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Pins the chain-depth ride-along: payloads published while an execution is
 * running (ChainDepthContext active) carry the reserved depth key for
 * WorkflowNotifier to strip and feed to Dispatcher's loop guard; ordinary
 * publishes carry no such key.
 */
class EventPublisherTest extends TestCase
{
    /** @var array<int, array{event: string, data: array}> */
    private array $dispatched = [];

    private function dispatcher(): EventDispatcher
    {
        $this->dispatched = [];
        $recorded = &$this->dispatched;
        return new class ($recorded) extends EventDispatcher {
            /** @param array<int, array> $recorded */
            public function __construct(private array &$recorded)
            {
            }

            public function dispatch(string $eventName, $output, int $storeId = 0): void
            {
                $this->recorded[] = ['event' => $eventName, 'data' => $output];
            }
        };
    }

    public function testOrdinaryPublishCarriesNoDepthKey(): void
    {
        $publisher = new EventPublisher($this->dispatcher(), new ChainDepthContext());

        $publisher->publish('sales.order.created', ['id' => 7, 'entity_id' => 7]);

        $this->assertCount(1, $this->dispatched);
        $this->assertFalse(
            array_key_exists(ChainDepthContext::PAYLOAD_KEY, $this->dispatched[0]['data']),
            'ordinary publishes must not carry the reserved depth key'
        );
    }

    public function testPublishInsideAnExecutionStampsTheChainDepth(): void
    {
        $context = new ChainDepthContext();
        $publisher = new EventPublisher($this->dispatcher(), $context);

        $context->enter(0);
        try {
            $publisher->publish('catalog.product.price_changed', ['productId' => 5, 'entity_id' => 5]);
        } finally {
            $context->restore(null);
        }

        $data = $this->dispatched[0]['data'];
        $this->assertSame(1, $data[ChainDepthContext::PAYLOAD_KEY]);
        $this->assertSame(5, $data['productId'], 'the rest of the payload must be untouched');
    }

    public function testExplicitDepthKeyIsNotOverwritten(): void
    {
        $context = new ChainDepthContext();
        $publisher = new EventPublisher($this->dispatcher(), $context);

        $context->enter(0);
        try {
            $publisher->publish('x.y', [ChainDepthContext::PAYLOAD_KEY => 9]);
        } finally {
            $context->restore(null);
        }

        $this->assertSame(9, $this->dispatched[0]['data'][ChainDepthContext::PAYLOAD_KEY]);
    }
}
