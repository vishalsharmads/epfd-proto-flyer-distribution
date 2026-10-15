# Browser QA — public, auth, business portal, Design Studio

Run: `npx playwright test -c playwright.qa.config.ts` (crawl.spec.ts + functional.spec.ts). Results: `tests/e2e/qa/results.json`, screenshots: `tests/e2e/qa/shots/<role>/<slug>-<viewport>-<theme>.png`.

## Coverage (112 page renders = 50 URLs, desktop 1440 + mobile 390, dark subset)
| Area | URLs |
|---|---|
| Public/auth (home, login, register, forgot, reset, 3 legal pages) | 8 |
| Dashboard (demo, vic, qld, pending) + distribution | 5 |
| Campaign wizard (start, draft x3 steps, paid x3 steps -> redirects to job page, pending start) | 8 |
| Print hub/start/product/specs/confirm | 5 |
| Design hub/start/service/brief/confirm | 5 |
| Jobs list (demo, vic) + campaign jobs (completed/cancelled/runs_published/collected/submitted), print jobs (completed/submitted/shipped/in_printing), design jobs (approved/submitted) | 13 |
| Account (6 tabs) | 6 |
| Support list + thread | 2 |
Dark mode: dashboard, campaign details step, completed campaign job, account profile.

Checks per render: HTTP status, console/page errors, failed requests, horizontal overflow, elements past viewport, broken images, visible undefined/NaN/null, h1 present, focus-visible on first Tab. Final result: **0 problems / 112**. All screenshots reviewed.

Functional (functional.spec.ts, all green): nav links, dashboard CTA buttons, campaign details name validation + Next enablement + material repricing, confirm Pay disabled + reason, print quantity repricing, job message send, rating update, invoice + proof PDFs (200 application/pdf), profile validation/save, password mismatch + wrong current password, delete-card confirm dialog, support thread + reply, pending approval banner + Pay disabled with explanation, forgot-password email in Mailpit, logout. Registration+OTP, artwork upload, design wizard and payment flows are covered by the main suite (register/print-order/design-order specs).

## Bugs fixed
| Bug | Fix |
|---|---|
| Job detail pages (campaign/print/design) and Support overflowed to ~600px on mobile: single-column grid track was `auto`, so the proof table's min-width blew out the column | `grid-cols-1` + `lg:grid-cols-[minmax(0,1fr)_…]` in pages/app/jobs/{campaign,print,design}.tsx, pages/app/support/index.tsx |
| Dashboard "Get started" card overflowed 20px on mobile | `grid-cols-1` on the dashboard grids (pages/app/dashboard.tsx) |
| Account > Invoices caused page-level horizontal scroll on mobile (sr-only "PDF" header, absolutely positioned, escaped the scroll container); dates/invoice numbers wrapped on 3 lines | `relative` on the scroll card; `whitespace-nowrap` on date/number cells (pages/app/account/index.tsx) |
| My Jobs: long status badges ("Awaiting Artwork Approval") overflowed the row (desktop and mobile) | fixed `w-36` -> `shrink-0 sm:min-w-36` (pages/app/jobs/index.tsx) |
| Campaign Audience step had no `<h1>` | sr-only h1 (pages/app/campaigns/audience.tsx) |

## Not fixed / notes
- Google Maps `ProjectDeniedMapError` console error on campaign job pages when the Google engine loads: API key/project config, map internals (out of scope).
- `Cross-Origin-Opener-Policy ignored` console warning: HTTP-only dev host, not an app bug.
- Mobile top nav scrolls horizontally inside its own strip (intentional pattern, no page overflow).
- eslint warning in resources/js/map/draw/TerraDrawLayer.tsx (map scope, other agent).
