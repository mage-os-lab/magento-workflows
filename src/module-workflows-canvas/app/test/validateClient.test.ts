import { describe, expect, it, vi } from 'vitest';
import {
  buildValidateRequest,
  debounce,
  pinMessages,
  postValidate,
  withValidationStaleGuard,
  type ValidateResponse,
} from '../src/validateClient';
import { makeConfig } from './support';
import type { ValidationMessage } from '../src/types';

describe('pinMessages', () => {
  it('buckets messages by node and collects document-level ones', () => {
    const messages: ValidationMessage[] = [
      { severity: 'warning', code: 'W1', message: 'w', step_key: 's1', edge: null },
      { severity: 'error', code: 'E1', message: 'e', step_key: 's1', edge: 'on_true' },
      { severity: 'error', code: 'DOC', message: 'd', step_key: null, edge: null },
    ];
    const pinned = pinMessages(messages);
    expect(pinned.hasErrors).toBe(true);
    expect(pinned.document).toHaveLength(1);
    // errors sort before warnings within a node
    expect(pinned.byNode.s1.map((m) => m.code)).toEqual(['E1', 'W1']);
  });

  it('reports no errors for warning-only findings', () => {
    const pinned = pinMessages([
      { severity: 'warning', code: 'W', message: 'w', step_key: 's2', edge: null },
    ]);
    expect(pinned.hasErrors).toBe(false);
  });
});

describe('postValidate', () => {
  it('posts form-encoded with the form key to the validate proxy', async () => {
    const config = makeConfig();
    const fetchImpl = vi.fn((_url: RequestInfo | URL, _init?: RequestInit) =>
      Promise.resolve(
        new Response(JSON.stringify({ success: true, valid: true, messages: [] }), { status: 200 }),
      ),
    );
    const res = await postValidate(config, { definition: '{"schema":3}' }, fetchImpl as unknown as typeof fetch);

    expect(res.success).toBe(true);
    const call = fetchImpl.mock.calls[0];
    expect(call[0]).toBe('/validate');
    const init = call[1] as RequestInit;
    const body = init.body as string;
    expect(body).toContain('form_key=FKEY');
    expect(body).toContain('definition=');
    expect(init.credentials).toBe('same-origin');
  });

  it('reports a failed request without throwing', async () => {
    const config = makeConfig();
    const fetchImpl = vi.fn(async () => new Response('', { status: 500 }));
    const res = await postValidate(config, { definition: '{}' }, fetchImpl as unknown as typeof fetch);
    expect(res.success).toBe(false);
    expect(res.error).toMatch(/500/);
  });
});

describe('buildValidateRequest — the live-validation contract seam (Data/Validate)', () => {
  it('carries the bootstrapped ROOT condition tree so root-condition findings surface live', () => {
    const tree = '{"aggregator":"all","conditions":[]}';
    const base = makeConfig();
    const config = makeConfig({
      workflow: { ...base.workflow!, conditionsSerialized: tree },
    });
    const req = buildValidateRequest(config, '{"schema":3}');
    expect(req.definition).toBe('{"schema":3}');
    expect(req.conditionsSerialized).toBe(tree);
  });

  it('yields null when the workflow has no root conditions (or no workflow yet)', () => {
    expect(buildValidateRequest(makeConfig(), '{}').conditionsSerialized).toBeNull();
    expect(buildValidateRequest(makeConfig({ workflow: null }), '{}').conditionsSerialized).toBeNull();
  });

  it('posts as the exact conditions_serialized param the Validate controller reads', async () => {
    const tree = '{"aggregator":"all","conditions":[]}';
    const base = makeConfig();
    const config = makeConfig({
      workflow: { ...base.workflow!, conditionsSerialized: tree },
    });
    const fetchImpl = vi.fn(async () =>
      new Response(JSON.stringify({ success: true, valid: true, messages: [] }), { status: 200 }),
    );
    await postValidate(config, buildValidateRequest(config, '{"schema":3}'), fetchImpl as unknown as typeof fetch);

    const body = (fetchImpl.mock.calls[0] as unknown[])[1] as RequestInit;
    const params = new URLSearchParams(body.body as string);
    // Param name must match Validate.php's getParam('conditions_serialized').
    expect(params.get('conditions_serialized')).toBe(tree);
    // The workflow's trigger/entity context rides along for plain-language rendering.
    expect(params.get('trigger_type')).toBe('event');
    expect(params.get('entity_type')).toBe('sales_order');
  });

  it('omits the param entirely for a null tree (the controller normalizes absent to null)', async () => {
    const config = makeConfig();
    const fetchImpl = vi.fn(async () =>
      new Response(JSON.stringify({ success: true, valid: true, messages: [] }), { status: 200 }),
    );
    await postValidate(config, buildValidateRequest(config, '{}'), fetchImpl as unknown as typeof fetch);
    const body = (fetchImpl.mock.calls[0] as unknown[])[1] as RequestInit;
    const params = new URLSearchParams(body.body as string);
    expect(params.has('conditions_serialized')).toBe(false);
  });
});

