import { expect, test } from '@playwright/test';

/**
 * The single Playwright smoke (docs/discovery/canvas.md §7): load a fixture →
 * edit → save → assert the stored definition JSON. It runs against the mount
 * contract with a mocked admin page (no Magento): the real built IIFE bundle
 * mounts from the data-config channel, the editor renders (grants.manage), a
 * palette add mutates the graph, and Save posts the mapped definition through
 * the (mocked) admin Save controller URL — the exact hidden-form POST the real
 * save loop performs. The server side of save (auth/ACL/Save controller/F2
 * plugin) is covered by the PHP suite.
 */
test('load fixture, add a step, save, and assert the posted definition', async ({ page }) => {
  const validateBodies: string[] = [];
  await page.route('**/__validate', async (route) => {
    validateBodies.push(route.request().postData() ?? '');
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, valid: true, messages: [] }) });
  });

  let savedBody: string | null = null;
  await page.route('**/__save', async (route) => {
    savedBody = route.request().postData();
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>saved</title>saved' });
  });

  await page.goto('/app/e2e/fixtures/admin-page.html');

  // The editor renders (not the read-only viewer): the palette is present.
  await expect(page.getByRole('navigation', { name: 'Step palette' })).toBeVisible();

  // Add a Stop step via the palette (keyboard/click path; deterministic).
  await page.getByRole('button', { name: 'Add Stop' }).click();

  // Save through the hidden-form POST to the admin Save controller URL.
  await page.getByRole('button', { name: 'Save', exact: true }).click();

  await expect.poll(() => savedBody, { timeout: 10_000 }).not.toBeNull();

  const params = new URLSearchParams(savedBody as unknown as string);
  expect(params.get('form_key')).toBe('test-form-key');
  expect(params.get('workflow_id')).toBe('5');

  const definition = JSON.parse(params.get('definition') ?? '{}');
  // Original action step survived, and the new stop step was added.
  const steps = definition.steps ?? {};
  const types = Object.values(steps).map((s: unknown) => (s as { type: string }).type);
  expect(types).toContain('action');
  expect(types).toContain('stop');
  expect(Object.keys(steps).length).toBe(2);
});
