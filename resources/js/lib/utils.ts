import { clsx, type ClassValue } from 'clsx';
import { formatInTimeZone } from 'date-fns-tz';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}

export const TZ = 'Australia/Sydney';

/** AUD from integer cents, e.g. 186486 → "$1,864.86". */
export function money(cents: number | null | undefined, opts: { compact?: boolean } = {}): string {
    const v = (cents ?? 0) / 100;
    return new Intl.NumberFormat('en-AU', {
        style: 'currency',
        currency: 'AUD',
        notation: opts.compact ? 'compact' : 'standard',
        maximumFractionDigits: opts.compact ? 1 : 2,
        minimumFractionDigits: opts.compact ? 0 : 2,
    }).format(v);
}

/** Per-item price in cents → "89c" or "$1.22". */
export function perItem(cents: number | null | undefined): string {
    const c = cents ?? 0;
    if (c < 100) return `${Math.round(c * 10) / 10}c`.replace('.0c', 'c');
    return money(Math.round(c));
}

export function num(n: number | null | undefined): string {
    return new Intl.NumberFormat('en-AU').format(n ?? 0);
}

/** dd MMM yyyy in Australia/Sydney (data stored as UTC). */
export function date(value: string | Date | null | undefined, pattern = 'dd MMM yyyy'): string {
    if (!value) return '—';
    try {
        return formatInTimeZone(typeof value === 'string' ? new Date(value) : value, TZ, pattern);
    } catch {
        return '—';
    }
}

export function dateTime(value: string | Date | null | undefined): string {
    return date(value, 'dd MMM yyyy, h:mm a');
}

export function km(v: number | null | undefined): string {
    return `${(v ?? 0).toFixed(1)} km`;
}

export function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function debounce<A extends unknown[]>(fn: (...a: A) => void, ms: number) {
    let t: ReturnType<typeof setTimeout> | undefined;
    const d = (...a: A) => {
        if (t) clearTimeout(t);
        t = setTimeout(() => fn(...a), ms);
    };
    d.cancel = () => t && clearTimeout(t);
    return d;
}

/** XSRF-aware JSON fetch for in-page APIs (session auth). Throws the problem+json body on error. */
export async function api<T>(url: string, init: RequestInit & { json?: unknown } = {}): Promise<T> {
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const res = await fetch(url, {
        ...init,
        method: init.method ?? (init.json !== undefined ? 'POST' : 'GET'),
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(init.json !== undefined ? { 'Content-Type': 'application/json' } : {}),
            'X-XSRF-TOKEN': xsrf,
            'X-Requested-With': 'XMLHttpRequest',
            ...(init.headers ?? {}),
        },
        body: init.json !== undefined ? JSON.stringify(init.json) : init.body,
    });
    const body = res.status === 204 ? null : await res.json().catch(() => null);
    if (!res.ok) {
        throw new ApiError(res.status, body as Problem | null);
    }
    return body as T;
}

export interface Problem {
    type?: string;
    title?: string;
    status?: number;
    detail?: string;
    code?: string;
    errors?: Record<string, string[]>;
}

export class ApiError extends Error {
    constructor(
        public status: number,
        public problem: Problem | null,
    ) {
        super(problem?.detail ?? problem?.title ?? `Request failed (${status})`);
    }
}
