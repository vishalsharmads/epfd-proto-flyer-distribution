import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/form';
import { cn, num } from '@/lib/utils';
import { Check, Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * Pick numbers from common presets (toggle chips) or add a custom one. Selected values outside the presets show as
 * chips too, so everything selected is visible in one row. Values stay sorted ascending.
 */
export function OptionChips({
    id,
    values,
    presets,
    onChange,
    suffix = '',
    placeholder,
    invalid,
}: {
    id: string;
    values: number[];
    presets: number[];
    onChange: (v: number[]) => void;
    suffix?: string;
    placeholder: string;
    invalid?: boolean;
}) {
    const [draft, setDraft] = useState('');
    const all = Array.from(new Set([...presets, ...values])).sort((a, b) => a - b);
    const toggle = (v: number) => onChange(values.includes(v) ? values.filter((x) => x !== v) : [...values, v].sort((a, b) => a - b));
    const add = () => {
        const n = Math.round(Number(draft.replace(/[,\s]/g, '')));
        if (n > 0 && !values.includes(n)) onChange([...values, n].sort((a, b) => a - b));
        setDraft('');
    };
    return (
        <div className="space-y-2.5">
            <div
                className={cn('flex flex-wrap gap-1.5', invalid && 'rounded-[10px] ring-2 ring-danger/40 ring-offset-2 ring-offset-surface')}
                role="group"
                aria-labelledby={`${id}-label`}
            >
                {all.map((v) => {
                    const on = values.includes(v);
                    return (
                        <button
                            key={v}
                            type="button"
                            aria-pressed={on}
                            onClick={() => toggle(v)}
                            className={cn(
                                'inline-flex h-8 items-center gap-1 rounded-full border px-3 text-sm tabular-nums transition-colors focus-visible:outline-2 focus-visible:outline-focus',
                                on ? 'border-fg bg-fg text-bg' : 'border-border bg-surface text-muted hover:border-border-strong hover:text-fg',
                            )}
                        >
                            {on && <Check className="size-3.5" aria-hidden />}
                            {num(v)}
                            {suffix}
                        </button>
                    );
                })}
            </div>
            <div className="flex max-w-xs gap-2">
                <Input
                    id={id}
                    inputMode="numeric"
                    aria-label={`Add a custom ${placeholder.toLowerCase()}`}
                    placeholder={`Custom ${placeholder.toLowerCase()}`}
                    value={draft}
                    className="h-9"
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            add();
                        }
                    }}
                />
                <Button type="button" size="sm" variant="secondary" onClick={add} disabled={!(Number(draft.replace(/[,\s]/g, '')) > 0)}>
                    <Plus /> Add
                </Button>
            </div>
        </div>
    );
}
