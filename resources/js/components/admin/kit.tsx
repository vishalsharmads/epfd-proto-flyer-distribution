import { Button } from '@/components/ui/button';
import { Field, Input, Select, Textarea } from '@/components/ui/form';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { ErrorState, Skeleton, SkeletonRows } from '@/components/ui/surface';
import { LazyAudienceBuilder, type AudienceBuilderProps } from '@/map';
import { cn, debounce } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import * as React from 'react';

/* ---------------------------------------------------------------- permissions */

export function useCan() {
    const { auth } = usePage<SharedProps>().props;
    return (perm?: string) => !perm || !!auth.user?.roles.includes('super_admin') || !!auth.user?.permissions.includes(perm);
}

/* ---------------------------------------------------------------- navigation state */

/** True while an Inertia visit (filter/search/pagination) is in flight — drives table skeletons. */
export function useNavigating() {
    const [busy, setBusy] = React.useState(false);
    React.useEffect(() => {
        const a = router.on('start', (e) => {
            if (e.detail.visit.method === 'get') setBusy(true);
        });
        const b = router.on('finish', () => setBusy(false));
        return () => {
            a();
            b();
        };
    }, []);
    return busy;
}

/** Merge query params into the current URL and visit (resets page). */
export function useQuery() {
    return React.useCallback((patch: Record<string, string | number | null | undefined>) => {
        const url = new URL(window.location.href);
        Object.entries(patch).forEach(([k, v]) => (v === null || v === undefined || v === '' ? url.searchParams.delete(k) : url.searchParams.set(k, String(v))));
        url.searchParams.delete('page');
        router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true, replace: true });
    }, []);
}

/* ---------------------------------------------------------------- filter bar */

export function SearchBox({ value, placeholder = 'Search…', param = 'q', className }: { value?: string | null; placeholder?: string; param?: string; className?: string }) {
    const setQuery = useQuery();
    const [v, setV] = React.useState(value ?? '');
    const push = React.useMemo(() => debounce((s: string) => setQuery({ [param]: s }), 350), [setQuery, param]);
    return (
        <div className={cn('relative w-full sm:max-w-xs', className)}>
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-subtle" aria-hidden />
            <Input
                type="search"
                aria-label={placeholder}
                placeholder={placeholder}
                value={v}
                onChange={(e) => {
                    setV(e.target.value);
                    push(e.target.value);
                }}
                className="pl-9"
            />
            {v && (
                <button type="button" aria-label="Clear search" className="absolute top-1/2 right-2 grid size-7 -translate-y-1/2 place-items-center rounded-full text-muted hover:bg-surface-2" onClick={() => (setV(''), setQuery({ [param]: null }))}>
                    <X className="size-3.5" />
                </button>
            )}
        </div>
    );
}

/** Pill filter chips (status etc.), URL-synced. */
export function FilterChips({ param, value, options, counts }: { param: string; value?: string | null; options: { value: string; label: string }[]; counts?: Record<string, number> }) {
    const setQuery = useQuery();
    const all = [{ value: '', label: 'All' }, ...options];
    return (
        <div role="group" aria-label={`Filter by ${param}`} className="flex flex-wrap gap-1.5">
            {all.map((o) => {
                const active = (value ?? '') === o.value;
                return (
                    <button
                        key={o.value || 'all'}
                        type="button"
                        aria-pressed={active}
                        onClick={() => setQuery({ [param]: o.value || null })}
                        className={cn('inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors', active ? 'border-ink-900 bg-ink-900 text-white dark:border-white dark:bg-white dark:text-ink-900' : 'border-border text-muted hover:border-border-strong hover:text-fg')}
                    >
                        {o.label}
                        {counts && o.value && counts[o.value] !== undefined && <span className={cn('tabular text-xs', active ? 'opacity-80' : 'text-subtle')}>{counts[o.value]}</span>}
                    </button>
                );
            })}
        </div>
    );
}

export function SelectFilter({ param, value, label, options }: { param: string; value?: string | null; label: string; options: { value: string; label: string }[] }) {
    const setQuery = useQuery();
    return (
        <Select aria-label={label} value={value ?? ''} onChange={(e) => setQuery({ [param]: e.target.value || null })} className="h-10 w-auto min-w-36">
            <option value="">{label}: all</option>
            {options.map((o) => (
                <option key={o.value} value={o.value}>
                    {o.label}
                </option>
            ))}
        </Select>
    );
}

export function DateRange({ from, to }: { from?: string | null; to?: string | null }) {
    const setQuery = useQuery();
    return (
        <div className="flex items-center gap-2">
            <Input type="date" aria-label="From date" value={from ?? ''} onChange={(e) => setQuery({ from: e.target.value || null })} className="h-10 w-auto" />
            <span className="text-sm text-subtle" aria-hidden>
                –
            </span>
            <Input type="date" aria-label="To date" value={to ?? ''} onChange={(e) => setQuery({ to: e.target.value || null })} className="h-10 w-auto" />
        </div>
    );
}

