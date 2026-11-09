import { defineConfig } from '@playwright/test';

export const apiOrigin = 'http://localhost:8787';
export const widgetOrigin = 'http://localhost:8788';

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: { browserName: 'chromium', headless: true, trace: 'retain-on-failure' },
  webServer: process.env.E2E_EXTERNAL_SERVERS === '1' ? undefined : {
    command: 'node serve.mjs',
    url: `${apiOrigin}/cookie.html`,
    reuseExistingServer: false,
    timeout: 30_000,
    gracefulShutdown: { signal: 'SIGTERM', timeout: 5_000 },
  },
});
