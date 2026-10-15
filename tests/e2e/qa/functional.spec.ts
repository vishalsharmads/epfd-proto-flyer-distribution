/// <reference types="node" />
import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';

/** Click-through functional checks. Runs after crawl.spec.ts (which saves the per-role storage states). */
const AUTH = (role: string) => path.join(import.meta.dirname, '.auth', `${role}.json`);
const errorsOf = (page: Page) => {
    const errs: string[] = [];
    page.on('pageerror', (e) => errs.push(e.message));
    page.on('console', (m) => m.type() === 'error' && !/Cross-Origin-Opener|ProjectDeniedMap/.test(m.text()) && errs.push(m.text()));
    return errs;
};

test.describe.configure({ timeout: 60_000 });

test.describe('demo business', () => {
    test.use({ storageState: AUTH('demo'), viewport: { width: 1440, height: 900 } });

    test('top nav links all load', async ({ page }) => {
        const errs = errorsOf(page);
        await page.goto('/app');
        const nav = page.getByRole('navigation').first();
        const hrefs = await nav.locator('a[href^="/app"], a[href^="http"]').evaluateAll((as) => [...new Set(as.map((a) => (a as HTMLAnchorElement).pathname))]);
        expect(hrefs.length).toBeGreaterThan(3);
        for (const h of hrefs) {
            await nav.locator(`a[href$="${h}"]`).first().click();
            await page.waitForURL((u) => u.pathname === h);
            await expect(page.locator('h1').first()).toBeVisible();
        }
        expect(errs).toEqual([]);
    });

    test('dashboard buttons route to the wizards', async ({ page }) => {
        for (const [name, url] of [['New Campaign', /campaigns\/new/], ['New Print Order', /print\/new/], ['New Design', /design\/new/]] as const) {
            await page.goto('/app');
            await page.getByRole('link', { name }).first().click();
            await page.waitForURL(url);
        }
    });

    test('campaign details: name check + next enablement + repricing', async ({ page }) => {
        const errs = errorsOf(page);
        await page.goto('/app/campaigns/14/details');
        const name = page.getByTestId('campaign-name');
        const original = await name.inputValue();
        await name.fill('');
        await expect(page.getByTestId('wizard-next')).toBeDisabled();
        await name.fill(`${original}`);
        await expect(page.getByTestId('wizard-next')).toBeEnabled({ timeout: 10_000 });
        const total = page.getByRole('complementary', { name: 'Campaign summary' });
        const before = await total.innerText();
        const cards = page.locator('label[data-testid^="material-"]');
        expect(await cards.count()).toBeGreaterThan(2);
        await cards.nth(2).click(); // Folded (+surcharge) — Standard DL and A5 share the base price
        await expect.poll(async () => total.innerText()).not.toBe(before);
        await cards.nth(0).click();
        await expect.poll(async () => total.innerText()).toBe(before);
        expect(errs).toEqual([]);
    });

    test('campaign confirm: pay disabled until terms + billing valid', async ({ page }) => {
        await page.goto('/app/campaigns/14/confirm');
        await expect(page.getByTestId('pay-button')).toBeDisabled();
        await expect(page.getByText(/accept the terms|Complete the billing/i).first()).toBeVisible();
    });

    test('print specs: quantity change reprices', async ({ page }) => {
        const errs = errorsOf(page);
        await page.goto('/app/print/16/specs');
        const price = page.getByText(/Total incl\. GST/).locator('..');
        const before = await price.innerText();
        const qtys = page.locator('[data-testid^="qty-"]');
        const target = (await qtys.nth(0).locator('input').isChecked()) ? qtys.nth(1) : qtys.nth(0);
        await target.click();
        await expect.poll(async () => price.innerText()).not.toBe(before);
        expect(errs).toEqual([]);
    });

    test('job pages: message send + PDFs', async ({ page, request }) => {
        await page.goto('/app/jobs/campaigns/1');
        const box = page.getByPlaceholder(/Type your message/);
        await box.fill('QA check message');
        await page.getByRole('button', { name: 'Send message' }).click();
        await expect(page.getByText('QA check message').first()).toBeVisible();
        for (const u of ['/app/invoices/1/pdf', '/app/jobs/campaigns/1/proof.pdf']) {
            const r = await page.request.get(u);
            expect(r.status(), u).toBe(200);
            expect(r.headers()['content-type']).toContain('application/pdf');
        }
        void request;
    });

    test('job page: rating update', async ({ page }) => {
        await page.goto('/app/jobs/campaigns/1');
        const saved = page.waitForResponse((r) => /rating/.test(r.url()) && r.request().method() !== 'GET');
        await page.getByRole('button', { name: /Update rating|Submit rating/ }).click();
        expect((await saved).status()).toBeLessThan(400);
    });

    test('account: profile validation, password validation, tabs', async ({ page }) => {
        const errs = errorsOf(page);
        await page.goto('/app/account?tab=profile');
        const bn = page.getByLabel('Business name');
        const orig = await bn.inputValue();
        await bn.fill('');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText(/business name.*required|required/i).first()).toBeVisible();
        for (const v of [`${orig} QA`, orig]) {
            await bn.fill(v);
            const saved = page.waitForResponse((r) => r.url().endsWith('/app/account/profile') && r.request().method() === 'PUT');
            await page.getByRole('button', { name: 'Save changes' }).click();
            expect((await saved).status()).toBeLessThan(400);
            await expect(page.getByText('The business name field is required.')).toBeHidden();
        }

        await page.goto('/app/account?tab=security');
        await page.getByLabel('Current password').fill('wrong-password');
        await page.getByLabel('New password', { exact: true }).fill('newpass123');
        await page.getByLabel('Confirm new password').fill('newpass124');
        await expect(page.getByText('Passwords do not match.')).toBeVisible();
        await page.getByLabel('Confirm new password').fill('newpass123');
        await page.getByRole('button', { name: 'Update password' }).click();
        await expect(page.getByText(/incorrect|current password/i).nth(1)).toBeVisible();

        await page.goto('/app/account?tab=payments');
        await page.getByRole('button', { name: /^Delete / }).first().click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(page.getByRole('dialog')).toBeHidden();
        expect(errs).toEqual([]);
    });

    test('support: new thread + reply', async ({ page }) => {
        await page.goto('/app/support');
        await page.getByTestId('support-subject').fill('QA thread');
        await page.getByTestId('support-body').fill('QA body text here');
        await page.getByRole('button', { name: 'Send request' }).click();
        await page.waitForURL(/\/app\/support\/\d+/);
        await page.getByPlaceholder(/Type your message/).fill('QA reply');
        await page.getByRole('button', { name: /Send/ }).click();
        await expect(page.getByText('QA reply').first()).toBeVisible();
    });
});

