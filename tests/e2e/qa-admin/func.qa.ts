/// <reference types="node" />
import { expect, test, type Page } from '@playwright/test';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Functional click-through QA of the admin panel (mutates the dev DB; prototype only).
 * Every test fails on console errors / page errors.
 */
const DIR = import.meta.dirname;
const ids = JSON.parse(fs.readFileSync(path.join(DIR, 'ids.json'), 'utf8')) as Record<string, number>;
/** These flows advance real records, so pick fresh ones from the dev DB each run (psql on :5433). */
const sql = (q: string) => execSync(`psql -p 5433 -d epfd -Atc "${q}"`).toString().trim().split('\n')[0] || '';
const IGNORE = /Cross-Origin-Opener-Policy|ProjectDeniedMapError|Failed to load resource: the server responded with a status of 422/;

test.describe.configure({ timeout: 60_000 });
test.use({ viewport: { width: 1440, height: 900 } });

const AUTH = path.join(DIR, '.auth-admin.json');
test.beforeAll(async ({ browser }) => {
    if (fs.existsSync(AUTH) && Date.now() - fs.statSync(AUTH).mtimeMs < 60 * 60_000) return;
    const page = await browser.newPage({ storageState: undefined, baseURL: 'http://epfd.test' });
    await page.goto('/admin/login');
    await page.locator('input[name="email"]').fill('admin@epfd.test');
    await page.locator('input[name="password"]').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((u) => !u.pathname.endsWith('/login'));
    await page.context().storageState({ path: AUTH });
    await page.close();
});
test.use({ storageState: AUTH });

let errors: string[] = [];
test.beforeEach(async ({ page }) => {
    errors = [];
    page.on('console', (m) => m.type() === 'error' && !IGNORE.test(m.text()) && errors.push(m.text()));
    page.on('pageerror', (e) => errors.push(String(e)));
});
test.afterEach(() => expect(errors, 'console/page errors').toEqual([]));

const toast = (page: Page, text: RegExp | string) => expect(page.locator('[data-sonner-toast]').filter({ hasText: text }).first()).toBeVisible();
const dialog = (page: Page) => page.getByRole('dialog');

test('command palette opens, filters and navigates', async ({ page }) => {
    await page.goto('/admin', { waitUntil: 'networkidle' });
    await page.keyboard.press('ControlOrMeta+k');
    const input = page.locator('[cmdk-input]');
    await expect(input).toBeVisible();
    await input.fill('audit');
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/admin\/audit-log/);
});

test('businesses: search, suspend + reinstate, credit add/deduct with validation', async ({ page }) => {
    await page.goto('/admin/businesses');
    await page.getByPlaceholder(/Search name/).fill('Swan');
    await expect(page.getByRole('row')).toHaveCount(2);
    await page.getByRole('row').nth(1).click();
    await page.waitForURL(/\/admin\/businesses\/\d+/);
    await page.getByRole('button', { name: 'Suspend' }).click();
    await dialog(page).locator('textarea').first().fill('QA suspend test').catch(() => {});
    await dialog(page).getByRole('button', { name: /Suspend/ }).click();
    await toast(page, /suspend/i);
    await expect(page.getByText('Suspended').first()).toBeVisible();
    await page.getByRole('button', { name: /Reinstate/ }).click();
    await dialog(page).getByRole('button', { name: /Reinstate/ }).click();
    await toast(page, /reinstat|approved/i);

    await page.getByRole('button', { name: 'Adjust' }).click();
    await dialog(page).getByLabel('Amount (AUD)').fill('12.50');
    await dialog(page).getByLabel('Reason').fill('QA goodwill');
    await dialog(page).getByRole('button', { name: /Add|Save|Apply/ }).last().click();
    await toast(page, /credit/i);
    await expect(page.getByText('QA goodwill').first()).toBeVisible();
    // deduct more than balance → validation error
    await page.getByRole('button', { name: 'Adjust' }).click();
    await dialog(page).getByLabel('Type').selectOption('deduct');
    await dialog(page).getByLabel('Amount (AUD)').fill('999999');
    await dialog(page).getByLabel('Reason').fill('QA too much');
    await dialog(page).getByRole('button', { name: /Deduct|Save|Apply/ }).last().click();
    await expect(dialog(page).locator('[id$="-error"], .text-danger, [role="alert"]').first()).toBeVisible();
    await page.keyboard.press('Escape');
});

