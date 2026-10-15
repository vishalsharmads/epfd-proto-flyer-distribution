import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/surface';
import { cn, km, money, num, perItem } from '@/lib/utils';
import type { LngLat } from '@/types';
import { Building2, ChevronDown, House, Loader2, MapPinned, Minus, Pencil, Plus, Receipt, Shapes } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { EstimateState } from '../hooks/estimate';

const shell = 'pointer-events-auto rounded-[16px] border border-border bg-surface shadow-lg';

function Row({ icon, label, value, testId, sub, strong }: { icon?: ReactNode; label: string; value: ReactNode; testId?: string; sub?: ReactNode; strong?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-3 py-1.5">
            <div className="flex items-start gap-2">
                {icon && <span className="mt-0.5 text-muted">{icon}</span>}
                <div>
                    <p className={cn('text-sm', strong ? 'font-semibold text-fg' : 'text-fg')}>{label}</p>
                    {sub && <p className="text-xs text-muted">{sub}</p>}
                </div>
            </div>
            <p className={cn('text-sm tabular-nums', strong ? 'text-lg font-semibold text-fg' : 'font-medium text-fg')} data-testid={testId}>
                {value}
            </p>
        </div>
    );
}

export interface Summary {
    houses: number;
    units: number;
    letterboxes: number;
    distance_km: number;
    fee_cents: number | null;
    per_item_cents: number | null;
}

/** Totals summary card (shown when not editing; screenshot 03). */
export function SummaryCard({ s, admin }: { s: Summary; admin?: boolean }) {
    return (
        <div className={cn(shell, 'max-h-full min-h-0 w-full overflow-y-auto p-4 md:w-[300px]')} data-testid="summary-card">
            <StatsBlock s={s} admin={admin} />
        </div>
    );
}

function StatsBlock({ s, admin, loading }: { s: Summary; admin?: boolean; loading?: boolean }) {
    const v = (n: ReactNode) => (loading ? <span className="skeleton inline-block h-4 w-10 rounded" aria-hidden /> : n);
    return (
        <div aria-busy={loading || undefined}>
            <div className="flex items-baseline justify-between rounded-[12px] bg-surface-2 px-3 py-2.5">
                <p className="text-sm font-medium text-fg">
                    Total letterboxes <span className="text-xs font-normal text-muted">(estimated)</span>
                </p>
                <p className="text-2xl font-semibold tracking-tight text-fg tabular-nums" data-testid="stat-total">
                    {v(num(s.letterboxes))}
                </p>
            </div>
            <p className="mt-3 text-xs font-semibold text-muted">Audience breakdown · Estimated</p>
            <div className="divide-y divide-border">
                <Row icon={<House className="size-4" aria-hidden />} label="Houses" value={v(num(s.houses))} testId="stat-houses" />
                <Row icon={<Building2 className="size-4" aria-hidden />} label="Units" value={v(num(s.units))} testId="stat-units" />
                <Row icon={<Shapes className="size-4" aria-hidden />} label="Distance" sub="Estimated walking route" value={v(km(s.distance_km))} testId="stat-distance" />
                {!admin && (
                    <Row
                        icon={<Receipt className="size-4" aria-hidden />}
                        label="Distribution fee"
                        sub={s.per_item_cents != null ? `Price per item · ${perItem(s.per_item_cents)} · incl. GST` : 'Incl. GST'}
                        value={v(s.fee_cents != null ? money(s.fee_cents) : '—')}
                        testId="stat-fee"
                        strong
                    />
                )}
            </div>
        </div>
    );
}

interface EditingProps {
    name: string;
    onRename: (n: string) => void;
    est: EstimateState;
    phase: 'drawing' | 'tracing' | 'suburb' | 'editing';
    pointsCount: number;
    canDone: boolean;
    admin?: boolean;
    approximate?: boolean;
    onDone: () => void;
    onRedraw: () => void;
    drawModeSlot: ReactNode;
    suburbSlot?: ReactNode;
    coords: LngLat[];
    onCoordsChange: (pts: LngLat[]) => void;
}

