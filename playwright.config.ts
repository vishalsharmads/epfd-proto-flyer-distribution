/// <reference types="node" />
import { defineConfig, devices } from '@playwright/test';

/**
 * EPFD smoke tests. The flows mutate a shared database, so everything runs serially (workers: 1).
 *
 * Project graph:
 *   setup (logs in demo + admin once, saves storage state; avoids the 10/min auth throttle)
 *     └─ flows      register · campaign-draw-modes · print-order · design-order   (write tests/e2e/.state/run.json)
 *          └─ followups  view-job · admin · design-review           (read run.json)
 *     └─ a11y       axe scans (desktop)
 *     └─ mobile     Pixel 7 (390px wide-ish) — a11y subset tagged @mobile + view-job
 */
export default defineConfig({
    testDir: './tests/e2e',
    testIgnore: ['**/qa-admin/**', '**/qa/**'],
    fullyParallel: false,
    workers: 1,
    retries: 1,
    timeout: 90_000,
    expect: { timeout: 15_000 },
    forbidOnly: !!process.env.CI,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://epfd.test',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        locale: 'en-AU',
        timezoneId: 'Australia/Sydney',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'flows',
            testMatch: /(register|campaign-draw-modes|print-order|design-order|payment-3ds)\.spec\.ts/,
            dependencies: ['setup'],
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
        {
            name: 'followups',
            testMatch: /(view-job|admin|design-review)\.spec\.ts/,
            dependencies: ['flows'],
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
        {
            name: 'a11y',
            testMatch: /a11y\.spec\.ts/,
            dependencies: ['setup'],
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
        {
            name: 'mobile',
            testMatch: /(a11y|view-job)\.spec\.ts/,
            grep: /@mobile/,
            dependencies: ['followups'],
            use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } },
        },
    ],
});
