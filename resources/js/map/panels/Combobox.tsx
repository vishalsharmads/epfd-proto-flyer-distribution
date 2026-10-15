import { cn } from '@/lib/utils';
import { Loader2, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

/** Accessible debounced typeahead (ARIA 1.2 combobox + listbox, full keyboard support). */
export function Combobox<T>({
    fetcher,
    onSelect,
    render,
    getKey,
    placeholder,
    label,
    testId,
    autoFocus,
    minChars = 2,
}: {
    fetcher: (q: string, signal: AbortSignal) => Promise<T[]>;
    onSelect: (item: T) => void;
    render: (item: T) => ReactNode;
    getKey: (item: T) => string | number;
    placeholder: string;
    label: string;
    testId?: string;
    autoFocus?: boolean;
    minChars?: number;
}) {
    const id = useId();
    const [q, setQ] = useState('');
    const [items, setItems] = useState<T[]>([]);
    const [active, setActive] = useState(0);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (autoFocus) inputRef.current?.focus();
    }, [autoFocus]);

    useEffect(() => {
        if (q.trim().length < minChars) {
            setItems([]);
            setLoading(false);
            return;
        }
        const ctrl = new AbortController();
        setLoading(true);
        const t = setTimeout(() => {
            fetcher(q.trim(), ctrl.signal)
                .then((r) => {
                    setItems(r);
                    setActive(0);
                    setError(false);
                    setLoading(false);
                })
                .catch(() => {
                    if (!ctrl.signal.aborted) {
                        setError(true);
                        setLoading(false);
                    }
                });
        }, 250);
        return () => {
            clearTimeout(t);
            ctrl.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q, minChars]);

    const choose = (it: T) => {
        onSelect(it);
        setItems([]);
    };
    const open = items.length > 0;
    const showEmpty = !loading && !error && q.trim().length >= minChars && items.length === 0;

    return (
        <div className="relative">
            <label htmlFor={`${id}-in`} className="sr-only">
                {label}
            </label>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-subtle" aria-hidden />
                <input
                    ref={inputRef}
                    id={`${id}-in`}
                    data-testid={testId}
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={`${id}-list`}
                    aria-autocomplete="list"
                    aria-activedescendant={open ? `${id}-o${active}` : undefined}
                    autoComplete="off"
                    value={q}
                    placeholder={placeholder}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            setActive((a) => Math.min(items.length - 1, a + 1));
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            setActive((a) => Math.max(0, a - 1));
                        } else if (e.key === 'Enter' && open) {
                            e.preventDefault();
                            e.stopPropagation();
                            choose(items[active]);
                        }
                    }}
                    className="h-10 w-full rounded-[12px] border border-border-strong bg-surface pr-9 pl-9 text-[15px] text-fg placeholder:text-subtle focus:border-primary focus:ring-4 focus:ring-primary/15 focus:outline-none"
                />
                {loading && <Loader2 className="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-subtle" aria-hidden />}
            </div>
            <ul id={`${id}-list`} role="listbox" aria-label={label} className={cn('mt-1.5 max-h-64 overflow-auto rounded-[12px] border border-border bg-surface p-1 shadow-lg', !open && 'hidden')}>
                {items.map((it, i) => (
                    <li
                        key={getKey(it)}
                        id={`${id}-o${i}`}
                        role="option"
                        aria-selected={i === active}
                        onMouseEnter={() => setActive(i)}
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => choose(it)}
                        className={cn('cursor-pointer rounded-[8px] px-3 py-2 text-sm text-fg', i === active && 'bg-surface-2')}
                    >
                        {render(it)}
                    </li>
                ))}
            </ul>
            <p className="sr-only" aria-live="polite">
                {open ? `${items.length} results` : showEmpty ? 'No results' : ''}
            </p>
            {showEmpty && <p className="mt-2 px-1 text-sm text-muted">No matches. Try another spelling or a postcode.</p>}
            {error && <p className="mt-2 px-1 text-sm text-danger">Search is unavailable right now. Try again.</p>}
        </div>
    );
}
