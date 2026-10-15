import { expect, test } from '@playwright/test';
import { AUTH, readState } from './helpers/common';

test.use({ storageState: AUTH.admin });

test.describe.configure({ mode: 'serial' });

test('approve the pending business registered by register.spec', async ({ page }) => {
    const { registeredBusiness } = readState();
    await page.goto('/admin/businesses');
    // Prefer the business created in register.spec.ts; fall back to any pending one.
    const link = registeredBusiness ? page.getByRole('link', { name: registeredBusiness, exact: true }) : page.getByRole('row').filter({ hasText: /Pending/ }).getByRole('link').first();
    await link.click();
    await page.waitForURL(/\/admin\/businesses\/\d+$/);
    await page.getByTestId('approve-business').click();
    await page.getByTestId('approve-business-confirm').click();
    await expect(page.getByTestId('approve-business')).toHaveCount(0);
    await expect(page.locator('main').getByText('Approved', { exact: true }).first()).toBeVisible();
});

test('create one run per area and publish runs', async ({ page }) => {
    const { campaignId } = readState();
    test.skip(!campaignId, 'needs campaign-draw-modes.spec.ts');
    await page.goto(`/admin/campaigns/${campaignId}`);
    await page.getByTestId('create-runs-one-per-area').click();
    await page.getByTestId('create-runs-confirm').click();
    await expect(page.getByTestId('publish-runs')).toBeVisible();
    await page.getByTestId('publish-runs').click();
    await page.getByTestId('publish-runs-confirm').click();
    await expect(page.getByTestId('publish-runs')).toHaveCount(0);
    await expect(page.locator('main').getByText('Runs Published', { exact: false }).first()).toBeVisible();
});

test('approve print order artwork', async ({ page }) => {
    const { printOrderId } = readState();
    test.skip(!printOrderId, 'needs print-order.spec.ts');
    await page.goto(`/admin/print-orders/${printOrderId}`);
    await page.getByTestId('approve-artwork').click();
    await page.getByTestId('approve-artwork-confirm').click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.locator('main').getByText('Approved', { exact: true }).first()).toBeVisible();
});
