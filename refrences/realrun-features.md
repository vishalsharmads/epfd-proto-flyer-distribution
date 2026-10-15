# Realrun (realrun.app/v2) — Feature Overview

Realrun is a self-serve platform for booking **letterbox flyer distribution** (delivered by crowd-sourced "Realrunners") and ordering **print jobs** for the flyers. Pricing is in AUD, incl. GST. Screenshots are in `realrun-screenshots/` (file names referenced below).

> Scope note: reviewed on 14 Oct 2026 from a new, unverified account. Nothing was paid for or submitted. Items marked *(not verified)* were not exercised.

---

## 1. Global shell

- Top navigation: **Dashboard**, **Print Store**, **Letterbox Distribution**.
- User menu (avatar, top right) — *(not opened)*.
- Dismissible banner prompting **email verification** ("Go to account").
- Account badge shows verification status (UNVERIFIED) and "Agent since <month>" with company and suburb.

## 2. Dashboard (`01-dashboard`)

- KPI tiles: **Total flyers** (all time), **Active runs** (in-flight campaigns), **Success rate** (x of y runs), **Support requests**, **Total credits** (credit balance in $).
- **Run outcomes** donut: success rate %, with Successful / Failed / In flight (not yet counted) counts and percentages.
- **Support** panel: lists open support requests, or "All clear".
- **Flyers distributed** — last-12-months bar chart.
- Primary **New Campaign** button.

## 3. Letterbox Distribution

### 3.1 Distribution hub (`/v2/distribution`)
- **Current runs** board with three stages:
  1. *No runs to allocate* — runs waiting on a Realrunner.
  2. *Nothing to collect* — allocated runs, out for pickup.
  3. *Nothing out for delivery* — active runs with **live GPS tracking**.
- **My campaigns** list; empty state with "Create a distribution campaign".

### 3.2 Campaign builder — 4-step wizard

**Step 1 — Campaign** (`02-campaign-start`, `14-campaign-start-drafts`)
- **Continue a draft**: shows thumbnail map, area count, flyer count, price, last-edited time; per-draft **delete** (trash) button.
- **Re-run a past campaign** (empty until a campaign has completed).
- **Start new**.
- Drafts auto-save (new drafts are auto-named like "Campaign 14-Oct").

**Step 2 — Audience** (`03-…`, `15-…`)
- Full-screen **Google Map** with a **pickup location** pin. Rule: all areas must be at least partly within **20 km** of the pickup location.
- Toggles: **Include houses**, **Include units**.
- **Add area** (keyboard shortcut: Space). Each area is collected by a **different Realrunner**; multiple areas allowed.
- Three drawing modes (`18-map-tour-3-draw-modes`):
  - **Tap points** — outline with pins.
  - **Pencil** — freehand trace.
  - **Suburb** — enter a suburb to drop in a ready-made boundary.
- Area lifecycle: **Save area** (Enter), **edit** (re-open an area, drag pins, tap "+" between pins to insert a pin), rename the area, **Delete area** (Backspace), **Cancel** (Esc).
- Live area stats: letterbox count, distance, **Houses vs Units** breakdown, **Total letterboxes**, **Distribution fee** with price per item (e.g. 89c).
- Error handling: "No letterboxes detected — fees unavailable, redraw" (`21-map-tour-6-edit-area`).
- Map tools (right rail): **Watch tutorial**, **Find an address**, **Show addresses**, **Show labels**, **Show streets**, **Satellite view**.
- **Interactive 8-step onboarding tour** (`16`–`23`): filter audience → add area → draw modes → create area → save → edit → delete → finish (with **Contact support** link). Re-launchable from the "?" button.

**Step 3 — Details / "Pick up details"** (`04-…`, `24-…`)
- **Campaign name** with live **uniqueness check** ("This name is available").
- **Collection**: pick-up address (Google address autocomplete) and **"Available from"** date picker ("Today — earliest available", future dates selectable) (`26-…`).
- **Material type** (`25-…`): Standard DL, A5 *(base)*, Folded (+$0.01/item), Envelope (+$0.02), Magnet (+$0.04), Booklet (+$0.06), Double Up Flyer (+$0.08), Magazine (+$0.10).
- **Coverage** with per-item price: Houses + units (~$0.891), Houses only (~$1.221), Units only (~$3.288).
- **Notes for the distributors** (free text, e.g. "Skip letterboxes with 'No junk mail' signs").
- **Campaign Summary** side panel: map thumbnail, audience breakdown, **Distribution fee incl. GST**.

