import { expect, type APIRequestContext, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

export const E2E_DIR = path.resolve(import.meta.dirname, '..');
export const AUTH = {
    business: path.join(E2E_DIR, '.auth', 'business.json'),
    admin: path.join(E2E_DIR, '.auth', 'admin.json'),
};
const STATE_FILE = path.join(E2E_DIR, '.state', 'run.json');
export const FIXTURE_PNG = path.join(E2E_DIR, 'fixtures', 'artwork.png');

export const USERS = {
    business: { email: 'demo@business.test', password: 'password' },
    admin: { email: 'admin@epfd.test', password: 'password' },
};

/** Cross-spec hand-off (flows → followups). */
export interface RunState {
    registeredEmail?: string;
    registeredBusiness?: string;
    campaignId?: number;
    campaignName?: string;
    printOrderId?: number;
}

export function readState(): RunState {
    try {
        return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')) as RunState;
    } catch {
        return {};
    }
}

export function writeState(patch: RunState): void {
    fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
    fs.writeFileSync(STATE_FILE, JSON.stringify({ ...readState(), ...patch }, null, 2));
}

export const unique = (prefix: string) => `${prefix} ${Date.now().toString(36)}${Math.floor(Math.random() * 1e4)}`;

export async function login(page: Page, who: keyof typeof USERS, url = '/login'): Promise<void> {
    await page.goto(url);
    await page.locator('input[name="email"]').fill(USERS[who].email);
    await page.locator('input[name="password"]').fill(USERS[who].password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((u) => !u.pathname.endsWith('/login'));
}

/** Parse "1,234" / "$1,234.56" → number. */
export const parseNum = (s: string | null) => Number((s ?? '').replace(/[^\d.]/g, '')) || 0;

/** Poll Mailpit for the newest message to `to` and return the 6-digit OTP in it. */
export async function otpFromMailpit(request: APIRequestContext, to: string): Promise<string> {
    const base = process.env.MAILPIT_URL ?? 'http://127.0.0.1:8025';
    let code = '';
    await expect
        .poll(
            async () => {
                const list = await (await request.get(`${base}/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}`)).json();
                const msg = list.messages?.[0];
                if (!msg) return '';
                const full = await (await request.get(`${base}/api/v1/message/${msg.ID}`)).json();
                const m = `${full.Subject ?? ''}\n${full.Text ?? ''}`.match(/\b(\d{6})\b/) ?? String(full.HTML ?? '').match(/\b(\d{6})\b/);
                code = m?.[1] ?? '';
                return code;
            },
            { timeout: 20_000, intervals: [500, 1000] },
        )
        .toMatch(/^\d{6}$/);
    return code;
}

/** Fill the shared PaymentForm with a new card and pay. */
export async function fillCard(page: Page, number: string): Promise<void> {
    const newCard = page.getByRole('radio', { name: 'New card' });
    if (await newCard.count()) await newCard.check();
    await page.getByTestId('card-number').fill(number);
    await page.getByTestId('card-expiry').fill('12/34');
    await page.getByTestId('card-cvc').fill('123');
    await page.getByTestId('card-name').fill('E2E Tester');
}
