# Admin / API docs / map — browser QA report

Run: `npx playwright test -c playwright.qa-admin.config.ts` (crawl + functional + map), then `python3 tests/e2e/qa-admin/analyze.py tests/e2e/qa-admin/results.json`.

## Result
266 page visits (41 admin URLs × desktop 1440 / tablet 1024 / mobile 390, dark mode on 8, plus /docs, /docs/api, /dev/map-preview normal/admin/readonly):
0 unexpected non-2xx, 0 console/page errors, 0 page-level horizontal overflow. RBAC: 120 role × URL checks (ops, finance, support) all as expected; forbidden pages render the styled 403; sidebars hide forbidden items.
Functional: 18/20 admin click-through flows pass (2 campaign-run flows self-skip once no submitted campaign remains). Map: 6/6.

## Bugs fixed
1. Admin business search by name matched every business with an ABN — ABN filter now only applies to digit queries (`Admin/BusinessController.php`).
2. G-NAF page rendered per-state counts as raw JSON and overflowed 485–896 px (`pages/admin/gnaf/index.tsx`).
3. COOP header logged a console error on plain http — now HTTPS only (`SecurityHeaders.php`).
4. Rate-card matrix: editing a cell right after opening could wipe other cells (`pages/admin/rate-card/index.tsx`).
5. Promo validation message ("value field") now says percent/amount (`Admin/RateCardController.php`).
6. Content editor: Create left a blank form (duplicate risk) and overflowed at 1024 px (`pages/admin/content/index.tsx`).
7. Dashboard chart: long axis labels overlapped legend (`pages/admin/dashboard.tsx`).
8. Stat tile icon collided with wrapped labels (`components/ui/surface.tsx`).
9. Reports months shown as `2025-11` → `Nov 2025` (`pages/admin/reports/index.tsx`).
10. Dark mode native controls stayed light — `color-scheme: dark` (`tokens.css`).
11. Pickup-point address dropdown clipped in dialog (`pages/admin/pickup-points/index.tsx`).
12. Scramble tag text polluted by doc comment (`Api/V1/GeoController.php`).
13. Swagger error-code table squashed on phones — scrolls in place (`views/docs/index.blade.php`).
14. Map: attribution covered Google logo / poor dark contrast; reopened drafts showed "Not estimated" (now re-estimated on open); draw-mode menu off-screen on phones; summary card collided with rail (`map/AudienceBuilder.tsx`).
15. Map rail ran into the bottom sheet on phones — two-column compact rail (`map/panels/MapRail.tsx`).
16. Summary card grew over the bottom sheet on phones (`map/panels/RightPanel.tsx`).
17. Bottom sheet covered the map while drawing on phones — collapses to heading + Cancel (`map/panels/LeftPanel.tsx`).
18. Tour bubble covered its target on phones (`map/tour/Tour.tsx`).
19. `/dev/map-preview` missing `<h1>`.
20. Unused eslint-disable in `map/draw/TerraDrawLayer.tsx`.

## Not fixed / notes
- Google Maps `ProjectDeniedMapError` on 4 visits when `GOOGLE_MAPS_KEY` is set — key/Cloud project configuration (enable Maps JavaScript API, billing, referrer restriction for the site), not code.
- Scramble `/docs/api` lists default tags for untagged controllers (cosmetic; primary docs at `/docs`).
- Swagger UI remains dense on phones (third-party).
- QA left demo-data changes in the dev DB (a qa staff user, credit rows, campaign 13 in delivery, print orders 6/9 refunded, design order 1 with proof). `make seed` resets everything.