**Step 4 — Confirm / Payment** (`27-campaign-confirm-payment`)
- **Billing details**: company name, ABN (optional), address (prefilled from account).
- **Payment methods**: **Apple Pay**, **Stripe Link**, **New card** (Visa / Mastercard / Amex; card number, expiry, CVC, cardholder name, country: AU, NZ, US, UK, CA, IE, SG), **Save this card for later**, or saved cards.
- Mandatory acceptance of **Terms & Conditions, Community Guidelines** and acknowledgement of **Privacy Policy**.
- **Pay $X** button (disabled until form is valid).

## 4. Print Store

### 4.1 Hub (`05-print-store-home`)
- **My Orders** list (print orders in progress) + **New Order**.

### 4.2 Print order builder — 4-step wizard

**Step 1 — Start** (`06-print-order-start`)
- **Continue a draft** (shows product, units, price, last edited) / **Re-order a past job** / **Start new**.

**Step 2 — Product** (`07-print-order-product`) — product catalogue with "from" prices:

| Product | Size | From |
|---|---|---|
| A4 Flyers | 210 × 297 mm | $0.07/pc |
| A5 Flyers | 148 × 210 mm | $0.04/pc |
| A6 Flyers | 105 × 148 mm | $0.03/pc |
| DL Flyers | 99 × 210 mm | $0.03/pc |
| A3 Folded Brochures | A3 folded to A4 | $0.13/pc |
| A4 Folded Brochures | A4 folded to A5/DL | $0.08/pc |
| A5 Folded Brochures | A5 folded to A6 | $0.05/pc |
| Magnets | — | $0.09/pc |

**Step 3 — Specs & quantity** (`08`–`11`)
- **Paper weight**: 115gsm, 150gsm, 170gsm, 250gsm (`11-…`).
- **Finish**: Matt / Gloss (availability depends on other options).
- **Sides**: Single / Double sided.
- **Orientation**: Portrait / Landscape.
- **Quantity**: 100, 250, 500, 1,000, 1,500, 2,500, 5,000, 10,000, 20,000 (`10-…`).
- **Artwork upload** with on-screen **preview** on a bleed/grid canvas; per-file **download**, **replace/upload**, **delete** controls. Disclaimer that preview may not match printed colour.
- Live **price per piece**, **Subtotal incl. GST**, and **Earliest delivery** date (with info tooltips).

**Step 4 — Confirm / Payment** (`12`, `13`)
- **Order details**: name, delivery address, phone number.
- **Billing details** (company, ABN optional) and the same **Apple Pay / Link / card** payment options, **Save this card**.
- Checkboxes: confirm artwork/order checked and that the user has the **right to print the uploaded artwork**; accept T&Cs, Community Guidelines, Privacy Policy.
- Notice that production may start soon after acceptance, so change-of-mind cancellation may not be possible.

## 5. Account (`/v2/account`)

- **Profile**: first/last name, email (with **Verify** action), mobile, company, ABN, business address, **Save changes**.
- **Payments**: saved cards list (cards are added at checkout via "Save this card").
- **Invoices**: table of Date / Reference / Total / Status, populated after paid orders.
- **Security**: change password (current / new / confirm).
- **Legal**: legal documents with a **Read** action *(content not opened)*.

## 6. Cross-cutting capabilities

- Auto-saved **drafts** for both campaigns and print orders; resume or delete.
- **Re-run / re-order** of past jobs.
- **Live pricing** that updates as audience, coverage, material, quantity and finish change.
- **GST-inclusive** pricing throughout; **credits** balance concept on the dashboard.
- **Email verification** nudges; unverified-account state.
- **Google Maps / Places** integration for areas, address lookup and pickup/delivery locations.
- **GPS live tracking** of runs in transit; run lifecycle (allocate → collect → deliver).
- **Support** requests surfaced on the dashboard; "Contact support" in the map tour.
- Multiple payment rails: Apple Pay, Stripe Link, cards.

## 7. Not verified

- User (avatar) dropdown menu contents.
- Support request creation flow and credit top-up.
- Legal documents' text; invoice/receipt downloads.
- Post-payment states (run allocation, GPS tracking, order status tracking).
- Whether uploading artwork supports specific file types/size limits.
