import { expect, test } from '@playwright/test';
import { AUTH, FIXTURE_PNG, readState } from './helpers/common';

test.describe.configure({ mode: 'serial' });

test.describe('studio', () => {
    test.use({ storageState: AUTH.admin });

    test('admin assigns a designer and uploads proof v1 for the paid design order', async ({ page }) => {
        const { designOrderId } = readState();
        test.skip(!designOrderId, 'needs design-order.spec.ts');
        await page.goto(`/admin/design-orders/${designOrderId}`);
        await expect(page.getByRole('heading', { name: /Design order DSN-/ })).toBeVisible();
        await page.getByTestId('designer-select').selectOption({ index: 1 });
        await page.getByRole('button', { name: 'Save designer' }).click();
        await expect(page.getByRole('button', { name: 'Save designer' })).toBeDisabled();
        await page.getByTestId('proof-file').setInputFiles(FIXTURE_PNG);
        await page.getByTestId('proof-upload').click();
        await expect(page.locator('main').getByText('Proof Ready', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('img', { name: /Preview of/ }).first()).toBeVisible();
    });
});

test.describe('business', () => {
    test.use({ storageState: AUTH.business });

    test('business approves the proof and sends the design to print', async ({ page }) => {
        const { designOrderId } = readState();
        test.skip(!designOrderId, 'needs design-order.spec.ts');
        await page.goto(`/app/jobs/design/${designOrderId}`);
        await expect(page.getByTestId('proof-v1')).toBeVisible();
        await page.getByTestId('approve-design').click();
        await page.getByTestId('approve-design-confirm').click();
        await expect(page.locator('main').getByText('Approved', { exact: true }).first()).toBeVisible();
        await page.getByTestId('print-design').click();
        await page.waitForURL(/\/app\/print\/\d+\/specs$/);
        await expect(page.getByRole('heading', { level: 1, name: 'A5 Flyers' })).toBeVisible();
        await expect(page.getByTestId('wizard-next')).toBeEnabled();
    });
});
