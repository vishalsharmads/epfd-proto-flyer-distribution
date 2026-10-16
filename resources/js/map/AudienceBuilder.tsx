/**
 * Map audience builder (RealRun-style). Default export; load it lazily (see ./index.ts → LazyAudienceBuilder).
 *
 * Props: see `AudienceBuilderProps` in ./types.ts.
 * Drawing is provider-independent (our own SVG overlay); the base map comes from ./providers.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/overlay';
import { GnafAttribution } from '@/components/brand';
import { areaColor } from '@/lib/tokens';
import { api, cn, money, num } from '@/lib/utils';
import type { GeoJsonPolygon, LngLat, SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { ChevronDown, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AddressLayer, type AddressStatus } from './draw/AddressLayer';
import { AreaFocusMask } from './draw/AreaFocusMask';
import { DrawOverlay, type OverlayArea, type Phase } from './draw/DrawOverlay';
import { TerraDrawLayer } from './draw/TerraDrawLayer';
import { geodesicCircle } from './geometry/circle';
import { bounds, fromPolygon, pointInPolygon, toPolygon } from './geometry/polygon';
import { requestEstimate, useLiveEstimate, type EstimateOptions } from './hooks/estimate';
import { isTypingTarget, storageGet, storageSet } from './hooks/useMapTick';
import { Combobox } from './panels/Combobox';
import { DRAW_MODES, DrawModeMenu } from './panels/DrawModeMenu';
import { AddAreaButton, LeftPanel } from './panels/LeftPanel';
import { MapRail, type RailState } from './panels/MapRail';
import { EditingPanel, SummaryCard, type Summary } from './panels/RightPanel';
import { createProvider, type MapProvider } from './providers';
import { Tour, tourDone } from './tour/Tour';
import type { AudienceBuilderProps, AudienceState, BuilderArea, DrawMode } from './types';

export type { AudienceBuilderProps, AudienceState, BuilderArea } from './types';

declare global {
    interface Window {
        /** Minimal hook for Playwright: geographic → viewport (client) pixel coordinates. */
        __epfdMap?: {
            project: (ll: LngLat) => { x: number; y: number };
            getZoom: () => number;
            getBounds: () => [number, number, number, number];
            setView: (center: LngLat, zoom: number) => void;
        };
    }
}

type Area = BuilderArea & { key: string };

interface EditState {
    key: string;
    isNew: boolean;
    name: string;
    mode: DrawMode;
    phase: Phase;
    points: LngLat[];
    suburb_id: number | null;
    approximate: boolean;
}

interface SuburbHit {
    id: number;
    name: string;
    label: string;
    approximate: boolean;
    stats?: { letterboxes: number };
}

const MODE_KEY = 'epfd-map-draw-mode';
const ADDRESS_PREFS_KEY = 'epfd-map-address-prefs';
type AddressPrefs = Pick<RailState, 'addressesInAreas' | 'addressesAll' | 'fadeOutside'>;

/** Address menu choices, remembered per browser. All off by default. */
function loadAddressPrefs(): AddressPrefs {
    const off: AddressPrefs = { addressesInAreas: false, addressesAll: false, fadeOutside: false };
    try {
        return { ...off, ...(JSON.parse(storageGet(ADDRESS_PREFS_KEY) ?? '{}') as Partial<AddressPrefs>) };
    } catch {
        return off;
    }
}
const phaseFor = (m: DrawMode): Phase => (m === 'pins' ? 'drawing' : m === 'trace' ? 'tracing' : 'suburb');
let seq = 0;
const newKey = () => `area-${Date.now().toString(36)}-${seq++}`;

/** Phones: the panel is a full-width bottom sheet, so popovers open above it rather than to the side (off-screen). */
const narrowViewport = () => typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches;

