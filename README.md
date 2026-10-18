# Easy Peasy Flyer Distribution (EPFD) — prototype

> **Smart Distribution for Smarter Marketing.** Businesses draw the streets they want on a map, see an
> estimated houses-vs-units letterbox count and a GST-inclusive price, order flyer printing, pay, and
> track distribution with GPS proof. Operations staff run everything from an admin panel; partners
> integrate through a versioned REST API.

Sydney · Melbourne · Wollongong · Newcastle — 0493 243 666 · Operations@epfd.com.au · Y-03, 257 Victoria Road, Rydalmere NSW 2116

---

## Contents
1. [Quick start](#quick-start)
2. [G-NAF import](#g-naf-import)
3. [Architecture](#architecture)
4. [Design Studio](#design-studio)
5. [Catalogue (print products & design services)](#catalogue-print-products--design-services)
6. [Assumptions](#assumptions)
7. [Needs human input](#needs-human-input)
8. [Licence and attribution](#licence-and-attribution)
9. [Prototype vs production gap list](#prototype-vs-production-gap-list)
10. [Verification log](#verification-log)

---

## Quick start

| Command | What it does |
|---|---|
| `make up` | Starts the stack. **Docker** (if installed): PostGIS 16, Mailpit, app, queue worker, Vite via `docker-compose.yml`. **Native fallback** (used on this machine — no Docker): Homebrew PostgreSQL 17 + PostGIS 3.6 on `:5433`, Mailpit, Composer/npm install, Vite build, migrations, and `herd link epfd`. |
| `make seed` | Fresh schema → imports the bundled **real G-NAF sample** (`database/gnaf-sample`, 116k addresses) → seeds demo data. Works offline, no keys. |
| `make gnaf` | Imports the full release from `GNAF_PATH` (default `--states=NSW,VIC`; `make gnaf STATES=NSW,VIC,QLD`). |
| `make bench` | `gnaf:benchmark` — proves the < 1 s estimate target with timings + `EXPLAIN (ANALYZE, BUFFERS)`. |
| `make test` | Pest (PostGIS test DB) + Playwright smoke tests. |
| `make lint` | Pint, ESLint, `tsc --noEmit`. |

URLs (native/Herd): app **http://epfd.test** · admin **http://epfd.test/admin** · API docs **http://epfd.test/docs** · OpenAPI **/docs/openapi.json** · Mailpit **http://127.0.0.1:8025**.
(Docker: replace the host with `http://localhost:8000`.)

### Seeded accounts (password `password`)
| Email | Role |
|---|---|
| admin@epfd.test | Super admin |
| ops@epfd.test | Operations |
| finance@epfd.test | Finance |
| support@epfd.test | Support |
| demo@business.test | Business owner (Harbour Coffee Co., approved, NSW) |

Mock payments: any Luhn-valid card succeeds (e.g. `4242 4242 4242 4242`); `4000 0000 0000 0002` always declines.

---

## G-NAF import

```bash
# .env
GNAF_PATH="/path/to/G-NAF/G-NAF AUGUST 2026"   # the unzipped release folder
php artisan gnaf:import --discover-only           # print what the release contains, then stop
php artisan gnaf:import --states=NSW,VIC          # default states; ALL for every state
php artisan gnaf:import --sample                  # bundled real extract (tests / CI)
php artisan gnaf:import --fresh                   # ignore resume state, re-stage every file
make boundaries BOUNDARIES_PATH=…/LOCALITIES_*_GDA2020_SHP_*   # official boundaries (Geoscape Localities, streamed); see Admin → G-NAF data status → Import guide
php artisan suburbs:rekey                         # one-off: key suburbs by locality_pid on databases imported before it existed
php artisan gnaf:benchmark
```

**Discover, don't assume.** `GnafDiscovery` walks the release, finds the `Standard` PSVs
(`{STATE}_{TABLE}_psv.psv`), the `Authority Code` files, the release's `create_tables_ansi.sql` and documents,
reads every PSV header for column names and prints any differences against the release SQL.

**Load.** Each PSV is streamed into a per-state UNLOGGED staging table (`gnaf_stg.nsw_address_detail`, …)
with `psql \copy … FORMAT csv, DELIMITER '|', HEADER` (server-side `COPY` fallback). No PHP row loops.
`gnaf_stg._progress` records path/size/mtime/rows, so a re-run **resumes** (skips unchanged files) and is
**idempotent** (changed files are reloaded; the state's `addresses` rows are replaced).

**Transform.** One set-based `INSERT … SELECT` per state joins ADDRESS_DETAIL + ADDRESS_DEFAULT_GEOCODE +
STREET_LOCALITY + LOCALITY (+ ADDRESS_SITE, PRIMARY_SECONDARY) into `addresses` with SRID 7844 points.
Then indexes (GIST geom, btree state/locality/postcode, GIN trigram on a normalised address) and `ANALYZE`.
Every run is recorded in `gnaf_releases` (label, CRS, states, row counts, kind counts, skipped counts, discovery).

**Classification rules** — one documented class, `app/Domain/Gnaf/AddressClassifier.php`, unit-tested,
each a flag in `config/epfd.php → gnaf.rules`:

| Rule | Flag (default) |
|---|---|
| Principal addresses only (`ALIAS_PRINCIPAL='P'`) | `principal_only` (true) |
| Skip retired rows | `skip_retired` (true) |
| Unit = flat number/type, or `PRIMARY_SECONDARY='S'` | `secondary_is_unit` (true) |
| Exclude unit-building primaries from counts (no double count) | `exclude_primary_with_units` (true) |
| Count a unit building as one letterbox instead | `include_unit_buildings_as_single_letterbox` (false) |
| Exclude commercial flat types (SHOP, OFFC, WHSE…) | `exclude_commercial_when_available` (true) |
| Skip rows without a default geocode (reported) | `require_geocode` (true) |

Anti double-count source of truth: `PRIMARY_SECONDARY` table if present → `ADDRESS_SITE` grouping → (locality, street, number_first).

**Suburb boundaries.** G-NAF has no polygons, so each locality's boundary is derived from its own points
(snap to 15 m grid → `ST_ConcaveHull(0.35)` → 60 m buffer → 25 m simplify, all in the MGA zone) and flagged
`boundary_source='derived'` (the UI shows "Approximate boundary"). `suburbs:import` replaces them with
official boundaries (`boundary_source='official'`). Suburbs are keyed by G-NAF `LOCALITY_PID` — one state can hold
several localities with the same name (NSW has five "Mayfield"s). Use **Geoscape Administrative Boundaries → Localities**
(data.gov.au, CC BY 4.0, same quarterly release as G-NAF): its `LOC_PID` is the G-NAF locality pid, so every gazetted
locality matches exactly. ABS SAL also imports, matched by name + state, but SAL is a mesh-block approximation that drops
or merges small localities, and names shared by several suburbs are skipped. Non-gazetted G-NAF localities have no
official polygon anywhere and stay derived.

**CRS.** Storage and queries in **GDA2020 (EPSG:7844)**. Browser lon/lat (WGS84) is treated as GDA2020 — the
≈1.8 m datum difference is negligible for letterbox counting. Distances and areas are always computed after
`ST_Transform` to the **MGA2020 zone** of the geometry (EPSG:7849–7856; Sydney 56, Melbourne 55) via the
SQL helpers `epfd_mga_srid()` / `epfd_to_mga()` — never in degrees.

---

## Architecture

```
app/
  Domain/
    Gnaf/        GnafDiscovery · GnafImporter (COPY) · AddressClassifier · SuburbBuilder
    Areas/       LetterboxSource (interface) · GnafLetterboxSource · GeoscapeBuildingsLetterboxSource (stub)
                 LetterboxEstimator (validate → cache → count) · PolygonValidator (+20 km rule) · LetterboxEstimate
    Pricing/     PricingService (pure: distribution · print · design) · PricingFactory (DB → service) · RateCardDefaults · Quote
    Design/      DesignService (Design Studio workflow: drafts, briefs, proofs, approval, print hand-off)
    Payments/    PaymentGateway · MockGateway · StripeGateway · CheckoutService (credits, invoices, refunds)
    Campaigns/   CampaignService (drafts, areas, repricing, readiness)
    Runs/        RunService (split areas into runs, publish/assign, GPS)
  Support/       Geo · ProblemJson (RFC 9457) · Audit · Reference · BusinessDays
  Http/Controllers/{App,Admin,Auth,Api/V1}
resources/
  css/tokens.css      ← THE design-token file (brand scales, neutrals, semantic, radius, shadow, spacing, type, motion)
  js/lib/tokens.ts    ← reads the same CSS variables at runtime (map colours etc.)
  js/map/             ← MapProvider abstraction (MapLibre default, Google optional) + in-house drawing tools
  js/pages/{app,admin,auth}/ · js/layouts · js/components/ui
routes/web.php · routes/web/{app,admin,auth,docs}.php · routes/api.php + routes/api_v1.php
```

* **Estimator**: `LetterboxSource::estimate(polygon, includeHouses, includeUnits)` → `{houses, units, total, distanceKm}`.
  `GnafLetterboxSource` is one PostGIS query: `ST_Intersects` on the GIST index with the classifier rules, plus a
  walking-distance heuristic (per street and side, points ordered by number, consecutive MGA distances; gaps
  > 400 m ignored; plus capped 250 m connectors between streets ordered by geohash — **an estimate, not routing**).
  Results cached by a polygon hash. Switch backends with `LETTERBOX_SOURCE=gnaf|geoscape_buildings`.
* **Pricing**: one pure `PricingService` (no I/O; money in hundredths of a cent internally) is used by the
  business UI, the REST API and the admin preview calculator. Rate card is versioned in `rate_cards`.
* **Maps**: `MapProvider` abstraction. Default MapLibre GL JS with OSM raster tiles toned to light grey, satellite via
  `MAP_SATELLITE_TILE_URL`. With `GOOGLE_MAPS_KEY` set, a Google Maps provider is used. Drawing tools (pins,
  freehand trace with Douglas–Peucker, suburb drop-in, vertex drag/insert) are an SVG overlay driven by pointer
  events, so behaviour is identical on both providers (Google's deprecated Drawing Library is not used).
  The map bundle is code-split.
* **Errors**: every JSON error is `application/problem+json` with a stable `code` (table in `/docs`).
* **Security**: Sanctum (session for web, tokens for API), Spatie Permission RBAC (Super admin, Operations,
  Finance, Support), throttles (api 120/min, estimate 240/min, auth 10/min/IP, payments 10/min), CSRF,
  secure headers, upload validation, audit log, PII redaction in audit payloads.

---

## Design Studio

Graphic design as a third service next to Print and Letterbox Distribution, mirroring epfd.com.au's *Graphic Design*
offer (Flyer, Brochure, Business Card, Presentation Folder and Stationery design): *"Our creative team delivers
professional graphic design that enhances your printed materials and supports effective distribution campaigns."*

### Architecture

| Piece | Where |
|---|---|
| Data | `design_orders` (reference `DSN-XXXXXX`, type, package, sides, optional target `print_product_id`/size, brief fields, status, `revisions_included/used`, money + `pricing` JSON, promo, `designer_id`, `print_order_id`, soft deletes) and `design_assets` (`brief_upload` · `proof` · `final`, versioned). Migration `2026_10_16_100000_create_design_tables`. Morph map `design_order`, `design_asset`. Messages reuse `support_threads` (`channel = job`, related morph `design_order`). |
| Pricing | `RateCardDefaults::design()` + `PricingService::design()` (pure; package price per type × double-sided uplift + add-ons − promo + GST). A rate card without a `design` key falls back to the defaults (`PricingService::designCard()`, `PricingFactory::rateCard()`), so databases seeded before Design Studio work unchanged. Admin → Rate card → **Design** tab edits it; the preview calculator supports `service = design`. Promo codes can target `design`. |
| Workflow | `App\Domain\Design\DesignService` — the one place used by the portal, admin and API. `draft → (pay) submitted → in_design → proof_ready → approved → completed`, with `proof_ready → changes_requested → (new proof) proof_ready` loops, and `cancelled`. Every staff/business action is written to the audit log. Payment goes through `CheckoutService::pay()` (now `Campaign\|PrintOrder\|DesignOrder`), so invoices, credits, refunds and the `OrderPaid` email all work as for other jobs. |
| Business portal | Nav **Design Studio** → `/app/design` (services "from $X" + my orders), wizard `/app/design/new` → `/{id}/service` → `/{id}/brief` (autosaved; logo/brand/reference uploads PDF/PNG/JPG/SVG ≤ 20 MB, max 10) → `/{id}/confirm` (add-ons, promo, shared `PaymentForm`). Job page `/app/jobs/design/{id}`: timeline, versioned proof gallery with preview, **Approve design** / **Request changes**, messages, final files, and after approval **Print this design** / **Print + distribute** (creates a print-order draft with the matching product and the final file — or the approved proof — copied in as front artwork). The print wizard's artwork step links "Don't have artwork? **Get it designed**" (design order with that product preselected). My Jobs lists design orders (filter *Design*). |
| Admin | **Design orders** (`design_orders.view` / `design_orders.manage`; Operations: both, Support: view). List with status/type/designer/search filters; detail with brief + uploads, designer assignment, *Mark in design*, versioned proof upload (emails the business via `DesignProofReadyMail` on the branded layout), final-file upload + *Complete*, cancel, messages, payments + refunds (`CheckoutService::refund`), timeline and audit history. Dashboard KPI *Designs awaiting proof* (submitted, in design or changes requested). |
| API v1 | `GET /design/services`, `POST /pricing/design/quote` (public); `GET/POST /design/orders`, `GET/PATCH/DELETE /design/orders/{id}`, `POST /design/orders/{id}/assets`, `…/pay`, `…/approve`, `…/request-changes`; admin `GET /admin/design-orders`, `GET /admin/design-orders/{id}`, `PATCH …/status`, `POST …/proofs`. Examples in `app/Docs/Examples.php`; Postman regenerated with `php artisan api:postman`. |
| Tests | `tests/Unit/DesignPricingTest.php`, `tests/Feature/DesignOrderTest.php` (wizard → pay → proofs → changes → approve → print; RBAC; API; rate card), Playwright `design-order.spec.ts` (flows), `design-review.spec.ts` (followups), a11y scans of the hub, service step, admin list and rate-card Design tab. |

**Default design rate card (placeholders, ex GST):**

| Service | Basic | Standard | Premium | Sides option |
|---|---|---|---|---|
| Flyer | $99 | $179 | $299 | yes (double-sided +40%) |
| Brochure | $249 | $399 | $599 | — |
| Business card | $79 | $129 | $199 | yes (+40%) |
| Presentation folder | $299 | $449 | $699 | — |
| Stationery set | $199 | $349 | $549 | — |
| **Revisions included** | 1 | 3 | unlimited (stored as 99) | |
| **First proof (business days)** | 5 | 3 | 2 | |

Add-ons: Rush 48 h **+50 %** of the design line (first proof in 2 business days) · Extra revision **$35** (also raises
the included revisions by one) · Editable source files **$49** · Licensed stock imagery **$25**.

### Assumptions

1. **Prices are placeholders** — realistic AU market rates, not EPFD's; editable in Admin → Rate card → Design.
2. **Extra revisions are invoiced offline, not charged online.** A change request beyond the package allowance is
   accepted (the business is warned in the dialog first), counted in `revisions_used`, flagged as an *extra revision*
   on the job page, the admin detail and in the audit log (`extra_revision_cents`), and must be invoiced by EPFD. This
   avoids a second checkout in the middle of a design conversation and can't double-charge; automated billing is a
   production follow-up.
3. **Double-sided uplift** applies to services with a sides option (flyer, business card); brochures, folders and
   stationery are multi-panel by nature and priced per design.
4. **"Print this design"** uses the order's chosen print product, else the service's *default print product* (set per
   service in Rate card → Design; defaults: flyer → A5 flyers, brochure → A4 folded brochures, business card/stationery →
   business cards). Presentation folders have no online print
   product, so the business is asked to message us. Front artwork = the first print-ready final file (PDF/PNG/JPG),
   else the latest proof; a second final file becomes the back when the design is double-sided.
5. **SVG uploads** are accepted for briefs but always served as downloads (never inline) with a locked-down CSP.
6. **Designers** are any staff user (no separate designer role yet).

---

## Catalogue (print products & design services)

Both catalogues are managed in the admin, with no code changes:

| What | Where | Notes |
|---|---|---|
| Print products | Admin → Catalogue → **Print products** (`catalogue.manage`: Operations, Finance) | Add/edit/hide/delete products: name, category, description, image (public disk), size and bleed, paper weights, finishes per weight (from the rate card's print finishes), sides offered, quantities, turnaround, distributable + distribution material. Ordered products can only be hidden, never deleted. |
| Print categories | Same page, *Categories* card | Name, icon, order, visible. Hidden categories hide their products. Only empty categories can be deleted. |
| Print prices | Admin → Rate card → **Print price matrix** (`rate_card.manage`) | One cell per paper weight × sides × quantity. Adding options creates **unpriced** cells; a product cannot go on sale until every cell has a price, and unpriced cells are never sold. The customer "from" price is the cheapest cell. |
| Design services & packages | Admin → Rate card → **Design** | Add/remove services and packages (versioned with the rate card). Per service: name, icon, description, sides option, a price for every package, **linked print categories** ("Get it designed" from those products opens this service; their sizes are offered as print sizes) and **default print product**. Removing a service stops new orders; existing orders keep a readable name. |

`App\Domain\Catalogue\PrintCatalogue` keeps the price matrix in step with product options;
`DesignService::typeKeys()/packageKeys()/typeForCategory()` read the active rate card. `CatalogueSeeder` only creates
missing categories/products, so re-seeding never overwrites admin edits.

**Deploying:** run `php artisan migrate` (creates categories from existing products and grants `catalogue.manage`) and
`php artisan storage:link` once, so product images are served from `/storage`.

## Assumptions

1. **PostgreSQL 17 instead of 16 locally.** Docker isn't installed on the build machine and Homebrew's PostGIS
   targets PG 17, so the native setup uses PG 17.11 + PostGIS 3.6.4 on port 5433 (the existing PG 15 on 5432 is
   untouched). `docker-compose.yml` uses `postgis/postgis:16-3.4` as specified. No PG-17-only features are used.
2. **Inertia 2 with React 18.** Inertia 3's React adapter requires React 19; the spec mandates React 18, so
   `inertiajs/inertia-laravel` ^2 + `@inertiajs/react` ^2 are used.
3. **TypeScript 5.9** (not 7) because `typescript-eslint` does not support TS 7 yet.
4. **Brand colours.** Primary `#FF244A` is the dominant pixel colour of the official logo (sampled). The logo
   is single-colour, so secondary = the site's ink `#141414` and accent = the site's yellow `#FFC446`
   (both from `epfd.com.au/assets/css/main.css`). Font: Inter (site body/heading font), self-hosted for offline use.
   Button radius 14 px (site CSS). Because `#FF244A` on white is 3.75:1, text-bearing fills use `brand-600 #E00B33` (4.92:1).
5. **Pricing calibration.** RealRun's public per-item prices (Houses+units ≈ $0.891, Houses only ≈ $1.221,
   Units only ≈ $3.288 incl. GST) are used as the default rate card (ex GST 0.81 / 1.11 / 2.989). Material
   modifiers are per item ex GST. Volume bands: 3 % ≥ 5,000, 5 % ≥ 10,000, 8 % ≥ 20,000. Minimum distribution fee
   $20 ex GST, print minimum $25 ex GST.
6. **Coverage ↔ toggles.** The map toggles (include houses/units) decide what is counted; the Details "Coverage"
   choice decides what is billed and its per-item price.
7. **Retired rows.** The AUG 2026 Standard PSVs contain no retired rows (0 skipped); the rule is still applied.
8. **Commercial exclusion** uses flat types (SHOP, OFFC, WHSE…) because G-NAF ADDRESS_TYPE only has
   Rural/Urban/Unknown. The paid Geoscape upgrade replaces this with land-use data.
9. **Walking distance** is a heuristic (see Architecture), labelled "estimated".
10. **Business days** skip weekends only (no public-holiday calendar).
11. **Sample dataset** is a real G-NAF AUG 2026 extract (Rydalmere, Ermington, Dundas Valley, Parramatta,
    Wollongong, Newcastle, Hamilton NSW; Richmond, Abbotsford VIC; New Farm QLD), gzipped (6.7 MB) and rebuilt with
    `make gnaf-sample-extract`.

## Needs human input

* **G-NAF licence for customer-facing commercial use** — confirm the Open G-NAF EULA (and any Geoscape terms)
  covers displaying counts/derived boundaries to paying customers (see below).
* **Brand assets** — confirm the extracted colours, provide an SVG logo and a square favicon (current favicon is the PNG logo, padded).
* **Real rate card** — replace the RealRun-calibrated defaults (Admin → Rate card).
* **Design Studio prices, revisions and turnaround** — the Design tab values are placeholders (see [Design Studio](#design-studio));
  also confirm how extra revisions should be invoiced and who the designers are.
* **Payment provider** — Stripe account/keys (`PAYMENT_GATEWAY=stripe`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`).
* **Legal copy** — T&Cs, Community Guidelines and Privacy Policy are placeholders (Admin → Content).
* **Official suburb boundaries** — download Geoscape Administrative Boundaries (GDA2020 Shapefile, same release as G-NAF) from data.gov.au and run `suburbs:import` on the `*_localities.shp` files.
* **Maps** — production tile provider (OSM's public tiles are for development only) and optional `GOOGLE_MAPS_KEY`.

## Licence and attribution

> Incorporates or developed using G-NAF © Geoscape Australia licensed by the Commonwealth of Australia under the
> Open Geo-coded National Address File (G-NAF) End User Licence Agreement.

This text is shown in the map footer, the API docs and Admin → G-NAF data status.
**Action required:** have someone confirm that the G-NAF EULA terms cover this customer-facing, commercial use
(estimates shown to paying customers, derived suburb polygons) before launch. Base map © OpenStreetMap
contributors (development tiles only; production needs a commercial tile provider). Satellite imagery
(Esri World Imagery by default) is subject to its provider's terms.

## Prototype vs production gap list

| Area | Prototype | Production |
|---|---|---|
| Letterbox data | G-NAF address points + flat-type heuristics | **Geoscape Buildings + Land Parcels/Land Use** via `GeoscapeBuildingsLetterboxSource` (stub documents the steps: load buildings, link via ADDRESS_LINK, count residential dwellings, `LETTERBOX_SOURCE=geoscape_buildings`) |
| Suburb polygons | Derived concave hulls ("approximate") | Geoscape Localities (matched on locality pid) via `suburbs:import` |
| Walking distance | Street-order heuristic | Routing on a road network (pgRouting/OSRM) |
| Payments | Mock gateway; optional Stripe REST with test PaymentMethods | Stripe Elements/Payment Element in the browser (no PAN on server), 3-D Secure, Apple Pay/Link, webhooks as source of truth |
| Distributor app | API stubs + GPS simulator | Native distributor app with background GPS, offline sync, anti-spoofing |
| Maps | OSM dev tiles | Commercial tiles (MapTiler/Mapbox/Google) with SLAs |
| Infra | Single node, database queue | Managed Postgres + read replica, Redis queue/cache, object storage for artwork, CDN |
| Security | ASVS basics | Pen test, WAF, MFA for staff, secrets manager, data-retention policy |
| Email | Mailpit | Transactional provider (Postmark/SES) with DKIM/SPF |
| Observability | Laravel logs | Sentry, metrics, uptime, slow-query monitoring |

## Verification log
Outputs of the commands actually run are in `docs/verification/`.

| # | Definition of Done | Result |
|---|---|---|
| 1 | `make up && make seed` | ✅ native stack (no Docker on build machine); seed 16 s; http://epfd.test 200 with EPFD branding (`make-up.txt`) |
| 2 | `make gnaf` (AUG 2026, NSW+VIC) | ✅ NSW 4,923,145 · VIC 4,187,113 principal addresses; 353,701 unit-building primaries excluded; 7,075 derived suburbs (`make-gnaf.txt`) |
| 3 | `gnaf:benchmark` | ✅ 9,119,984 rows; ~5,000-letterbox polygons: worst case **76 ms** (< 1 s) with EXPLAIN (`gnaf-benchmark-main.txt`) |
| 4 | Three draw modes in a real browser | ✅ Playwright `campaign-draw-modes.spec.ts` (pins, trace, suburb → live houses/units/fee) |
| 5 | Pay print + campaign end-to-end, admin processes | ✅ Playwright `campaign-draw-modes`, `print-order`, `design-order`, `admin`, `design-review` |
| 6 | `/docs` live, every §9 endpoint with examples | ✅ OpenAPI 3.1, every operation has examples (`api-docs.txt`); Postman `docs/postman/` |
| 7 | Pest, Playwright, Pint, ESLint | ✅ Pest 117/117, Playwright 33/33 (incl. axe WCAG 2.2 AA), Pint/ESLint/tsc clean (`lint.txt`) |
| 8 | README | ✅ this file |

Browser QA of every page (desktop/tablet/mobile, light/dark, every role): `tests/e2e/qa/REPORT.md`, `tests/e2e/qa-admin/REPORT.md` — 378 page renders, 0 errors, 0 page-level overflow.
