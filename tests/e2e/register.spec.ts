import { expect, test } from '@playwright/test';
import { otpFromMailpit, writeState } from './helpers/common';

test('register a new business with G-NAF address and email OTP', async ({ page, request }) => {
    const stamp = Date.now();
    const email = `e2e+${stamp}@example.test`;
    const business = `E2E Bakery ${stamp}`;

    await page.goto('/register');
    await page.getByTestId('register-business-name').fill(business);
    await page.getByTestId('register-contact-name').fill('Erin Tester');
    await page.getByTestId('register-mobile').fill('0412 345 678');
    await page.getByTestId('register-email').fill(email);

    // G-NAF typeahead: wait for the search response, then pick the first hit.
    const address = page.getByTestId('register-address');
    const search = page.waitForResponse((r) => r.url().includes('/api/v1/addresses/search') && r.ok());
    await address.pressSequentially('257 Victoria Rd Rydalmere', { delay: 15 });
    await search;
    const option = page.getByRole('option', { name: /257 VICTORIA ROAD, RYDALMERE/ }).first();
    await option.click();
    await expect(page.getByText('Address verified with G-NAF')).toBeVisible();

    await page.getByTestId('register-industry').selectOption({ index: 1 });
    await page.getByTestId('register-password').fill('Secret123!');
    await page.getByTestId('register-password-confirmation').fill('Secret123!');
    await page.getByTestId('register-terms').click();
    await page.getByTestId('register-submit').click();

    await page.waitForURL(/\/verify/);
    await expect(page.getByText(email)).toBeVisible();

    const code = await otpFromMailpit(request, email);
    await page.getByTestId('otp-0').fill(code); // multi-digit input spreads across the boxes and auto-submits

    await page.waitForURL(/\/app(\/)?$/);
    await expect(page.getByText('Awaiting approval.')).toBeVisible();
    writeState({ registeredEmail: email, registeredBusiness: business });
});
