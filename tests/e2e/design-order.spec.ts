import { expect, test, type Page } from '@playwright/test';
import { AUTH, FIXTURE_PNG, fillCard, writeState } from './helpers/common';

test.use({ storageState: AUTH.business });

const patched = (page: Page, id: number) => page.waitForResponse((r) => r.url().endsWith(`/app/design/${id}`) && r.request().method() === 'PATCH' && r.ok());

test('design order: flyer service, brief with upload, pay, job shows Submitted', async ({ page }) => {
    await page.goto('/app/design/new');
    await page.getByTestId('start-new-design').click();
    await page.waitForURL(/\/app\/design\/\d+\/service$/);
    const id = Number(page.url().match(/design\/(\d+)/)![1]);

    // Service: flyer (default) + Basic package, single-sided.
    await expect(page.getByRole('heading', { level: 1, name: 'What should we design?' })).toBeVisible();
    if (!(await page.getByTestId('type-flyer').locator('input').isChecked())) {
        const saved = patched(page, id);
        await page.getByTestId('type-flyer').click();
        await saved;
    }
    const saved = patched(page, id);
    await page.getByTestId('package-basic').click();
    await saved;
    await expect(page.getByLabel('Price').getByText('Flyer design — Basic package, single-sided')).toBeVisible();
    await page.getByTestId('wizard-next').click();
    await page.waitForURL(new RegExp(`/app/design/${id}/brief$`));

    // Brief: headline + key message autosave, plus a reference image.
    await expect(page.getByTestId('wizard-next')).toBeDisabled();
    await page.getByTestId('brief-headline').fill('E2E grand opening');
    await page.getByTestId('brief-key-message').fill('Free coffee for the first 100 customers this Saturday.');
    const upload = page.waitForResponse((r) => r.url().endsWith(`/app/design/${id}/assets`) && r.request().method() === 'POST');
    await page.getByTestId('brief-files-input').setInputFiles(FIXTURE_PNG);
    expect((await upload).status()).toBe(201);
    await expect(page.getByRole('list', { name: 'Uploaded files' }).getByRole('listitem')).toHaveCount(1);
    await expect(page.getByTestId('wizard-next')).toBeEnabled();
    await page.getByTestId('wizard-next').click();
    await page.waitForURL(new RegExp(`/app/design/${id}/confirm$`));

    // Confirm & pay with the mock gateway.
    await expect(page.getByRole('heading', { level: 1, name: 'Confirm & pay' })).toBeVisible();
    await page.getByTestId('accept-terms').click();
    await fillCard(page, '4242 4242 4242 4242');
    await expect(page.getByTestId('pay-button')).toBeEnabled();
    await page.getByTestId('pay-button').click();

    await page.waitForURL(new RegExp(`/app/jobs/design/${id}$`));
    await expect(page.getByRole('list', { name: 'Status timeline' }).getByText('Submitted', { exact: false }).first()).toBeVisible();
    await expect(page.locator('main').getByText('Submitted', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Your brief is with our studio')).toBeVisible();
    writeState({ designOrderId: id });
});
