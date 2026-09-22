/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { ConditionSlideOut } from '../src/components/ConditionSlideOut';
import { resetConditionMetaCache } from '../src/conditionsClient';
import type { NodeMeta } from '../src/conditionTree';
import { makeConfig } from './support';

/**
 * The first RENDERED component test in the suite, and deliberately so: the
 * "add condition buttons do nothing" bug shipped green precisely because every
 * prior test exercised the pure modules while the dead end lived in the
 * component wiring (a disabled button fed by a starved metadata feed). These
 * tests drive the real ConditionSlideOut through react-dom against fixture
 * fetch responses.
 */

declare global {
  // eslint-disable-next-line no-var
  var IS_REACT_ACT_ENVIRONMENT: boolean | undefined;
}
globalThis.IS_REACT_ACT_ENVIRONMENT = true;

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

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  resetConditionMetaCache();
  container = document.createElement('div');
  document.body.appendChild(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

function mount(props: Partial<Parameters<typeof ConditionSlideOut>[0]>): void {
  act(() => {
    root.render(
      <ConditionSlideOut
        target={{ scope: 'workflow' }}
        value={null}
        revalidateEntity={null}
        config={makeConfig()}
        onApply={() => undefined}
        onClose={() => undefined}
        {...props}
      />,
    );
  });
}

/** Let pending promises (the mocked fetch round-trips) settle inside act(). */
async function flush(): Promise<void> {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
  });
}

function addConditionsButton(): HTMLButtonElement {
  const button = [...container.querySelectorAll('button')].find(
    (b) => b.textContent === 'Add conditions',
  );
  expect(button, 'the empty-state "Add conditions" button').toBeTruthy();
  return button as HTMLButtonElement;
}

describe('ConditionSlideOut — metadata failures must never leave a silent dead end', () => {
  it('with no entity type: explains what to do, and never fetches', async () => {
    const fetchImpl = vi.fn();
    const config = makeConfig();
    config.workflow = { ...config.workflow!, entityType: '' };
    mount({ config, fetchImpl: fetchImpl as unknown as typeof fetch });
    await flush();

    expect(fetchImpl).not.toHaveBeenCalled();
    expect(container.textContent).toContain('Choose an entity type in Workflow settings first');
    expect(addConditionsButton().disabled).toBe(true);
    // Nothing to retry until an entity type exists.
    const retry = [...container.querySelectorAll('button')].find((b) => b.textContent === 'Retry');
    expect(retry).toBeUndefined();
  });

  it('surfaces the server error text and recovers via Retry', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ success: false, error: 'boom' }, 500))
      .mockResolvedValueOnce(jsonResponse({ success: true, root: NODE.type, node: NODE }));
    mount({ fetchImpl: fetchImpl as unknown as typeof fetch });
    await flush();

    // The failure is explained, not swallowed.
    expect(container.textContent).toContain('boom');
    expect(addConditionsButton().disabled).toBe(true);

    const retry = [...container.querySelectorAll('button')].find((b) => b.textContent === 'Retry');
    expect(retry, 'a Retry button next to the failure text').toBeTruthy();
    act(() => (retry as HTMLButtonElement).click());
    await flush();

    expect(fetchImpl).toHaveBeenCalledTimes(2);
    expect(container.textContent).not.toContain('boom');
    expect(addConditionsButton().disabled).toBe(false);
  });

  it('with healthy metadata: Add conditions starts a tree and the add-child menu is populated', async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ success: true, root: NODE.type, node: NODE }));
    mount({ fetchImpl: fetchImpl as unknown as typeof fetch });
    await flush();

    const button = addConditionsButton();
    expect(button.disabled).toBe(false);
    act(() => button.click());
    await flush();

    // The tree rendered: the per-combine "add condition" select carries the
    // server-provided group and the Grand Total option.
    const select = container.querySelector('.wf-cond__add-select') as HTMLSelectElement | null;
    expect(select, 'the add-child select').toBeTruthy();
    expect(select?.innerHTML).toContain('Grand Total');
  });
});
