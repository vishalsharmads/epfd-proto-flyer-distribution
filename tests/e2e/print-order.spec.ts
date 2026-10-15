import { expect, test, type Page } from '@playwright/test';
import { AUTH, FIXTURE_PNG, fillCard, writeState } from './helpers/common';

test.use({ storageState: AUTH.business });

const patched = (page: Page, id: number) => page.waitForResponse((r) => r.url().endsWith(`/app/print/${id}`) && r.request().method() === 'PATCH' && r.ok());

test('print order: A5 flyers, specs, PNG artwork, confirm and pay', async ({ page }) => {
    await page.goto('/app/print/new');
    await page.getByTestId('start-new-print').click();
    await page.waitForURL(/\/app\/print\/\d+\/product$/);
    const orderId = Number(page.url().match(/print\/(\d+)/)![1]);

    await page.getByTestId('product-a5-flyers').click();
    await page.waitForURL(new RegExp(`/app/print/${orderId}/specs$`));
    await expect(page.getByRole('heading', { level: 1, name: 'A5 Flyers' })).toBeVisible();

    const gsm = page.locator('[data-testid^="gsm-"]').first();
    let saved: Promise<unknown>;
    if (!(await gsm.locator('input').isChecked())) {
        saved = patched(page, orderId);
        await gsm.click();
        await saved;
    }
    if (!(await page.getByTestId('sides-1').locator('input').isChecked())) {
        saved = patched(page, orderId);
        await page.getByTestId('sides-1').click();
        await saved;
    }
    const qty = page.locator('[data-testid^="qty-"]').first();
    if (!(await qty.locator('input').isChecked())) {
        saved = patched(page, orderId);
        await qty.click();
        await saved;
    }

    const upload = page.waitForResponse((r) => r.url().endsWith(`/app/print/${orderId}/artworks`) && r.request().method() === 'POST');
    await page.getByTestId('artwork-input').setInputFiles(FIXTURE_PNG);
    expect((await upload).status()).toBe(201);
    await expect(page.getByTestId('wizard-next')).toBeEnabled();
    await page.getByTestId('wizard-next').click();
    await page.waitForURL(new RegExp(`/app/print/${orderId}/confirm$`));

    // Delivery details are prefilled from the business profile; make sure they're valid.
    if ((await page.getByTestId('delivery-phone').inputValue()).replace(/\D/g, '').length < 8) await page.getByTestId('delivery-phone').fill('0412 345 678');
    if ((await page.getByTestId('delivery-name').inputValue()).trim().length < 2) await page.getByTestId('delivery-name').fill('Erin Tester');

    await page.getByTestId('artwork-checked').click();
    await page.getByTestId('right-to-print').click();
    await page.getByTestId('accept-terms').click();
    await fillCard(page, '4242 4242 4242 4242');
    await expect(page.getByTestId('pay-button')).toBeEnabled();
    await page.getByTestId('pay-button').click();

    await page.waitForURL(new RegExp(`/app/jobs/print/${orderId}$`));
    await expect(page.locator('main').getByText('Submitted', { exact: true }).first()).toBeVisible();
    writeState({ printOrderId: orderId });
});
