import { expect, test, type Page } from '@playwright/test';

/**
 * The Playwright smoke (docs/discovery/canvas.md §7): load a fixture → edit →
 * save → assert the stored definition JSON. It runs against the mount contract
 * with a mocked admin page (no Magento): the real built IIFE bundle mounts from
 * the data-config channel, the editor renders (grants.manage), palette/canvas
 * edits mutate the graph, and Save posts the mapped definition through the
 * (mocked) admin Save controller URL — the exact hidden-form POST the real
 * save loop performs. The server side of save (auth/ACL/Save controller/F2
 * plugin) is covered by the PHP suite.
 *
 * Three scenarios:
 *   1. add a Stop step, WIRE it from the existing action's `next` handle by
 *      dragging a connection, and assert the posted definition carries the
 *      edge in the right key AND persists ui positions (bootstrapped + moved);
 *   2. connect then DELETE the edge via select + Backspace and assert the
 *      posted definition reflects the removal (next back to null);
 *   3. canvas-first creation: mount the no-id blank-workflow config
 *      (admin-page-new.html), fill name + entity type in the auto-opened
 *      settings panel, add a node, save, and assert the POST carries the
 *      general fields and back=canvas with NO workflow_id.
 */

async function mockEndpoints(page: Page): Promise<{ saved: string[] }> {
  await page.route('**/__validate', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, valid: true, messages: [] }),
    });
  });
  const saved: string[] = [];
  await page.route('**/__save', async (route) => {
    saved.push(route.request().postData() ?? '');
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>saved</title>saved' });
  });
  return { saved };
}

/**
 * Add a Stop step via the palette, drag it below the action node (clearing the
 * overlap so both handle sets are hittable — and arming the layout-dirty flag),
 * then drag a connection from the action's `next` source handle to the stop's
 * target handle. Deterministic: plain mouse gestures on React Flow's handle
 * elements, then an explicit wait for the committed edge to render.
 */
async function addStopAndConnect(page: Page): Promise<void> {
  await expect(page.getByRole('navigation', { name: 'Step palette' })).toBeVisible();
  await page.getByRole('button', { name: 'Add Stop' }).click();

  const actionNode = page.locator('.react-flow__node', { hasText: 'Add Comment' });
  const stopNode = page.locator('.react-flow__node', { hasText: 'Stop' });
  await expect(stopNode).toBeVisible();

  // Move the freshly-dropped stop node well below the action node.
  const stopBox = (await stopNode.boundingBox())!;
  await page.mouse.move(stopBox.x + stopBox.width / 2, stopBox.y + 10);
  await page.mouse.down();
  await page.mouse.move(stopBox.x + stopBox.width / 2, stopBox.y + 220, { steps: 10 });
  await page.mouse.up();

  // Drag a connection: action `next` source handle -> stop target handle.
  const sourceHandle = actionNode.locator('.react-flow__handle.source');
  const targetHandle = stopNode.locator('.react-flow__handle.target');
  const src = (await sourceHandle.boundingBox())!;
  const tgt = (await targetHandle.boundingBox())!;
  await page.mouse.move(src.x + src.width / 2, src.y + src.height / 2);
  await page.mouse.down();
  await page.mouse.move(tgt.x + tgt.width / 2, tgt.y + tgt.height / 2, { steps: 15 });
  await page.mouse.up();

  // The committed (graph-backed) edge renders.
  await expect(page.locator('.react-flow__edge')).toHaveCount(1);
}

function parseSavedDefinition(body: string): {
  params: URLSearchParams;
  definition: { entry?: string; steps: Record<string, Record<string, unknown>>; ui?: { nodes?: Record<string, { x: number; y: number }> } };
} {
  const params = new URLSearchParams(body);
  return { params, definition: JSON.parse(params.get('definition') ?? '{}') };
}

