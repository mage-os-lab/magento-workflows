<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Integration\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\WorkflowsSales\Model\AbandonedCartDetector;
use MageOS\WorkflowsScheduler\Test\Integration\_files\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Plan #24c (docs/20-integration-test-plan.md §6): AbandonedCartDetector window
 * boundaries against a real quote fixture with a rewound updated_at, and the
 * real mageos_workflow_abandoned_flag dedupe table. The async-events publisher
 * is a recording double; timestamps are rewound with direct UPDATEs (no sleeps,
 * §2.4). The abandonment window is the shipped default (idle >= 4h) and the
 * hard age cutoff MAX_AGE_DAYS = 7.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class AbandonedCartTest extends TestCase
{
    private const FLAG_TABLE = 'mageos_workflow_abandoned_flag';
    private const EVENT_NAME = 'quote.abandoned';
    private const RESERVED_ORDER_ID = 'wf-abandoned-cart';

    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->resource = $this->objectManager->get(ResourceConnection::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture MageOS_WorkflowsScheduler::Test/Integration/_files/abandoned_quote.php
     */
    public function testAbandonedQuoteFiresOnceAndFlags(): void
    {
        $publisher = $this->configureRecordingPublisher();
        $quoteId = $this->quoteId();
        $this->prepareQuote($quoteId, '-5 hours', 1, 1);

        $detector = $this->objectManager->create(AbandonedCartDetector::class);
        $detector->execute();

        $this->assertSame(1, $publisher->count(), 'An idle cart in the window fires once');
        $this->assertSame(1, $this->flagCount($quoteId), 'The quote is flagged');
        $payloads = $publisher->payloadsFor(self::EVENT_NAME);
        $this->assertCount(1, $payloads);
        $this->assertSame($quoteId, $payloads[0]['quote_id']);
        $this->assertSame('abandoned-cart@example.com', $payloads[0]['customer_email']);

        // Second sweep: the flag suppresses a duplicate dispatch.
        $detector->execute();
        $this->assertSame(1, $publisher->count(), 'One dispatch per quote across sweeps');
        $this->assertSame(1, $this->flagCount($quoteId));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture MageOS_WorkflowsScheduler::Test/Integration/_files/abandoned_quote.php
     */
    public function testQuoteInsideTheIdleWindowIsExcluded(): void
    {
        $publisher = $this->configureRecordingPublisher();
        $quoteId = $this->quoteId();
        // Only 1h idle — below the 4h abandonment threshold.
        $this->prepareQuote($quoteId, '-1 hours', 1, 1);

        $this->objectManager->create(AbandonedCartDetector::class)->execute();

        $this->assertSame(0, $publisher->count(), 'A too-recent cart is not abandoned yet');
        $this->assertSame(0, $this->flagCount($quoteId));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture MageOS_WorkflowsScheduler::Test/Integration/_files/abandoned_quote.php
     */
    public function testQuoteOlderThanMaxAgeIsExcluded(): void
    {
        $publisher = $this->configureRecordingPublisher();
        $quoteId = $this->quoteId();
        // 8 days idle — past the hard MAX_AGE_DAYS cutoff.
        $this->prepareQuote($quoteId, '-8 days', 1, 1);

        $this->objectManager->create(AbandonedCartDetector::class)->execute();

        $this->assertSame(0, $publisher->count(), 'A cart older than the age cutoff is excluded');
        $this->assertSame(0, $this->flagCount($quoteId));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture MageOS_WorkflowsScheduler::Test/Integration/_files/abandoned_quote.php
     */
    public function testConvertedOrInactiveQuoteIsExcluded(): void
    {
        $publisher = $this->configureRecordingPublisher();
        $quoteId = $this->quoteId();
        // Idle long enough, but converted to an order (is_active = 0).
        $this->prepareQuote($quoteId, '-5 hours', 0, 1);

        $this->objectManager->create(AbandonedCartDetector::class)->execute();

        $this->assertSame(0, $publisher->count(), 'A converted/inactive quote never abandons');
        $this->assertSame(0, $this->flagCount($quoteId));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture MageOS_WorkflowsScheduler::Test/Integration/_files/abandoned_quote.php
     */
    public function testEmptyQuoteIsExcluded(): void
    {
        $publisher = $this->configureRecordingPublisher();
        $quoteId = $this->quoteId();
        // Idle long enough, but no items.
        $this->prepareQuote($quoteId, '-5 hours', 1, 0);

        $this->objectManager->create(AbandonedCartDetector::class)->execute();

        $this->assertSame(0, $publisher->count(), 'An emptied cart is not an abandoned cart');
        $this->assertSame(0, $this->flagCount($quoteId));
    }

    private function configureRecordingPublisher(): RecordingEventPublisher
    {
        $this->objectManager->configure([
            'preferences' => [EventPublisher::class => RecordingEventPublisher::class],
        ]);
        /** @var RecordingEventPublisher $publisher */
        $publisher = $this->objectManager->get(EventPublisher::class);
        return $publisher;
    }

    private function quoteId(): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('quote'), 'entity_id')
                ->where('reserved_order_id = ?', self::RESERVED_ORDER_ID)
                ->limit(1)
        );
    }

    private function prepareQuote(int $quoteId, string $updatedAtOffset, int $isActive, int $itemsCount): void
    {
        $updatedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify($updatedAtOffset)
            ->format('Y-m-d H:i:s');
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName('quote'),
            // updated_at is set explicitly so ON UPDATE CURRENT_TIMESTAMP does not
            // clobber the rewind.
            ['updated_at' => $updatedAt, 'is_active' => $isActive, 'items_count' => $itemsCount],
            ['entity_id = ?' => $quoteId]
        );
    }

    private function flagCount(int $quoteId): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::FLAG_TABLE), 'COUNT(*)')
                ->where('quote_id = ?', $quoteId)
        );
    }
}
