import { Button } from '@/components/ui/button';
import type { LngLat } from '@/types';
import { Trash2, X } from 'lucide-react';
import { useRef } from 'react';
import { useMapTick } from '../hooks/useMapTick';
import type { MapProvider } from '../providers';

/**
 * The pin chosen by a click or tap while editing: a highlight ring and a "Remove pin" button beside it.
 * Gives touch screens and keyboard users a way to remove pins (otherwise right-click only).
 * The button opens towards the middle of the map, so it is not hidden under the side panels.
 */
export function PinActions({
    provider,
    point,
    canRemove,
    onRemove,
    onDismiss,
}: {
    provider: MapProvider;
    point: LngLat;
    canRemove: boolean;
    onRemove: () => void;
    onDismiss: () => void;
}) {
    useMapTick(provider);
    const box = useRef<HTMLDivElement>(null);
    const q = provider.project(point);
    const width = box.current?.clientWidth ?? 0;
    const height = box.current?.clientHeight ?? 0;
    const towardsLeft = width > 0 && q.x > width / 2;
    const above = height > 0 && q.y > height - 90;

    return (
        <div ref={box} className="pointer-events-none absolute inset-0 z-[25] overflow-hidden" data-testid="pin-actions">
            <span
                className="absolute size-7 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[var(--brand-500)] bg-[var(--brand-500)]/15"
                style={{ left: q.x, top: q.y }}
                aria-hidden
            />
            <div
                className="pointer-events-auto absolute flex items-center gap-1 rounded-full border border-border bg-surface p-1 shadow-md"
                style={{
                    left: q.x + (towardsLeft ? -18 : 18),
                    top: q.y + (above ? -18 : 18),
                    transform: `translate(${towardsLeft ? '-100%' : '0'}, ${above ? '-100%' : '0'})`,
                }}
            >
                <Button
                    size="sm"
                    variant="danger-soft"
                    disabled={!canRemove}
                    onClick={onRemove}
                    data-testid="remove-pin"
                    title={canRemove ? 'Remove pin (Delete)' : 'An area needs at least 3 pins'}
                >
                    <Trash2 /> {canRemove ? 'Remove pin' : 'Needs 3 pins'}
                </Button>
                <Button size="icon-sm" variant="ghost" onClick={onDismiss} aria-label="Close (Esc)" title="Close (Esc)">
                    <X />
                </Button>
            </div>
        </div>
    );
}
