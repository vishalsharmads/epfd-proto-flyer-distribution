import type { LngLat } from '@/types';
import { useEffect, useRef } from 'react';
import { TerraDraw, TerraDrawFreehandMode, TerraDrawPolygonMode, TerraDrawSelectMode, type GeoJSONStoreFeatures, type HexColor } from 'terra-draw';
import { toast } from 'sonner';
import { simplifyTrace, untangleTrace } from '../geometry/douglasPeucker';
import { tidyRing } from '../geometry/polygon';
import type { MapProvider } from '../providers';
import type { Phase } from './DrawOverlay';

interface Props {
    provider: MapProvider;
    phase: Phase | null;
    points: LngLat[];
    color: string;
    onPointsChange: (points: LngLat[]) => void;
    /** Pins or trace finished: the closed ring (without the repeated first point). */
    onComplete: (points: LngLat[]) => void;
}

const MAX_VERTICES = 150;

/** Open ring (our LngLat[] shape) from a Terra Draw polygon feature. */
function ringOf(f: GeoJSONStoreFeatures | undefined): LngLat[] {
    if (!f || f.geometry.type !== 'Polygon') return [];
    const ring = f.geometry.coordinates[0] as LngLat[];
    return ring.slice(0, -1).map(([x, y]) => [x, y] as LngLat);
}

/** Same clean-up as the SVG overlay's trace: simplify in screen pixels, cap vertices, remove self-crossings. */
function cleanTrace(provider: MapProvider, points: LngLat[]): LngLat[] {
    const px = untangleTrace(
        simplifyTrace(
            points.map((p) => provider.project(p)),
            5,
            MAX_VERTICES,
        ),
    );
    return px.length >= 3 ? px.map((q) => provider.unproject(q)) : [];
}

const same = (a: LngLat[], b: LngLat[]) => a.length === b.length && a.every((p, i) => p[0] === b[i][0] && p[1] === b[i][1]);

async function createAdapter(provider: MapProvider) {
    const map = provider.native();
    if (provider.kind === 'google') {
        const { TerraDrawGoogleMapsAdapter } = await import('terra-draw-google-maps-adapter');
        const g = (window as unknown as { google: { maps: never } }).google.maps;
        const div = (map as { getDiv: () => HTMLElement }).getDiv();
        if (!div.id) div.id = 'epfd-google-map';
        return new TerraDrawGoogleMapsAdapter({ lib: g, map: map as never, coordinatePrecision: 9 });
    }
    const { TerraDrawMapLibreGLAdapter } = await import('terra-draw-maplibre-gl-adapter');
    return new TerraDrawMapLibreGLAdapter({ map: map as never, coordinatePrecision: 9 });
}

/**
 * POC drawing engine: Terra Draw renders and edits the area being drawn, inside the map engine itself
 * (MapLibre layers or Google data layer). Saved areas, pickup pin and radius ring stay on DrawOverlay.
 * Phases map to Terra Draw modes: drawing → polygon, tracing → freehand (press and drag), editing → select.
 */
