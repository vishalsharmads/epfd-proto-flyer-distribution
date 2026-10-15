import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';

/** Wizard stepper (Campaign → Audience → Details → Confirm), RealRun style. */
export function Stepper({ steps, current, onStep }: { steps: string[]; current: number; onStep?: (i: number) => void }) {
    return (
        <nav aria-label="Progress" className="w-full">
            <ol className="flex items-center justify-center gap-2 sm:gap-3">
                {steps.map((s, i) => {
                    const done = i < current;
                    const active = i === current;
                    const clickable = onStep && i < current;
                    const inner = (
                        <>
                            <span className={cn('grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold transition-colors', done && 'bg-ink-900 text-white dark:bg-brand-500 dark:text-ink-900', active && 'bg-ink-900 text-white ring-4 ring-ink-900/10 dark:bg-white dark:text-ink-900', !done && !active && 'border-2 border-border-strong text-subtle')}>
                                {done ? <Check className="size-3.5" strokeWidth={3} /> : i + 1}
                            </span>
                            <span className={cn('hidden text-sm font-semibold sm:inline', active || done ? 'text-fg' : 'text-subtle')}>{s}</span>
                        </>
                    );
                    return (
                        <li key={s} className="flex items-center gap-2 sm:gap-3">
                            {clickable ? (
                                <button type="button" onClick={() => onStep(i)} className="flex items-center gap-2 rounded-full focus-visible:outline-2" aria-label={`Back to step ${i + 1}: ${s}`}>
                                    {inner}
                                </button>
                            ) : (
                                <span className="flex items-center gap-2" aria-current={active ? 'step' : undefined}>
                                    {inner}
                                    <span className="sr-only sm:hidden">{s}</span>
                                </span>
                            )}
                            {i < steps.length - 1 && <span className={cn('h-px w-6 sm:w-16', done ? 'bg-ink-900 dark:bg-brand-500' : 'bg-border-strong')} aria-hidden />}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) return null;
    return (
        <nav aria-label="Pagination" className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
            <p className="text-muted">
                Showing {page.from}–{page.to} of {page.total}
            </p>
            <div className="flex flex-wrap gap-1">
                {page.links.map((l, i) =>
                    l.url ? (
                        <Link key={i} href={l.url} preserveScroll preserveState className={cn('min-w-9 rounded-[10px] border px-3 py-1.5 text-center', l.active ? 'border-ink-900 bg-ink-900 text-white dark:border-white dark:bg-white dark:text-ink-900' : 'border-border hover:bg-surface-2')} aria-current={l.active ? 'page' : undefined}>
                            <span dangerouslySetInnerHTML={{ __html: l.label }} />
                        </Link>
                    ) : (
                        <span key={i} className="min-w-9 rounded-[10px] border border-transparent px-3 py-1.5 text-center text-subtle" dangerouslySetInnerHTML={{ __html: l.label }} />
                    ),
                )}
            </div>
        </nav>
    );
}

/** Simple accessible tabs driven by a value (URL-synced by callers). */
export function Tabs({ tabs, value, onChange }: { tabs: { value: string; label: string; count?: number }[]; value: string; onChange: (v: string) => void }) {
    return (
        <div role="tablist" className="mb-6 flex gap-1 overflow-x-auto border-b border-border">
            {tabs.map((t) => (
                <button
                    key={t.value}
                    role="tab"
                    type="button"
                    aria-selected={value === t.value}
                    onClick={() => onChange(t.value)}
                    className={cn('-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors', value === t.value ? 'border-ink-900 text-fg dark:border-white' : 'border-transparent text-muted hover:text-fg')}
                >
                    {t.label}
                    {t.count !== undefined && <span className="rounded-full bg-surface-2 px-1.5 text-xs text-muted">{t.count}</span>}
                </button>
            ))}
        </div>
    );
}
