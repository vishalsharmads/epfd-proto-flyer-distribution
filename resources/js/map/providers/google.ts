/* Google Maps JS provider. Loaded only when shared `map.provider === 'google'` (GOOGLE_MAPS_KEY set).
 * Uses the core Maps library only (no deprecated Drawing Library) — drawing is done by our SVG overlay. */
/* eslint-disable @typescript-eslint/no-explicit-any */
import type { LngLat } from '@/types';
import type { BaseStyle, Bbox, MapClick, MapInit, MapProvider } from './types';

declare global {
    interface Window {
        google?: any;
        __epfdGoogleLoading?: Promise<void>;
    }
}

function loadGoogle(key: string): Promise<void> {
    if (window.google?.maps?.Map) return Promise.resolve();
    if (window.__epfdGoogleLoading) return window.__epfdGoogleLoading;
    window.__epfdGoogleLoading = new Promise((resolve, reject) => {
        const cb = `__epfdGoogleReady${Date.now()}`;
        (window as any)[cb] = () => resolve();
        const s = document.createElement('script');
        s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&v=weekly&loading=async&callback=${cb}`;
        s.async = true;
        s.onerror = () => reject(new Error('Google Maps failed to load'));
        document.head.appendChild(s);
    });
    return window.__epfdGoogleLoading;
}

const greyStyles = (dark: boolean, labels: boolean, streets: boolean) => [
    { stylers: [{ saturation: -90 }, { lightness: dark ? -70 : 10 }] },
    ...(dark ? [{ stylers: [{ invert_lightness: true }] }] : []),
    { elementType: 'labels', stylers: [{ visibility: labels ? 'on' : 'off' }] },
    { featureType: 'road', elementType: 'geometry', stylers: [{ visibility: streets ? 'on' : 'off' }] },
    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
];

export class GoogleProvider implements MapProvider {
    readonly kind = 'google' as const;
    ready: Promise<void>;
    private map: any = null;
    private overlay: any = null;
    /** Called on every OverlayView.draw(): Google redraws overlays each frame of a zoom animation, but fires no camera events then. */
    private frameListeners = new Set<() => void>();
    private container: HTMLElement;
    private labels = true;
    private streets = true;
    private base: BaseStyle = 'streets';
    private dark: boolean;

    constructor({ container, center, zoom, config, dark }: MapInit) {
        this.container = container;
        this.dark = dark;
        this.ready = loadGoogle(config.google_key ?? '').then(
            () =>
                new Promise<void>((resolve) => {
                    const g = window.google.maps;
                    this.map = new g.Map(container, {
                        center: { lng: center[0], lat: center[1] },
                        zoom,
                        disableDefaultUI: true,
                        clickableIcons: false,
                        gestureHandling: 'greedy',
                        styles: greyStyles(dark, true, true),
                    });
                    // An empty OverlayView gives us a projection for container pixel <-> lat/lng, and its draw()
                    // runs on every frame of a zoom animation, so our overlays can follow the tiles smoothly.
                    const ov = new g.OverlayView();
                    ov.onAdd = () => undefined;
                    ov.draw = () => {
                        resolve();
                        this.frameListeners.forEach((f) => f());
                    };
                    ov.onRemove = () => undefined;
                    ov.setMap(this.map);
                    this.overlay = ov;
                }),
        );
    }

    private restyle() {
        if (!this.map) return;
        this.map.setMapTypeId(this.base === 'satellite' ? (this.labels ? 'hybrid' : 'satellite') : 'roadmap');
        this.map.setOptions({ styles: greyStyles(this.dark, this.labels, this.streets) });
    }

    setView(c: LngLat, zoom: number) {
        this.map?.setCenter({ lng: c[0], lat: c[1] });
        this.map?.setZoom(zoom);
    }
    flyTo(c: LngLat, zoom?: number) {
        this.map?.panTo({ lng: c[0], lat: c[1] });
        if (zoom !== undefined) this.map?.setZoom(Math.round(zoom));
    }
    fitBounds(b: Bbox, padding = 80) {
        this.map?.fitBounds({ west: b[0], south: b[1], east: b[2], north: b[3] }, padding);
    }
    project(ll: LngLat) {
        const proj = this.overlay?.getProjection?.();
        if (!proj) return { x: -9999, y: -9999 };
        const p = proj.fromLatLngToContainerPixel(new window.google.maps.LatLng(ll[1], ll[0]));
        return { x: p.x, y: p.y };
    }
    unproject(p: { x: number; y: number }): LngLat {
        const proj = this.overlay?.getProjection?.();
        if (!proj) return [0, 0];
        const ll = proj.fromContainerPixelToLatLng(new window.google.maps.Point(p.x, p.y));
        return [ll.lng(), ll.lat()];
    }
    getBounds(): Bbox {
        const b = this.map?.getBounds();
        if (!b) return [0, 0, 0, 0];
        const sw = b.getSouthWest(),
            ne = b.getNorthEast();
        return [sw.lng(), sw.lat(), ne.lng(), ne.lat()];
    }
    getZoom() {
        return this.map?.getZoom() ?? 0;
    }
    setBase(s: BaseStyle) {
        this.base = s;
        this.restyle();
    }
    setLabels(on: boolean) {
        this.labels = on;
        this.restyle();
    }
    setStreets(on: boolean) {
        this.streets = on;
        this.restyle();
    }
    setDark(d: boolean) {
        this.dark = d;
        this.restyle();
    }
    setInteractive(on: boolean) {
        this.map?.setOptions({ gestureHandling: on ? 'greedy' : 'none', disableDoubleClickZoom: !on, keyboardShortcuts: on });
    }
    on(event: 'click', cb: (e: MapClick) => void): () => void;
    on(event: 'move' | 'moveend' | 'zoom', cb: () => void): () => void;
    on(event: string, cb: ((e: MapClick) => void) | (() => void)): () => void {
        let l: any = null;
        let cancelled = false;
        void this.ready.then(() => {
            if (cancelled) return;
            const g = window.google.maps;
            if (event === 'click') {
                l = this.map.addListener('click', (e: any) => {
                    const lngLat: LngLat = [e.latLng.lng(), e.latLng.lat()];
                    (cb as (e: MapClick) => void)({ lngLat, point: this.project(lngLat) });
                });
            } else {
                const name = event === 'moveend' ? 'idle' : event === 'zoom' ? 'zoom_changed' : 'bounds_changed';
                l = g.event.addListener(this.map, name, () => (cb as () => void)());
                // bounds_changed covers dragging; zoom animations only show up as draw() frames.
                if (event === 'move' || event === 'zoom') this.frameListeners.add(cb as () => void);
            }
        });
        return () => {
            cancelled = true;
            l?.remove();
            this.frameListeners.delete(cb as () => void);
        };
    }
    native(): unknown {
        return this.map;
    }

    resize() {
        /* Google maps resize automatically with their container. */
    }
    destroy() {
        this.frameListeners.clear();
        this.overlay?.setMap(null);
        this.container.innerHTML = '';
        this.map = null;
    }
}