export function TerraDrawLayer({ provider, phase, points, color, onPointsChange, onComplete }: Props) {
    const drawRef = useRef<TerraDraw | null>(null);
    const featureId = useRef<string | number | null>(null);
    const lastEmitted = useRef<LngLat[]>([]);
    const cb = useRef({ onPointsChange, onComplete });
    cb.current = { onPointsChange, onComplete };
    const phaseRef = useRef(phase);
    phaseRef.current = phase;
    const pointsRef = useRef(points);
    pointsRef.current = points;

    /**
     * Put `pts` on the map as the one editable feature and select it. Terra Draw refuses outlines it reads as
     * crossing themselves, which left the pins impossible to drag: tidy the outline first (drop sub-metre points
     * and spikes), then fall back to a light on-screen simplification, and say so if it still cannot be edited.
     */
    const showEditable = (draw: TerraDraw, pts: LngLat[]) => {
        draw.clear();
        featureId.current = null;
        if (pts.length < 3) return;
        draw.setMode('select');
        const add = (ring: LngLat[]) =>
            ring.length >= 3
                ? draw.addFeatures([
                      { type: 'Feature', geometry: { type: 'Polygon', coordinates: [[...ring, ring[0]]] }, properties: { mode: 'polygon' } },
                  ])[0]
                : undefined;
        const candidates = [tidyRing(pts), cleanTrace(provider, tidyRing(pts))];
        for (const ring of candidates) {
            const res = add(ring);
            if (!res?.valid) continue;
            featureId.current = res.id as string | number;
            draw.selectFeature(res.id as string | number);
            lastEmitted.current = ring;
            if (!same(ring, pts)) cb.current.onPointsChange(ring); // keep the tidied outline (and re-estimate it)
            return;
        }
        lastEmitted.current = pts;
        toast.error("This area's outline can't be edited. Delete it and draw it again.");
    };

    const applyPhase = (draw: TerraDraw) => {
        const p = phaseRef.current;
        if (p === 'drawing') {
            draw.clear();
            draw.setMode('polygon');
        } else if (p === 'tracing') {
            draw.clear();
            draw.setMode('freehand');
        } else if (p === 'editing') {
            showEditable(draw, pointsRef.current);
        } else {
            draw.clear();
            draw.setMode('static');
        }
    };

    useEffect(() => {
        let disposed = false;
        let draw: TerraDraw | null = null;
        const hex = color as HexColor;
        const style = { fillColor: hex, fillOpacity: 0.18, outlineColor: hex, outlineWidth: 3 };
        // Pins and midpoints are draggable; the area itself is not, so pressing inside it pans the map
        // instead of accidentally moving the whole area.
        const editable = { feature: { draggable: false, coordinates: { midpoints: true, draggable: true, deletable: true } } };
        const touch = !!window.matchMedia?.('(pointer: coarse)').matches;

        createAdapter(provider).then((adapter) => {
            if (disposed) return;
            draw = new TerraDraw({
                adapter,
                modes: [
                    new TerraDrawPolygonMode({
                        styles: {
                            ...style,
                            closingPointColor: hex,
                            closingPointWidth: 7,
                            closingPointOutlineColor: '#ffffff',
                            closingPointOutlineWidth: 2,
                        },
                    }),
                    new TerraDrawFreehandMode({ drawInteraction: 'click-drag', styles: style }),
                    new TerraDrawSelectMode({
                        flags: { polygon: editable },
                        styles: {
                            selectedPolygonColor: hex,
                            selectedPolygonFillOpacity: 0.22,
                            selectedPolygonOutlineColor: hex,
                            selectedPolygonOutlineWidth: 3,
                            selectionPointColor: '#ffffff',
                            selectionPointOutlineColor: hex,
                            selectionPointOutlineWidth: 2,
                            selectionPointWidth: touch ? 10 : 6,
                            midPointColor: hex,
                            midPointOutlineColor: '#ffffff',
                            midPointWidth: touch ? 7 : 4,
                        },
                    }),
                ],
            });
            draw.on('ready', () => applyPhase(draw!));
            draw.on('finish', (id, ctx) => {
                if (!draw) return;
                const p = phaseRef.current;
                if (p === 'drawing' || p === 'tracing') {
                    const pts = ringOf(draw.getSnapshotFeature(id));
                    if (pts.length < 3) return;
                    const out = p === 'tracing' ? cleanTrace(provider, pts) : pts;
                    if (out.length < 3) return;
                    lastEmitted.current = out;
                    cb.current.onComplete(out);
                } else if (p === 'editing' && ctx?.action !== 'draw') {
                    const pts = ringOf(draw.getSnapshotFeature(id));
                    if (pts.length >= 3) {
                        lastEmitted.current = pts;
                        cb.current.onPointsChange(pts);
                    }
                }
            });
            // Keep the area selected: clicking empty map would otherwise drop the vertex handles.
            draw.on('deselect', () => {
                if (phaseRef.current === 'editing' && featureId.current != null) {
                    queueMicrotask(() => draw?.selectFeature(featureId.current!));
                }
            });
            drawRef.current = draw;
            draw.start();
        });

        return () => {
            disposed = true;
            draw?.stop();
            drawRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [provider, color]);

    /* phase changes (new draw, redraw, done, cancel) */
    useEffect(() => {
        const draw = drawRef.current;
        if (draw?.enabled) applyPhase(draw);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [phase]);

    /* points changed from outside the map (coordinate editor, suburb pick): redraw the feature */
    useEffect(() => {
        const draw = drawRef.current;
        if (!draw?.enabled || phase !== 'editing' || same(points, lastEmitted.current)) return;
        showEditable(draw, points);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [points, phase]);

    return null;
}