test('add + connect a stop step: the posted definition wires next and persists ui positions', async ({ page }) => {
  const { saved } = await mockEndpoints(page);
  await page.goto('/app/e2e/fixtures/admin-page.html');

  await addStopAndConnect(page);

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect.poll(() => saved.length, { timeout: 10_000 }).toBeGreaterThan(0);

  const { params, definition } = parseSavedDefinition(saved[0]);
  expect(params.get('form_key')).toBe('test-form-key');
  expect(params.get('workflow_id')).toBe('5');

  const steps = definition.steps ?? {};
  expect(Object.keys(steps).length).toBe(2);

  // EDGE correctness: the new stop step is actually wired from s1's `next`,
  // not merely present by count.
  const stopKey = Object.keys(steps).find((k) => steps[k].type === 'stop');
  expect(stopKey).toBeTruthy();
  expect(steps.s1.type).toBe('action');
  expect(steps.s1.next).toBe(stopKey);
  // And nothing leaked into the stop sink.
  expect(steps[stopKey as string]).toEqual({ type: 'stop' });

  // UI positions persist: the bootstrapped s1 position survives verbatim and
  // the moved stop node persists a real (dragged-below) position.
  const uiNodes = definition.ui?.nodes ?? {};
  expect(uiNodes.s1).toMatchObject({ x: 120, y: 80 });
  const stopPos = uiNodes[stopKey as string];
  expect(typeof stopPos?.x).toBe('number');
  expect(typeof stopPos?.y).toBe('number');
  // Dropped at y=80, then dragged ~220px down: the persisted y moved.
  expect(stopPos.y).toBeGreaterThan(80);
});

test('delete a connected edge via keyboard: the posted definition drops the edge', async ({ page }) => {
  const { saved } = await mockEndpoints(page);
  await page.goto('/app/e2e/fixtures/admin-page.html');

  await addStopAndConnect(page);

  // Select the edge and delete it with the keyboard (deleteKeyCode).
  await page.locator('.react-flow__edge').click();
  await page.keyboard.press('Backspace');
  await expect(page.locator('.react-flow__edge')).toHaveCount(0);

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect.poll(() => saved.length, { timeout: 10_000 }).toBeGreaterThan(0);

  const { definition } = parseSavedDefinition(saved[0]);
  const steps = definition.steps ?? {};
  // Both steps survive, but the deleted edge is really gone from the
  // serialized definition (next back to null — not just hidden client-side).
  expect(Object.keys(steps).length).toBe(2);
  const stopKey = Object.keys(steps).find((k) => steps[k].type === 'stop');
  expect(stopKey).toBeTruthy();
  expect(steps.s1.next).toBeNull();
});

test('canvas-first creation: settings + save post the general fields with back=canvas and no workflow_id', async ({ page }) => {
  const { saved } = await mockEndpoints(page);
  await page.goto('/app/e2e/fixtures/admin-page-new.html');

  // A brand-new workflow (id 0) opens the settings panel by itself: name and
  // entity type are the first authoring decisions.
  const settings = page.getByRole('dialog', { name: 'Workflow settings' });
  await expect(settings).toBeVisible();
  await settings.getByLabel('Name').fill('Canvas-born Workflow');
  await settings.getByLabel('Entity type').selectOption('sales_order');
  await settings.getByRole('button', { name: 'Close' }).click();
  await expect(settings).toBeHidden();

  // Author a minimal graph so the save carries a real definition.
  await page.getByRole('button', { name: 'Add Stop' }).click();
  await expect(page.locator('.react-flow__node')).toHaveCount(1);

  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await expect.poll(() => saved.length, { timeout: 10_000 }).toBeGreaterThan(0);

  const { params, definition } = parseSavedDefinition(saved[0]);
  // The edited general fields post through the classic Save controller...
  expect(params.get('name')).toBe('Canvas-born Workflow');
  expect(params.get('entity_type')).toBe('sales_order');
  // ...asking to land back on the canvas (which is where the new id appears)...
  expect(params.get('back')).toBe('canvas');
  // ...and a NEW workflow posts no id at all — the controller creates one.
  expect(params.get('workflow_id')).toBeNull();

  const steps = definition.steps ?? {};
  expect(Object.keys(steps).length).toBe(1);
  expect(Object.values(steps)[0].type).toBe('stop');
});
