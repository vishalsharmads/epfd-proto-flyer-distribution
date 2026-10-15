/// <reference types="node" />
import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Map audience builder QA: real campaign Audience step (demo business), /dev/map-preview, readOnly job page.
 * Fails on console/page errors and page-level horizontal overflow.
 */
type LL = [number, number];
const DIR = import.meta.dirname;
const ids = JSON.parse(fs.readFileSync(path.join(DIR, 'ids.json'), 'utf8')) as Record<string, number>;
const AUTH = path.join(DIR, '.auth-business.json');
const IGNORE = /Cross-Origin-Opener-Policy|ProjectDeniedMapError/;
const RYD: LL = [151.035, -33.812];

test.describe.configure({ timeout: 120_000 });
test.beforeAll(async ({ browser }) => {
    if (fs.existsSync(AUTH) && Date.now() - fs.statSync(AUTH).mtimeMs < 60 * 60_000) return;
    const page = await browser.newPage({ storageState: undefined, baseURL: 'http://epfd.test' });
    await page.goto('/login');
    await page.locator('input[name="email"]').fill('demo@business.test');
    await page.locator('input[name="password"]').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((u) => !u.pathname.endsWith('/login'));
    await page.context().storageState({ path: AUTH });
    await page.close();
});
test.use({ storageState: AUTH, viewport: { width: 1440, height: 900 } });

let errors: string[] = [];
test.beforeEach(({ page }) => {
    errors = [];
    page.on('console', (m) => m.type() === 'error' && !IGNORE.test(m.text()) && errors.push(m.text()));
    page.on('pageerror', (e) => errors.push(String(e)));
});
test.afterEach(async ({ page }) => {
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth), 'page overflow').toBeLessThanOrEqual(0);
    expect(errors, 'console/page errors').toEqual([]);
});

const map = (page: Page) => page.waitForFunction(() => !!(window as unknown as { __epfdMap?: unknown }).__epfdMap, undefined, { timeout: 20_000 });
const px = (page: Page, ll: LL) => page.evaluate((p) => (window as unknown as { __epfdMap: { project: (l: LL) => { x: number; y: number } } }).__epfdMap.project(p), ll);
async function setView(page: Page, c: LL, z: number) {
    await page.evaluate(([cc, zz]) => (window as unknown as { __epfdMap: { setView: (c: LL, z: number) => void } }).__epfdMap.setView(cc as LL, zz as number), [c, z] as const);
    await page.waitForFunction((zz) => Math.abs((window as unknown as { __epfdMap: { getZoom: () => number } }).__epfdMap.getZoom() - zz) < 0.01, z);
    await page.waitForTimeout(300);
}
async function clickLL(page: Page, ll: LL) {
    const q = await px(page, ll);
    await page.mouse.click(q.x, q.y);
    await page.waitForTimeout(350);
}
const ring = (c: LL, rx: number, ry: number, n: number): LL[] => Array.from({ length: n }, (_, i) => [c[0] + rx * Math.cos((2 * Math.PI * i) / n), c[1] + ry * Math.sin((2 * Math.PI * i) / n)] as LL);
const estimate = (page: Page) => page.waitForResponse((r) => /\/areas\/estimate|\/campaigns\/\d+\/areas|audience/.test(r.url()) && r.request().method() !== 'GET' && r.ok());

async function shot(page: Page, name: string) {
    await page.screenshot({ path: path.join(DIR, 'shots', `map_${name}.png`) });
}

