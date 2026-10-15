import { expect, test, type Page } from '@playwright/test';
import { AUTH, fillCard, parseNum, unique, writeState } from './helpers/common';
import { clickLngLat, ring, setView, skipTour, startArea, traceLoop, waitForMap } from './helpers/map';

test.use({ storageState: AUTH.business });

const RYDALMERE: [number, number] = [151.0325, -33.8135];
const ERMINGTON: [number, number] = [151.0603, -33.8096];

const isEstimate = (r: { url: () => string; request: () => { method: () => string } }) => r.url().includes('/api/v1/areas/estimate') && r.request().method() === 'POST';

/** Live stats inside the editing panel must show houses, units and a fee > 0. */
async function expectLiveStats(page: Page) {
    const panel = page.getByTestId('editing-panel');
    for (const id of ['stat-houses', 'stat-units', 'stat-fee'] as const) {
        await expect.poll(async () => parseNum(await panel.getByTestId(id).textContent()), { message: `${id} > 0` }).toBeGreaterThan(0);
    }
    await expect(page.getByTestId('done-button')).toBeEnabled();
}

async function finishArea(page: Page, expectedRows: number) {
    await page.getByTestId('done-button').click();
    await expect(page.getByTestId('area-row')).toHaveCount(expectedRows);
    const summary = page.getByTestId('summary-card');
    for (const id of ['stat-houses', 'stat-units', 'stat-fee'] as const) {
        expect(parseNum(await summary.getByTestId(id).textContent()), `summary ${id}`).toBeGreaterThan(0);
    }
}

test('campaign: three draw modes, details, declined then successful payment', async ({ page }) => {
    await skipTour(page);
    await page.goto('/app/campaigns/new');
    await page.getByTestId('start-new-campaign').click();
    await page.waitForURL(/\/app\/campaigns\/\d+\/audience/);
    const campaignId = Number(page.url().match(/campaigns\/(\d+)/)![1]);
    await waitForMap(page);
    await expect(page.getByTestId('map-tour')).toHaveCount(0);

    // (a) Outline with pins around Rydalmere, closed with Enter.
    await setView(page, RYDALMERE, 15);
    await startArea(page, 'pins');
    for (const p of ring(RYDALMERE, 0.004, 0.003, 5)) await clickLngLat(page, p);
    let est = page.waitForResponse((r) => isEstimate(r) && r.ok());
    await page.keyboard.press('Enter');
    await est;
    await expectLiveStats(page);
    await finishArea(page, 1);

    // (b) Freehand trace over Ermington (press-drag-release).
    await setView(page, ERMINGTON, 15);
    est = page.waitForResponse((r) => isEstimate(r) && r.ok());
    await traceLoop(page, ring(ERMINGTON, 0.004, 0.003, 10));
    await est;
    await expectLiveStats(page);
    await finishArea(page, 2);

    // (c) Suburb drop-in.
    await startArea(page, 'suburb');
    const search = page.getByTestId('suburb-search');
    const suburbs = page.waitForResponse((r) => r.url().includes('/api/v1/suburbs?') && r.ok());
    await search.fill('Dundas Valley');
    await suburbs;
    est = page.waitForResponse((r) => isEstimate(r) && r.ok());
    await page.getByRole('option', { name: /Dundas Valley/ }).click();
    await est;
    await expectLiveStats(page);
    await finishArea(page, 3);

    // Autosave of the three areas, then on to Details.
    await expect(page.getByTestId('wizard-next')).toBeEnabled();
    await page.getByTestId('wizard-next').click();
    await page.waitForURL(/\/details$/);

    const name = unique('E2E Campaign');
    const nameCheck = page.waitForResponse((r) => r.url().includes('/app/campaigns/name-check') && r.ok());
    await page.getByTestId('campaign-name').fill(name);
    await nameCheck;
    await expect(page.getByText(/available/i).first()).toBeVisible();

    const pickup = page.getByTestId('pickup-address');
    if (!(await pickup.inputValue())) {
        const s = page.waitForResponse((r) => r.url().includes('/api/v1/addresses/search') && r.ok());
        await pickup.pressSequentially('257 Victoria Rd Rydalmere', { delay: 15 });
        await s;
        await page.getByRole('option').first().click();
    }

    const save = () => page.waitForResponse((r) => r.url().endsWith(`/app/campaigns/${campaignId}`) && r.request().method() === 'PATCH' && r.ok());
    let saved = save();
    await page.locator('[data-testid^="material-"]').nth(1).click();
    await saved;
    saved = save();
    await page.locator('[data-testid^="coverage-"]').first().click();
    await saved;

    await expect(page.getByTestId('wizard-next')).toBeEnabled();
    await page.getByTestId('wizard-next').click();
    await page.waitForURL(/\/confirm$/);

    // Declined card first.
    await fillCard(page, '4000 0000 0000 0002');
    await page.getByTestId('accept-terms').click();
    await page.getByTestId('pay-button').click();
    await expect(page.getByText('Payment not completed')).toBeVisible();
    await expect(page).toHaveURL(/\/confirm$/);

    // Then the good card.
    await fillCard(page, '4242 4242 4242 4242');
    if ((await page.getByTestId('accept-terms').getAttribute('data-state')) !== 'checked') await page.getByTestId('accept-terms').click();
    await page.getByTestId('pay-button').click();
    await page.waitForURL(new RegExp(`/app/jobs/campaigns/${campaignId}$`));
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    await expect(page.locator('main').getByText('Submitted', { exact: true }).first()).toBeVisible();

    writeState({ campaignId, campaignName: name });
});