export function EditingPanel(p: EditingProps) {
    const [renaming, setRenaming] = useState(false);
    const [draft, setDraft] = useState(p.name);
    const inputRef = useRef<HTMLInputElement>(null);
    useEffect(() => setDraft(p.name), [p.name]);
    useEffect(() => {
        if (renaming) inputRef.current?.select();
    }, [renaming]);
    const commitName = () => {
        const n = draft.trim();
        if (n) p.onRename(n.slice(0, 60));
        else setDraft(p.name);
        setRenaming(false);
    };

    const e = p.est.data?.estimate;
    const q = p.est.data?.quote;
    const s: Summary = {
        houses: e?.houses ?? 0,
        units: e?.units ?? 0,
        letterboxes: e?.total ?? 0,
        distance_km: e?.distance_km ?? 0,
        fee_cents: q?.total_cents ?? null,
        per_item_cents: q?.per_item_incl_gst_cents ?? null,
    };

    return (
        <div className={cn(shell, 'flex max-h-full w-full flex-col overflow-hidden md:w-[320px]')} data-testid="editing-panel">
            <div className="overflow-y-auto p-4">
                <p className="text-[11px] font-semibold tracking-[0.12em] text-primary uppercase">Editing</p>
                <div className="mt-1 flex items-center gap-2">
                    {renaming ? (
                        <input
                            ref={inputRef}
                            value={draft}
                            aria-label="Area name"
                            maxLength={60}
                            onChange={(ev) => setDraft(ev.target.value)}
                            onBlur={commitName}
                            onKeyDown={(ev) => {
                                if (ev.key === 'Enter') {
                                    ev.preventDefault();
                                    commitName();
                                } else if (ev.key === 'Escape') {
                                    ev.stopPropagation();
                                    setDraft(p.name);
                                    setRenaming(false);
                                }
                            }}
                            className="h-9 min-w-0 flex-1 rounded-[10px] border border-border-strong bg-surface px-2.5 text-base font-semibold text-fg focus:border-primary focus:ring-4 focus:ring-primary/15 focus:outline-none"
                            data-testid="area-name-input"
                        />
                    ) : (
                        <>
                            <h3 className="min-w-0 truncate text-lg font-semibold tracking-tight text-fg">{p.name}</h3>
                            <button type="button" onClick={() => setRenaming(true)} className="grid size-8 place-items-center rounded-full text-muted hover:bg-surface-2 hover:text-fg focus-visible:outline-2 focus-visible:outline-focus" aria-label="Rename area" data-testid="rename-area">
                                <Pencil className="size-4" aria-hidden />
                            </button>
                        </>
                    )}
                </div>
                <div className="mt-2">{p.drawModeSlot}</div>

                {p.phase === 'suburb' && p.suburbSlot && <div className="mt-3">{p.suburbSlot}</div>}
                {p.approximate && p.phase === 'editing' && (
                    <p className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-warning-soft px-2.5 py-1 text-xs font-medium text-fg" data-testid="approximate-chip">
                        <MapPinned className="size-3.5" aria-hidden /> Approximate boundary — adjust the pins if needed
                    </p>
                )}

                {p.phase === 'editing' && (
                    <div className="mt-3">
                        {p.est.error ? (
                            <Alert tone="danger" title="This area can't be used yet" className="mb-3">
                                {p.est.error}
                            </Alert>
                        ) : e?.no_letterboxes ? (
                            <Alert
                                tone="danger"
                                title="No letterboxes detected"
                                className="mb-3"
                                action={
                                    <Button size="sm" variant="secondary" onClick={p.onRedraw}>
                                        Edit
                                    </Button>
                                }
                            >
                                No letterboxes could be retrieved so fees are unavailable. Redraw to try again.
                            </Alert>
                        ) : null}
                        <div className="relative">
                            {p.est.loading && (
                                <span className="absolute -top-1 right-0 inline-flex items-center gap-1 text-xs text-muted" role="status">
                                    <Loader2 className="size-3.5 animate-spin" aria-hidden /> Updating…
                                </span>
                            )}
                            <StatsBlock s={s} admin={p.admin} loading={p.est.loading && !p.est.data} />
                            {!p.admin && p.est.data && !p.est.data.fee_available && !e?.no_letterboxes && p.est.data.message && <p className="mt-2 text-xs text-muted">{p.est.data.message}</p>}
                        </div>
                        <CoordinateEditor points={p.coords} onChange={p.onCoordsChange} />
                    </div>
                )}
                {p.phase === 'drawing' && p.pointsCount > 0 && <p className="mt-3 text-sm text-muted">{p.pointsCount} pin{p.pointsCount === 1 ? '' : 's'} placed. Close the area to see the estimate.</p>}
            </div>
            {p.phase === 'editing' && (
                <div className="border-t border-border p-3">
                    <Button variant="ink" size="lg" kbd="Enter" className="w-full" disabled={!p.canDone} onClick={p.onDone} data-testid="done-button" data-tour="done">
                        Done
                    </Button>
                </div>
            )}
        </div>
    );
}

