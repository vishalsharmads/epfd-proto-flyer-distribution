import type { MapInit, MapProvider } from './types';

export type { BaseStyle, Bbox, MapClick, MapConfig, MapProvider } from './types';

/**
 * Create the configured provider. `provider` comes from the shared Inertia prop `map.provider`
 * ('google' when GOOGLE_MAPS_KEY is set on the server, otherwise 'maplibre').
 * Both implementations are dynamically imported so only the chosen engine is downloaded.
 */
export async function createProvider(provider: 'maplibre' | 'google', init: MapInit): Promise<MapProvider> {
    if (provider === 'google' && init.config.google_key) {
        const { GoogleProvider } = await import('./google');
        return new GoogleProvider(init);
    }
    const { MapLibreProvider } = await import('./maplibre');
    return new MapLibreProvider(init);
}
