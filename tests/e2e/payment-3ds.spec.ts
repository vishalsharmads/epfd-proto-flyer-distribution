import { expect, test, type Page } from '@playwright/test';
import { AUTH, fillCard } from './helpers/common';

test.use({ storageState: AUTH.business });

const patched = (page: Page, id: number) =>
    page.waitForResponse((r) => r.url().endsWith(`/app/design/${id}`) && r.request().method() === 'PATCH' && r.ok());

test('payment that needs 3-D Secure is confirmed and the job is submitted', async ({ page }) => {
    await page.goto('/app/design/new');
    await page.getByTestId('start-new-design').click();
    await page.waitForURL(/\/app\/design\/\d+\/service$/);
    const id = Number(page.url().match(/design\/(\d+)/)![1]);

    const saved = patched(page, id);
    await page.getByTestId('package-basic').click();
    await saved;
    await page.getByTestId('wizard-next').click();
    await page.waitForURL(new RegExp(`/app/design/${id}/brief$`));
    await page.getByTestId('brief-headline').fill('E2E 3-D Secure');
    await page.getByTestId('brief-key-message').fill('Checks the bank authentication round trip.');
    await expect(page.getByTestId('wizard-next')).toBeEnabled();
    await page.getByTestId('wizard-next').click();
    await page.waitForURL(new RegExp(`/app/design/${id}/confirm$`));

    // The mock gateway's authentication card needs 3-D Secure; the simulated bank approves it.
    await page.getByTestId('accept-terms').click();
    await fillCard(page, '4000 0025 0000 3155');
    await page.getByTestId('pay-button').click();

    await page.waitForURL(new RegExp(`/app/jobs/design/${id}$`));
    await expect(page.getByText('Your brief is with our studio')).toBeVisible();
});