export default function AudienceBuilder({
    areas: initialAreas = [],
    pickup = null,
    includeHouses = true,
    includeUnits = true,
    coverage,
    material,
    onChange,
    mode = 'business',
    boundary = null,
    readOnly = false,
    tracks,
    className,
    engine,
    drawEngine: drawEngineProp,
}: AudienceBuilderProps) {
    const { map: cfg } = usePage<SharedProps>().props;
    const drawEngine = drawEngineProp ?? cfg.draw_engine ?? 'overlay';
    const admin = mode === 'admin';
    const terra = drawEngine === 'terra' && !readOnly;
    const radiusKm = cfg.pickup_radius_km ?? 20;

    const rootRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<HTMLDivElement>(null);
    const [provider, setProvider] = useState<MapProvider | null>(null);
    const [mapError, setMapError] = useState(false);

    const [areas, setAreas] = useState<Area[]>(() => initialAreas.map((a) => ({ ...a, key: a.key ?? (a.id != null ? `id-${a.id}` : newKey()) })));
    const [houses, setHouses] = useState(includeHouses || !includeUnits);
    const [units, setUnits] = useState(includeUnits);
    const [edit, setEdit] = useState<EditState | null>(null);
    const [menuOpen, setMenuOpen] = useState(false);
    const [modeMenuOpen, setModeMenuOpen] = useState(false);
    const [lastMode, setLastMode] = useState<DrawMode>(() => (storageGet(MODE_KEY) as DrawMode | null) ?? 'pins');
    const [rail, setRail] = useState<RailState>(() => ({ ...loadAddressPrefs(), labels: true, streets: true, satellite: false }));
    const [addr, setAddr] = useState<AddressStatus | null>(null);
    const [marker, setMarker] = useState<LngLat | null>(null);
    const [confirmKey, setConfirmKey] = useState<string | null>(null);
    const [tourOpen, setTourOpen] = useState(false);
    const [announce, setAnnounce] = useState('');

    const opts: EstimateOptions = useMemo(() => ({ includeHouses: houses, includeUnits: units, coverage, material, pickup }), [houses, units, coverage, material, pickup]);
    /** "My areas" for the address menu: the selected area while editing, otherwise every saved area (none while drawing). */
    const editingClosed = edit?.phase === 'editing' && edit.points.length >= 3;
    const est = useLiveEstimate(edit?.phase === 'editing' ? edit.points : null, opts, radiusKm);

    const focus = useMemo<LngLat[][] | null>(() => {
        if (editingClosed) return [edit!.points];
        if (edit) return null;
        return areas.length ? areas.map((a) => fromPolygon(a.geometry)) : null;
    }, [editingClosed, edit, areas]);
    /** "Whole map" wins over "In my areas" (it already includes them). */
    const addressesShown = rail.addressesAll || (rail.addressesInAreas && !!focus);
    useEffect(() => {
        storageSet(ADDRESS_PREFS_KEY, JSON.stringify({ addressesInAreas: rail.addressesInAreas, addressesAll: rail.addressesAll, fadeOutside: rail.fadeOutside }));
    }, [rail.addressesInAreas, rail.addressesAll, rail.fadeOutside]);
    const overlayAreas: OverlayArea[] = useMemo(() => areas.map((a, index) => ({ ...a, index, color: areaColor(index) })), [areas]);
    const radiusRing = useMemo(() => (pickup && !readOnly ? geodesicCircle([pickup.lng, pickup.lat], radiusKm, 64) : null), [pickup, radiusKm, readOnly]);

    /* ---------------- map lifecycle ---------------- */
    useEffect(() => {
        const el = mapRef.current;
        if (!el) return;
        let disposed = false;
        let instance: MapProvider | null = null;
        const allPts = [...initialAreas.flatMap((a) => fromPolygon(a.geometry)), ...(tracks ?? []).flatMap((t) => t.points)];
        const center: LngLat = pickup ? [pickup.lng, pickup.lat] : allPts.length ? (bounds(allPts).slice(0, 2) as LngLat) : [151.0, -33.85];
        createProvider(engine ?? cfg.provider, { container: el, center, zoom: 14, config: cfg, dark: document.documentElement.classList.contains('dark') })
            .then(async (p) => {
                instance = p;
                if (disposed) return p.destroy();
                await p.ready;
                if (disposed) return;
                const bpts = allPts.length ? allPts : boundary ? fromPolygon(boundary) : [];
                if (bpts.length) p.fitBounds(bounds(bpts), 80);
                setProvider(p);
                window.__epfdMap = {
                    project: (ll) => {
                        const q = p.project(ll);
                        const r = el.getBoundingClientRect();
                        return { x: r.left + q.x, y: r.top + q.y };
                    },
                    getZoom: () => p.getZoom(),
                    getBounds: () => p.getBounds(),
                    setView: (c, z) => p.setView(c, z),
                };
            })
            .catch(() => !disposed && setMapError(true));
        const mo = new MutationObserver(() => instance?.setDark(document.documentElement.classList.contains('dark')));
        mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        const ro = new ResizeObserver(() => instance?.resize());
        ro.observe(el);
        return () => {
            disposed = true;
            mo.disconnect();
            ro.disconnect();
            instance?.destroy();
            delete window.__epfdMap;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!provider) return;
        provider.setBase(rail.satellite ? 'satellite' : 'streets');
        provider.setLabels(rail.labels);
        provider.setStreets(rail.streets);
    }, [provider, rail.satellite, rail.labels, rail.streets]);

    useEffect(() => {
        if (provider && !readOnly && !tourDone()) setTourOpen(true);
    }, [provider, readOnly]);

    /* ---------------- onChange ---------------- */
    const totals = useMemo(() => {
        let h = 0,
            u = 0,
            t = 0,
            d = 0,
            fee = 0,
            feeKnown = false;
        for (const a of areas) {
            h += a.estimate?.houses ?? 0;
            u += a.estimate?.units ?? 0;
            t += a.estimate?.total ?? 0;
            d += a.estimate?.distance_km ?? 0;
            if (a.quote) {
                fee += a.quote.total_cents;
                feeKnown = true;
            }
        }
        return { houses: h, units: u, letterboxes: t, distance_km: Math.round(d * 100) / 100, fee_cents: admin || !feeKnown ? null : fee };
    }, [areas, admin]);

    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    const mounted = useRef(false);
    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;
            return;
        }
        const state: AudienceState = { areas, include_houses: houses, include_units: units, totals };
        onChangeRef.current?.(state);
    }, [areas, houses, units, totals]);

    /* ---------------- actions ---------------- */
    const startNew = useCallback(
        (m: DrawMode) => {
            setMenuOpen(false);
            setLastMode(m);
            storageSet(MODE_KEY, m);
            setMarker(null);
            const n = areas.length + 1;
            setEdit({ key: newKey(), isNew: true, name: admin ? `Run ${n}` : `Run area ${n}`, mode: m, phase: phaseFor(m), points: [], suburb_id: null, approximate: false });
        },
        [areas.length, admin],
    );

    const switchMode = (m: DrawMode) => {
        setModeMenuOpen(false);
        setLastMode(m);
        storageSet(MODE_KEY, m);
        setEdit((e) => (e ? { ...e, mode: m, phase: phaseFor(m), points: [], suburb_id: null, approximate: false } : e));
    };

    const startEdit = useCallback(
        (key: string) => {
            if (readOnly) return;
            const a = areas.find((x) => x.key === key);
            if (!a) return;
            setEdit({ key, isNew: false, name: a.name, mode: a.draw_mode, phase: 'editing', points: fromPolygon(a.geometry), suburb_id: a.suburb_id ?? null, approximate: !!a.approximate });
        },
        [areas, readOnly],
    );

    const cancel = useCallback(() => {
        provider?.setInteractive(true);
        setEdit(null);
    }, [provider]);

    const setPoints = useCallback((points: LngLat[]) => setEdit((e) => (e ? { ...e, points } : e)), []);

    const closePolygon = useCallback(() => setEdit((e) => (e && e.points.length >= 3 ? { ...e, phase: 'editing' } : e)), []);

    const canDone = !!edit && edit.phase === 'editing' && edit.points.length >= 3 && !!est.data && !est.loading && !est.error && !est.data.estimate.no_letterboxes;

    const done = useCallback(() => {
        if (!edit || !canDone || !est.data) return;
        const area: Area = {
            key: edit.key,
            id: areas.find((a) => a.key === edit.key)?.id,
            name: edit.name,
            draw_mode: edit.mode,
            suburb_id: edit.suburb_id,
            approximate: edit.approximate,
            geometry: toPolygon(edit.points),
            estimate: est.data.estimate,
            quote: admin ? null : est.data.quote,
        };
        setAreas((list) => (list.some((a) => a.key === area.key) ? list.map((a) => (a.key === area.key ? area : a)) : [...list, area]));
        setEdit(null);
        setAnnounce(`${area.name} saved.`);
    }, [edit, canDone, est.data, areas, admin]);

    const deleteArea = (key: string) => {
        const a = areas.find((x) => x.key === key);
        setAreas((list) => list.filter((x) => x.key !== key));
        if (edit?.key === key) setEdit(null);
        setConfirmKey(null);
        if (a) setAnnounce(`${a.name} deleted.`);
    };

    const requestDelete = () => {
        if (!edit) return;
        if (edit.isNew) cancel();
        else setConfirmKey(edit.key);
    };

    const toggleAudience = async (k: 'houses' | 'units', v: boolean) => {
        const h = k === 'houses' ? v : houses;
        const u = k === 'units' ? v : units;
        if (!h && !u) return;
        setHouses(h);
        setUnits(u);
        // Re-estimate saved areas with the new audience filter.
        for (const a of areas) {
            try {
                const r = await requestEstimate(a.geometry, { ...opts, includeHouses: h, includeUnits: u });
                setAreas((list) => list.map((x) => (x.key === a.key ? { ...x, estimate: r.estimate, quote: admin ? null : r.quote } : x)));
            } catch {
                /* keep the previous estimate; the area will re-estimate when edited */
            }
        }
    };

    // Saved areas handed in without an estimate (e.g. a draft reopened from the server) are estimated once on mount,
    // so the list and totals don't show "Not estimated" / 0 letterboxes until the user edits something.
    useEffect(() => {
        if (readOnly) return;
        let live = true;
        for (const a of areas.filter((x) => !x.estimate)) {
            requestEstimate(a.geometry, opts)
                .then((r) => live && setAreas((list) => list.map((x) => (x.key === a.key && !x.estimate ? { ...x, estimate: r.estimate, quote: admin ? null : r.quote } : x))))
                .catch(() => undefined);
        }
        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const pickSuburb = async (s: SuburbHit) => {
        try {
            const b = await api<{ geometry: GeoJsonPolygon; approximate: boolean; name: string }>(`/api/v1/suburbs/${s.id}/boundary`);
            const pts = fromPolygon(b.geometry);
            setEdit((e) => (e ? { ...e, phase: 'editing', points: pts, suburb_id: s.id, approximate: b.approximate, name: /^Run (area )?\d+$/.test(e.name) ? s.name : e.name } : e));
            provider?.fitBounds(bounds(pts), 60);
        } catch {
            setAnnounce('Could not load that suburb boundary. Try again or draw the area instead.');
        }
    };

    /* ---------------- map clicks ---------------- */
    const clickRef = useRef<(ll: LngLat) => void>(() => undefined);
    clickRef.current = (ll) => {
        if (terra && edit) return;
        if (edit?.phase === 'drawing') {
            if (edit.points.length >= 150) return;
            setPoints([...edit.points, ll]);
            return;
        }
        if (!edit && !readOnly) {
            for (let i = areas.length - 1; i >= 0; i--) {
                if (pointInPolygon(ll, fromPolygon(areas[i].geometry))) return startEdit(areas[i].key);
            }
        }
    };
    useEffect(() => provider?.on('click', (e) => clickRef.current(e.lngLat)), [provider]);

    /* ---------------- keyboard ---------------- */
    const keyRef = useRef<(e: KeyboardEvent) => void>(() => undefined);
    keyRef.current = (e) => {
        if (readOnly || tourOpen || confirmKey || e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey) return;
        const t = e.target as HTMLElement | null;
        const typing = isTypingTarget(t);
        const onControl = !!t && ['BUTTON', 'A'].includes(t.tagName) && t.dataset.testid !== 'add-area';
        if (e.key === 'Escape') {
            if (menuOpen || modeMenuOpen) return; // Radix closes the popover
            if (edit) {
                e.preventDefault();
                cancel();
            }
            return;
        }
        if (typing) return;
        if (e.key === ' ' || e.code === 'Space') {
            if (onControl) return;
            if (!edit) {
                e.preventDefault();
                setMenuOpen(true);
            }
        } else if (e.key === 'Enter') {
            if (onControl) return;
            if (edit?.phase === 'drawing' && edit.points.length >= 3) {
                e.preventDefault();
                closePolygon();
            } else if (edit?.phase === 'editing' && canDone) {
                e.preventDefault();
                done();
            }
        } else if (e.key === 'Backspace' || e.key === 'Delete') {
            if (edit?.phase === 'drawing' && edit.points.length) {
                e.preventDefault();
                setPoints(edit.points.slice(0, -1));
            } else if (edit?.phase === 'editing') {
                e.preventDefault();
                requestDelete();
            }
        }
    };
    useEffect(() => {
        const h = (e: KeyboardEvent) => keyRef.current(e);
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, []);

    /* ---------------- announcements ---------------- */
    useEffect(() => {
        const e = est.data?.estimate;
        if (est.error) setAnnounce(est.error);
        else if (e?.no_letterboxes) setAnnounce('No letterboxes detected. Redraw to try again.');
        else if (e) setAnnounce(`Estimated ${num(e.total)} letterboxes: ${num(e.houses)} houses, ${num(e.units)} units${!admin && est.data?.quote ? `, fee ${money(est.data.quote.total_cents)}` : ''}.`);
    }, [est.data, est.error, admin]);

    /* ---------------- derived UI ---------------- */
    const hint = (() => {
        if (!edit) return null;
        if (edit.phase === 'drawing') {
            if (edit.points.length === 0) return 'Click the map to drop the first pin';
            if (edit.points.length < 3) return 'Keep clicking to add pins · Backspace removes the last pin';
            return 'Click the first pin or press Enter to close the area · Backspace removes the last pin';
        }
        if (edit.phase === 'tracing') return 'Press and drag to trace your area';
        if (terra && edit.phase === 'editing') return 'Drag pins to reshape · drag + to add a pin · right-click a pin to remove it';
        if (edit.phase === 'suburb') return 'Search for a suburb in the panel';
        return null;
    })();
    const addrHint = addressesShown && addr ? (addr.zoomedOut ? 'Zoom in to see addresses' : addr.capped ? `Showing first ${num(addr.count)} addresses — zoom in for more` : addr.error ? 'Addresses unavailable right now' : null) : null;

    const summary: Summary = { ...totals, per_item_cents: totals.fee_cents != null && totals.letterboxes ? totals.fee_cents / totals.letterboxes : null };
    const editIndex = edit ? areas.findIndex((a) => a.key === edit.key) : -1;
    const modeInfo = DRAW_MODES.find((m) => m.mode === (edit?.mode ?? lastMode))!;

    const footer = (
        <div className="flex flex-col gap-0.5 text-[11px] leading-snug text-muted">
            <p>Counts are estimates based on G-NAF address data. · {rail.satellite ? 'Imagery © Esri' : cfg.attribution}</p>
            <GnafAttribution className="line-clamp-2 max-w-[640px]" />
        </div>
    );

    return (
        <div ref={rootRef} className={cn('relative isolate h-full min-h-[480px] w-full overflow-hidden bg-surface-2', className)} data-testid="audience-builder">
            <div className="absolute inset-0 z-0" data-tour="map">
                <div ref={mapRef} className="size-full" role="application" aria-label="Map. Use the panels or Suburb mode to build areas with the keyboard." />
            </div>
            {!provider && !mapError && (
                <div className="absolute inset-0 z-[1] grid place-items-center">
                    <Loader2 className="size-6 animate-spin text-subtle" aria-label="Loading map" />
                </div>
            )}
            {mapError && <p className="absolute inset-x-0 top-1/3 z-[1] text-center text-sm text-muted">The map could not be loaded. Refresh the page to try again.</p>}

            {provider && rail.fadeOutside && focus && <AreaFocusMask provider={provider} areas={focus} />}
            {provider && addressesShown && <AddressLayer provider={provider} minZoom={cfg.viewport_min_zoom ?? 16} onStatus={setAddr} clip={rail.addressesAll ? null : focus} />}
            {provider && (
                <DrawOverlay
                    provider={provider}
                    areas={overlayAreas}
                    editingKey={edit?.key ?? null}
                    phase={terra ? null : (edit?.phase ?? null)}
                    points={terra ? [] : (edit?.points ?? [])}
                    pickup={pickup}
                    radiusRing={radiusRing}
                    radiusKm={radiusKm}
                    boundary={boundary}
                    tracks={tracks}
                    marker={marker}
                    readOnly={readOnly}
                    admin={admin}
                    onPointsChange={setPoints}
                    onClose={closePolygon}
                    onTraceComplete={(pts) => setEdit((e) => (e ? { ...e, points: pts, phase: 'editing' } : e))}
                    onSelectArea={startEdit}
                    onDeleteRequest={requestDelete}
                />
            )}
            {provider && terra && (
                <TerraDrawLayer
                    provider={provider}
                    phase={edit?.phase ?? null}
                    points={edit?.points ?? []}
                    color={areaColor(editIndex >= 0 ? editIndex : areas.length)}
                    onPointsChange={setPoints}
                    onComplete={(pts) => setEdit((e) => (e ? { ...e, points: pts, phase: 'editing' } : e))}
                />
            )}

            {readOnly ? (
                areas.length > 0 && (
                    <ul className="pointer-events-auto absolute top-3 left-3 z-20 flex max-w-[calc(100%-5rem)] flex-col gap-1 rounded-[14px] border border-border bg-surface/95 p-2.5 text-xs shadow-md backdrop-blur" aria-label="Legend" data-testid="map-legend">
                        {overlayAreas.map((a) => (
                            <li key={a.key} className="flex items-center gap-2 text-fg">
                                <span className="size-2.5 shrink-0 rounded-full" style={{ background: a.color }} aria-hidden />
                                <span className="truncate font-medium">
                                    {a.index + 1}. {a.name}
                                </span>
                                {a.estimate && <span className="ml-auto pl-2 text-muted tabular-nums">{num(a.estimate.total)}</span>}
                            </li>
                        ))}
                        {!!tracks?.length && (
                            <li className="mt-1 flex items-center gap-2 border-t border-border pt-1.5 text-muted">
                                <span className="h-0.5 w-3 rounded bg-[var(--map-pickup)]" aria-hidden /> GPS track · latest position pulses
                            </li>
                        )}
                    </ul>
                )
            ) : (
            <LeftPanel
                areas={overlayAreas}
                includeHouses={houses}
                includeUnits={units}
                onToggle={toggleAudience}
                busy={!!edit}
                readOnly={readOnly}
                admin={admin}
                onCancel={cancel}
                onSelect={startEdit}
                onDelete={(k) => setConfirmKey(k)}
                totals={totals}
                footer={<div className="md:hidden">{footer}</div>}
                addAreaSlot={
                    <Popover open={menuOpen} onOpenChange={setMenuOpen}>
                        <PopoverAnchor asChild>
                            <div>
                                <AddAreaButton onClick={() => setMenuOpen((o) => !o)} aria-haspopup="menu" aria-expanded={menuOpen} />
                            </div>
                        </PopoverAnchor>
                        <PopoverContent side={narrowViewport() ? 'top' : 'right'} align="start" sideOffset={narrowViewport() ? 8 : 16} collisionPadding={12}>
                            <DrawModeMenu selected={lastMode} onPick={startNew} />
                        </PopoverContent>
                    </Popover>
                }
            />
            )}

            {/* right panel: editing card or totals */}
            <div className="pointer-events-none absolute top-3 right-28 left-3 z-20 flex max-h-[42%] flex-col md:top-4 md:right-[76px] md:left-auto md:max-h-[calc(100%-5rem)]">
                {edit ? (
                    <EditingPanel
                        name={edit.name}
                        onRename={(name) => setEdit((e) => (e ? { ...e, name } : e))}
                        est={est}
                        phase={edit.phase}
                        pointsCount={edit.points.length}
                        canDone={canDone}
                        admin={admin}
                        approximate={edit.approximate}
                        onDone={done}
                        onRedraw={() => switchMode(edit.mode)}
                        coords={edit.points}
                        onCoordsChange={setPoints}
                        drawModeSlot={
                            <Popover open={modeMenuOpen} onOpenChange={setModeMenuOpen}>
                                <PopoverTrigger asChild>
                                    <button type="button" className="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs font-medium text-fg hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-focus" data-testid="draw-mode-button">
                                        <modeInfo.icon className="size-3.5" aria-hidden /> Draw mode: {modeInfo.title}
                                        <ChevronDown className="size-3.5 text-muted" aria-hidden />
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent side="bottom" align="start" collisionPadding={12}>
                                    <DrawModeMenu selected={edit.mode} onPick={switchMode} />
                                </PopoverContent>
                            </Popover>
                        }
                        suburbSlot={
                            <Combobox<SuburbHit>
                                label="Search suburbs"
                                placeholder="Suburb or postcode, e.g. Rydalmere"
                                testId="suburb-search"
                                autoFocus
                                fetcher={(q, signal) => api<{ data: SuburbHit[] }>(`/api/v1/suburbs?q=${encodeURIComponent(q)}`, { signal }).then((r) => r.data)}
                                getKey={(s) => s.id}
                                render={(s) => (
                                    <span className="flex items-center justify-between gap-2">
                                        <span>{s.label}</span>
                                        {s.stats && <span className="text-xs text-muted tabular-nums">~{num(s.stats.letterboxes)}</span>}
                                    </span>
                                )}
                                onSelect={pickSuburb}
                            />
                        }
                    />
                ) : areas.length > 0 && !readOnly ? (
                    <SummaryCard s={summary} admin={admin} />
                ) : null}
            </div>

            <MapRail
                className="pointer-events-auto absolute top-3 right-3 z-20 md:top-auto md:right-4 md:bottom-16"
                state={rail}
                minimal={readOnly}
                onToggle={(k) => setRail((r) => ({ ...r, [k]: !r[k] }))}
                onHelp={readOnly ? undefined : () => setTourOpen(true)}
                radiusKm={radiusKm}
                onRadius={radiusRing ? () => provider?.fitBounds(bounds(radiusRing), 40) : undefined}
                onFound={(ll, label) => {
                    setMarker(ll);
                    provider?.flyTo(ll, 17);
                    setAnnounce(`Showing ${label}`);
                }}
            />

            {/* hint pills */}
            <div className="pointer-events-none absolute inset-x-0 bottom-[calc(55%+12px)] z-10 flex flex-col items-center gap-2 px-4 md:bottom-16">
                {hint && !readOnly && (
                    <p className="rounded-full bg-ink-900/90 px-4 py-2 text-center text-sm font-medium text-white shadow-lg backdrop-blur dark:bg-white/90 dark:text-ink-900" data-testid="draw-hint">
                        {hint}
                    </p>
                )}
                {addrHint && <p className="rounded-full border border-border bg-surface/95 px-3 py-1.5 text-xs font-medium text-fg shadow-md">{addrHint}</p>}
                {addressesShown && addr && !addr.zoomedOut && (
                    <p className="flex items-center gap-3 rounded-full border border-border bg-surface/95 px-3 py-1.5 text-xs text-fg shadow-md" aria-label="Address legend">
                        <span className="flex items-center gap-1.5">
                            <span className="size-2 rounded-full bg-[var(--map-house)]" aria-hidden /> House
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="size-2 bg-[var(--map-unit)]" aria-hidden /> Unit
                        </span>
                    </p>
                )}
            </div>

            <div className="pointer-events-auto absolute inset-x-0 bottom-0 z-10 hidden items-end justify-between gap-4 bg-gradient-to-t from-surface-2/90 to-transparent px-4 pt-6 pb-7 md:flex">{footer}</div>

            {!readOnly && <Tour open={tourOpen} onClose={() => setTourOpen(false)} root={rootRef.current} />}

            <Dialog open={!!confirmKey} onOpenChange={(o) => !o && setConfirmKey(null)}>
                {confirmKey && (
                    <DialogContent title="Delete this area?" description={`${areas.find((a) => a.key === confirmKey)?.name ?? 'This area'} will be removed from your audience.`}>
                        <div className="mt-4 flex justify-end gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Keep area</Button>
                            </DialogClose>
                            <Button variant="danger" onClick={() => deleteArea(confirmKey)} data-testid="confirm-delete" autoFocus>
                                Delete area
                            </Button>
                        </div>
                    </DialogContent>
                )}
            </Dialog>

            <p className="sr-only" aria-live="polite" aria-atomic="true" data-testid="map-live">
                {announce}
            </p>
        </div>
    );
}
