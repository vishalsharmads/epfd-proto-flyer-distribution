import { expect, test } from '@playwright/test';
import { AUTH, readState } from './helpers/common';

test.use({ storageState: AUTH.business });

test('My Jobs lists the paid campaign; job page has timeline and invoice PDF @mobile', async ({ page }) => {
    const { campaignId, campaignName } = readState();
    test.skip(!campaignId || !campaignName, 'needs campaign-draw-modes.spec.ts to have run (project "flows")');

    await page.goto('/app/jobs');
    const row = page.getByTestId(`job-c${campaignId}`);
    await expect(row).toBeVisible();
    await expect(row).toContainText(campaignName!);
    await row.click();

    await page.waitForURL(new RegExp(`/app/jobs/campaigns/${campaignId}$`));
    await expect(page.getByRole('heading', { level: 1, name: campaignName })).toBeVisible();
    const timeline = page.getByRole('list', { name: 'Status timeline' });
    await expect(timeline).toBeVisible();
    await expect(timeline.getByRole('listitem').first()).toContainText('Submitted');

    const invoice = page.getByRole('link', { name: /Invoice PDF/ });
    await expect(invoice).toBeVisible();
    const href = await invoice.getAttribute('href');
    const res = await page.request.get(href!);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('application/pdf');
    expect((await res.body()).subarray(0, 5).toString()).toBe('%PDF-');
});
