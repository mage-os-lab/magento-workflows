import { describe, expect, it, vi } from 'vitest';
import { debounce, pinMessages, postValidate } from '../src/validateClient';
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
