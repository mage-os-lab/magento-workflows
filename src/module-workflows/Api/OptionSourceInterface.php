<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * A named source of `{value, label}` options for a config-panel select (F6
 * option-source union). Register implementations into
 * {@see \MageOS\Workflows\Model\Option\OptionSourcePool} via di.xml, keyed by
 * code. A `getConfigForm()` select field points at a source two ways:
 *
 *   - bounded lists (order statuses, customer groups): the field may declare
 *     `options_search: {source: "<code>", min_chars: 0}` (or inline `options`),
 *     and this endpoint resolves the whole list on an empty query;
 *   - large lists (cart price rules, email templates): the field declares
 *     `options_search: {source: "<code>", min_chars: 2}`, and the client calls
 *     GET /V1/workflows/meta/options?source=<code>&q=… as the user types.
 *
 * @api
 */
interface OptionSourceInterface
{
    /**
     * Stable source code, e.g. "order_statuses"
     */
    public function getCode(): string;

    /**
     * Resolve options, optionally narrowed by a free-text query (case-insensitive
     * substring over value + label). A null/empty query returns the full list
     * for bounded sources.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function fetch(?string $query = null): array;
}
