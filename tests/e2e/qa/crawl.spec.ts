/// <reference types="node" />
import { test, type Browser, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const DIR = import.meta.dirname;
const SHOTS = path.join(DIR, 'shots');

type Role = 'guest' | 'demo' | 'vic' | 'qld' | 'pending';
const EMAIL: Record<Exclude<Role, 'guest'>, string> = {
    demo: 'demo@business.test',
    vic: 'vic@business.test',
    qld: 'qld@business.test',
    pending: 'pending@business.test',
};

interface Target { role: Role; slug: string; url: string; dark?: boolean }

const T: Target[] = [
    ...['/', '/login', '/register', '/forgot-password', '/reset-password/fake-token?email=x@y.z', '/legal/terms', '/legal/privacy', '/legal/community-guidelines'].map(
        (url) => ({ role: 'guest' as Role, slug: url === '/' ? 'home' : url.split('?')[0].replace(/\//g, '_').slice(1), url }),
    ),
    { role: 'demo', slug: 'dashboard', url: '/app', dark: true },
    { role: 'demo', slug: 'distribution', url: '/app/distribution' },
    { role: 'demo', slug: 'campaign-new', url: '/app/campaigns/new' },
    ...['audience', 'details', 'confirm'].map((s) => ({ role: 'demo' as Role, slug: `campaign-draft-${s}`, url: `/app/campaigns/14/${s}`, dark: s === 'details' })),
    ...['audience', 'details', 'confirm'].map((s) => ({ role: 'demo' as Role, slug: `campaign-paid-${s}`, url: `/app/campaigns/13/${s}` })),
    { role: 'demo', slug: 'print-hub', url: '/app/print' },
    { role: 'demo', slug: 'print-new', url: '/app/print/new' },
    ...['product', 'specs', 'confirm'].map((s) => ({ role: 'demo' as Role, slug: `print-draft-${s}`, url: `/app/print/16/${s}` })),
    { role: 'demo', slug: 'design-hub', url: '/app/design' },
    { role: 'demo', slug: 'design-new', url: '/app/design/new' },
    ...['service', 'brief', 'confirm'].map((s) => ({ role: 'demo' as Role, slug: `design-draft-${s}`, url: `/app/design/8/${s}` })),
    { role: 'demo', slug: 'jobs', url: '/app/jobs' },
    ...[1, 4, 18, 10, 13].map((id) => ({ role: 'demo' as Role, slug: `job-campaign-${id}`, url: `/app/jobs/campaigns/${id}`, dark: id === 1 })),
    ...[1, 7, 4, 9].map((id) => ({ role: 'demo' as Role, slug: `job-print-${id}`, url: `/app/jobs/print/${id}` })),
    ...[4, 1].map((id) => ({ role: 'demo' as Role, slug: `job-design-${id}`, url: `/app/jobs/design/${id}` })),
    ...['profile', 'payments', 'invoices', 'security', 'legal', 'areas'].map((t) => ({ role: 'demo' as Role, slug: `account-${t}`, url: `/app/account?tab=${t}`, dark: t === 'profile' })),
    { role: 'demo', slug: 'support', url: '/app/support' },
    { role: 'demo', slug: 'support-thread', url: '/app/support/1' },
    { role: 'vic', slug: 'dashboard', url: '/app' },
    { role: 'vic', slug: 'jobs', url: '/app/jobs' },
    { role: 'qld', slug: 'dashboard', url: '/app' },
    { role: 'pending', slug: 'dashboard', url: '/app' },
    { role: 'pending', slug: 'campaign-new', url: '/app/campaigns/new' },
];

const VIEWPORTS = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } };
const IGNORED_REQ = /(tile|basemaps|openfreemap|maptiler|\.pbf|favicon|sentry)/i;

async function ctxFor(browser: Browser, role: Role, vp: { width: number; height: number }, dark: boolean) {
    const statePath = path.join(DIR, '.auth', `${role}.json`);
    const ctx = await browser.newContext({ viewport: vp, storageState: fs.existsSync(statePath) ? statePath : undefined, locale: 'en-AU', timezoneId: 'Australia/Sydney' });
    await ctx.addInitScript((d) => { try { localStorage.setItem('epfd-theme', d ? 'dark' : 'light'); } catch { /* */ } }, dark);
    return ctx;
}

async function login(page: Page, email: string) {
    await page.goto('/login');
    await page.getByLabel(/email/i).first().fill(email);
    await page.getByLabel(/password/i).first().fill('password');
    await page.getByRole('button', { name: /log ?in|sign ?in/i }).first().click();
    await page.waitForURL(/\/app/, { timeout: 30_000 });
}

test('crawl', async ({ browser }) => {
    fs.mkdirSync(path.join(DIR, '.auth'), { recursive: true });
    for (const role of Object.keys(EMAIL) as (keyof typeof EMAIL)[]) {
        const ctx = await browser.newContext();
        const p = await ctx.newPage();
        await login(p, EMAIL[role]);
        await ctx.storageState({ path: path.join(DIR, '.auth', `${role}.json`) });
        await ctx.close();
    }
    const only = process.env.QA_ONLY ? new RegExp(process.env.QA_ONLY) : null;
    const results: Record<string, unknown>[] = [];
    for (const t of T) {
        if (only && !only.test(`${t.role}/${t.slug}`)) continue;
        for (const [vpName, vp] of Object.entries(VIEWPORTS)) {
            for (const dark of t.dark ? [false, true] : [false]) {
                const ctx = await ctxFor(browser, t.role, vp, dark);
                const page = await ctx.newPage();
                const consoleErrors: string[] = [];
                const failed: string[] = [];
                page.on('console', (m) => m.type() === 'error' && consoleErrors.push(m.text().slice(0, 300)));
                page.on('pageerror', (e) => consoleErrors.push('PAGEERROR ' + e.message.slice(0, 300)));
                page.on('response', (r) => r.status() >= 400 && !IGNORED_REQ.test(r.url()) && failed.push(`${r.status()} ${r.url()}`));
                const resp = await page.goto(t.url, { waitUntil: 'load' });
                await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined);
                await page.waitForTimeout(600);
                const dom = await page.evaluate(() => {
                    const de = document.documentElement;
                    const vw = de.clientWidth;
                    const over: string[] = [];
                    document.querySelectorAll('body *').forEach((el) => {
                        const r = el.getBoundingClientRect();
                        if (r.width && r.right > vw + 1) {
                            let p: Element | null = el.parentElement, clipped = false;
                            while (p) { const s = getComputedStyle(p); if (/(hidden|auto|scroll|clip)/.test(s.overflowX)) { clipped = true; break; } p = p.parentElement; }
                            if (!clipped) over.push(`${el.tagName.toLowerCase()}.${String((el as HTMLElement).className).slice(0, 60)} r=${Math.round(r.right)}`);
                        }
                    });
                    const brokenImgs = [...document.images].filter((i) => i.complete && i.naturalWidth === 0 && i.src).map((i) => i.src);
                    const text = document.body.innerText;
                    const bad = (text.match(/\b(undefined|NaN|null|\[object Object\])\b/g) ?? []).slice(0, 5);
                    return { hOverflow: de.scrollWidth > vw + 1, scrollWidth: de.scrollWidth, over: over.slice(0, 8), brokenImgs, bad, h1: document.querySelectorAll('h1').length };
                });
                await page.keyboard.press('Tab');
                const focusVisible = await page.evaluate(() => { const a = document.activeElement; return !!a && a !== document.body && a.matches(':focus-visible'); });
                const theme = dark ? 'dark' : 'light';
                const shot = path.join(SHOTS, t.role, `${t.slug}-${vpName}-${theme}.png`);
                fs.mkdirSync(path.dirname(shot), { recursive: true });
                await page.screenshot({ path: shot, fullPage: true });
                results.push({ role: t.role, slug: t.slug, url: t.url, finalUrl: page.url().replace(/^https?:\/\/[^/]+/, ''), vp: vpName, theme, status: resp?.status(), consoleErrors, failed, focusVisible, ...dom });
                await ctx.close();
            }
        }
    }
    fs.writeFileSync(path.join(DIR, only ? 'results.partial.json' : 'results.json'), JSON.stringify(results, null, 2));
    const problems = results.filter((r) => r.status !== 200 || (r.consoleErrors as string[]).length || (r.failed as string[]).length || r.hOverflow || (r.over as string[]).length || (r.brokenImgs as string[]).length || (r.bad as string[]).length || !r.h1 || !r.focusVisible);
    console.log(`pages=${results.length} problems=${problems.length}`);
    for (const p of problems) console.log(JSON.stringify(p));
});