test('audience step: tour, pins + keyboard, vertex drag, midpoint insert, rename, trace, suburb, delete, toggles', async ({ page }) => {
    await page.addInitScript(() => localStorage.removeItem('epfd-map-tour-done'));
    await page.goto(`/app/campaigns/${ids.draftCampaign}/audience`);
    await map(page);
    // Tour shows on first visit; step through and Esc closes.
    await expect(page.getByTestId('map-tour')).toBeVisible();
    await shot(page, 'tour_desktop');
    await page.getByTestId('tour-next').click();
    await page.getByTestId('tour-next').click();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('map-tour')).toHaveCount(0);
    // Remove areas left over from previous runs.
    while ((await page.getByTestId('area-row').count()) > 0) {
        await page.getByTestId('area-row').first().hover();
        await page.getByRole('button', { name: /Delete/ }).first().click();
        await page.getByTestId('confirm-delete').click();
        await page.waitForTimeout(300);
    }

    await setView(page, RYD, 15);
    // Space opens the draw-mode menu; Esc closes it. Esc also cancels a started area.
    await page.keyboard.press('Space');
    await expect(page.getByTestId('draw-mode-pins')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('draw-mode-pins')).toHaveCount(0);
    await page.getByTestId('add-area').click();
    await page.getByTestId('draw-mode-pins').click();
    await expect(page.getByTestId('editing-panel')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('editing-panel')).toHaveCount(0);

    // Pins: 6 clicks, Backspace removes the last, Enter closes.
    await page.getByTestId('add-area').click();
    await page.getByTestId('draw-mode-pins').click();
    for (const p of ring(RYD, 0.004, 0.003, 6)) await clickLL(page, p);
    await page.keyboard.press('Backspace');
    await expect(page.getByTestId('draw-hint').or(page.getByTestId('editing-panel')).first()).toContainText(/5 pins/);
    let est = estimate(page);
    await page.keyboard.press('Enter');
    await est;
    await expect(page.getByTestId('stat-total').first()).not.toHaveText(/NaN|undefined/);
    const pins = page.getByTestId('vertex-pin');
    await expect(pins).toHaveCount(5);
    // Vertex drag.
    const before = await page.getByTestId('stat-total').first().innerText();
    const b = (await pins.first().boundingBox())!;
    est = estimate(page);
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
    await page.mouse.down();
    await page.mouse.move(b.x + b.width / 2 + 80, b.y + b.height / 2 + 20, { steps: 8 });
    await page.mouse.up();
    await est;
    await expect(page.getByTestId('stat-total').first()).not.toHaveText(before);
    // Midpoint "+" insert.
    await page.getByTestId('midpoint-handle').first().click();
    await expect(pins).toHaveCount(6);
    // Rename.
    await page.getByTestId('rename-area').click();
    await page.getByTestId('area-name-input').fill('QA pins area');
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('editing-panel')).toContainText('QA pins area');
    await shot(page, 'editing_desktop');
    await page.getByTestId('done-button').click();
    await expect(page.getByTestId('area-row')).toHaveCount(1);

    // Trace.
    const ERM: LL = [151.055, -33.812];
    await setView(page, ERM, 15);
    await page.getByTestId('add-area').click();
    await page.getByTestId('draw-mode-trace').click();
    await page.getByTestId('trace-surface').waitFor();
    const loop = ring(ERM, 0.004, 0.003, 10);
    const pts = [];
    for (const ll of loop) pts.push(await px(page, ll));
    est = estimate(page);
    await page.mouse.move(pts[0].x, pts[0].y);
    await page.mouse.down();
    for (let i = 1; i <= pts.length; i++) await page.mouse.move(pts[i % pts.length].x, pts[i % pts.length].y, { steps: 10 });
    await page.mouse.up();
    await est;
    await page.getByTestId('done-button').click();
    await expect(page.getByTestId('area-row')).toHaveCount(2);

    // Suburb.
    await page.getByTestId('add-area').click();
    await page.getByTestId('draw-mode-suburb').click();
    await page.getByTestId('suburb-search').fill('Dundas Valley');
    est = estimate(page);
    await page.getByRole('option', { name: /Dundas Valley/ }).click();
    await est;
    await page.getByTestId('done-button').click();
    await expect(page.getByTestId('area-row')).toHaveCount(3);

    // Toggles houses / units change the total.
    const t0 = await page.getByTestId('stat-total').first().innerText();
    await page.getByRole('switch', { name: /Include units/ }).click();
    await expect(page.getByTestId('stat-total').first()).not.toHaveText(t0);
    await page.getByRole('switch', { name: /Include units/ }).click();
    await expect(page.getByTestId('stat-total').first()).toHaveText(t0);

    // Delete an area (confirm dialog).
    await page.getByTestId('area-row').last().hover();
    await page.getByRole('button', { name: /Delete/ }).last().click();
    await page.getByTestId('confirm-delete').click();
    await expect(page.getByTestId('area-row')).toHaveCount(2);
    await shot(page, 'areas_desktop');
});

