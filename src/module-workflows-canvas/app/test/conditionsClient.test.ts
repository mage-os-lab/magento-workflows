import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  offlineWarning,
  applyConditions,
  cachedNodeMeta,
  loadNodeMeta,
  resetConditionMetaCache,
} from '../src/conditionsClient';
import type { NodeMeta } from '../src/conditionTree';
import { makeConfig } from './support';

/**
 * The two condition endpoints, exercised against FIXTURE responses shaped
 * exactly per the endpoint contract (conditionMeta: {success, root, node};
 * conditions: Conditions.php's {success, valid, conditions_serialized,
 * messages}). fetch is injected, so nothing here touches the network.
 */

const NODE: NodeMeta = {
  type: 'MageOS\\Workflows\\Model\\Rule\\Condition\\Order\\Combine',
  kind: 'combine',
  label: 'Conditions Combination',
  new_children: [
    {
      label: 'Order Attribute',
      options: [
        {
          value: 'MageOS\\Workflows\\Model\\Rule\\Condition\\Order\\Attribute|grand_total',
          label: 'Grand Total',
        },
      ],
    },
  ],
  aggregators: [
    { value: 'all', label: 'ALL' },
    { value: 'any', label: 'ANY' },
  ],
};

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status });
}

beforeEach(() => {
  resetConditionMetaCache();
});

describe('loadNodeMeta — lazy, cached metadata per node type', () => {
  it('GETs the entity root with entity_type only and reports the root class', async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ success: true, root: NODE.type, node: NODE }));
    const res = await loadNodeMeta(
      makeConfig(),
      'sales_order',
      null,
      fetchImpl as unknown as typeof fetch,
    );

    expect(res.ok).toBe(true);
    expect(res.root).toBe(NODE.type);
    expect(res.node?.kind).toBe('combine');
    const url = String((fetchImpl.mock.calls[0] as unknown[])[0]);
    expect(url.startsWith('/conditionMeta?')).toBe(true);
    expect(url).toContain('entity_type=sales_order');
    expect(url).not.toContain('node_type=');
    const init = (fetchImpl.mock.calls[0] as unknown[])[1] as RequestInit;
    expect(init.credentials).toBe('same-origin');
  });

  it('encodes the FQCN node type and caches the result (one request per type)', async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ success: true, root: NODE.type, node: NODE }));
    const config = makeConfig();
    await loadNodeMeta(config, 'sales_order', NODE.type, fetchImpl as unknown as typeof fetch);
    await loadNodeMeta(config, 'sales_order', NODE.type, fetchImpl as unknown as typeof fetch);

    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(String((fetchImpl.mock.calls[0] as unknown[])[0])).toContain(
      `node_type=${encodeURIComponent(NODE.type)}`,
    );
    expect(cachedNodeMeta('sales_order', NODE.type)?.node?.type).toBe(NODE.type);
  });

  it('shares one in-flight request between concurrent callers', async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ success: true, root: NODE.type, node: NODE }));
    const config = makeConfig();
    const [a, b] = await Promise.all([
      loadNodeMeta(config, 'sales_order', NODE.type, fetchImpl as unknown as typeof fetch),
      loadNodeMeta(config, 'sales_order', NODE.type, fetchImpl as unknown as typeof fetch),
    ]);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(a).toBe(b);
  });

  it('caches a rejected class so it is not re-requested on every render', async () => {
    const fetchImpl = vi.fn(async () =>
      jsonResponse({ success: false, error: 'Unknown condition type.' }, 400),
    );
    const config = makeConfig();
    const res = await loadNodeMeta(config, 'sales_order', 'Evil\\Class', fetchImpl as unknown as typeof fetch);
    expect(res.ok).toBe(false);
    expect(res.node).toBeNull();
    expect(res.error).toBe('Unknown condition type.');
    await loadNodeMeta(config, 'sales_order', 'Evil\\Class', fetchImpl as unknown as typeof fetch);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it('does NOT cache a 5xx failure — a later call retries the request', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ success: false, error: 'boom' }, 500))
      .mockResolvedValueOnce(jsonResponse({ success: true, root: NODE.type, node: NODE }));
    const config = makeConfig();

    const first = await loadNodeMeta(config, 'sales_order', null, fetchImpl as unknown as typeof fetch);
    expect(first.ok).toBe(false);
    expect(cachedNodeMeta('sales_order', null)).toBeNull();

    const second = await loadNodeMeta(config, 'sales_order', null, fetchImpl as unknown as typeof fetch);
    expect(second.ok).toBe(true);
    expect(fetchImpl).toHaveBeenCalledTimes(2);
  });

  it('does NOT cache a network failure — a later call retries the request', async () => {
    const fetchImpl = vi
      .fn()
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce(jsonResponse({ success: true, root: NODE.type, node: NODE }));
    const config = makeConfig();

    const first = await loadNodeMeta(config, 'sales_order', null, fetchImpl as unknown as typeof fetch);
    expect(first.ok).toBe(false);
    expect(first.error).toMatch(/could not be loaded/);
    expect(cachedNodeMeta('sales_order', null)).toBeNull();

    const second = await loadNodeMeta(config, 'sales_order', null, fetchImpl as unknown as typeof fetch);
    expect(second.ok).toBe(true);
    expect(fetchImpl).toHaveBeenCalledTimes(2);
  });

  it('survives a non-JSON / unreachable endpoint without throwing', async () => {
    const fetchImpl = vi.fn(async () => new Response('<html>login</html>', { status: 302 }));
    const res = await loadNodeMeta(
      makeConfig(),
      'sales_order',
      null,
      fetchImpl as unknown as typeof fetch,
    );
    expect(res.ok).toBe(false);
    expect(res.node).toBeNull();
    expect(res.error).toMatch(/unavailable/);
  });

  it('caches per entity type as well as per node type', async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ success: true, root: NODE.type, node: NODE }));
    const config = makeConfig();
    await loadNodeMeta(config, 'sales_order', NODE.type, fetchImpl as unknown as typeof fetch);
    await loadNodeMeta(config, 'customer', NODE.type, fetchImpl as unknown as typeof fetch);
    expect(fetchImpl).toHaveBeenCalledTimes(2);
    expect(cachedNodeMeta('quote', NODE.type)).toBeNull();
  });
});