test('reports CSV download is text/csv and non-empty', async ({ page }) => {
    for (const r of ['revenue', 'campaigns', 'letterboxes', 'print', 'businesses']) {
        const res = await page.request.get(`/admin/reports/${r}/export`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type']).toContain('text/csv');
        expect((await res.text()).split('\n').length).toBeGreaterThan(1);
    }
    for (const u of ['/admin/payments/export', '/admin/invoices/export']) {
        const res = await page.request.get(u);
        expect(res.status(), u).toBe(200);
        expect(res.headers()['content-type'], u).toContain('text/csv');
    }
    await page.goto('/admin/reports');
    const dl = page.waitForEvent('download');
    await page.getByRole('link', { name: /Export CSV/ }).or(page.getByRole('button', { name: /Export CSV/ })).first().click();
    expect((await dl).suggestedFilename()).toMatch(/\.csv$/);
});

test('audit log diff viewer + filters + pagination', async ({ page }) => {
    await page.goto('/admin/audit-log');
    await page.getByRole('button', { name: 'View' }).first().click();
    await expect(dialog(page)).toBeVisible();
    await expect(dialog(page)).not.toContainText('undefined');
    await page.keyboard.press('Escape');
    await page.getByRole('link', { name: '2', exact: true }).click();
    await page.waitForURL(/page=2/);
});

test('content: create, edit, preview, delete', async ({ page }) => {
    await page.goto('/admin/content');
    await page.getByRole('button', { name: /New/ }).first().click();
    const t = `QA FAQ ${Date.now()}`;
    await page.getByLabel(/Title|Question/).first().fill(t);
    await page.locator('textarea').first().fill('**Bold** answer for QA.');
    await page.getByRole('button', { name: 'Create' }).click();
    await toast(page, /saved|created/i);
    await expect(page.getByRole('navigation', { name: 'Pages' })).toContainText(t);
    await page.getByRole('tab', { name: 'Preview' }).click();
    await expect(page.locator('strong', { hasText: 'Bold' })).toBeVisible();
    await page.getByRole('tab', { name: 'Write' }).click();
    await page.getByLabel(/Title|Question/).first().fill(t + ' edited');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await toast(page, /saved|updated/i);
    await page.getByRole('button', { name: /Delete/ }).click();
    await dialog(page).getByRole('button', { name: /Delete/ }).click();
    await toast(page, /deleted/i);
});

test('support: reply + status change', async ({ page }) => {
    await page.goto(`/admin/support/${ids.thread}`);
    await page.getByPlaceholder('Write a reply…').fill('QA reply from admin');
    await page.getByRole('button', { name: /Send reply/ }).click();
    await toast(page, /sent|reply/i);
    await expect(page.getByRole('list', { name: 'Conversation' })).toContainText('QA reply from admin');
    await page.getByLabel('Priority').selectOption({ index: 2 });
    await toast(page, /updated|saved/i);
});

test('users: invite staff validation + role change', async ({ page }) => {
    await page.goto('/admin/users');
    await page.getByRole('button', { name: 'Invite staff' }).click();
    await dialog(page).getByRole('button', { name: /Invite|Send|Create/ }).last().click();
    await expect(dialog(page).locator('[id$="-error"], .text-danger, [role="alert"]').first()).toBeVisible();
    const email = `qa${Date.now()}@epfd.test`;
    await dialog(page).getByLabel('Full name').fill('QA Staffer');
    await dialog(page).getByLabel('Email', { exact: true }).fill(email);
    await dialog(page).getByLabel('Role').selectOption({ label: 'Support' }).catch(() => {});
    await dialog(page).getByRole('button', { name: /Invite|Send|Create/ }).last().click();
    await toast(page, /invit|created/i);
    const row = page.getByRole('row').filter({ hasText: email });
    await row.getByRole('combobox').selectOption({ label: 'Operations' });
    await dialog(page).getByRole('button', { name: /Change|Confirm|Save/ }).last().click();
    await toast(page, /is now operations/i);
});

// ---------- campaigns: split tool (admin map), one-per-area, edit, publish, assign, advance, GPS, proof ----------
type LL = [number, number];
async function waitMap(page: Page) {
    await page.waitForFunction(() => !!(window as unknown as { __epfdMap?: unknown }).__epfdMap, undefined, { timeout: 20_000 });
}
async function px(page: Page, ll: LL) {
    return page.evaluate((p) => (window as unknown as { __epfdMap: { project: (l: LL) => { x: number; y: number } } }).__epfdMap.project(p), ll);
}
async function setView(page: Page, c: LL, z: number) {
    await page.evaluate(([cc, zz]) => (window as unknown as { __epfdMap: { setView: (c: LL, z: number) => void } }).__epfdMap.setView(cc as LL, zz as number), [c, z] as const);
    await page.waitForTimeout(600);
}

const submitted = () => Number(sql("select c.id from campaigns c where c.status='submitted' and c.deleted_at is null and not exists (select 1 from runs r where r.campaign_id=c.id) order by c.id limit 1"));

test('campaign split tool: draw a run polygon in admin map mode and save', async ({ page }) => {
    const id = submitted();
    test.skip(!id, 'no submitted campaign without runs left in the dev DB');
    const env = sql(`select ST_XMin(e)||','||ST_YMin(e)||','||ST_XMax(e)||','||ST_YMax(e) from (select ST_Extent(geom) e from campaign_areas where campaign_id=${id}) x`).split(',').map(Number);
    const c: LL = [(env[0] + env[2]) / 2, (env[1] + env[3]) / 2];
    const dx = (env[2] - env[0]) / 4, dy = (env[3] - env[1]) / 4;
    await page.addInitScript(() => localStorage.setItem('epfd-map-tour-done', '1'));
    await page.goto(`/admin/campaigns/${id}`);
    await page.getByTestId('create-runs').click();
    await waitMap(page);
    await setView(page, c, 16);
    await page.getByTestId('add-area').click();
    await page.getByTestId('draw-mode-pins').click();
    for (const ll of [[c[0] - dx, c[1] - dy], [c[0] + dx, c[1] - dy], [c[0] + dx, c[1] + dy], [c[0] - dx, c[1] + dy]] as LL[]) {
        const q = await px(page, ll);
        await page.mouse.click(q.x, q.y);
        await page.waitForTimeout(450);
    }
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('save-runs')).toHaveText(/Save 1 run/);
    await page.getByTestId('save-runs').click();
    await toast(page, /run/i);
    await expect(page.getByRole('heading', { name: 'Runs' }).or(page.getByText(/1 run\(s\)/)).first()).toBeVisible();
});

