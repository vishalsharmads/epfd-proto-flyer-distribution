import { lazy } from 'react';

/**
 * Public entry for the map module. Pages should render <LazyAudienceBuilder/> inside <Suspense>
 * so MapLibre (its own `maplibre` chunk) is only downloaded on map pages.
 */
export const LazyAudienceBuilder = lazy(() => import('./AudienceBuilder'));
export { MapThumbnail } from './MapThumbnail';
export type { AudienceBuilderProps, AudienceState, BuilderArea, Coverage, DrawMode, GpsTrack, Pickup } from './types';
