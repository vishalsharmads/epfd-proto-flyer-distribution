import { token } from '@/lib/tokens';
import type { LngLat } from '@/types';
import maplibregl, { type StyleSpecification } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import type { BaseStyle, Bbox, MapClick, MapInit, MapProvider } from './types';

/**
 * MapLibre GL JS provider: raster-only style, no vector tiles or API keys.
 *
 * Layers (bottom → top):
 *   bg        solid background (token --color-surface-2) — visible when "Show streets" is off
 *   streets   shared `map.base_tile_url` (OSM), desaturated + lightened for the calm grey look;
 *             in dark mode the luminance is inverted (brightness-min > brightness-max) for a dark map
 *   nolabels  CARTO "light_nolabels" — swapped in for `streets` when "Show labels" is off
 *   satellite shared `map.satellite_tile_url`
 *   labels    CARTO "light_only_labels" — label overlay drawn over satellite / plain background
 *
 * "Show labels" therefore works in every combination: streets(+labels) = OSM, streets(−labels)
 * = CARTO no-labels, satellite/plain(+labels) = label overlay on top.
 */
const CARTO_NOLABELS = ['a', 'b', 'c'].map((s) => `https://${s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}.png`);
const CARTO_LABELS = ['a', 'b', 'c'].map((s) => `https://${s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}.png`);

function rasterPaint(dark: boolean) {
    return dark
        ? { 'raster-saturation': -1, 'raster-brightness-min': 0.86, 'raster-brightness-max': 0.1, 'raster-contrast': -0.1 }
        : { 'raster-saturation': -0.9, 'raster-brightness-min': 0.14, 'raster-brightness-max': 1, 'raster-contrast': -0.15 };
}

export class MapLibreProvider implements MapProvider {
    readonly kind = 'maplibre' as const;
    ready: Promise<void>;
    private map: maplibregl.Map;
    private base: BaseStyle = 'streets';
    private labels = true;
    private streets = true;
    private dark: boolean;

    constructor({ container, center, zoom, config, dark }: MapInit) {
        this.dark = dark;
        const style: StyleSpecification = {
            version: 8,
            sources: {
                streets: { type: 'raster', tiles: [config.base_tile_url], tileSize: 256, maxzoom: 19, attribution: config.attribution },
                nolabels: { type: 'raster', tiles: CARTO_NOLABELS, tileSize: 256, maxzoom: 19, attribution: '© CARTO' },
                satellite: { type: 'raster', tiles: [config.satellite_tile_url], tileSize: 256, maxzoom: 19, attribution: 'Imagery © Esri' },
                labels: { type: 'raster', tiles: CARTO_LABELS, tileSize: 256, maxzoom: 19 },
            },
            layers: [
                { id: 'bg', type: 'background', paint: { 'background-color': token('--color-surface-2') } },
                { id: 'streets', type: 'raster', source: 'streets', paint: rasterPaint(dark) },
                { id: 'nolabels', type: 'raster', source: 'nolabels', layout: { visibility: 'none' }, paint: rasterPaint(dark) },
                { id: 'satellite', type: 'raster', source: 'satellite', layout: { visibility: 'none' } },
                { id: 'labels', type: 'raster', source: 'labels', layout: { visibility: 'none' }, paint: dark ? { 'raster-brightness-min': 1, 'raster-brightness-max': 0 } : {} },
            ],
        };
        this.map = new maplibregl.Map({
            container,
            style,
            center,
            zoom,
            maxZoom: 19,
            attributionControl: false,
            dragRotate: false,
            pitchWithRotate: false,
            touchPitch: false,
        });
        this.map.touchZoomRotate.disableRotation();
        this.ready = new Promise((resolve) => {
            if (this.map.loaded()) resolve();
            else this.map.once('load', () => resolve());
        });
    }

    private applyLayers() {
        const vis = (id: string, on: boolean) => this.map.getLayer(id) && this.map.setLayoutProperty(id, 'visibility', on ? 'visible' : 'none');
        const sat = this.base === 'satellite';
        vis('streets', !sat && this.streets && this.labels);
        vis('nolabels', !sat && this.streets && !this.labels);
        vis('satellite', sat);
        vis('labels', this.labels && (sat || !this.streets));
    }

    setView(center: LngLat, zoom: number) {
        this.map.jumpTo({ center, zoom });
    }
    flyTo(center: LngLat, zoom?: number) {
        const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        this.map.flyTo({ center, zoom: zoom ?? this.map.getZoom(), duration: reduce ? 0 : 900, essential: true });
    }
    fitBounds(b: Bbox, padding = 80) {
        const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        this.map.fitBounds([[b[0], b[1]], [b[2], b[3]]], { padding, duration: reduce ? 0 : 700, maxZoom: 17 });
    }
    project(ll: LngLat) {
        const p = this.map.project(ll);
        return { x: p.x, y: p.y };
    }
    unproject(p: { x: number; y: number }): LngLat {
        const ll = this.map.unproject([p.x, p.y]);
        return [ll.lng, ll.lat];
    }
    getBounds(): Bbox {
        const b = this.map.getBounds();
        return [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()];
    }
    getZoom() {
        return this.map.getZoom();
    }
    setBase(style: BaseStyle) {
        this.base = style;
        this.applyLayers();
    }
    setLabels(on: boolean) {
        this.labels = on;
        this.applyLayers();
    }
    setStreets(on: boolean) {
        this.streets = on;
        this.applyLayers();
    }
    setDark(dark: boolean) {
        if (dark === this.dark) return;
        this.dark = dark;
        for (const id of ['streets', 'nolabels']) {
            for (const [k, v] of Object.entries(rasterPaint(dark))) this.map.setPaintProperty(id, k, v);
        }
        this.map.setPaintProperty('labels', 'raster-brightness-min', dark ? 1 : 0);
        this.map.setPaintProperty('labels', 'raster-brightness-max', dark ? 0 : 1);
        this.map.setPaintProperty('bg', 'background-color', token('--color-surface-2'));
    }
    setInteractive(on: boolean) {
        const m = this.map;
        for (const h of [m.dragPan, m.scrollZoom, m.doubleClickZoom, m.touchZoomRotate, m.boxZoom, m.keyboard]) {
            if (on) h.enable();
            else h.disable();
        }
    }
    on(event: 'click', cb: (e: MapClick) => void): () => void;
    on(event: 'move' | 'moveend' | 'zoom', cb: () => void): () => void;
    on(event: string, cb: ((e: MapClick) => void) | (() => void)): () => void {
        if (event === 'click') {
            const h = (e: maplibregl.MapMouseEvent) => (cb as (e: MapClick) => void)({ lngLat: [e.lngLat.lng, e.lngLat.lat], point: { x: e.point.x, y: e.point.y } });
            this.map.on('click', h);
            return () => this.map.off('click', h);
        }
        const h = () => (cb as () => void)();
        this.map.on(event as 'move', h);
        return () => this.map.off(event as 'move', h);
    }
    native(): unknown {
        return this.map;
    }

    resize() {
        this.map.resize();
    }
    destroy() {
        this.map.remove();
    }
}
