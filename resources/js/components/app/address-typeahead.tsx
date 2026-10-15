import { Input } from '@/components/ui/form';
import { api, cn, debounce } from '@/lib/utils';
import type { LngLat } from '@/types';
import { Loader2, MapPin } from 'lucide-react';
import { forwardRef, useEffect, useId, useMemo, useRef, useState, type InputHTMLAttributes } from 'react';

export interface AddressHit {
    gnaf_pid: string;
    label: string;
    kind: string;
    state: string;
    locality: string;
    postcode: string;
    location: LngLat;
}

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value' | 'onSelect'> {
    value: string;
    onChange: (text: string) => void;
    onSelect: (hit: AddressHit) => void;
}

/** G-NAF address typeahead (combobox pattern) backed by GET /api/v1/addresses/search. */
export const AddressTypeahead = forwardRef<HTMLInputElement, Props>(function AddressTypeahead({ value, onChange, onSelect, className, ...rest }, ref) {
    const [hits, setHits] = useState<AddressHit[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [active, setActive] = useState(-1);
    const listId = useId();
    const lastPicked = useRef<string | null>(null);

    const search = useMemo(
        () =>
            debounce(async (q: string) => {
                setLoading(true);
                setFailed(false);
                try {
                    const res = await api<{ data: AddressHit[] }>(`/api/v1/addresses/search?q=${encodeURIComponent(q)}`);
                    setHits(res.data);
                    setOpen(true);
                    setActive(-1);
                } catch {
                    setFailed(true);
                    setHits([]);
                } finally {
                    setLoading(false);
                }
            }, 250),
        [],
    );

    useEffect(() => () => void search.cancel(), [search]);

    const change = (v: string) => {
        onChange(v);
        if (v.trim().length >= 3 && v !== lastPicked.current) search(v);
        else {
            setHits([]);
            setOpen(false);
        }
    };

    const pick = (h: AddressHit) => {
        lastPicked.current = h.label;
        onSelect(h);
        setOpen(false);
    };

    return (
        <div className="relative">
            <MapPin className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-subtle" aria-hidden />
            <Input
                ref={ref}
                {...rest}
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={active >= 0 ? `${listId}-${active}` : undefined}
                autoComplete="off"
                className={cn('pl-10', className)}
                value={value}
                onChange={(e) => change(e.target.value)}
                onFocus={() => hits.length && setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onKeyDown={(e) => {
                    if (!open || !hits.length) return;
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setActive((a) => Math.min(hits.length - 1, a + 1));
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setActive((a) => Math.max(0, a - 1));
                    } else if (e.key === 'Enter' && active >= 0) {
                        e.preventDefault();
                        pick(hits[active]);
                    } else if (e.key === 'Escape') setOpen(false);
                }}
            />
            {loading && <Loader2 className="absolute top-1/2 right-3.5 size-4 -translate-y-1/2 animate-spin text-subtle" aria-label="Searching" />}
            {open && (
                <ul id={listId} role="listbox" className="absolute z-40 mt-1.5 max-h-72 w-full overflow-auto rounded-[14px] border border-border bg-surface p-1.5 shadow-lg">
                    {hits.length === 0 && <li className="px-3 py-2.5 text-sm text-muted">{failed ? 'Address search is unavailable. Try again.' : 'No matching addresses. Try the street number and name.'}</li>}
                    {hits.map((h, i) => (
                        <li
                            key={h.gnaf_pid}
                            id={`${listId}-${i}`}
                            role="option"
                            aria-selected={i === active}
                            onMouseDown={(e) => {
                                e.preventDefault();
                                pick(h);
                            }}
                            className={cn('cursor-pointer rounded-[10px] px-3 py-2 text-sm', i === active ? 'bg-surface-2' : 'hover:bg-surface-2')}
                        >
                            <span className="block font-medium text-fg">{h.label}</span>
                            <span className="text-xs text-muted">
                                {h.kind === 'unit' ? 'Unit' : 'Street address'} · G-NAF
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
});
