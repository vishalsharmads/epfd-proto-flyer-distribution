/**
 * JS access to the design tokens. Values are READ from resources/css/tokens.css at runtime
 * (CSS custom properties), so tokens.css stays the single source of truth for re-branding.
 * Fallbacks below are only used before CSS has loaded (e.g. in tests).
 */
const fallback = {
    '--brand-500': '#ff244a',
    '--brand-600': '#e00b33',
    '--ink-900': '#141414',
    '--accent-400': '#ffc446',
    '--map-pickup': '#2563eb',
    '--map-outline': '#141414',
    '--map-house': '#141414',
    '--map-unit': '#e00b33',
    '--color-surface': '#ffffff',
    '--color-danger': '#d1172e',
} as const;

export function token(name: string): string {
    if (typeof window !== 'undefined') {
        const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        if (v) return v;
    }
    return (fallback as Record<string, string>)[name] ?? '#000000';
}

/** Distinct, accessible colour per saved area (cycled). */
export function areaColor(index: number): string {
    return token(`--map-area-${(index % 8) + 1}`);
}

export const tokens = {
    get brand() {
        return token('--brand-500');
    },
    get primary() {
        return token('--color-primary');
    },
    get ink() {
        return token('--ink-900');
    },
    get accent() {
        return token('--accent-400');
    },
    get pickup() {
        return token('--map-pickup');
    },
    get outline() {
        return token('--map-outline');
    },
    get house() {
        return token('--map-house');
    },
    get unit() {
        return token('--map-unit');
    },
    get danger() {
        return token('--color-danger');
    },
    motion: { fast: 150, base: 200, slow: 250 },
};