describe('debounce', () => {
  it('collapses rapid calls into one trailing run', async () => {
    vi.useFakeTimers();
    const fn = vi.fn();
    const debounced = debounce(fn, 200);
    debounced('a');
    debounced('b');
    debounced('c');
    expect(fn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(200);
    expect(fn).toHaveBeenCalledTimes(1);
    expect(fn).toHaveBeenCalledWith('c');
    vi.useRealTimers();
  });
});

// BUG 2: the continuous validation loop had no staleness guard, so an
// out-of-order network response (a slow EARLIER request resolving after a
// fast LATER one) could pin stale validation messages over fresh ones.
describe('withValidationStaleGuard', () => {
  const okResponse = (messages: ValidationMessage[]): Response =>
    new Response(JSON.stringify({ success: true, messages }), { status: 200 });

  it('ignores a stale response that resolves after a newer one has already been applied', async () => {
    const config = makeConfig();
    const applied: ValidateResponse[] = [];

    // Two in-flight requests; resolvers held open so the test controls order.
    let resolveFirst!: (r: Response) => void;
    let resolveSecond!: (r: Response) => void;
    const fetchImpl = vi
      .fn()
      .mockImplementationOnce(() => new Promise<Response>((resolve) => (resolveFirst = resolve)))
      .mockImplementationOnce(() => new Promise<Response>((resolve) => (resolveSecond = resolve)));

    const guarded = withValidationStaleGuard(
      (cfg, req) => postValidate(cfg, req, fetchImpl as unknown as typeof fetch),
      (res) => applied.push(res),
    );

    guarded(config, { definition: '{"schema":3,"v":1}' }); // ordinal 1 — the stale one
    guarded(config, { definition: '{"schema":3,"v":2}' }); // ordinal 2 — the fresh one

    // The FRESH (later) request's network response lands first.
    resolveSecond(
      okResponse([{ severity: 'warning', code: 'FRESH', message: 'fresh', step_key: null, edge: null }]),
    );
    await flushMicrotasks();

    // The STALE (earlier) request's response lands last.
    resolveFirst(
      okResponse([{ severity: 'error', code: 'STALE', message: 'stale', step_key: null, edge: null }]),
    );
    await flushMicrotasks();

    expect(applied).toHaveLength(1);
    expect(applied[0].messages?.[0].code).toBe('FRESH');
  });

  it('applies in-order responses normally', async () => {
    const config = makeConfig();
    const applied: ValidateResponse[] = [];
    const fetchImpl = vi.fn(async () =>
      okResponse([{ severity: 'warning', code: 'W', message: 'w', step_key: null, edge: null }]),
    );
    const guarded = withValidationStaleGuard(
      (cfg, req) => postValidate(cfg, req, fetchImpl as unknown as typeof fetch),
      (res) => applied.push(res),
    );

    guarded(config, { definition: '{}' });
    await flushMicrotasks();
    guarded(config, { definition: '{}' });
    await flushMicrotasks();

    expect(applied).toHaveLength(2);
  });
});

function flushMicrotasks(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0));
}
