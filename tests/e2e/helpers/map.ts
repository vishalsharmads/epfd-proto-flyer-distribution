import type { Page } from '@playwright/test';

type LngLat = [number, number];

/** Wait until the audience builder map is ready (window.__epfdMap exposed). */
export async function waitForMap(page: Page): Promise<void> {
    await page.waitForFunction(() => !!(window as unknown as { __epfdMap?: unknown }).__epfdMap, undefined, { timeout: 20_000 });
}

/** Geographic coordinate → viewport pixel. */
export async function project(page: Page, ll: LngLat): Promise<{ x: number; y: number }> {
    return page.evaluate((p) => (window as unknown as { __epfdMap: { project: (l: [number, number]) => { x: number; y: number } } }).__epfdMap.project(p), ll);
}

export async function clickLngLat(page: Page, ll: LngLat): Promise<void> {
    const q = await project(page, ll);
    await page.mouse.click(q.x, q.y);
}

/** Add an area in "Outline with pins" mode and close it with Enter. */
export async function drawPins(page: Page, points: LngLat[]): Promise<void> {
    await page.getByTestId('add-area').click();
    await page.getByTestId('draw-mode-pins').click();
    for (const p of points) await clickLngLat(page, p);
    await page.keyboard.press('Enter');
}

/** Skip the first-run tour for a clean test. Call before navigation via addInitScript. */
export async function skipTour(page: Page): Promise<void> {
    await page.addInitScript(() => {
        try {
            localStorage.setItem('epfd-map-tour-done', '1');
        } catch {
            /* ignore */
        }
    });
}
