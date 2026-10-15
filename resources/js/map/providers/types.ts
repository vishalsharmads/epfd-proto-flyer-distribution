import type { LngLat } from '@/types';

export type BaseStyle = 'streets' | 'satellite';
export type MapEvent = 'move' | 'moveend' | 'zoom' | 'click';
export type Bbox = [number, number, number, number];

export interface MapClick {
    lngLat: LngLat;
    point: { x: number; y: number };
}

export interface MapConfig {
    base_tile_url: string;
    satellite_tile_url: string;
    attribution: string;
    google_key: string | null;
}

export interface MapInit {
    container: HTMLElement;
    center: LngLat;
    zoom: number;
    config: MapConfig;
    dark: boolean;
}

/**
 * Everything the audience builder needs from a map engine. Drawing is NOT delegated to the
 * engine: polygons, pins and handles live in our own SVG overlay and use `project`/`unproject`,
 * so every provider behaves identically.
 */
export interface MapProvider {
    readonly kind: 'maplibre' | 'google';
    /** Resolves once the map can project coordinates. */
    ready: Promise<void>;
    setView(center: LngLat, zoom: number): void;
    flyTo(center: LngLat, zoom?: number): void;
    fitBounds(bbox: Bbox, padding?: number): void;
    project(lngLat: LngLat): { x: number; y: number };
    unproject(point: { x: number; y: number }): LngLat;
    getBounds(): Bbox;
    getZoom(): number;
    setBase(style: BaseStyle): void;
    /** Street/place labels on top of the base. */
    setLabels(on: boolean): void;
    /** Street network (base raster) visibility; off leaves a plain background. */
    setStreets(on: boolean): void;
    setDark(dark: boolean): void;
    /** Enable/disable user panning/zooming (disabled while dragging a pin or tracing). */
    setInteractive(on: boolean): void;
    on(event: 'click', cb: (e: MapClick) => void): () => void;
    on(event: Exclude<MapEvent, 'click'>, cb: () => void): () => void;
    resize(): void;
    destroy(): void;
}
