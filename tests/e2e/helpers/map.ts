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

/** Centre the map deterministically (no fly animation). */
export async function setView(page: Page, center: LngLat, zoom: number): Promise<void> {
    await page.evaluate(([c, z]) => (window as unknown as { __epfdMap: { setView: (c: [number, number], z: number) => void } }).__epfdMap.setView(c as [number, number], z as number), [center, zoom] as const);
    // Wait until the map reports the requested zoom (setView may be applied on the next frame).
    await page.waitForFunction((z) => Math.abs((window as unknown as { __epfdMap: { getZoom: () => number } }).__epfdMap.getZoom() - z) < 0.01, zoom);
}

/** Open "Add area" and choose a draw mode. */
export async function startArea(page: Page, mode: 'pins' | 'trace' | 'suburb'): Promise<void> {
    await page.getByTestId('add-area').click();
    await page.getByTestId(`draw-mode-${mode}`).click();
}

/** Freehand trace: press, drag through a closed loop of geographic points with many steps, release. */
export async function traceLoop(page: Page, loop: LngLat[], stepsPerSegment = 12): Promise<void> {
    await startArea(page, 'trace');
    await page.getByTestId('trace-surface').waitFor();
    const px = [];
    for (const ll of loop) px.push(await project(page, ll));
    await page.mouse.move(px[0].x, px[0].y);
    await page.mouse.down();
    for (let i = 1; i <= px.length; i++) {
        const q = px[i % px.length];
        await page.mouse.move(q.x, q.y, { steps: stepsPerSegment });
    }
    await page.mouse.up();
}

/** Ring of `n` points around a centre (degrees). */
export function ring(center: LngLat, rLng: number, rLat: number, n: number): LngLat[] {
    return Array.from({ length: n }, (_, i) => {
        const a = (2 * Math.PI * i) / n;
        return [center[0] + rLng * Math.cos(a), center[1] + rLat * Math.sin(a)] as LngLat;
    });
}
