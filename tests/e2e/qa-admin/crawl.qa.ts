/// <reference types="node" />
import { test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Browser QA crawler for the admin panel, API docs and map preview.
 * Each URL × viewport × theme: HTTP status, console/page errors, failed requests,
 * page-level horizontal overflow, broken images, <h1> presence, full-page screenshot.
 * Writes results.json next to this file. Run: npx playwright test -c playwright.qa-admin.config.ts
 */
const DIR = import.meta.dirname;
const SHOTS = path.join(DIR, 'shots');
const ids = JSON.parse(fs.readFileSync(path.join(DIR, 'ids.json'), 'utf8')) as Record<string, number | string>;

const VIEWPORTS = { desktop: { width: 1440, height: 900 }, tablet: { width: 1024, height: 768 }, mobile: { width: 390, height: 844 } } as const;
type Vp = keyof typeof VIEWPORTS;

/** [url, required permission or null (any staff)] */
export const ADMIN_URLS: [string, string | null][] = [
    ['/admin', null],
    ['/admin/businesses', 'businesses.view'],
    ['/admin/businesses?status=pending', 'businesses.view'],
    [`/admin/businesses/${ids.bizApproved}`, 'businesses.view'],
    [`/admin/businesses/${ids.bizPending}`, 'businesses.view'],
    ['/admin/campaigns', 'campaigns.view'],
    [`/admin/campaigns/${ids.campSubmitted}`, 'campaigns.view'],
    [`/admin/campaigns/${ids.campPublished}`, 'campaigns.view'],
    [`/admin/campaigns/${ids.campDelivery}`, 'campaigns.view'],
    [`/admin/campaigns/${ids.campCompleted}`, 'campaigns.view'],
    [`/admin/campaigns/${ids.campAllocated}`, 'campaigns.view'],
    ['/admin/print-orders', 'print_orders.view'],
    [`/admin/print-orders/${ids.poSubmitted}`, 'print_orders.view'],
    [`/admin/print-orders/${ids.poArtwork}`, 'print_orders.view'],
    [`/admin/print-orders/${ids.poPrinting}`, 'print_orders.view'],
    [`/admin/print-orders/${ids.poShipped}`, 'print_orders.view'],
    [`/admin/print-orders/${ids.poCompleted}`, 'print_orders.view'],
    ['/admin/design-orders', 'design_orders.view'],
    [`/admin/design-orders/${ids.doSubmitted}`, 'design_orders.view'],
    [`/admin/design-orders/${ids.doApproved}`, 'design_orders.view'],
    ['/admin/payments', 'payments.view'],
    ['/admin/payments?tab=invoices', 'payments.view'],
    ['/admin/rate-card?tab=distribution', 'rate_card.manage'],
    ['/admin/rate-card?tab=print', 'rate_card.manage'],
    ['/admin/rate-card?tab=matrix', 'rate_card.manage'],
    ['/admin/rate-card?tab=design', 'rate_card.manage'],
    ['/admin/rate-card?tab=promos', 'rate_card.manage'],
    ['/admin/rate-card?tab=history', 'rate_card.manage'],
    ['/admin/service-areas', 'service_areas.manage'],
    [`/admin/service-areas/${ids.suburb}`, 'service_areas.manage'],
    ['/admin/gnaf', 'gnaf.view'],
    ['/admin/pickup-points', 'pickup_points.manage'],
    ['/admin/support', 'support.view'],
    [`/admin/support/${ids.thread}`, 'support.view'],
    ['/admin/content', 'content.manage'],
    ['/admin/reports', 'reports.view'],
    ['/admin/reports?report=letterboxes', 'reports.view'],
    ['/admin/users', 'users.manage'],
    ['/admin/users?tab=distributors', 'users.manage'],
    ['/admin/audit-log', 'audit.view'],
];
const DARK_SUBSET = ['/admin', '/admin/businesses', `/admin/campaigns/${ids.campDelivery}`, '/admin/rate-card?tab=matrix', '/admin/reports', '/admin/audit-log', `/admin/print-orders/${ids.poArtwork}`, '/admin/gnaf'];
const OTHER_URLS = ['/docs', '/docs/api', '/dev/map-preview', '/dev/map-preview?admin=1', '/dev/map-preview?readonly=1'];

interface Result {
    who: string; url: string; vp: Vp; theme: string; status: number | null;
    console: string[]; pageErrors: string[]; failed: string[]; overflow: number; brokenImgs: string[]; h1: boolean; shot: string;
}
const results: Result[] = [];

async function newCtx(browser: Browser, email: string, loginUrl: string): Promise<BrowserContext> {
    const ctx = await browser.newContext({ baseURL: 'http://epfd.test' });
    const p = await ctx.newPage();
    await p.goto(loginUrl);
    await p.locator('input[name="email"]').fill(email);
    await p.locator('input[name="password"]').fill('password');
    await p.getByRole('button', { name: 'Sign in' }).click();
    await p.waitForURL((u) => !u.pathname.endsWith('/login'));
    await p.close();
    return ctx;
}

async function visit(ctx: BrowserContext, who: string, url: string, vp: Vp, theme: 'light' | 'dark'): Promise<Result> {
    const page: Page = await ctx.newPage();
    await page.setViewportSize(VIEWPORTS[vp]);
    await page.addInitScript((t) => localStorage.setItem('epfd-theme', t), theme);
    const r: Result = { who, url, vp, theme, status: null, console: [], pageErrors: [], failed: [], overflow: 0, brokenImgs: [], h1: false, shot: '' };
    page.on('console', (m) => m.type() === 'error' && r.console.push(m.text().slice(0, 300)));
    page.on('pageerror', (e) => r.pageErrors.push(String(e).slice(0, 300)));
    page.on('requestfailed', (q) => !/tile|basemaps|arcgisonline|openstreetmap|carto/.test(q.url()) && r.failed.push(`${q.failure()?.errorText} ${q.url()}`));
    page.on('response', (s) => s.status() >= 400 && s.url() !== new URL(url, 'http://epfd.test').href && r.failed.push(`${s.status()} ${s.url()}`));
    try {
        const resp = await page.goto(url, { waitUntil: 'networkidle', timeout: 45_000 });
        r.status = resp?.status() ?? null;
    } catch (e) {
        r.pageErrors.push('NAV ' + String(e).slice(0, 200));
    }
    await page.waitForTimeout(400);
    const m = await page.evaluate(() => ({
        overflow: document.documentElement.scrollWidth - window.innerWidth,
        broken: [...document.images].filter((i) => i.complete && i.naturalWidth === 0 && i.src).map((i) => i.src),
        h1: !!document.querySelector('h1'),
    }));
    r.overflow = m.overflow; r.brokenImgs = m.broken; r.h1 = m.h1;
    const name = `${who}_${url.replace(/[^a-z0-9]+/gi, '_')}_${vp}_${theme}.png`.replace(/_+/g, '_');
    r.shot = name;
    if (who === 'admin' || r.status !== 200) await page.screenshot({ path: path.join(SHOTS, name), fullPage: true }).catch(() => {});
    await page.close();
    return r;
}

test('admin crawl', async ({ browser }) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    const only = process.env.QA_ONLY;
    const ctx = await newCtx(browser, 'admin@epfd.test', '/admin/login');
    for (const [url] of ADMIN_URLS) {
        if (only && !url.includes(only)) continue;
        for (const vp of Object.keys(VIEWPORTS) as Vp[]) results.push(await visit(ctx, 'admin', url, vp, 'light'));
        if (DARK_SUBSET.includes(url)) results.push(await visit(ctx, 'admin', url, 'desktop', 'dark'));
    }
    for (const url of OTHER_URLS) {
        if (only && !url.includes(only)) continue;
        for (const vp of ['desktop', 'mobile'] as Vp[]) results.push(await visit(ctx, 'admin', url, vp, 'light'));
        results.push(await visit(ctx, 'admin', url, 'desktop', 'dark'));
    }
    await ctx.close();

    // RBAC
    if (!only) {
        const perms = JSON.parse(fs.readFileSync(path.join(DIR, 'perms.json'), 'utf8')) as Record<string, string[]>;
        for (const who of ['ops', 'finance', 'support']) {
            const c = await newCtx(browser, `${who}@epfd.test`, '/admin/login');
            for (const [url, perm] of ADMIN_URLS) {
                const res = await visit(c, who, url, 'desktop', 'light');
                const expected = perm === null || perms[who].includes(perm) ? 200 : 403;
                (res as Result & { expected: number }).expected = expected;
                results.push(res);
            }
            // sidebar check
            const p = await c.newPage();
            await p.goto('/admin');
            const hrefs = await p.locator('nav a[href^="/admin"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
            results.push({ who, url: 'SIDEBAR', vp: 'desktop', theme: 'light', status: 0, console: hrefs as string[], pageErrors: [], failed: [], overflow: 0, brokenImgs: [], h1: true, shot: '' });
            await p.close();
            await c.close();
        }
    }
    fs.writeFileSync(path.join(DIR, only ? 'results-partial.json' : 'results.json'), JSON.stringify(results, null, 1));
});
