import { Kbd } from '@/components/ui/surface';
import { token, tokens } from '@/lib/tokens';
import { cn, num } from '@/lib/utils';
import type { GeoJsonPolygon, LngLat } from '@/types';
import { Mail, Trash2 } from 'lucide-react';
import { useRef, useState, type PointerEvent as RPointerEvent } from 'react';
import { simplifyTrace, untangleTrace } from '../geometry/douglasPeucker';
import { fromPolygon, labelPoint, topMost } from '../geometry/polygon';
import { useMapTick } from '../hooks/useMapTick';
import type { MapProvider } from '../providers';
import type { BuilderArea, GpsTrack, Pickup } from '../types';

export type Phase = 'drawing' | 'tracing' | 'suburb' | 'editing';

export interface OverlayArea extends BuilderArea {
    key: string;
    color: string;
    index: number;
}

interface Props {
    provider: MapProvider;
    areas: OverlayArea[];
    editingKey: string | null;
    phase: Phase | null;
    points: LngLat[];
    pickup?: Pickup | null;
    radiusRing?: LngLat[] | null;
    boundary?: GeoJsonPolygon | null;
    tracks?: GpsTrack[];
    marker?: LngLat | null;
    readOnly?: boolean;
    admin?: boolean;
    radiusKm?: number;
    onPointsChange: (points: LngLat[]) => void;
    onClose: () => void;
    onTraceComplete: (points: LngLat[]) => void;
    onSelectArea: (key: string) => void;
    onDeleteRequest: () => void;
}

const PIN_R = 7;
const HIT_R = 22; // 44 px touch target

/**
 * Provider-independent drawing layer: an SVG over the map re-projected on every move/zoom.
 * Handles vertex dragging, midpoint insertion, rubber-band and freehand trace with pointer events.
 */
