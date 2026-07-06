<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Relation\Resolver;

/**
 * Shared helper: turn a list of sales order DTOs into their ids, newest-first
 * (by created_at, id as the tiebreaker). Kept out of the DB query so the
 * to-many order resolvers stay trivially unit-testable with plain DTO stubs;
 * the RelationContext cap then keeps the most recent ids.
 */
class OrderIdCollector
{
    /**
     * @param iterable<object> $orders order DTOs exposing getEntityId()/getId()
     *        and getCreatedAt()
     * @return int[] order ids, newest-first
     */
    public static function newestFirst(iterable $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $id = (int) (method_exists($order, 'getEntityId') ? $order->getEntityId() : $order->getId());
            if ($id <= 0) {
                continue;
            }
            $rows[] = ['id' => $id, 'created_at' => (string) $order->getCreatedAt()];
        }
        usort($rows, static function (array $a, array $b): int {
            return ($b['created_at'] <=> $a['created_at']) ?: ($b['id'] <=> $a['id']);
        });
        return array_map(static fn (array $row): int => $row['id'], $rows);
    }
}
