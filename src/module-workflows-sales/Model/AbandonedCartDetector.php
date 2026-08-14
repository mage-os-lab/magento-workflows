<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs every 10 minutes (etc/crontab.xml, job mageos_workflows_abandoned_carts).
 *
 * This is the scheduler's canonical "query trigger" (docs/05-triggers.md,
 * footnote 1): cart abandonment isn't an async event, it's a query -
 * "quote updated > N hours ago, no order". Detected quotes are published as
 * a 'quote.abandoned' async event so they ride the same single dispatch
 * path as every other trigger.
 *
 * Dependency note: this pack hard-requires mageos-workflows-triggers-core
 * (composer.json + module.xml sequence), which owns the EventPublisher that
 * turns the detection into a real async-events subscription target. The
 * runtime ObjectManager probe below predates the domain-pack split (when the
 * dependency really was soft) and is kept only as a belt-and-braces degrade
 * path - on any correctly composed install the publisher always resolves.
 */
class AbandonedCartDetector
{
    private const XML_PATH_ABANDONED_HOURS = 'mageos_workflows/scheduler/abandoned_hours';
    private const DEFAULT_ABANDONED_HOURS = 4;
    private const MAX_AGE_DAYS = 7;
    private const BATCH_SIZE = 500;

    private const QUOTE_TABLE = 'quote';
    private const FLAG_TABLE = 'mageos_workflow_abandoned_flag';

    /** @see class docblock - hard dependency; probe kept as a degrade path */
    private const EVENT_PUBLISHER_CLASS = 'MageOS\\WorkflowsTriggersCore\\Service\\EventPublisher';
    private const EVENT_NAME = 'quote.abandoned';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $quotes = $this->fetchAbandonedCandidates($connection);
        if ($quotes === []) {
            return;
        }

        $publisher = $this->resolvePublisher();
        $flagTable = $this->resourceConnection->getTableName(self::FLAG_TABLE);
        $flaggedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        foreach ($quotes as $quote) {
            $quoteId = (int) $quote['entity_id'];
            try {
                $this->publish($publisher, $quote);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'AbandonedCartDetector: failed publishing "%s" for quote #%d: %s',
                    self::EVENT_NAME,
                    $quoteId,
                    $e->getMessage()
                ), ['exception' => $e]);
                // Do not flag - retry on the next run.
                continue;
            }
            $this->flag($connection, $flagTable, $quoteId, $flaggedAt);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAbandonedCandidates(AdapterInterface $connection): array
    {
        $quoteTable = $this->resourceConnection->getTableName(self::QUOTE_TABLE);
        $flagTable = $this->resourceConnection->getTableName(self::FLAG_TABLE);

        $hours = (int) $this->scopeConfig->getValue(self::XML_PATH_ABANDONED_HOURS);
        if ($hours <= 0) {
            $hours = self::DEFAULT_ABANDONED_HOURS;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $olderThan = $now->modify(sprintf('-%d hours', $hours))->format('Y-m-d H:i:s');
        // The MAX_AGE_DAYS lookback floor is relative to the THRESHOLD, not to
        // "now": with a fixed now-7d floor, any configured threshold above
        // 7*24 hours inverted the range (updated_at < olderThan AND
        // >= newerThan can match nothing) and silently disabled detection.
        $newerThan = $now->modify(sprintf('-%d hours', $hours + self::MAX_AGE_DAYS * 24))->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from(['q' => $quoteTable], ['entity_id', 'customer_email', 'store_id', 'updated_at'])
            ->joinLeft(['f' => $flagTable], 'f.quote_id = q.entity_id', [])
            ->where('q.is_active = ?', 1)
            ->where('q.items_count > ?', 0)
            ->where('q.customer_email IS NOT NULL')
            ->where('q.updated_at < ?', $olderThan)
            ->where('q.updated_at >= ?', $newerThan)
            ->where('f.quote_id IS NULL')
            ->limit(self::BATCH_SIZE);

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
                'AbandonedCartDetector: %s is present but could not be instantiated: %s',
                self::EVENT_PUBLISHER_CLASS,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function publish(?object $publisher, array $quote): void
    {
        $quoteId = (int) $quote['entity_id'];

        if ($publisher !== null && method_exists($publisher, 'publish')) {
            $payload = [
                // 'cartId' hydrates via CartRepositoryInterface::get($cartId) —
                // async-events binds service arguments by parameter name, so the
                // key MUST match the declared method's parameter (see
                // etc/async_events.xml). 'entity_id' rides along for the
                // fan-out/aggregation layer, same shape as every other publisher.
                'cartId' => $quoteId,
                'entity_id' => $quoteId,
                'quote_id' => $quoteId,
                'customer_email' => $quote['customer_email'] ?? null,
                'store_id' => (int) ($quote['store_id'] ?? 0),
                'updated_at' => $quote['updated_at'] ?? null,
            ];
            $publisher->publish(self::EVENT_NAME, $payload);
            return;
        }

        // mageos-workflows-triggers-core is not installed - deliberately soft
        // dependency (see class docblock). Cart abandonment simply won't fire
        // as a workflow trigger until it is; still flag so we don't re-log
        // the same quote every 10 minutes.
        $this->logger->debug(sprintf(
            'AbandonedCartDetector: quote #%d looks abandoned but no EventPublisher is available '
            . '(mageos-workflows-triggers-core not installed); skipping "%s" publish.',
            $quoteId,
            self::EVENT_NAME
        ));
    }

    private function flag(AdapterInterface $connection, string $flagTable, int $quoteId, string $flaggedAt): void
    {
        $connection->insertOnDuplicate(
            $flagTable,
            [
                'quote_id' => $quoteId,
                'flagged_at' => $flaggedAt,
            ],
            ['flagged_at']
        );
    }
}
