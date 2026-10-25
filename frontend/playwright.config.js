/* global process */
import { defineConfig } from '@playwright/test'

const storageState = process.env.PLAYWRIGHT_STORAGE_STATE

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? [['html', { open: 'never' }], ['line']] : 'list',
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:5173',
    storageState: storageState || undefined,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
})