test('campaign: one-per-area, edit run, assign, publish, advance, GPS, proof report', async ({ page }) => {
    const id = Number(sql("select c.id from campaigns c where c.status='submitted' and c.deleted_at is null and not exists (select 1 from runs r where r.campaign_id=c.id and r.status<>'draft') order by c.id limit 1"));
    test.skip(!id, 'no submitted campaign left in the dev DB');
    await page.goto(`/admin/campaigns/${id}`);
    await page.getByTestId('create-runs-one-per-area').click();
    await dialog(page).getByTestId('create-runs-confirm').click();
    await toast(page, /run/i);
    const more = page.getByRole('button', { name: /More actions for/ }).first();
    await more.click();
    await page.getByRole('menuitem', { name: 'Edit run…' }).click();
    await dialog(page).getByLabel(/Flyers/).fill('-5');
    expect(await dialog(page).getByLabel(/Flyers/).evaluate((el: HTMLInputElement) => el.validity.valid)).toBe(false);
    await dialog(page).getByLabel(/Flyers/).fill('70');
    await dialog(page).getByRole('button', { name: /Save/ }).click();
    await toast(page, /saved|updated/i);
    await page.getByTestId('publish-runs').click();
    await dialog(page).getByTestId('publish-runs-confirm').click();
    await toast(page, /publish/i);
    await page.getByRole('button', { name: /More actions for/ }).first().click();
    await page.getByRole('menuitem', { name: /Assign distributor/ }).click();
    await dialog(page).getByRole('combobox').selectOption({ index: 1 });
    await dialog(page).getByTestId('assign-run').click();
    await toast(page, /assign|allocated/i);
    for (const s of ['collected', 'in_delivery']) {
        await page.getByTestId(`run-status-${s}`).first().click();
        await dialog(page).getByTestId(`run-status-${s}-confirm`).click();
        await toast(page, /status|collected|delivery/i);
    }
    await page.getByRole('button', { name: /More actions for/ }).first().click();
    await page.getByTestId('simulate-gps').click();
    await dialog(page).getByTestId('simulate-gps-confirm').click();
    await toast(page, /gps|coverage/i);
    await page.getByTestId('generate-proof').click();
    await toast(page, /proof|report/i);
    const res = await page.request.get(`/admin/campaigns/${id}/proof-report`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('pdf');
});

// ---------- print orders ----------
test('print order: artwork reject + approve (if pending), ship needs tracking, proof upload, refunds', async ({ page }) => {
    await page.goto(`/admin/print-orders/${ids.poSubmitted}`);
    const reject = page.getByRole('button', { name: 'Reject' }).first();
    if ((await reject.count()) > 0 && (await reject.isEnabled())) {
        await reject.click();
        await dialog(page).locator('textarea').fill('QA: bleed missing');
        await dialog(page).getByRole('button', { name: /Reject/ }).click();
        await toast(page, /reject/i);
        await page.getByTestId('approve-artwork').first().click();
        await dialog(page).getByRole('button', { name: /Approve/ }).click();
        await toast(page, /approv/i);
    }
    const printing = Number(sql("select id from print_orders where status='in_printing' order by id limit 1"));
    test.skip(!printing, 'no in_printing print order left');
    await page.goto(`/admin/print-orders/${printing}`);
    await page.getByTestId('print-status-shipped').click();
    await dialog(page).getByLabel('Print vendor / courier').fill('');
    await dialog(page).getByLabel('Tracking number').fill('');
    await dialog(page).getByTestId('print-ship-confirm').click();
    await expect(dialog(page).locator('[role="alert"], .text-danger').first()).toBeVisible();
    await dialog(page).getByLabel('Print vendor / courier').fill('QA Print via StarTrack');
    await dialog(page).getByLabel('Tracking number').fill('QA123456');
    await dialog(page).getByTestId('print-ship-confirm').click();
    await toast(page, /shipped|status/i);
    await page.getByLabel(/Upload proof/).setInputFiles(path.join(DIR, '..', 'fixtures', 'artwork.png'));
    await page.getByRole('button', { name: 'Upload', exact: true }).click();
    await toast(page, /proof/i);
    // refund validation
    await page.getByRole('button', { name: 'Refund' }).first().click();
    await dialog(page).getByLabel('Amount (AUD)').fill('99999');
    await expect(dialog(page).getByText(/Maximum/)).toBeVisible();
    await dialog(page).getByLabel('Amount (AUD)').fill('1.00');
    await dialog(page).getByLabel('Refund to').selectOption('credit');
    await dialog(page).getByLabel('Reason').fill('QA partial credit refund');
    await dialog(page).getByRole('button', { name: /Refund/ }).last().click();
    await toast(page, /refund/i);
    await page.getByRole('button', { name: 'Refund' }).first().click();
    await dialog(page).getByLabel('Amount (AUD)').fill('1.00');
    await dialog(page).getByLabel('Refund to').selectOption('card');
    await dialog(page).getByLabel('Reason').fill('QA partial card refund');
    await dialog(page).getByRole('button', { name: /Refund/ }).last().click();
    await toast(page, /refund/i);
});

// ---------- design orders ----------
test('design order: assign designer, in design, proof upload', async ({ page }) => {
    await page.goto(`/admin/design-orders/${ids.doSubmitted}`);
    const sel = page.getByTestId('designer-select');
    const cur = await sel.inputValue();
    const opts = await sel.locator('option').evaluateAll((o) => (o as HTMLOptionElement[]).map((x) => x.value).filter(Boolean));
    await sel.selectOption(opts.find((v) => v !== cur)!);
    await page.getByRole('button', { name: 'Save designer' }).click();
    await toast(page, /designer|assigned/i);
    await page.getByTestId('design-status-in_design').click();
    await dialog(page).getByRole('button', { name: 'Confirm' }).click();
    await toast(page, /design|status/i);
    await page.getByTestId('proof-file').setInputFiles(path.join(DIR, '..', 'fixtures', 'artwork.png'));
    await page.getByTestId('proof-upload').click();
    await toast(page, /proof/i);
});

// ---------- rate card ----------
test('rate card: edit + live preview + save version, matrix cell, promo CRUD', async ({ page }) => {
    await page.goto('/admin/rate-card?tab=distribution');
    const total = page.getByText('Total incl GST').locator('..');
    const before = await total.innerText();
    const input = page.getByLabel('Houses + units').first();
    const v = await input.inputValue();
    await input.fill(String(Number(v) + 0.01));
    await expect(total).not.toHaveText(before);
    await page.getByRole('button', { name: /Save new version/ }).click();
    await dialog(page).getByRole('button', { name: /Save/ }).last().click();
    await toast(page, /version|saved/i);
    await page.getByRole('tab', { name: 'Print price matrix' }).click();
    const cell = page.locator('table input').first();
    await expect(cell).not.toHaveValue('');
    await cell.fill((Number(await cell.inputValue()) + 0.0001).toFixed(4));
    await expect(page.getByText('1 unsaved cell(s)')).toBeVisible();
    await page.getByRole('button', { name: /Save matrix/ }).click();
    await dialog(page).getByRole('button', { name: /Save matrix/ }).click();
    await toast(page, /matrix|saved/i);
    await page.getByRole('tab', { name: /Promo codes/ }).click();
    await page.getByRole('button', { name: /New promo|Add promo|Create/ }).first().click();
    const code = `QA${Date.now().toString(36).toUpperCase()}`;
    await dialog(page).getByLabel('Code').fill(code);
    await dialog(page).getByRole('button', { name: 'Create promo' }).click();
    await expect(dialog(page).getByText('The percent field is required.')).toBeVisible();
    await dialog(page).getByLabel('Percent').fill('10');
    await dialog(page).getByRole('button', { name: 'Create promo' }).click();
    await expect(dialog(page)).toBeHidden();
    await expect(page.getByRole('row').filter({ hasText: code })).toBeVisible();
    await page.getByRole('button', { name: `Delete ${code}` }).click();
    await dialog(page).getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByRole('row').filter({ hasText: code })).toHaveCount(0);
});