export function Toolbar({ children, className }: { children: React.ReactNode; className?: string }) {
    return <div className={cn('mb-4 flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center', className)}>{children}</div>;
}

/* ---------------------------------------------------------------- table */

export function Table({ children, className, busy, label }: { children: React.ReactNode; className?: string; busy?: boolean; label?: string }) {
    return (
        <div className={cn('relative overflow-hidden rounded-[14px] border border-border bg-surface', className)}>
            <div className="max-h-[70vh] overflow-auto">
                <table className={cn('w-full border-collapse text-sm transition-opacity', busy && 'opacity-50')} aria-label={label} aria-busy={busy || undefined}>
                    {children}
                </table>
            </div>
        </div>
    );
}

export function THead({ children }: { children: React.ReactNode }) {
    return (
        <thead className="sticky top-0 z-10 bg-surface-2/95 backdrop-blur">
            <tr className="border-b border-border">{children}</tr>
        </thead>
    );
}

export function Th({ children, className, numeric }: { children?: React.ReactNode; className?: string; numeric?: boolean }) {
    return (
        <th scope="col" className={cn('px-4 py-2.5 text-left text-xs font-semibold whitespace-nowrap text-muted', numeric && 'text-right', className)}>
            {children}
        </th>
    );
}

export function Td({ children, className, numeric, ...rest }: React.TdHTMLAttributes<HTMLTableCellElement> & { numeric?: boolean }) {
    return (
        <td className={cn('px-4 py-3 align-middle', numeric && 'tabular text-right whitespace-nowrap', className)} {...rest}>
            {children}
        </td>
    );
}

export function Tr({ children, className, onClick, href }: { children: React.ReactNode; className?: string; onClick?: () => void; href?: string }) {
    return (
        <tr
            className={cn('border-b border-border last:border-0', (onClick || href) && 'cursor-pointer hover:bg-surface-2/60', className)}
            onClick={href ? (e) => !(e.target as HTMLElement).closest('a,button,input,select') && router.visit(href) : onClick}
        >
            {children}
        </tr>
    );
}

export function TableSkeleton({ rows = 6 }: { rows?: number }) {
    return (
        <div className="rounded-[14px] border border-border p-4">
            <SkeletonRows rows={rows} />
        </div>
    );
}

/* ---------------------------------------------------------------- confirm dialog */

interface ConfirmProps {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    title: string;
    description?: React.ReactNode;
    confirmLabel?: string;
    tone?: 'primary' | 'danger' | 'ink';
    /** Show a note textarea; `required` forces input. */
    note?: { label: string; required?: boolean; placeholder?: string };
    children?: React.ReactNode;
    onConfirm: (note: string) => void;
    processing?: boolean;
    error?: string | null;
    testId?: string;
}

export function ConfirmDialog({ open, onOpenChange, title, description, confirmLabel = 'Confirm', tone = 'primary', note, children, onConfirm, processing, error, testId }: ConfirmProps) {
    const [text, setText] = React.useState('');
    const [touched, setTouched] = React.useState(false);
    React.useEffect(() => {
        if (open) {
            setText('');
            setTouched(false);
        }
    }, [open]);
    const missing = !!note?.required && text.trim().length < 3;
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent title={title} description={description}>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        setTouched(true);
                        if (!missing) onConfirm(text.trim());
                    }}
                    className="space-y-4"
                >
                    {children}
                    {note && (
                        <Field label={note.label} optional={!note.required} error={(touched && missing ? 'Please add a short note (3+ characters).' : null) ?? error}>
                            <Textarea rows={3} value={text} placeholder={note.placeholder} onChange={(e) => setText(e.target.value)} />
                        </Field>
                    )}
                    {!note && error && <p className="text-sm text-danger" role="alert">{error}</p>}
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" variant={tone === 'danger' ? 'danger' : tone} loading={processing} data-testid={testId ? `${testId}-confirm` : undefined}>
                            {confirmLabel}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Post an Inertia action with a confirm dialog. Returns [dialog element, open()] */
export function useAction() {
    const [processing, setProcessing] = React.useState(false);
    const [error, setError] = React.useState<string | null>(null);
    const run = (method: 'post' | 'put' | 'patch' | 'delete', url: string, data: Record<string, unknown> = {}, onSuccess?: () => void) => {
        setProcessing(true);
        setError(null);
        router[method](url, data as never, {
            preserveScroll: true,
            onError: (errs) => setError(Object.values(errs)[0] ?? 'Something went wrong.'),
            onSuccess: () => onSuccess?.(),
            onFinish: () => setProcessing(false),
        });
    };
    return { processing, error, setError, run };
}

/* ---------------------------------------------------------------- misc */

