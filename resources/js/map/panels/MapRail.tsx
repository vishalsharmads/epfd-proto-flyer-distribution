import { Popover, PopoverContent, PopoverTrigger, Tooltip } from '@/components/ui/overlay';
import { api, cn } from '@/lib/utils';
import type { LngLat } from '@/types';
import { CircleDashed, CircleHelp, Dot, Globe, MapPin, Route, Search, Tag } from 'lucide-react';
import { forwardRef, useState, type ReactNode } from 'react';
import { Combobox } from './Combobox';

export interface RailState {
    addresses: boolean;
    labels: boolean;
    streets: boolean;
    satellite: boolean;
}

interface AddressHit {
    gnaf_pid: string;
    label: string;
    kind: string;
    location: LngLat;
}

const RailButton = forwardRef<HTMLButtonElement, { label: string; pressed?: boolean; onClick?: () => void; children: ReactNode; testId?: string; tour?: string }>(
    ({ label, pressed, onClick, children, testId, tour, ...rest }, ref) => (
        <Tooltip content={label} side="left">
            <button
                ref={ref}
                type="button"
                aria-label={label}
                aria-pressed={pressed}
                onClick={onClick}
                data-testid={testId}
                data-tour={tour}
                {...rest}
                className={cn(
                    'grid size-10 place-items-center md:size-11 rounded-full border shadow-md transition-[background,color,transform] duration-150 active:scale-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&_svg]:size-[18px]',
                    pressed ? 'border-ink-900 bg-ink-900 text-white dark:border-white dark:bg-white dark:text-ink-900' : 'border-border bg-surface text-fg hover:bg-surface-2',
                )}
            >
                {children}
            </button>
        </Tooltip>
    ),
);
RailButton.displayName = 'RailButton';

export function MapRail({ state, onToggle, onHelp, onFound, onRadius, radiusKm, minimal, className }: { minimal?: boolean; state: RailState; onToggle: (k: keyof RailState) => void; onHelp?: () => void; onRadius?: () => void; radiusKm?: number; onFound: (ll: LngLat, label: string) => void; className?: string }) {
    const [searchOpen, setSearchOpen] = useState(false);
    return (
        <nav aria-label="Map tools" className={cn('flex flex-col gap-2', !minimal && 'max-md:grid max-md:grid-cols-2 max-md:[direction:rtl]', className)}>
            {onHelp && (
                <RailButton label="Help and tour" onClick={onHelp} testId="rail-help" tour="help">
                    <CircleHelp aria-hidden />
                </RailButton>
            )}
            {!minimal && (
            <Popover open={searchOpen} onOpenChange={setSearchOpen}>
                <PopoverTrigger asChild>
                    <RailButton label="Find an address" pressed={searchOpen} testId="rail-search">
                        <Search aria-hidden />
                    </RailButton>
                </PopoverTrigger>
                <PopoverContent side="left" align="start" className="w-[min(340px,calc(100vw-80px))] p-3">
                    <p className="mb-2 text-sm font-semibold text-fg">Find an address</p>
                    <Combobox<AddressHit>
                        label="Search addresses"
                        placeholder="e.g. 1 Victoria Road, Parramatta"
                        testId="address-search"
                        autoFocus
                        minChars={3}
                        fetcher={(q, signal) => api<{ data: AddressHit[] }>(`/api/v1/addresses/search?q=${encodeURIComponent(q)}`, { signal }).then((r) => r.data)}
                        getKey={(a) => a.gnaf_pid}
                        render={(a) => (
                            <span className="flex items-start gap-2">
                                <MapPin className="mt-0.5 size-4 shrink-0 text-subtle" aria-hidden />
                                <span>
                                    {a.label}
                                    <span className="block text-xs text-muted capitalize">{a.kind}</span>
                                </span>
                            </span>
                        )}
                        onSelect={(a) => {
                            onFound(a.location, a.label);
                            setSearchOpen(false);
                        }}
                    />
                </PopoverContent>
            </Popover>
            )}
            {onRadius && (
                <RailButton label={`Show ${radiusKm ?? 20} km pickup radius`} onClick={onRadius} testId="rail-radius">
                    <CircleDashed aria-hidden />
                </RailButton>
            )}
            {!minimal && (
            <RailButton label={state.addresses ? 'Hide addresses' : 'Show addresses'} pressed={state.addresses} onClick={() => onToggle('addresses')} testId="rail-addresses">
                <Dot aria-hidden className="scale-[2]" />
            </RailButton>
            )}
            <RailButton label="Show labels" pressed={state.labels} onClick={() => onToggle('labels')} testId="rail-labels">
                <Tag aria-hidden />
            </RailButton>
            <RailButton label="Show streets" pressed={state.streets} onClick={() => onToggle('streets')} testId="rail-streets">
                <Route aria-hidden />
            </RailButton>
            <RailButton label="Satellite" pressed={state.satellite} onClick={() => onToggle('satellite')} testId="rail-satellite">
                <Globe aria-hidden />
            </RailButton>
        </nav>
    );
}
