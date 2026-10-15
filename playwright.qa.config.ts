/// <reference types="node" />
import { defineConfig } from '@playwright/test';

/** Browser QA crawler (not part of the normal suite): npx playwright test -c playwright.qa.config.ts */
export default defineConfig({
    testDir: './tests/e2e/qa',
    workers: 1,
    timeout: 900_000,
    reporter: [['list']],
    use: { baseURL: process.env.E2E_BASE_URL ?? 'http://epfd.test', locale: 'en-AU', timezoneId: 'Australia/Sydney' },
});
