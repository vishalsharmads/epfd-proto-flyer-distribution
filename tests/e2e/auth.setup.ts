import { test as setup } from '@playwright/test';
import { AUTH, login } from './helpers/common';

setup('log in demo business', async ({ page }) => {
    await login(page, 'business');
    await page.waitForURL(/\/app/);
    await page.context().storageState({ path: AUTH.business });
});

setup('log in admin', async ({ page }) => {
    await login(page, 'admin', '/admin/login');
    await page.waitForURL(/\/admin(?!\/login)/);
    await page.context().storageState({ path: AUTH.admin });
});
