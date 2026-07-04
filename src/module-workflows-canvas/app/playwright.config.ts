import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright smoke config (Phase B / stage 6). ONE smoke: load a mocked admin
 * mount page → edit → save → assert the posted definition JSON. It exercises
 * the mount contract (data-config channel + the real built IIFE bundle + the
 * hidden-form save POST) without a running Magento — the parts Magento owns
 * (auth, ACL, the Save controller, the F2 plugin) are covered by the PHP
 * suite. See e2e/README notes in canvas.yml for what a full end-to-end run
 * needs (a Magento fixture store).
 *
 * Chromium is preinstalled in CI (PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers);
 * we NEVER run `playwright install`. If the pinned @playwright/test browser
 * build differs from the preinstalled one, PW_CHROMIUM_BIN pins the executable.
 */
const chromiumBin = process.env.PW_CHROMIUM_BIN || undefined;

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  fullyParallel: false,
  reporter: 'line',
  webServer: {
    command: 'node e2e/server.mjs',
    url: 'http://127.0.0.1:4173/app/e2e/fixtures/admin-page.html',
    reuseExistingServer: !process.env.CI,
    timeout: 20_000,
  },
  use: {
    baseURL: 'http://127.0.0.1:4173',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: chromiumBin ? { executablePath: chromiumBin } : {},
      },
    },
  ],
});
