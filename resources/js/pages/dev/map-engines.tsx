import { LazyAudienceBuilder, type AudienceState } from '@/map';
import { cn, money, num } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { Suspense, useState, type ReactNode } from 'react';

type Engine = 'maplibre' | 'google';
type DrawEngine = 'terra' | 'overlay';

/** Richmond VIC: where the local G-NAF sample has the densest addresses, so estimates are real. */
const PICKUP = { lng: 145.0, lat: -37.818, label: 'Richmond' };

function Segmented<T extends string>({ label, value, options, onChange }: { label: string; value: T; options: { value: T; label: string; disabled?: string }[]; onChange: (v: T) => void }) {
    return (
        <div className="flex items-center gap-2" role="group" aria-label={label}>
            <span className="text-xs text-muted">{label}</span>
            <div className="flex rounded-full border border-border bg-surface-2 p-0.5">
                {options.map((o) => (
                    <button
                        key={o.value}
                        type="button"
                        disabled={!!o.disabled}
                        title={o.disabled}
                        aria-pressed={value === o.value}
                        onClick={() => onChange(o.value)}
                        className={cn(
                            'rounded-full px-3 py-1 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-40',
                            value === o.value ? 'bg-surface text-fg shadow-sm' : 'text-muted hover:text-fg',
                        )}
                    >
                        {o.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

function Tab({ href, active, children }: { href: string; active?: boolean; children: ReactNode }) {
    return (
        <a href={href} aria-current={active ? 'page' : undefined} className={cn('rounded-full px-3 py-1.5 text-sm font-medium', active ? 'bg-primary text-primary-fg' : 'text-muted hover:text-fg')}>
            {children}
        </a>
    );
}

/** Dev-only POC: the real audience builder with a switchable map engine and drawing engine (Terra Draw vs our SVG overlay). */
export default function MapEngines() {
    const { map: cfg } = usePage<SharedProps>().props;
    const [engine, setEngine] = useState<Engine>(cfg.google_key ? cfg.provider : 'maplibre');
    const [drawEngine, setDrawEngine] = useState<DrawEngine>(cfg.draw_engine);
    const [state, setState] = useState<AudienceState | null>(null);

    return (
        <>
            <Head title="Map engines POC" />
            <div className="flex h-dvh flex-col bg-bg">
                <header className="flex shrink-0 flex-wrap items-center gap-x-6 gap-y-2 border-b border-border bg-surface px-4 py-2">
                    <nav className="flex items-center gap-1" aria-label="POC sections">
                        <Tab href="/dev/map-engines" active>
                            Live POC
                        </Tab>
                        <Tab href="/dev/map-engines?tab=compare">Comparison, costs, pros &amp; cons</Tab>
                    </nav>
                    <Segmented<Engine>
                        label="Map"
                        value={engine}
                        onChange={setEngine}
                        options={[
                            { value: 'maplibre', label: 'MapLibre + OSM' },
                            { value: 'google', label: 'Google Maps', disabled: cfg.google_key ? undefined : 'Set GOOGLE_MAPS_KEY in .env to enable' },
                        ]}
                    />
                    <Segmented<DrawEngine>
                        label="Drawing"
                        value={drawEngine}
                        onChange={setDrawEngine}
                        options={[
                            { value: 'terra', label: 'Terra Draw' },
                            { value: 'overlay', label: 'Current (SVG overlay)' },
                        ]}
                    />
                    <span className="ml-auto text-sm text-muted tabular-nums" data-testid="preview-state">
                        {state ? `${state.areas.length} areas · ${num(state.totals.letterboxes)} letterboxes${state.totals.fee_cents != null ? ` · ${money(state.totals.fee_cents)}` : ''}` : 'Add an area to start'}
                    </span>
                </header>
                <main className="relative min-h-0 flex-1">
                    <Suspense
                        fallback={
                            <div className="grid h-full place-items-center">
                                <Loader2 className="size-6 animate-spin text-subtle" aria-label="Loading map" />
                            </div>
                        }
                    >
                        {/* Remount on switch: each engine owns its own map instance. Areas carry over. */}
                        <LazyAudienceBuilder key={`${engine}-${drawEngine}`} engine={engine} drawEngine={drawEngine} pickup={PICKUP} areas={state?.areas} includeHouses={state?.include_houses} includeUnits={state?.include_units} onChange={setState} />
                    </Suspense>
                </main>
            </div>
        </>
    );
}
