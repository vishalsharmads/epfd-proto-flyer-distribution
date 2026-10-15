import { LazyAudienceBuilder, type AudienceState } from '@/map';
import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { Suspense, useState } from 'react';

const PICKUP = { lng: 151.0365, lat: -33.8126, label: 'Rydalmere' };

/** Dev-only preview of the audience builder (no wizard). /dev/map-preview?admin=1 for admin mode. */
export default function MapPreview() {
    const [state, setState] = useState<AudienceState | null>(null);
    const admin = typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('admin');
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
                        <LazyAudienceBuilder pickup={PICKUP} mode={admin ? 'admin' : 'business'} onChange={setState} />
                    </Suspense>
                </main>
            </div>
        </>
    );
}