export function DrawOverlay(p: Props) {
    const { provider } = p;
    useMapTick(provider);
    const svgRef = useRef<SVGSVGElement>(null);
    const drag = useRef<{ index: number; startX: number; startY: number; moved: boolean; pointerId: number } | null>(null);
    const [cursor, setCursor] = useState<{ x: number; y: number } | null>(null);
    const [trace, setTrace] = useState<{ x: number; y: number }[] | null>(null);
    const pointsRef = useRef(p.points);
    pointsRef.current = p.points;

    const local = (e: { clientX: number; clientY: number }) => {
        const r = svgRef.current!.getBoundingClientRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    };
    const proj = (ll: LngLat) => provider.project(ll);
    const path = (ring: LngLat[], close = true) => {
        if (!ring.length) return '';
        const d = ring.map((ll, i) => {
            const q = proj(ll);
            return `${i ? 'L' : 'M'}${q.x.toFixed(1)},${q.y.toFixed(1)}`;
        });
        return d.join('') + (close ? 'Z' : '');
    };

    /* ---------- vertex drag ---------- */
    const startDrag = (index: number, e: RPointerEvent<SVGElement>) => {
        if (p.readOnly) return;
        e.stopPropagation();
        e.preventDefault();
        (e.currentTarget as Element).setPointerCapture(e.pointerId);
        provider.setInteractive(false);
        drag.current = { index, startX: e.clientX, startY: e.clientY, moved: false, pointerId: e.pointerId };
    };
    const onDragMove = (e: RPointerEvent<SVGElement>) => {
        const d = drag.current;
        if (!d || d.pointerId !== e.pointerId) return;
        if (!d.moved && Math.hypot(e.clientX - d.startX, e.clientY - d.startY) < 3) return;
        d.moved = true;
        const ll = provider.unproject(local(e));
        const next = pointsRef.current.slice();
        next[d.index] = ll;
        p.onPointsChange(next);
    };
    const endDrag = (e: RPointerEvent<SVGElement>) => {
        const d = drag.current;
        if (!d || d.pointerId !== e.pointerId) return;
        drag.current = null;
        provider.setInteractive(true);
        if (!d.moved && p.phase === 'drawing' && d.index === 0 && pointsRef.current.length >= 3) p.onClose();
    };
    const insertAt = (i: number, e: RPointerEvent<SVGElement>) => {
        const pts = pointsRef.current;
        const a = pts[i],
            b = pts[(i + 1) % pts.length];
        const next = pts.slice();
        next.splice(i + 1, 0, [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2]);
        p.onPointsChange(next);
        pointsRef.current = next;
        startDrag(i + 1, e);
        if (drag.current) drag.current.moved = true; // a tap on "+" still inserts
    };

    /* ---------- freehand trace ---------- */
    const traceDown = (e: RPointerEvent<SVGRectElement>) => {
        e.preventDefault();
        (e.currentTarget as Element).setPointerCapture(e.pointerId);
        provider.setInteractive(false);
        setTrace([local(e)]);
    };
    const traceMove = (e: RPointerEvent<SVGRectElement>) => {
        if (!trace) return;
        const coalesced = (e.nativeEvent.getCoalescedEvents?.() ?? [e.nativeEvent]).map(local);
        setTrace((t) => (t ? [...t, ...coalesced] : t));
    };
    const traceUp = () => {
        provider.setInteractive(true);
        const t = trace;
        setTrace(null);
        if (!t || t.length < 3) return;
        const simplified = untangleTrace(simplifyTrace(t, 5, 150));
        if (simplified.length < 3) return;
        p.onTraceComplete(simplified.map((q) => provider.unproject(q)));
    };

    const editing = p.editingKey !== null;
    const pts = p.points;
    const drawing = p.phase === 'drawing';
    const closed = p.phase === 'editing';
    const outline = tokens.outline;
    const brand = token('--brand-500');
    const surface = token('--color-surface');

    const top = closed && pts.length ? proj(topMost(pts)!) : null;

    return (
        <div className="pointer-events-none absolute inset-0 z-[5] overflow-hidden" onPointerMove={drawing ? (e) => setCursor(local(e)) : undefined} onPointerLeave={() => setCursor(null)}>
            <svg ref={svgRef} data-testid="map-overlay" className="absolute inset-0 size-full" aria-hidden style={{ touchAction: p.phase === 'tracing' ? 'none' : undefined }}>
                {/* pickup radius */}
                {p.radiusRing && <path d={path(p.radiusRing)} fill="none" stroke={tokens.pickup} strokeWidth={1.5} strokeDasharray="6 6" opacity={0.7} />}
                {/* admin reference boundary */}
                {p.boundary && <path d={path(fromPolygon(p.boundary))} fill="none" stroke={outline} strokeWidth={2} strokeDasharray="2 6" strokeLinecap="round" opacity={0.6} />}

                {/* saved areas */}
                {p.areas.map((a) =>
                    a.key === p.editingKey ? null : (
                        <path key={a.key} d={path(fromPolygon(a.geometry))} fill={a.color} fillOpacity={editing ? 0.1 : 0.18} stroke={outline} strokeOpacity={editing ? 0.45 : 0.85} strokeWidth={1.5} strokeDasharray="5 4" />
                    ),
                )}

                {/* GPS tracks */}
                {p.tracks?.map((t, i) => (
                    <path key={i} d={path(t.points, false)} fill="none" stroke={t.color ?? tokens.pickup} strokeWidth={3} strokeLinejoin="round" strokeLinecap="round" opacity={0.9} />
                ))}

                {/* polygon being edited */}
                {editing && pts.length > 0 && (
                    <g>
                        {closed ? (
                            <path d={path(pts)} fill={brand} fillOpacity={0.18} stroke={outline} strokeWidth={2} strokeDasharray="6 4" strokeLinejoin="round" />
                        ) : (
                            <path d={path(pts, false)} fill="none" stroke={outline} strokeWidth={2} strokeDasharray="6 4" />
                        )}
                        {drawing && cursor && (
                            <line x1={proj(pts[pts.length - 1]).x} y1={proj(pts[pts.length - 1]).y} x2={cursor.x} y2={cursor.y} stroke={outline} strokeWidth={1.5} strokeDasharray="3 4" opacity={0.7} />
                        )}
                        {/* midpoint + handles */}
                        {closed &&
                            pts.map((a, i) => {
                                const b = pts[(i + 1) % pts.length];
                                const pa = proj(a),
                                    pb = proj(b);
                                if (Math.hypot(pa.x - pb.x, pa.y - pb.y) < 36) return null;
                                const mx = (pa.x + pb.x) / 2,
                                    my = (pa.y + pb.y) / 2;
                                return (
                                    <g key={`m${i}`} className="pointer-events-auto cursor-copy" onPointerDown={(e) => insertAt(i, e)} onPointerMove={onDragMove} onPointerUp={endDrag} onPointerCancel={endDrag} data-testid="midpoint-handle">
                                        <circle cx={mx} cy={my} r={HIT_R - 6} fill="transparent" />
                                        <circle cx={mx} cy={my} r={6} fill={surface} stroke={outline} strokeWidth={1.25} opacity={0.9} />
                                        <path d={`M${mx - 3},${my}H${mx + 3}M${mx},${my - 3}V${my + 3}`} stroke={outline} strokeWidth={1.4} strokeLinecap="round" />
                                    </g>
                                );
                            })}
                        {/* vertex pins */}
                        {pts.map((ll, i) => {
                            const q = proj(ll);
                            const isFirst = i === 0 && drawing && pts.length >= 3;
                            return (
                                <g key={`v${i}`} className="pointer-events-auto cursor-grab active:cursor-grabbing" onPointerDown={(e) => startDrag(i, e)} onPointerMove={onDragMove} onPointerUp={endDrag} onPointerCancel={endDrag} data-testid="vertex-pin">
                                    <circle cx={q.x} cy={q.y} r={HIT_R} fill="transparent" />
                                    {isFirst && <circle cx={q.x} cy={q.y} r={PIN_R + 5} fill={brand} opacity={0.25} />}
                                    <circle cx={q.x} cy={q.y} r={PIN_R} fill={surface} stroke={outline} strokeWidth={2} />
                                </g>
                            );
                        })}
                    </g>
                )}

                {/* freehand trace capture surface */}
                {p.phase === 'tracing' && (
                    <>
                        <rect className="pointer-events-auto cursor-crosshair" x={0} y={0} width="100%" height="100%" fill="transparent" onPointerDown={traceDown} onPointerMove={traceMove} onPointerUp={traceUp} onPointerCancel={traceUp} data-testid="trace-surface" />
                        {trace && trace.length > 1 && <polyline points={trace.map((q) => `${q.x},${q.y}`).join(' ')} fill="none" stroke={outline} strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round" />}
                    </>
                )}
            </svg>

            {/* pickup marker + label */}
            {p.pickup && <PickupMarker at={proj([p.pickup.lng, p.pickup.lat])} label={p.pickup.label} admin={p.admin} radiusKm={p.radiusKm ?? 20} />}

            {/* address search marker */}
            {p.marker && (
                <span className="absolute size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-ink-900 shadow-md ring-4 ring-ink-900/15" style={{ left: proj(p.marker).x, top: proj(p.marker).y }} aria-hidden />
            )}

            {/* saved area chips */}
            {p.areas.map((a) => {
                if (a.key === p.editingKey) return null;
                const ring = fromPolygon(a.geometry);
                if (ring.length < 3) return null;
                const q = proj(labelPoint(ring));
                return (
                    <button
                        key={a.key}
                        type="button"
                        disabled={p.readOnly || editing}
                        onClick={() => p.onSelectArea(a.key)}
                        className={cn(
                            'pointer-events-auto absolute -translate-x-1/2 -translate-y-1/2 rounded-[10px] border border-border bg-surface/95 px-2 py-1 text-left shadow-md backdrop-blur transition-opacity focus-visible:outline-2 focus-visible:outline-focus disabled:cursor-default',
                            editing && 'opacity-60',
                            !p.readOnly && !editing && 'hover:shadow-lg',
                        )}
                        style={{ left: q.x, top: q.y }}
                        aria-label={`${a.index + 1}. ${a.name}${a.estimate ? `, ${a.estimate.total} letterboxes` : ''}${p.readOnly ? '' : '. Edit area'}`}
                        data-testid="area-chip"
                    >
                        <span className="flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap text-fg">
                            <span className="size-2.5 rounded-full" style={{ background: a.color }} aria-hidden />
                            {a.index + 1}. {a.name}
                            {a.estimate && (
                                <span className="inline-flex items-center gap-0.5 font-medium text-muted">
                                    <Mail className="size-3" aria-hidden /> {num(a.estimate.total)}
                                </span>
                            )}
                        </span>
                        {a.estimate && (
                            <span className="block text-[10px] leading-tight text-subtle">
                                {num(a.estimate.houses)} H · {num(a.estimate.units)} U
                            </span>
                        )}
                    </button>
                );
            })}

            {/* delete pill above the area while editing */}
            {top && !p.readOnly && (
                <button
                    type="button"
                    onClick={p.onDeleteRequest}
                    className="pointer-events-auto absolute flex -translate-x-1/2 -translate-y-full items-center gap-1.5 rounded-full bg-danger px-3 py-1.5 text-xs font-semibold text-white shadow-lg transition-transform hover:scale-[1.03] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    style={{ left: top.x, top: Math.max(40, top.y - 18) }}
                    data-testid="delete-area-pill"
                    data-tour="delete"
                >
                    <Trash2 className="size-3.5" aria-hidden /> Delete area <Kbd className="border-white/30 bg-white/15 text-white shadow-none">⌫</Kbd>
                </button>
            )}
        </div>
    );
}

function PickupMarker({ at, label, admin, radiusKm }: { at: { x: number; y: number }; label?: string; admin?: boolean; radiusKm: number }) {
    return (
        <div className="absolute" style={{ left: at.x, top: at.y }} data-testid="pickup-marker">
            <span className="absolute size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-[3px] border-white shadow-md" style={{ background: tokens.pickup }} aria-hidden />
            <div className="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-[10px] border border-border bg-surface/95 px-2.5 py-1 text-center whitespace-nowrap shadow-md backdrop-blur">
                <p className="text-xs font-semibold text-fg">{label ? `Pickup · ${label}` : 'Pickup location'}</p>
                {!admin && <p className="text-[10px] font-medium" style={{ color: tokens.pickup }}>All areas must be partly within {radiusKm} km</p>}
            </div>
        </div>
    );
}
