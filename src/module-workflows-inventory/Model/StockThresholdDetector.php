<?php
declare(strict_types=1);

namespace MageOS\WorkflowsInventory\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs every 10 minutes (etc/crontab.xml, job mageos_workflows_stock_threshold).
 *
 * Query trigger, same shape as AbandonedCartDetector: "stock qty crossed
 * below threshold" isn't an async event Magento emits, it's a query against
 * legacy stock (cataloginventory_stock_item - consistent with v1 scope).
 * Detected products are published as 'inventory.stock_threshold_crossed'
 * async events (declared in mageos-workflows-triggers-core's
 * async_events.xml) so they ride the same single dispatch path as every
 * other trigger.
 *
 * Hysteresis via mageos_workflow_stock_flag (owned by this module's
 * db_schema, relocated from the engine in domain-packs S4): a product fires
 * once when it crosses at-or-below the
 * threshold, then stays flagged. The recovery pass deletes flags for
 * products whose qty is back above the threshold, re-arming the trigger.
 * Note: AbandonedCartDetector never unflags (quotes age out via MAX_AGE_DAYS
 * instead); stock is cyclical, so re-arming is required here.
 *
 * Soft dependency, intentionally: mageos-workflows-triggers-core owns the
 * EventPublisher. This module must not hard-require that package
 * (composer.json only "suggest"s it), so we probe for the class at runtime
 * and degrade to a debug log when it's absent - the query/detection logic
 * still runs and still dedupes via mageos_workflow_stock_flag either way.
 */
class StockThresholdDetector
{
    private const XML_PATH_STOCK_THRESHOLD = 'mageos_workflows/scheduler/stock_threshold';
    private const BATCH_SIZE = 500;

    private const STOCK_ITEM_TABLE = 'cataloginventory_stock_item';
    private const PRODUCT_TABLE = 'catalog_product_entity';
    private const FLAG_TABLE = 'mageos_workflow_stock_flag';

    /** @see class docblock - soft dependency, not in composer.json "require" */
    private const EVENT_PUBLISHER_CLASS = 'MageOS\\WorkflowsTriggersCore\\Service\\EventPublisher';
    private const EVENT_NAME = 'inventory.stock_threshold_crossed';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function execute(): void
    {
        $threshold = (float) $this->scopeConfig->getValue(self::XML_PATH_STOCK_THRESHOLD);
        if ($threshold <= 0) {
            // 0 or empty = detector disabled by configuration.
            $this->logger->debug(
                'StockThresholdDetector: mageos_workflows/scheduler/stock_threshold is 0 or empty; '
                . 'detector is disabled.'
            );
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $flagTable = $this->resourceConnection->getTableName(self::FLAG_TABLE);

        // Recovery pass first: products back above the threshold re-arm the
        // trigger, so a later dip fires again (simple hysteresis at exactly
        // the threshold boundary).
        $this->unflagRecovered($connection, $flagTable, $threshold);

        $products = $this->fetchCrossedCandidates($connection, $flagTable, $threshold);
        if ($products === []) {
            return;
        }

        $publisher = $this->resolvePublisher();
        $flaggedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        foreach ($products as $product) {
            $productId = (int) $product['product_id'];
            try {
                $this->publish($publisher, $product, $threshold);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'StockThresholdDetector: failed publishing "%s" for product #%d: %s',
                    self::EVENT_NAME,
                    $productId,
                    $e->getMessage()
                ), ['exception' => $e]);
                // Do not flag - retry on the next run.
                continue;
            }
            $this->flag($connection, $flagTable, $productId, (float) $product['qty'], $flaggedAt);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCrossedCandidates(
        AdapterInterface $connection,
        string $flagTable,
        float $threshold
    ): array {
        $stockItemTable = $this->resourceConnection->getTableName(self::STOCK_ITEM_TABLE);
        $productTable = $this->resourceConnection->getTableName(self::PRODUCT_TABLE);

        $select = $connection->select()
            ->from(['si' => $stockItemTable], ['product_id', 'qty'])
            ->join(['e' => $productTable], 'e.entity_id = si.product_id', ['sku'])
            ->joinLeft(['f' => $flagTable], 'f.product_id = si.product_id', [])
            ->where('si.qty <= ?', $threshold)
            ->where('si.is_in_stock = ?', 1)
            // use_config_manage_stock = 1 means "use the global setting",
            // which defaults to managed - treat as managed.
            ->where('(si.use_config_manage_stock = 1 OR si.manage_stock = 1)')
            ->where('f.product_id IS NULL')
            ->limit(self::BATCH_SIZE);

        return $connection->fetchAll($select);
    }

    /**
     * Deletes flags for products whose qty has recovered above the threshold,
     * so the next dip below it fires again.
     */
    private function unflagRecovered(AdapterInterface $connection, string $flagTable, float $threshold): void
    {
        $stockItemTable = $this->resourceConnection->getTableName(self::STOCK_ITEM_TABLE);

        $select = $connection->select()
            ->from(['f' => $flagTable], ['product_id'])
            ->join(['si' => $stockItemTable], 'si.product_id = f.product_id', [])
            ->where('si.qty > ?', $threshold);

        $recoveredIds = array_map('intval', $connection->fetchCol($select));
        if ($recoveredIds === []) {
            return;
        }

        $connection->delete($flagTable, ['product_id IN (?)' => $recoveredIds]);
    }

    private function resolvePublisher(): ?object
    {
        if (!class_exists(self::EVENT_PUBLISHER_CLASS) && !interface_exists(self::EVENT_PUBLISHER_CLASS)) {
            return null;
        }
        try {
            return $this->objectManager->get(self::EVENT_PUBLISHER_CLASS);
        } catch (\Throwable $e) {
            $this->logger->debug(sprintf(
                'StockThresholdDetector: %s is present but could not be instantiated: %s',
                self::EVENT_PUBLISHER_CLASS,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * @param array<string, mixed> $product
     */
    private function publish(?object $publisher, array $product, float $threshold): void
    {
        $productId = (int) $product['product_id'];

        if ($publisher !== null && method_exists($publisher, 'publish')) {
            $payload = [
                'entity_id' => $productId,
                'product_id' => $productId,
                'sku' => $product['sku'] ?? null,
                'qty' => (float) $product['qty'],
                'threshold' => $threshold,
                'store_id' => 0,
            ];
            $publisher->publish(self::EVENT_NAME, $payload);
            return;
        }

        // mageos-workflows-triggers-core is not installed - deliberately soft
        // dependency (see class docblock). The stock threshold trigger simply
        // won't fire as a workflow trigger until it is; still flag so we
        // don't re-log the same product every 10 minutes.
        $this->logger->debug(sprintf(
            'StockThresholdDetector: product #%d crossed the stock threshold but no EventPublisher is '
            . 'available (mageos-workflows-triggers-core not installed); skipping "%s" publish.',
            $productId,
            self::EVENT_NAME
        ));
    }

    private function flag(
        AdapterInterface $connection,
        string $flagTable,
        int $productId,
        float $qty,
        string $flaggedAt
    ): void {
        $connection->insertOnDuplicate(
            $flagTable,
            [
                'product_id' => $productId,
                'qty_at_flag' => $qty,
                'flagged_at' => $flaggedAt,
            ],
            ['qty_at_flag', 'flagged_at']
        );
    }
}
