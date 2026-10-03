import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    // dev の allowedDevOrigins のため 127.0.0.1 ではなく localhost
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:3100',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    navigationTimeout: 30_000,
    actionTimeout: 10_000,
    locale: 'ja-JP',
    timezoneId: 'Asia/Tokyo',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
