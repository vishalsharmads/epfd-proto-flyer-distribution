import { LazyAudienceBuilder, type AudienceState, type BuilderArea } from '@/map';
import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { Suspense, useState } from 'react';

const READONLY_AREAS: BuilderArea[] = [
    {
        id: 1,
        name: 'Run area 1',
        draw_mode: 'pins',
        geometry: { type: 'Polygon', coordinates: [[[151.033, -33.811], [151.038, -33.811], [151.038, -33.815], [151.033, -33.815], [151.033, -33.811]]] },
        estimate: { houses: 52, units: 127, total: 179, distance_km: 2.7, source: 'gnaf', estimated: true, no_letterboxes: false },
    },
];
const TRACKS = [{ points: [[151.0335, -33.8113], [151.0352, -33.8114], [151.0371, -33.8116], [151.0372, -33.8131], [151.0351, -33.8133]] as [number, number][] }];

const PICKUP = { lng: 151.0365, lat: -33.8126, label: 'Rydalmere' };

/** Dev-only preview of the audience builder (no wizard). /dev/map-preview?admin=1 for admin mode, ?readonly=1 for the job-page view. */
export default function MapPreview() {
    const [state, setState] = useState<AudienceState | null>(null);
    const qs = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams();
    const admin = qs.has('admin');
    const readOnly = qs.has('readonly');
    return (
        <>
            <Head title="Map preview" />
            <div className="flex h-dvh flex-col bg-bg">
                <header className="flex h-12 shrink-0 items-center justify-between border-b border-border bg-surface px-4 text-sm">
                    <span className="font-semibold text-fg">Map preview {admin && '· admin'}</span>
                    <span className="text-muted tabular-nums" data-testid="preview-state">
                        {state ? `${state.areas.length} areas · ${state.totals.letterboxes} letterboxes` : 'No changes yet'}
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
                        {readOnly ? <LazyAudienceBuilder readOnly areas={READONLY_AREAS} tracks={TRACKS} /> : <LazyAudienceBuilder pickup={PICKUP} mode={admin ? 'admin' : 'business'} onChange={setState} />}
                    </Suspense>
                </main>
            </div>
        </>
    );
}
