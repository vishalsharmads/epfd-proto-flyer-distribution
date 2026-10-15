/// <reference types="node" />
import { defineConfig } from '@playwright/test';

/** Admin/docs/map browser QA crawler. Run with: npx playwright test -c playwright.qa-admin.config.ts */
export default defineConfig({
    testDir: './tests/e2e/qa-admin',
    testMatch: /.*\.qa\.ts/,
    workers: 1,
    retries: 0,
    timeout: 600_000,
    reporter: [['list']],
    outputDir: './tests/e2e/qa-admin/.results',
    use: { screenshot: 'only-on-failure', baseURL: process.env.E2E_BASE_URL ?? 'http://epfd.test', locale: 'en-AU', timezoneId: 'Australia/Sydney' },
});
