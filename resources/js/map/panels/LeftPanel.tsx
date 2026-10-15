import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/form';
import { cn, km, num } from '@/lib/utils';
import { Building2, ChevronDown, House, Plus, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { MapThumbnail } from '../MapThumbnail';
import type { OverlayArea } from '../draw/DrawOverlay';

interface Props {
    areas: OverlayArea[];
    includeHouses: boolean;
    includeUnits: boolean;
    onToggle: (k: 'houses' | 'units', v: boolean) => void;
    busy: boolean;
    readOnly?: boolean;
    admin?: boolean;
    addAreaSlot: ReactNode;
    onCancel: () => void;
    onSelect: (key: string) => void;
    onDelete: (key: string) => void;
    totals: { letterboxes: number; distance_km: number };
    footer?: ReactNode;
}

/** "Set your audience" panel. Floating card on desktop; collapsible bottom sheet on phones. */
export function LeftPanel(p: Props) {
    const [collapsed, setCollapsed] = useState(false);
    return (
        <section
            aria-labelledby="audience-heading"
            className={cn(
                'pointer-events-auto flex flex-col overflow-hidden border-border bg-surface shadow-lg',
                'absolute inset-x-0 bottom-0 z-20 max-h-[55%] rounded-t-[20px] border-t',
                'md:inset-x-auto md:top-4 md:bottom-auto md:left-4 md:max-h-[calc(100%-5rem)] md:w-[340px] md:rounded-[16px] md:border',
            )}
            data-testid="left-panel"
        >
            <button type="button" className="mx-auto mt-2 h-1.5 w-10 rounded-full bg-border-strong md:hidden" onClick={() => setCollapsed((c) => !c)} aria-label={collapsed ? 'Expand audience panel' : 'Collapse audience panel'} aria-expanded={!collapsed} />
            <div className="flex items-start justify-between gap-2 px-5 pt-3 md:pt-5">
                <div>
                    <h2 id="audience-heading" className="text-lg font-semibold tracking-tight text-fg">
                        {p.readOnly ? 'Audience' : p.admin ? 'Split into runs' : 'Set your audience'}
                    </h2>
                    {!p.readOnly && <p className="mt-1 text-sm text-muted">{p.admin ? 'Draw one area per run. Each run is collected by a different distributor.' : 'Choose where and who to deliver to. Each area will be collected by a different distributor.'}</p>}
                </div>
                <button type="button" onClick={() => setCollapsed((c) => !c)} className="mt-1 grid size-8 shrink-0 place-items-center rounded-full text-muted hover:bg-surface-2 md:hidden" aria-label={collapsed ? 'Expand' : 'Collapse'}>
                    <ChevronDown className={cn('size-4 transition-transform', collapsed && 'rotate-180')} aria-hidden />
                </button>
            </div>
            <div className={cn('flex min-h-0 flex-col overflow-y-auto px-5 pb-5', collapsed && 'max-md:hidden')}>
                {!p.readOnly && (
                    <div className="mt-4 flex flex-col gap-3" data-tour="filter">
                        <ToggleRow icon={<House className="size-4" aria-hidden />} label="Include houses" checked={p.includeHouses} disabled={p.busy || (p.includeHouses && !p.includeUnits)} onChange={(v) => p.onToggle('houses', v)} />
                        <ToggleRow icon={<Building2 className="size-4" aria-hidden />} label="Include units" checked={p.includeUnits} disabled={p.busy || (p.includeUnits && !p.includeHouses)} onChange={(v) => p.onToggle('units', v)} />
                    </div>
                )}
                {p.areas.length > 0 && (
                    <ul className="mt-4 flex flex-col gap-1 border-t border-border pt-3" aria-label="Areas" data-tour="areas">
                        {p.areas.map((a) => (
                            <li key={a.key} data-testid="area-row" className="group flex items-center gap-3 rounded-[12px] p-1.5 hover:bg-surface-2">
                                <button type="button" disabled={p.busy || p.readOnly} onClick={() => p.onSelect(a.key)} className="flex min-w-0 flex-1 items-center gap-3 rounded-[8px] text-left focus-visible:outline-2 focus-visible:outline-focus disabled:cursor-default" aria-label={`Edit ${a.index + 1}. ${a.name}`}>
                                    <MapThumbnail polygons={[a.geometry]} colorIndex={a.index} size={40} />
                                    <span className="min-w-0">
                                        <span className="flex items-center gap-1.5 truncate text-sm font-semibold text-fg">
                                            <span className="size-2 shrink-0 rounded-full" style={{ background: a.color }} aria-hidden />
                                            {a.index + 1}. {a.name}
                                        </span>
                                        <span className="block text-xs text-muted">{a.estimate ? `${num(a.estimate.total)} letterboxes · ${km(a.estimate.distance_km)}` : 'Not estimated'}</span>
                                    </span>
                                </button>
                                {!p.readOnly && (
                                    <button type="button" disabled={p.busy} onClick={() => p.onDelete(a.key)} className="grid size-8 shrink-0 place-items-center rounded-full text-danger hover:bg-danger-soft focus-visible:outline-2 focus-visible:outline-focus disabled:opacity-40" aria-label={`Delete ${a.name}`}>
                                        <X className="size-4" aria-hidden />
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {!p.readOnly && (
                    <div className="mt-4 flex flex-col gap-2">
                        {p.busy ? (
                            <>
                                <Button variant="secondary" size="lg" disabled className="w-full">
                                    Editing area…
                                </Button>
                                <Button variant="ghost" size="md" onClick={p.onCancel} kbd="Esc" className="w-full" data-testid="cancel-button">
                                    Cancel
                                </Button>
                            </>
                        ) : (
                            p.addAreaSlot
                        )}
                    </div>
                )}
                {p.areas.length > 0 && (
                    <dl className="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-4 text-sm">
                        <div>
                            <dt className="text-xs text-muted">Estimated letterboxes</dt>
                            <dd className="font-semibold text-fg tabular-nums">{num(p.totals.letterboxes)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted">Total distance</dt>
                            <dd className="font-semibold text-fg tabular-nums">{km(p.totals.distance_km)}</dd>
                        </div>
                    </dl>
                )}
                {p.footer && <div className="mt-4">{p.footer}</div>}
            </div>
        </section>
    );
}

function ToggleRow({ icon, label, checked, disabled, onChange }: { icon: ReactNode; label: string; checked: boolean; disabled: boolean; onChange: (v: boolean) => void }) {
    const id = `toggle-${label.replace(/\s/g, '-').toLowerCase()}`;
    return (
        <div className="flex items-center justify-between gap-3">
            <label htmlFor={id} className="flex items-center gap-2 text-sm font-medium text-fg">
                <span className="text-muted">{icon}</span>
                {label}
            </label>
            <Switch id={id} checked={checked} disabled={disabled} onCheckedChange={onChange} data-testid={id} />
        </div>
    );
}

export function AddAreaButton(props: React.ComponentProps<typeof Button>) {
    return (
        <Button variant="secondary" size="lg" kbd="Space" className="w-full bg-surface-2" data-testid="add-area" data-tour="add-area" {...props}>
            <Plus aria-hidden /> Add area
        </Button>
    );
}
