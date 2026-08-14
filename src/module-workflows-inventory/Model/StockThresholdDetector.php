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
 * Detected products are published as async events (declared in this module's
 * async_events.xml) so they ride the same single dispatch path as every other
 * trigger. This detector owns TWO published triggers off the same flag table:
 *
 *   - 'inventory.stock_threshold_crossed' - on the downward crossing;
 *   - 'inventory.back_in_stock' (INV-T1) - on the recovery transition, i.e.
 *     the exact moment the hysteresis flag is cleared.
 *
 * Hysteresis via mageos_workflow_stock_flag (owned by this module's
 * db_schema, relocated from the engine in domain-packs S4): a product fires
 * 'inventory.stock_threshold_crossed' once when it crosses at-or-below the
 * threshold, then stays flagged. The recovery pass finds flagged products
 * whose qty is back strictly above the threshold, publishes
 * 'inventory.back_in_stock' for each, and only then deletes the flag,
 * re-arming the downward trigger.
 *
 * Loop-guard / debounce: the flag/unflag cycle IS the debounce. Each trigger
 * fires exactly once per cycle - 'stock_threshold_crossed' once on the way
 * down (the flag suppresses re-fires while qty stays at/below the threshold),
 * 'back_in_stock' once on the way back up (recovery clears the flag, so it
 * cannot re-fire until the product has dipped and been re-flagged). Recovery
 * therefore publishes once per flag/unflag cycle, never every 10 minutes. A
 * publish failure leaves the relevant flag state unchanged so the next run
 * retries (crossing: not flagged -> retried; recovery: still flagged ->
 * retried, and the product is NOT re-armed for the crossing trigger until the
 * back-in-stock announcement succeeds).
 *
 * Note: AbandonedCartDetector never unflags (quotes age out via MAX_AGE_DAYS
 * instead); stock is cyclical, so re-arming - and the recovery trigger that
 * rides on it - is unique to this detector.
 *
 * Soft dependency, intentionally: mageos-workflows-triggers-core owns the
 * EventPublisher. This module must not hard-require that package
 * (composer.json only "suggest"s it), so we probe for the class at runtime
 * and degrade to a debug log when it's absent - the query/detection logic
 * still runs and still dedupes/re-arms via mageos_workflow_stock_flag either
 * way (when the publisher is absent, recovery has nothing to announce, so the
 * flag is simply cleared).
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
    private const EVENT_BACK_IN_STOCK = 'inventory.back_in_stock';

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
        $publisher = $this->resolvePublisher();

        // Recovery pass first: flagged products back strictly above the
        // threshold publish 'inventory.back_in_stock' and are unflagged, so a
        // later dip fires 'stock_threshold_crossed' again (hysteresis at
        // exactly the threshold boundary).
        $this->unflagRecovered($connection, $flagTable, $threshold, $publisher);

        $products = $this->fetchCrossedCandidates($connection, $flagTable, $threshold);
        if ($products === []) {
            return;
        }

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
     * Recovery pass (INV-T1): for each flagged product whose qty is back
     * strictly above the threshold, publish 'inventory.back_in_stock' and then
     * delete its flag, re-arming the downward trigger. The publish-then-delete
     * order keeps the flag as the dedupe watermark: recovery fires exactly once
     * per flag/unflag cycle, and a publish failure leaves the flag in place so
     * the next run retries the announcement (the product is not re-armed for
     * the crossing trigger until back-in-stock is successfully published).
     */
    private function unflagRecovered(
        AdapterInterface $connection,
        string $flagTable,
        float $threshold,
        ?object $publisher
    ): void {
        $recovered = $this->fetchRecoveredCandidates($connection, $flagTable, $threshold);
        if ($recovered === []) {
            return;
        }

        foreach ($recovered as $product) {
            $productId = (int) $product['product_id'];
            try {
                $this->publishBackInStock($publisher, $product, $threshold);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'StockThresholdDetector: failed publishing "%s" for product #%d: %s',
                    self::EVENT_BACK_IN_STOCK,
                    $productId,
                    $e->getMessage()
                ), ['exception' => $e]);
                // Leave the flag so the next run retries the recovery
                // announcement (and the crossing trigger stays disarmed until
                // then).
                continue;
            }
            $connection->delete($flagTable, ['product_id IN (?)' => [$productId]]);
        }
    }

    /**
     * Flagged products whose qty has recovered strictly above the threshold,
     * with the recovered qty and sku for the back-in-stock payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecoveredCandidates(
        AdapterInterface $connection,
        string $flagTable,
        float $threshold
    ): array {
        $stockItemTable = $this->resourceConnection->getTableName(self::STOCK_ITEM_TABLE);
        $productTable = $this->resourceConnection->getTableName(self::PRODUCT_TABLE);

        $select = $connection->select()
            ->from(['f' => $flagTable], ['product_id'])
            ->join(['si' => $stockItemTable], 'si.product_id = f.product_id', ['qty'])
            ->join(['e' => $productTable], 'e.entity_id = f.product_id', ['sku'])
            ->where('si.qty > ?', $threshold);

        return $connection->fetchAll($select);
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
        $this->publishEvent(
            $publisher,
            self::EVENT_NAME,
            $product,
            $threshold,
            'crossed the stock threshold'
        );
    }

    /**
     * @param array<string, mixed> $product
     */
    private function publishBackInStock(?object $publisher, array $product, float $threshold): void
    {
        $this->publishEvent(
            $publisher,
            self::EVENT_BACK_IN_STOCK,
            $product,
            $threshold,
            'recovered above the stock threshold'
        );
    }

    /**
     * Publishes one detector event, or degrades to a debug log when the soft
     * EventPublisher dependency is unavailable. The payload shape is shared by
     * both triggers (entity id twice for the fan-out + async re-hydration
     * layer, plus the ride-along sku/qty/threshold).
     *
     * @param array<string, mixed> $product
     */
    private function publishEvent(
        ?object $publisher,
        string $eventName,
        array $product,
        float $threshold,
        string $reason
    ): void {
        $productId = (int) $product['product_id'];

        if ($publisher !== null && method_exists($publisher, 'publish')) {
            $publisher->publish($eventName, [
                // 'productId' hydrates via ProductRepositoryInterface::getById($productId)
                // — async-events binds service arguments by parameter name (see
                // etc/async_events.xml); 'entity_id'/'product_id' ride along for
                // the fan-out/aggregation layer and template variables.
                'productId' => $productId,
                'entity_id' => $productId,
                'product_id' => $productId,
                'sku' => $product['sku'] ?? null,
                'qty' => (float) $product['qty'],
                'threshold' => $threshold,
                'store_id' => 0,
            ]);
            return;
        }

        // mageos-workflows-triggers-core is not installed - deliberately soft
        // dependency (see class docblock). The trigger simply won't fire as a
        // workflow trigger until it is; the flag state is still updated so the
        // same product is not re-logged every 10 minutes.
        $this->logger->debug(sprintf(
            'StockThresholdDetector: product #%d %s but no EventPublisher is available '
            . '(mageos-workflows-triggers-core not installed); skipping "%s" publish.',
            $productId,
            $reason,
            $eventName
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
