import { defineConfig } from '@playwright/test'

/**
 * Browser smoke tests against a local TEDC (API on :8000, web on :5173, demo and sample data loaded):
 *   npm run e2e
 * Chrome's fake camera (a moving test pattern, never a face) stands in for a webcam. Never point this at production.
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 180_000,
  expect: { timeout: 20_000 },
  workers: 1,
  globalSetup: './e2e/global-setup.ts',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    channel: process.env.E2E_CHANNEL ?? 'chrome',
    headless: true,
    permissions: ['camera'],
    launchOptions: { args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] },
    trace: 'retain-on-failure',
  },
})