export function Dl({ items, className }: { items: [React.ReactNode, React.ReactNode][]; className?: string }) {
    return (
        <dl className={cn('grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-[minmax(120px,auto)_1fr]', className)}>
            {items.map(([k, v], i) => (
                <React.Fragment key={i}>
                    <dt className="text-muted">{k}</dt>
                    <dd className="min-w-0 break-words text-fg">{v ?? <span className="text-subtle">—</span>}</dd>
                </React.Fragment>
            ))}
        </dl>
    );
}

/** Read-only notice for roles that can view but not act. */
export function ReadOnlyNote({ show, children = 'You have read-only access to this page.' }: { show: boolean; children?: React.ReactNode }) {
    if (!show) return null;
    return <p className="mb-4 rounded-[10px] border border-border bg-surface-2 px-3 py-2 text-sm text-muted">{children}</p>;
}

/* ---------------------------------------------------------------- map */

class MapBoundary extends React.Component<{ children: React.ReactNode }, { error: boolean; key: number }> {
    state = { error: false, key: 0 };
    static getDerivedStateFromError() {
        return { error: true };
    }
    render() {
        if (this.state.error)
            return (
                <div className="grid h-full place-items-center p-6">
                    <ErrorState title="The map couldn't load" description="Check your connection and try again." onRetry={() => this.setState((s) => ({ error: false, key: s.key + 1 }))} />
                </div>
            );
        return <React.Fragment key={this.state.key}>{this.props.children}</React.Fragment>;
    }
}

/** Lazy AudienceBuilder (map agent) with skeleton + error retry. Give it a height via className. */
export function AdminMap({ className, ...props }: AudienceBuilderProps & { className?: string }) {
    return (
        <div className={cn('relative overflow-hidden rounded-[14px] border border-border bg-surface-2', className)}>
            <MapBoundary>
                <React.Suspense fallback={<Skeleton className="absolute inset-0 h-full rounded-none" />}>
                    <LazyAudienceBuilder {...props} className="absolute inset-0" />
                </React.Suspense>
            </MapBoundary>
        </div>
    );
}

/* ---------------------------------------------------------------- json diff */

type Json = unknown;
function flatten(obj: Json, prefix = '', out: Record<string, string> = {}): Record<string, string> {
    if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
        Object.entries(obj as Record<string, Json>).forEach(([k, v]) => flatten(v, prefix ? `${prefix}.${k}` : k, out));
    } else if (Array.isArray(obj) && obj.some((v) => v && typeof v === 'object')) {
        obj.forEach((v, i) => flatten(v, `${prefix}[${i}]`, out));
    } else {
        out[prefix || '(value)'] = JSON.stringify(obj);
    }
    return out;
}

/** before/after diff when the change has that shape; else a pretty key/value list. */
export function JsonDiff({ changes }: { changes: Record<string, Json> | null }) {
    if (!changes || Object.keys(changes).length === 0) return <p className="text-sm text-muted">No details recorded.</p>;
    if ('before' in changes && 'after' in changes) {
        const a = flatten(changes.before);
        const b = flatten(changes.after);
        const keys = Array.from(new Set([...Object.keys(a), ...Object.keys(b)])).filter((k) => a[k] !== b[k]);
        const rest = Object.fromEntries(Object.entries(changes).filter(([k]) => k !== 'before' && k !== 'after'));
        return (
            <div className="space-y-3">
                {keys.length === 0 ? (
                    <p className="text-sm text-muted">No field changed.</p>
                ) : (
                    <div className="overflow-x-auto rounded-[10px] border border-border font-mono text-xs">
                        {keys.map((k) => (
                            <div key={k} className="grid grid-cols-1 border-b border-border last:border-0 sm:grid-cols-[minmax(140px,30%)_1fr]">
                                <div className="bg-surface-2 px-3 py-2 font-semibold break-all">{k}</div>
                                <div className="px-3 py-2">
                                    {a[k] !== undefined && <div className="break-all text-danger line-through decoration-danger/50">− {a[k]}</div>}
                                    {b[k] !== undefined && <div className="break-all text-success">+ {b[k]}</div>}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
                {Object.keys(rest).length > 0 && <JsonDiff changes={rest} />}
            </div>
        );
    }
    const flat = flatten(changes);
    return (
        <div className="overflow-x-auto rounded-[10px] border border-border font-mono text-xs">
            {Object.entries(flat).map(([k, v]) => (
                <div key={k} className="grid grid-cols-1 border-b border-border last:border-0 sm:grid-cols-[minmax(140px,30%)_1fr]">
                    <div className="bg-surface-2 px-3 py-2 font-semibold break-all">{k}</div>
                    <div className="px-3 py-2 break-all">{v}</div>
                </div>
            ))}
        </div>
    );
}

export function dollars(cents: number | null | undefined): string {
    return ((cents ?? 0) / 100).toFixed(2);
}
