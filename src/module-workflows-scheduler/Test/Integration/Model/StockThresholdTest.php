<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Integration\Model;

use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\WorkflowsInventory\Model\StockThresholdDetector;
use MageOS\WorkflowsScheduler\Test\Integration\_files\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Plan #24b (docs/20-integration-test-plan.md §6): stock-threshold hysteresis
 * against real cataloginventory stock items and the real
 * mageos_workflow_stock_flag table (docs/05-triggers.md footnote 2). The
 * async-events publisher is replaced with a recording double so the detector's
 * detection/flag/dedupe logic runs end-to-end without the real transport, and
 * the published payload is asserted directly.
 *
 * Threshold is the shipped default (mageos_workflows/scheduler/stock_threshold
 * = 5). Fires inventory.stock_threshold_crossed once on the downward crossing,
 * stays silent while below, then publishes inventory.back_in_stock and re-arms
 * once qty recovers above the threshold (INV-T1).
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class StockThresholdTest extends TestCase
{
    private const FLAG_TABLE = 'mageos_workflow_stock_flag';
    private const EVENT_NAME = 'inventory.stock_threshold_crossed';
    private const EVENT_BACK_IN_STOCK = 'inventory.back_in_stock';

    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->resource = $this->objectManager->get(ResourceConnection::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testHysteresisFiresOnceStaysSilentThenReArms(): void
    {
        $publisher = $this->configureRecordingPublisher();
        $productId = (int) $this->objectManager->get(ProductRepositoryInterface::class)
            ->get('simple')->getId();
        $stockRegistry = $this->objectManager->get(StockRegistryInterface::class);
        $stockItemRepository = $this->objectManager->get(StockItemRepositoryInterface::class);
        $stockItem = $stockRegistry->getStockItem($productId);

        $setQty = function (float $qty) use ($stockItem, $stockItemRepository): void {
            $stockItem->setQty($qty);
            $stockItem->setIsInStock(true);
            $stockItemRepository->save($stockItem);
        };

        $detector = $this->objectManager->create(StockThresholdDetector::class);

        // (1) Drop below the threshold: fires the crossing trigger once and flags.
        $setQty(3.0);
        $detector->execute();
        $this->assertCount(1, $publisher->payloadsFor(self::EVENT_NAME), 'Crossing below the threshold fires once');
        $this->assertSame(1, $this->flagCount($productId), 'The product is flagged after firing');
        $payloads = $publisher->payloadsFor(self::EVENT_NAME);
        $this->assertSame($productId, $payloads[0]['product_id']);
        $this->assertSame($productId, $payloads[0]['entity_id']);
        $this->assertSame(3.0, $payloads[0]['qty']);
        $this->assertSame(5.0, $payloads[0]['threshold']);

        // (2) Still below: the flag suppresses a re-fire.
        $detector->execute();
        $this->assertCount(1, $publisher->payloadsFor(self::EVENT_NAME), 'Staying below the threshold does not re-fire');
        $this->assertCount(0, $publisher->payloadsFor(self::EVENT_BACK_IN_STOCK));
        $this->assertSame(1, $this->flagCount($productId));

        // (3) Recover above the threshold: the recovery pass publishes
        // inventory.back_in_stock once and unflags (re-arms), INV-T1.
        $setQty(10.0);
        $detector->execute();
        $backInStock = $publisher->payloadsFor(self::EVENT_BACK_IN_STOCK);
        $this->assertCount(1, $backInStock, 'Recovery above the threshold publishes back_in_stock once');
        $this->assertSame($productId, $backInStock[0]['product_id']);
        $this->assertSame($productId, $backInStock[0]['entity_id']);
        $this->assertSame(10.0, $backInStock[0]['qty'], 'back_in_stock carries the recovered qty');
        $this->assertSame(5.0, $backInStock[0]['threshold']);
        $this->assertCount(1, $publisher->payloadsFor(self::EVENT_NAME), 'Recovery does not re-fire the crossing');
        $this->assertSame(0, $this->flagCount($productId), 'Recovery removes the flag (re-arms)');

        // (4) Still above on the next run: back_in_stock does not re-publish.
        $detector->execute();
        $this->assertCount(
            1,
            $publisher->payloadsFor(self::EVENT_BACK_IN_STOCK),
            'back_in_stock fires once per flag/unflag cycle'
        );

        // (5) Dip below again: the re-armed crossing trigger fires a second time.
        $setQty(2.0);
        $detector->execute();
        $this->assertCount(2, $publisher->payloadsFor(self::EVENT_NAME), 'A re-armed product fires again on the next dip');
        $this->assertSame(1, $this->flagCount($productId));
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

    private function flagCount(int $productId): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::FLAG_TABLE), 'COUNT(*)')
                ->where('product_id = ?', $productId)
        );
    }
}
