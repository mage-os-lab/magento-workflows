<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Hydrator;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

/**
 * Customer order-history aggregates in ONE sales_order query (canceled
 * orders excluded): orders_count, lifetime_sales, avg_order_value,
 * last_order_at and days_since_last_order (whole days from last_order_at to
 * now UTC — sales timestamps are stored UTC).
 *
 * last_order_at / days_since_last_order are ABSENT (not null-set) when the
 * customer has no orders: absent attributes only match the negative
 * operators (fail-toward-false, AbstractWorkflowCondition::validateAttribute()).
 *
 * Merged into hydrated customer data by CustomerHydrator — hydration-time
 * only, never part of trigger snapshots.
 */
class CustomerAggregateProvider
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array{orders_count: int, lifetime_sales: float, avg_order_value: float,
     *               last_order_at?: string, days_since_last_order?: int}
     */
    public function getAggregates(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('sales_order', 'sales'),
                [
                    'orders_count' => new \Zend_Db_Expr('COUNT(*)'),
                    'lifetime_sales' => new \Zend_Db_Expr('COALESCE(SUM(base_grand_total), 0)'),
                    'last_order_at' => new \Zend_Db_Expr('MAX(created_at)'),
                ]
            )
            ->where('customer_id = ?', $customerId)
            ->where('state <> ?', Order::STATE_CANCELED);
        $row = $connection->fetchRow($select) ?: [];

        $ordersCount = (int)($row['orders_count'] ?? 0);
        $lifetimeSales = (float)($row['lifetime_sales'] ?? 0);
        $aggregates = [
            'orders_count' => $ordersCount,
            'lifetime_sales' => $lifetimeSales,
            'avg_order_value' => $ordersCount > 0 ? $lifetimeSales / $ordersCount : 0.0,
        ];

        $lastOrderAt = $row['last_order_at'] ?? null;
        if ($ordersCount > 0 && is_string($lastOrderAt) && $lastOrderAt !== '') {
            $aggregates['last_order_at'] = $lastOrderAt;
            $lastTimestamp = strtotime($lastOrderAt . ' UTC');
            if ($lastTimestamp !== false) {
                $aggregates['days_since_last_order'] = max(0, (int)floor((time() - $lastTimestamp) / 86400));
            }
        }
        return $aggregates;
    }
}