describe('applyConditions — the apply round-trip through the shared endpoint', () => {
  const tree = '{"type":"combine","aggregator":"all","value":"1","conditions":[]}';

  it('posts form-encoded with the form key and the workflow trigger context', async () => {
    const fetchImpl = vi.fn(async () =>
      jsonResponse({ success: true, valid: true, conditions_serialized: tree, messages: [] }),
    );
    await applyConditions(makeConfig(), tree, fetchImpl as unknown as typeof fetch);

    const call = fetchImpl.mock.calls[0] as unknown[];
    expect(call[0]).toBe('/conditions');
    const init = call[1] as RequestInit;
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    const params = new URLSearchParams(init.body as string);
    expect(params.get('form_key')).toBe('FKEY');
    expect(params.get('conditions_serialized')).toBe(tree);
    expect(params.get('entity_type')).toBe('sales_order');
    expect(params.get('trigger_type')).toBe('event');
    expect(params.get('trigger_ref')).toBe('sales.order.created');
  });

  it('commits the ECHOED normalized tree, not the locally serialized one', async () => {
    const normalized = '{"type":"combine","aggregator":"all","value":"1","conditions":[]}';
    const fetchImpl = vi.fn(async () =>
      jsonResponse({
        success: true,
        valid: true,
        conditions_serialized: normalized,
        messages: [{ severity: 'warning', code: 'W', message: 'heads up', step_key: null, edge: null }],
      }),
    );
    const res = await applyConditions(
      makeConfig(),
      '{"type":"combine","value":"1","aggregator":"all","conditions":[]}',
      fetchImpl as unknown as typeof fetch,
    );
    expect(res.outcome).toBe('committed');
    expect(res.conditionsSerialized).toBe(normalized);
    // Warnings ride along with a valid tree and are rendered inline.
    expect(res.messages).toHaveLength(1);
    expect(res.warning).toBeNull();
  });

  it('round-trips an empty tree to null ("always run")', async () => {
    const fetchImpl = vi.fn(async () =>
      jsonResponse({ success: true, valid: true, conditions_serialized: null, messages: [] }),
    );
    const res = await applyConditions(makeConfig(), null, fetchImpl as unknown as typeof fetch);
    expect(res.outcome).toBe('committed');
    expect(res.conditionsSerialized).toBeNull();
    // '' is the endpoint's own spelling of an empty tree (normalize() -> null).
    const init = (fetchImpl.mock.calls[0] as unknown[])[1] as RequestInit;
    expect(new URLSearchParams(init.body as string).get('conditions_serialized')).toBe('');
  });

  it('commits nothing and surfaces the server messages when invalid', async () => {
    const messages = [
      {
        severity: 'error',
        code: 'CONDITIONS_UNKNOWN_TYPE',
        message: 'Condition type "Nope" is not registered.',
        step_key: null,
        edge: null,
      },
    ];
    const fetchImpl = vi.fn(async () =>
      jsonResponse({ success: true, valid: false, conditions_serialized: tree, messages }),
    );
    const res = await applyConditions(makeConfig(), tree, fetchImpl as unknown as typeof fetch);
    expect(res.outcome).toBe('invalid');
    expect(res.conditionsSerialized).toBeNull();
    expect(res.messages[0].message).toContain('not registered');
  });

  it('treats the 400 "not a condition tree" verdict as invalid, reading its JSON body', async () => {
    const fetchImpl = vi.fn(async () =>
      jsonResponse(
        { success: false, valid: false, error: 'Conditions must be a JSON condition tree.' },
        400,
      ),
    );
    const res = await applyConditions(makeConfig(), 'nope', fetchImpl as unknown as typeof fetch);
    expect(res.outcome).toBe('invalid');
    expect(res.conditionsSerialized).toBeNull();
    expect(res.error).toMatch(/JSON condition tree/);
  });

  it('falls back to the local tree with a warning when the endpoint is unreachable', async () => {
    const fetchImpl = vi.fn(async () => {
      throw new TypeError('Failed to fetch');
    });
    const res = await applyConditions(makeConfig(), tree, fetchImpl as unknown as typeof fetch);
    expect(res.outcome).toBe('offline');
    expect(res.conditionsSerialized).toBe(tree);
    expect(res.warning).toBe(offlineWarning());
    expect(res.error).toBeNull();
  });

  it('treats a non-JSON response (expired session redirect) as unreachable', async () => {
    const fetchImpl = vi.fn(async () => new Response('<html>login</html>', { status: 200 }));
    const res = await applyConditions(makeConfig(), tree, fetchImpl as unknown as typeof fetch);
    expect(res.outcome).toBe('offline');
    expect(res.conditionsSerialized).toBe(tree);
  });
});
