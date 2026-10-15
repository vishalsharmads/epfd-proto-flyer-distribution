import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { AUTH } from './helpers/common';
import { skipTour, waitForMap } from './helpers/map';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/**
 * Run axe and fail on serious/critical violations.
 * `exclude` is for third-party internals only (documented at each call site).
 */
async function scan(page: Page, exclude: string[] = []) {
    // Let fonts, transitions and lazy chunks settle so contrast is measured on the final paint.
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
    let builder = new AxeBuilder({ page }).withTags(TAGS);
    for (const sel of exclude) builder = builder.exclude(sel);
    const { violations } = await builder.analyze();
    const blocking = violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const report = blocking.map((v) => `[${v.impact}] ${v.id}: ${v.help}\n${v.nodes.map((n) => `   - ${n.target.join(' ')} :: ${n.any.concat(n.all, n.none).map((c) => c.message).slice(0, 1).join(' ')}`).join('\n')}`).join('\n');
    expect(report, `serious/critical axe violations on ${page.url()}`).toBe('');
}

test.describe('guest pages', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('welcome @mobile', async ({ page }) => {
        await page.goto('/');
        await scan(page);
    });

    test('login @mobile', async ({ page }) => {
        await page.goto('/login');
        await scan(page);
    });

    test('register @mobile', async ({ page }) => {
        await page.goto('/register');
        await scan(page);
    });

    test('API docs (/docs)', async ({ page }) => {
        await page.goto('/docs');
        await page.locator('.swagger-ui .opblock-tag').first().waitFor();
        // Swagger UI's generated operation markup is third-party (swagger-ui-dist); we scan our own page shell
        // and exclude only the rendered spec body. See the report for the exclusion rationale.
        await scan(page, ['.swagger-ui .wrapper']);
    });
});

test.describe('business pages', () => {
    test.use({ storageState: AUTH.business });

    test('app dashboard @mobile', async ({ page }) => {
        await page.goto('/app');
        await scan(page);
    });

    test('my jobs @mobile', async ({ page }) => {
        await page.goto('/app/jobs');
        await scan(page);
    });

    test('campaign audience (map) and details steps', async ({ page }) => {
        await skipTour(page);
        await page.goto('/app/campaigns/new');
        await page.getByTestId('start-new-campaign').click();
        await page.waitForURL(/\/app\/campaigns\/\d+\/audience$/);
        await waitForMap(page);
        // The MapLibre canvas itself (third-party, role=application) is excluded; all panels are scanned.
        await scan(page, ['.maplibregl-canvas-container', '.maplibregl-ctrl-attrib']);

        const id = page.url().match(/campaigns\/(\d+)/)![1];
        await page.goto(`/app/campaigns/${id}/details`);
        await scan(page);
        // Clean up the throwaway draft.
        await page.request.delete(`/app/campaigns/${id}`, { headers: { 'X-XSRF-TOKEN': await xsrf(page) } }).catch(() => undefined);
    });

    test('print product step', async ({ page }) => {
        await page.goto('/app/print/new');
        await page.getByTestId('start-new-print').click();
        await page.waitForURL(/\/app\/print\/\d+\/product$/);
        await scan(page);
        const id = page.url().match(/print\/(\d+)/)![1];
        await page.request.delete(`/app/print/${id}`, { headers: { 'X-XSRF-TOKEN': await xsrf(page) } }).catch(() => undefined);
    });
});

test.describe('admin pages', () => {
    test.use({ storageState: AUTH.admin });

    test('admin dashboard', async ({ page }) => {
        await page.goto('/admin');
        await scan(page);
    });

    test('admin businesses', async ({ page }) => {
        await page.goto('/admin/businesses');
        await scan(page);
    });
});

async function xsrf(page: Page): Promise<string> {
    const c = (await page.context().cookies()).find((x) => x.name === 'XSRF-TOKEN');
    return c ? decodeURIComponent(c.value) : '';
}
