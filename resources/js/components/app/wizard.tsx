import { Button } from '@/components/ui/button';
import { Stepper } from '@/components/ui/navigation';
import { cn, money } from '@/lib/utils';
import type { Quote, QuoteLine } from '@/types';
import { Check, ChevronLeft, ChevronRight, CloudOff, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

export type SaveState = 'idle' | 'saving' | 'saved' | 'error';

export function SavedIndicator({ state, onRetry }: { state: SaveState; onRetry?: () => void }) {
    return (
        <span className="inline-flex min-h-6 items-center gap-1.5 text-xs text-muted" role="status" aria-live="polite">
            {state === 'saving' && (
                <>
                    <Loader2 className="size-3.5 animate-spin" aria-hidden /> Saving…
                </>
            )}
            {state === 'saved' && (
                <>
                    <Check className="size-3.5 text-success" aria-hidden /> Saved
                </>
            )}
            {state === 'error' && (
                <>
                    <CloudOff className="size-3.5 text-danger" aria-hidden /> Not saved
                    {onRetry && (
                        <button type="button" onClick={onRetry} className="font-medium text-fg underline underline-offset-2">
                            Retry
                        </button>
                    )}
                </>
            )}
        </span>
    );
}

/** Stepper header + sticky bottom Back / Next bar shared by both wizards. */
export function WizardShell({
    steps,
    current,
    onStep,
    children,
    back,
    next,
    nextLabel = 'Next',
    nextDisabled,
    nextHint,
    nextLoading,
    saveState,
    fill = false,
    hideNext = false,
}: {
    steps: string[];
    current: number;
    onStep?: (i: number) => void;
    children: ReactNode;
    back?: () => void;
    next?: () => void;
    nextLabel?: string;
    nextDisabled?: boolean;
    nextHint?: ReactNode;
    nextLoading?: boolean;
    saveState?: SaveState;
    fill?: boolean;
    hideNext?: boolean;
}) {
    return (
        <div className={cn('flex flex-col', fill ? 'h-[calc(100dvh-64px)] max-md:h-[calc(100dvh-108px)]' : 'min-h-[calc(100dvh-64px)]')}>
            <div className="border-b border-border bg-surface px-4 py-3.5">
                <Stepper steps={steps} current={current} onStep={onStep} />
            </div>
            <div className={cn('flex-1', fill ? 'relative min-h-0' : 'mx-auto w-full max-w-6xl px-4 py-8 pb-28 sm:px-6 lg:py-10')}>{children}</div>
            <div className={cn('z-30 border-t border-border bg-surface/95 backdrop-blur', fill ? '' : 'sticky bottom-0')}>
                <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3 sm:px-6">
                    {back ? (
                        <Button variant="ghost" onClick={back} data-testid="wizard-back">
                            <ChevronLeft /> Back
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="ml-auto flex items-center gap-4">
                        {saveState && <SavedIndicator state={saveState} />}
                        {nextHint && <span className="hidden text-sm text-muted sm:inline">{nextHint}</span>}
                        {!hideNext && (
                            <Button variant="ink" size="lg" onClick={next} disabled={nextDisabled} loading={nextLoading} data-testid="wizard-next">
                                {nextLabel} <ChevronRight />
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

/** Line items + Subtotal ex GST + GST 10% + Total incl. GST. */
export function PriceBreakdown({ quote, totalLabel = 'Total incl. GST', compact }: { quote: Pick<Quote, 'lines' | 'subtotal_cents' | 'gst_cents' | 'total_cents'> | null | undefined; totalLabel?: string; compact?: boolean }) {
    if (!quote || !quote.lines?.length) return <p className="text-sm text-muted">Pricing appears once your order has items.</p>;
    return (
        <dl className="space-y-2 text-sm">
            {!compact &&
                quote.lines.map((l: QuoteLine, i) => (
                    <div key={i} className="flex justify-between gap-4">
                        <dt className="text-muted">
                            {l.label}
                            {l.quantity > 1 && <span className="tabular block text-xs text-subtle">{new Intl.NumberFormat('en-AU').format(l.quantity)} × {l.unit_cents < 100 ? `${Math.round(l.unit_cents * 100) / 100}c` : money(l.unit_cents)} ex GST</span>}
                        </dt>
                        <dd className={cn('tabular shrink-0', l.amount_cents < 0 && 'text-success')}>{money(l.amount_cents)}</dd>
                    </div>
                ))}
            <div className="flex justify-between gap-4 border-t border-border pt-2">
                <dt className="text-muted">Subtotal (ex GST)</dt>
                <dd className="tabular">{money(quote.subtotal_cents)}</dd>
            </div>
            <div className="flex justify-between gap-4">
                <dt className="text-muted">GST (10%)</dt>
                <dd className="tabular">{money(quote.gst_cents)}</dd>
            </div>
            <div className="flex items-baseline justify-between gap-4 border-t border-border pt-3">
                <dt className="font-semibold">{totalLabel}</dt>
                <dd className="tabular text-xl font-semibold tracking-tight">{money(quote.total_cents)}</dd>
            </div>
        </dl>
    );
}

/**
 * Debounced JSON autosave. `save(patch)` merges patches and flushes after `delay` ms.
 * Returns state for the "Saved" indicator and the last server error.
 */
export function useAutosave<T>(send: (patch: Record<string, unknown>) => Promise<T>, onSaved: (res: T) => void, delay = 500) {
    const [state, setState] = useState<SaveState>('idle');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const pending = useRef<Record<string, unknown>>({});
    const timer = useRef<ReturnType<typeof setTimeout>>();
    const lastPatch = useRef<Record<string, unknown>>({});
    const inflight = useRef<Promise<boolean> | null>(null);

    const flush = useCallback(async (): Promise<boolean> => {
        const patch = pending.current;
        pending.current = {};
        if (!Object.keys(patch).length) return inflight.current ? inflight.current : true;
        lastPatch.current = patch;
        setState('saving');
        const p = send(patch)
            .then((res) => {
                onSaved(res);
                setErrors((e) => {
                    const n = { ...e };
                    Object.keys(patch).forEach((k) => delete n[k]);
                    return n;
                });
                setState('saved');
                return true;
            })
            .catch((err: { problem?: { errors?: Record<string, string[]>; detail?: string } }) => {
                const fe: Record<string, string> = {};
                Object.entries(err?.problem?.errors ?? {}).forEach(([k, v]) => (fe[k] = Array.isArray(v) ? v[0] : String(v)));
                if (!Object.keys(fe).length) fe._ = err?.problem?.detail ?? 'Could not save your changes.';
                setErrors((e) => ({ ...e, ...fe }));
                setState('error');
                return false;
            });
        inflight.current = p;
        return p;
    }, [send, onSaved]);

    const save = useCallback(
        (patch: Record<string, unknown>, immediate = false) => {
            pending.current = { ...pending.current, ...patch };
            if (timer.current) clearTimeout(timer.current);
            if (immediate) void flush();
            else timer.current = setTimeout(() => void flush(), delay);
        },
        [flush, delay],
    );

    /** Flush pending changes; resolves false if the save failed. */
    const settle = useCallback(async (): Promise<boolean> => {
        if (timer.current) clearTimeout(timer.current);
        if (inflight.current) await inflight.current;
        return flush();
    }, [flush]);

    const retry = useCallback(() => save(lastPatch.current, true), [save]);

    useEffect(() => () => void (timer.current && clearTimeout(timer.current)), []);

    return { state, errors, save, settle, retry, setErrors };
}

/** "edited 2 hours ago" */
export function ago(iso: string | null | undefined): string {
    if (!iso) return '';
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat('en-AU', { numeric: 'auto' });
    if (s < 60) return 'just now';
    if (s < 3600) return rtf.format(-Math.round(s / 60), 'minute');
    if (s < 86400) return rtf.format(-Math.round(s / 3600), 'hour');
    return rtf.format(-Math.round(s / 86400), 'day');
}

export const CAMPAIGN_STEPS = ['Campaign', 'Audience', 'Details', 'Confirm'];
export const PRINT_STEPS = ['Start', 'Product', 'Specs', 'Confirm'];
