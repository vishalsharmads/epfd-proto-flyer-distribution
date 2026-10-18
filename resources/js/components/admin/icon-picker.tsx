import { CATEGORY_ICONS } from '@/components/app/print-bits';
import { cn } from '@/lib/utils';

const label = (name: string) => name.replace(/-/g, ' ');

/** Grid of catalogue icons with radio semantics (arrow keys move, Space/Enter or click selects). */
export function IconPicker({
    value,
    onChange,
    icons,
    id,
    ariaLabel = 'Icon',
}: {
    value: string;
    onChange: (icon: string) => void;
    icons: string[];
    id?: string;
    ariaLabel?: string;
}) {
    const list = icons.filter((i) => CATEGORY_ICONS[i]);
    const move = (from: number, delta: number) => {
        const next = list[(from + delta + list.length) % list.length];
        onChange(next);
        requestAnimationFrame(() => document.getElementById(`${id ?? 'icon'}-${next}`)?.focus());
    };
    return (
        <div role="radiogroup" aria-label={ariaLabel} className="grid grid-cols-6 gap-1.5 sm:grid-cols-9">
            {list.map((name, i) => {
                const Icon = CATEGORY_ICONS[name];
                const selected = name === value;
                return (
                    <button
                        key={name}
                        id={`${id ?? 'icon'}-${name}`}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        aria-label={label(name)}
                        title={label(name)}
                        tabIndex={selected || (!list.includes(value) && i === 0) ? 0 : -1}
                        onClick={() => onChange(name)}
                        onKeyDown={(e) => {
                            const delta = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
                            if (delta) {
                                e.preventDefault();
                                move(i, delta);
                            }
                        }}
                        className={cn(
                            'grid aspect-square place-items-center rounded-[10px] border transition-[background-color,border-color,box-shadow] focus-visible:outline-2 focus-visible:outline-focus',
                            selected ? 'border-fg bg-surface-2 ring-2 ring-fg/10' : 'border-border hover:border-border-strong hover:bg-surface-2',
                        )}
                    >
                        <Icon className="size-[18px]" aria-hidden />
                    </button>
                );
            })}
        </div>
    );
}
