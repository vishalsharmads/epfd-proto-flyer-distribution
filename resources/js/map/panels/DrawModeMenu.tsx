import { cn } from '@/lib/utils';
import { Check, MapPinned, MousePointerClick, PenLine } from 'lucide-react';
import type { DrawMode } from '../types';

export const DRAW_MODES: { mode: DrawMode; title: string; subtitle: string; icon: typeof PenLine }[] = [
    { mode: 'pins', title: 'Outline with pins', subtitle: 'Tap points on the map to outline your area', icon: MousePointerClick },
    { mode: 'trace', title: 'Trace an area', subtitle: 'Press and drag to draw freehand', icon: PenLine },
    { mode: 'suburb', title: 'Enter a suburb', subtitle: 'Start from a suburb boundary', icon: MapPinned },
];

/** Option list rendered inside a Popover (opened by "Add area" or the editing panel's "Draw mode"). */
export function DrawModeMenu({ selected, onPick }: { selected: DrawMode; onPick: (m: DrawMode) => void }) {
    return (
        <div role="menu" aria-label="Choose how to draw" className="flex w-[300px] flex-col gap-0.5" data-tour="draw-modes">
            <p className="px-2.5 pt-1 pb-2 text-xs font-semibold tracking-wide text-subtle uppercase">Draw mode</p>
            {DRAW_MODES.map(({ mode, title, subtitle, icon: Icon }) => (
                <button
                    key={mode}
                    type="button"
                    role="menuitemradio"
                    aria-checked={selected === mode}
                    data-testid={`draw-mode-${mode}`}
                    onClick={() => onPick(mode)}
                    className={cn('flex items-center gap-3 rounded-[10px] px-2.5 py-2 text-left transition-colors hover:bg-surface-2 focus-visible:bg-surface-2 focus-visible:outline-2 focus-visible:outline-focus', selected === mode && 'bg-surface-2')}
                >
                    <span className="grid size-9 shrink-0 place-items-center rounded-full border border-border bg-surface text-fg">
                        <Icon className="size-4" aria-hidden />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-fg">{title}</span>
                        <span className="block text-xs text-muted">{subtitle}</span>
                    </span>
                    {selected === mode && <Check className="size-4 text-fg" aria-hidden />}
                </button>
            ))}
        </div>
    );
}