// ---------- service areas / pickup points ----------
test('service area toggle + recompute', async ({ page }) => {
    await page.goto(`/admin/service-areas/${ids.suburb}`);
    await page.getByRole('switch').first().click();
    await toast(page, /servic|updated|saved/i);
    await page.getByRole('switch').first().click();
    await toast(page, /servic|updated|saved/i);
    await page.getByRole('button', { name: 'Recompute stats' }).click();
    await dialog(page).getByRole('button', { name: 'Recompute' }).click();
    await toast(page, /recomput|letterbox/i);
});

test('pickup point create with G-NAF typeahead', async ({ page }) => {
    await page.goto('/admin/pickup-points');
    await page.getByRole('button', { name: /Add|New/ }).first().click();
    const name = `QA depot ${Date.now().toString(36)}`;
    await dialog(page).getByPlaceholder('e.g. Rydalmere depot').fill(name);
    const addr = dialog(page).getByRole('combobox').first();
    await addr.pressSequentially('257 Victoria Road Rydal', { delay: 30 });
    await page.getByRole('listbox').getByRole('option').first().click();
    await expect(dialog(page).getByText('Located from G-NAF')).toBeVisible();
    await dialog(page).getByRole('button', { name: /Create|Save|Add/ }).last().click();
    await toast(page, /pickup|created|saved/i);
    await page.getByRole('button', { name: `Delete ${name}` }).click();
    await dialog(page).getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByRole('row').filter({ hasText: name })).toHaveCount(0);
});
