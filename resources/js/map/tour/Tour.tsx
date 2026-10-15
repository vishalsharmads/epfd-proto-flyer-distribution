import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/surface';
import { cn } from '@/lib/utils';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { prefersReducedMotion, storageGet, storageSet } from '../hooks/useMapTick';

export const TOUR_KEY = 'epfd-map-tour-done';

interface Step {
    anchor: string | null; // data-tour value
    title: string;
    body: React.ReactNode;
}

const STEPS: Step[] = [
    { anchor: 'filter', title: 'Filter your audience', body: 'Choose whether to deliver to houses, units or both. Counts and fees update automatically.' },
    { anchor: 'add-area', title: 'Add an area', body: 'Press Add area (or the Space bar) to start a new delivery area. Each area is collected by a different distributor.' },
    { anchor: 'add-area', title: 'Pick a draw mode', body: 'Outline with pins, trace freehand, or start from a suburb boundary. We remember your last choice.' },
    { anchor: 'map', title: 'Create an area', body: 'Click the map to drop pins. Click the first pin or press Enter to close the shape. Backspace removes the last pin.' },
    { anchor: 'done', title: 'Save an area', body: 'When you are happy with the outline, press Done (or Enter) and it joins your list of areas.' },
    { anchor: 'areas', title: 'Edit an area', body: 'Click a saved area on the map or in the list to edit it. Drag pins to move them, or use the + handles to add more.' },
    { anchor: 'delete', title: 'Delete an area', body: 'While editing, use Delete area (or Backspace) to remove it. You can also use the red X in the list.' },
    {
        anchor: 'help',
        title: "You're all set",
        body: (
            <>
                Replay this tour any time from the ? button. Need a hand?{' '}
                <a href="/app/support" className="font-medium text-primary underline underline-offset-2">
                    Contact support
                </a>
                .
            </>
        ),
    },
];

export function tourDone(): boolean {
    return storageGet(TOUR_KEY) === '1';
}

/** 8-step popover tour anchored to `[data-tour]` elements; falls back to the centre when an anchor is not on screen. */
export function Tour({ open, onClose, root }: { open: boolean; onClose: () => void; root: HTMLElement | null }) {
    const [i, setI] = useState(0);
    const [pos, setPos] = useState<{ top: number; left: number; ring: DOMRect | null }>({ top: 0, left: 0, ring: null });
    const card = useRef<HTMLDivElement>(null);
    const step = STEPS[i];

    const finish = useCallback(() => {
        storageSet(TOUR_KEY, '1');
        setI(0);
        onClose();
    }, [onClose]);

    const place = useCallback(() => {
        if (!root || !card.current) return;
        const host = root.getBoundingClientRect();
        const c = card.current.getBoundingClientRect();
        const el = step.anchor ? (root.querySelector(`[data-tour="${step.anchor}"]`) as HTMLElement | null) : null;
        const r = el?.getBoundingClientRect();
        let top = host.height / 2 - c.height / 2;
        let left = host.width / 2 - c.width / 2;
        let ring: DOMRect | null = null;
        if (r && r.width > 0 && step.anchor !== 'map') {
            ring = new DOMRect(r.left - host.left, r.top - host.top, r.width, r.height);
            const rx = r.left - host.left,
                ry = r.top - host.top;
            // Prefer right of anchor, then left, then below.
            if (rx + r.width + 16 + c.width < host.width) {
                left = rx + r.width + 16;
                top = ry;
            } else if (rx - 16 - c.width > 0) {
                left = rx - 16 - c.width;
                top = ry;
            } else {
                left = rx;
                top = ry + r.height + 12;
            }
        }
        top = Math.max(12, Math.min(top, host.height - c.height - 12));
        left = Math.max(12, Math.min(left, host.width - c.width - 12));
        setPos({ top, left, ring });
    }, [root, step]);

    useLayoutEffect(() => {
        if (open) place();
    }, [open, place, i]);

    useEffect(() => {
        if (!open) return;
        card.current?.focus();
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopImmediatePropagation();
                finish();
            } else if (e.key === 'ArrowRight' || e.key === 'Enter') {
                if ((e.target as HTMLElement)?.tagName === 'A') return;
                e.preventDefault();
                e.stopImmediatePropagation();
                if (i < STEPS.length - 1) setI(i + 1);
                else finish();
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                e.stopImmediatePropagation();
                setI((x) => Math.max(0, x - 1));
            } else if (e.key === ' ' || e.key === 'Backspace') {
                e.stopImmediatePropagation();
            }
        };
        window.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', place);
        return () => {
            window.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', place);
        };
    }, [open, i, finish, place]);

    if (!open) return null;
    const anim = prefersReducedMotion() ? '' : 'transition-[top,left] duration-200 ease-out';

    return (
        <div className="pointer-events-none absolute inset-0 z-40" data-testid="map-tour">
            {pos.ring && <div className={cn('absolute rounded-[14px] ring-4 ring-primary/50', anim)} style={{ left: pos.ring.x - 4, top: pos.ring.y - 4, width: pos.ring.width + 8, height: pos.ring.height + 8 }} aria-hidden />}
            <div
                ref={card}
                tabIndex={-1}
                role="dialog"
                aria-modal="false"
                aria-labelledby="tour-title"
                aria-describedby="tour-body"
                className={cn('pointer-events-auto absolute w-[min(320px,calc(100%-24px))] rounded-[16px] border border-border bg-surface p-4 shadow-lg outline-none', anim)}
                style={{ top: pos.top, left: pos.left }}
            >
                <div className="flex items-center justify-between">
                    <div className="flex gap-1" aria-hidden>
                        {STEPS.map((_, k) => (
                            <span key={k} className={cn('h-1.5 rounded-full', k === i ? 'w-5 bg-fg' : 'w-1.5 bg-border-strong')} />
                        ))}
                    </div>
                    <button type="button" onClick={finish} className="inline-flex items-center gap-1.5 text-xs font-medium text-muted hover:text-fg" data-testid="tour-skip">
                        Skip <Kbd>Esc</Kbd>
                    </button>
                </div>
                <h2 id="tour-title" className="mt-3 text-base font-semibold text-fg">
                    {step.title}
                </h2>
                <p id="tour-body" className="mt-1 text-sm text-muted">
                    {step.body}
                </p>
                <div className="mt-4 flex items-center justify-between">
                    <span className="text-xs text-subtle tabular-nums" aria-live="polite">
                        {i + 1} / {STEPS.length}
                    </span>
                    <div className="flex gap-2">
                        {i > 0 && (
                            <Button variant="secondary" size="sm" onClick={() => setI(i - 1)}>
                                <ArrowLeft aria-hidden /> Back
                            </Button>
                        )}
                        <Button variant="ink" size="sm" onClick={() => (i < STEPS.length - 1 ? setI(i + 1) : finish())} data-testid="tour-next">
                            {i < STEPS.length - 1 ? 'Next' : 'Done'} <ArrowRight aria-hidden />
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