/** Keyboard / screen-reader path: edit vertices as numbers. */
function CoordinateEditor({ points, onChange }: { points: LngLat[]; onChange: (p: LngLat[]) => void }) {
    const [open, setOpen] = useState(false);
    const set = (i: number, axis: 0 | 1, v: string) => {
        const n = Number(v);
        if (!Number.isFinite(n)) return;
        const next = points.map((p) => [p[0], p[1]] as LngLat);
        next[i][axis] = n;
        onChange(next);
    };
    return (
        <div className="mt-3 border-t border-border pt-2">
            <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} aria-controls="coord-editor" className="flex w-full items-center justify-between rounded-[8px] py-1.5 text-sm font-medium text-fg focus-visible:outline-2 focus-visible:outline-focus" data-testid="edit-coordinates">
                Edit coordinates
                <ChevronDown className={cn('size-4 text-muted transition-transform', open && 'rotate-180')} aria-hidden />
            </button>
            {open && (
                <div id="coord-editor" className="mt-1 flex flex-col gap-1.5">
                    {points.map((p, i) => (
                        <fieldset key={i} className="flex items-center gap-1.5">
                            <legend className="sr-only">Pin {i + 1}</legend>
                            <span className="w-5 text-xs text-muted tabular-nums" aria-hidden>
                                {i + 1}
                            </span>
                            <input type="number" step="0.00001" aria-label={`Pin ${i + 1} longitude`} value={p[0]} onChange={(e) => set(i, 0, e.target.value)} className="h-8 min-w-0 flex-1 rounded-[8px] border border-border-strong bg-surface px-2 text-xs text-fg tabular-nums focus:border-primary focus:outline-none" />
                            <input type="number" step="0.00001" aria-label={`Pin ${i + 1} latitude`} value={p[1]} onChange={(e) => set(i, 1, e.target.value)} className="h-8 min-w-0 flex-1 rounded-[8px] border border-border-strong bg-surface px-2 text-xs text-fg tabular-nums focus:border-primary focus:outline-none" />
                            <button
                                type="button"
                                aria-label={`Add pin after pin ${i + 1}`}
                                onClick={() => {
                                    const b = points[(i + 1) % points.length];
                                    const next = points.slice();
                                    next.splice(i + 1, 0, [(p[0] + b[0]) / 2, (p[1] + b[1]) / 2]);
                                    onChange(next);
                                }}
                                className="grid size-7 place-items-center rounded-full text-muted hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-focus"
                            >
                                <Plus className="size-3.5" aria-hidden />
                            </button>
                            <button type="button" aria-label={`Remove pin ${i + 1}`} disabled={points.length <= 3} onClick={() => onChange(points.filter((_, j) => j !== i))} className="grid size-7 place-items-center rounded-full text-muted hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-focus disabled:opacity-30">
                                <Minus className="size-3.5" aria-hidden />
                            </button>
                        </fieldset>
                    ))}
                </div>
            )}
        </div>
    );
}