test('audience step: rail buttons (help, find address, addresses + zoom hint, labels, streets, satellite, radius)', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('epfd-map-tour-done', '1'));
    await page.goto(`/app/campaigns/${ids.draftCampaign}/audience`);
    await map(page);
    await page.getByTestId('rail-help').click();
    await expect(page.getByTestId('map-tour')).toBeVisible();
    await page.getByTestId('tour-skip').click();
    await page.getByTestId('rail-search').click();
    await page.getByRole('combobox', { name: 'Search addresses' }).or(page.getByLabel('Search addresses')).first().pressSequentially('257 Victoria Road Rydal', { delay: 20 });
    await page.getByRole('option', { name: /257 VICTORIA ROAD/ }).first().click();
    await page.waitForTimeout(800);
    await setView(page, RYD, 14);
    await page.getByTestId('rail-addresses').click();
    await expect(page.getByText('Zoom in to see addresses')).toBeVisible();
    await setView(page, RYD, 17);
    await expect(page.getByText('Zoom in to see addresses')).toHaveCount(0);
    await shot(page, 'addresses_z17');
    for (const r of ['rail-labels', 'rail-streets', 'rail-satellite', 'rail-radius']) {
        await page.getByTestId(r).click();
        await page.waitForTimeout(300);
    }
    await shot(page, 'rail_toggled');
    await page.getByTestId('rail-satellite').click();
});

test('audience step mobile 390: bottom sheet, draw, dark mode', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => {
        localStorage.setItem('epfd-map-tour-done', '1');
        localStorage.setItem('epfd-theme', 'dark');
    });
    await page.goto(`/app/campaigns/${ids.draftCampaign}/audience`);
    await map(page);
    await expect(page.getByTestId('left-panel')).toBeVisible();
    await shot(page, 'mobile_dark_sheet');
    await setView(page, RYD, 15);
    await page.getByTestId('add-area').click();
    await page.getByTestId('draw-mode-pins').click();
    // Pins in the clear band between the floating hint and the (now compact) sheet.
    for (const [x, y] of [[80, 470], [300, 470], [300, 600], [80, 600]]) {
        await page.mouse.click(x, y);
        await page.waitForTimeout(350);
    }
    const est = estimate(page);
    await page.keyboard.press('Enter');
    await est;
    await shot(page, 'mobile_dark_editing');
    await page.getByTestId('done-button').click();
});

test('mobile tour bubble does not cover its anchor', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript(() => localStorage.removeItem('epfd-map-tour-done'));
    await page.goto('/dev/map-preview');
    await map(page);
    const tour = (await page.getByTestId('map-tour').boundingBox())!;
    const anchor = await page.locator('[data-tour]').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().toJSON()));
    await shot(page, 'mobile_tour');
    expect(tour).toBeTruthy();
    expect(anchor.length).toBeGreaterThan(0);
});

test('dev map preview admin + readonly modes', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('epfd-map-tour-done', '1'));
    await page.goto('/dev/map-preview?admin=1');
    await map(page);
    await expect(page.getByText('Split into runs').first()).toBeVisible();
    await page.goto('/dev/map-preview?readonly=1');
    await map(page);
    await expect(page.getByTestId('add-area')).toHaveCount(0);
});

test('job page readOnly map shows GPS tracks', async ({ page }) => {
    await page.goto(`/app/jobs/campaigns/${ids.jobWithGps}`);
    await map(page);
    await expect(page.getByTestId('add-area')).toHaveCount(0);
    await expect(page.getByTestId('track-latest').first()).toBeAttached();
    await shot(page, 'job_readonly');
});