test.describe('pending business', () => {
    test.use({ storageState: AUTH('pending'), viewport: { width: 1440, height: 900 } });
    test('sees approval banner and payment blocked', async ({ page }) => {
        await page.goto('/app');
        await expect(page.getByText(/approves your business/i)).toBeVisible();
        await page.goto('/app/campaigns/new');
        await page.getByRole('button', { name: /Start new/ }).first().click();
        await page.waitForURL(/campaigns\/\d+\/audience/);
        const id = page.url().match(/campaigns\/(\d+)/)![1];
        await page.goto(`/app/campaigns/${id}/confirm`);
        await expect(page.getByTestId('pay-button')).toBeDisabled();
        await expect(page.getByText(/awaiting approval|approves/i).first()).toBeVisible();
    });
});

test('forgot password sends an email; logout works', async ({ page, browser }) => {
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill('qld@business.test');
    await page.getByRole('button', { name: /reset link/i }).click();
    await expect(page.getByText(/sent|check your email|if an account/i).first()).toBeVisible();
    const r = await page.request.get('http://127.0.0.1:8025/api/v1/search?query=to:qld@business.test');
    expect((await r.json()).messages_count).toBeGreaterThan(0);

    const ctx = await browser.newContext();
    const p = await ctx.newPage();
    await p.goto('/login');
    await p.getByLabel(/email/i).first().fill('vic@business.test');
    await p.getByLabel(/password/i).first().fill('password');
    await p.getByRole('button', { name: /sign in/i }).first().click();
    await p.waitForURL(/\/app/);
    await p.getByRole('button', { name: /Marcus|account menu/i }).first().click();
    await p.getByRole('menuitem', { name: /Log out/ }).click();
    await p.waitForURL((u) => !u.pathname.startsWith('/app'));
    await ctx.close();
});
