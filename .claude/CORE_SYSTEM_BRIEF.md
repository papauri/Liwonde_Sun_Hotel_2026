# CORE SYSTEM BRIEF — Liwonde Sun Hotel 2026

> **Every agent reads this file first, before doing anything else.** It is deliberately short.
> It exists so no agent ever has to "explore" to learn what this system is.
> Detail lives in `.claude/SYSTEM_MAP.md` (file/table map) — never re-scan the repo for it.

## What the system is

One vanilla-PHP (≥7.4, no framework, no build step) codebase that is simultaneously a
**hotel website** and a **property-management system (PMS)** for a single property,
self-hosted, with **no OTA commission and no SaaS fees**. Three faces:

| Face | Location | Purpose |
|------|----------|---------|
| Public site | root `*.php` | marketing, direct booking engine, enquiries, PWA |
| Back office | `admin/` | the whole PMS — ops, money, content, users |
| JSON API | `api/` | key-auth, permission-scoped, router-fronted |

**Provenance (matters for every decision):** this codebase is a fork of the Rosalyn's Hotel
2026 platform, transplanted with Liwonde Sun's own branding, content, imagery and guest-facing
design. See `MIGRATION_PLAN.md` at the repo root. The **schema-parity rule below is a
consequence of that fork and is the single most Liwonde-specific rail in this brief.**

## Core functional domains (the whole system, in one table)

Every agent must know these exist and roughly where they live. If your task touches a
domain, read that domain's section of `SYSTEM_MAP.md` — not the whole repo.

| # | Domain | Core functionality | Primary files |
|---|--------|--------------------|---------------|
| 1 | **Booking engine** | availability search, rate plans/packages/discounts, capacity-gated occupancy, auto-split of large parties, per-room `FOR UPDATE` locks on every create/mutate path, confirmation | `booking.php`, `check-availability.php`, `includes/booking-widget.php`, `includes/booking-functions.php`, `includes/pricing.php`, `admin/create-booking.php`, `admin/edit-booking.php`, `admin/bookings.php`, `admin/rate-plans.php`, `admin/packages.php` |
| 2 | **Reservations & front desk** | calendar, check-in/check-out, room assignment, tentative holds + expiry, blocked dates, no-shows, multi-room folios, housekeeping status | `admin/bookings.php`, `admin/calendar.php`, `admin/process-checkin.php`, `admin/housekeeping.php`, `admin/individual-rooms.php`, `admin/room-dashboard.php`, `admin/tentative-bookings.php`, `admin/blocked-dates.php`, `includes/booking-timeline.php`, `admin/includes/booking-lifecycle.php` |
| 3 | **Rooms & inventory of rooms** | rooms ARE the room types (`bookings.room_id → rooms.id`); physical units in `individual_rooms`; joined rooms via `room_combinations`; maintenance blocks | `admin/room-management.php`, `admin/individual-rooms.php`, `admin/room-maintenance.php`, `includes/room-management.php`, `room.php`, `rooms-gallery.php`, `rooms-showcase.php` |
| 4 | **POS & F&B** | orders, bar tabs, KDS kitchen display, CDS/BDS counter+bar displays, table locking, room service, auto-serve, menus | `admin/pos.php`, `admin/kds.php`, `admin/cds.php`, `admin/bds.php`, `admin/menu-management.php`, `admin/restaurant-tables.php`, `admin/order-lifecycle.php`, `admin/room-service-dashboard.php`, `admin/station-settings.php`, `restaurant.php`, `menu-pdf.php`, `includes/restaurant-location-locks.php`, `includes/station-hours.php` |
| 5 | **Stock & procurement** | FIFO stock engine, recipes, wastage/shrinkage, barcode receiving, batches, suppliers, purchase orders, reorder/par levels | `admin/stock-*.php` (14 pages), `admin/purchase-orders.php`, `admin/includes/procurement-schema.php`, `scripts/stock-audit.php` |
| 6 | **Finance & accounting** | invoices, receipts, credit notes, quotations, payments, refunds, end-of-day, shift close, accounting dashboard, VAT (`vat_pricing_mode`: off/inclusive/exclusive; `payment_amount` is always **net**; F&B prices always gross vs mode-driven room prices) | `admin/invoices.php`, `admin/payments.php`, `admin/payment-{add,details,refund}.php`, `admin/receipts.php`, `admin/credit-notes.php`, `admin/quotations.php`, `admin/end-of-day-report.php`, `admin/shift-close-report.php`, `admin/accounting-dashboard.php`, `admin/pos-accounting.php`, `admin/includes/finance-schema.php`, `admin/includes/finance-account-sync.php`, `includes/finance-sequences.php`, `includes/quotation-pdf.php`, `includes/eod-pdf-builder.php`, `config/{database,invoice,receipts,credit-notes}.php` |
| 7 | **Gym** | package-driven membership enrol (auto fee + expiry), complimentary hotel-guest option, check-in, classes, optional slot calendar | `gym.php`, `gym-schedule.php`, `gym-confirmation.php`, `admin/gym-*.php` (8 pages), `admin/includes/gym-*-lib.php` (5 libs), `scripts/gym_membership_reminders.php` |
| 8 | **Conference & events** | `conference_inquiries` is the live model (`conference_bookings` is legacy), double-booking guard, payment snapshot sync, events media | `conference.php`, `conference-confirmation.php`, `events.php`, `events-confirmation.php`, `admin/conference-management.php`, `admin/events-management.php`, `admin/events-inquiries.php`, `includes/upcoming-events.php` |
| 9 | **Guest communication** | confirmation + pre-arrival + post-stay review-request emails, template-driven, toggleable in admin, PHPMailer via `config/email.php`; WhatsApp + Facebook integrations | `config/email.php`, `templates/emails/`, `templates/whatsapp/`, `includes/report-mailer.php`, `admin/includes/guest-lifecycle-lib.php`, `admin/includes/kds-report-email.php`, `scripts/guest_lifecycle_emails.php`, `scripts/daily_reports.php`, `includes/whatsapp-functions.php`, `includes/facebook-functions.php`, `admin/{whatsapp,facebook}-settings.php` |
| 10 | **Content & marketing** | pages, gallery, media, footers, section headers, deals, reviews, SEO meta, visitor analytics | `admin/page-management.php`, `admin/gallery-management.php`, `admin/media-management.php`, `admin/footer-management.php`, `admin/section-headers-management.php`, `admin/deals.php`, `admin/reviews.php`, `submit-review.php`, `includes/seo-meta.php`, `includes/reviews-{display,section}.php`, `includes/section-headers.php`, `includes/hotel-gallery.php`, `includes/video-display.php`, `admin/visitor-analytics.php`, `includes/visitor-tracker.php`, `generate-sitemap.php`, `robots.php` |
| 11 | **Admin platform** | session auth, CSRF, security headers, per-page permissions, users/roles, system logs, backups, cache admin, dashboard presets/module gating | `admin/admin-init.php`, `admin/includes/permissions.php`, `admin/user-management.php`, `admin/{login,logout,change-password,forgot-password,reset-password}.php`, `admin/includes/password-change-lib.php`, `admin/system-logs.php`, `admin/backup-management.php`, `admin/module-settings.php`, `admin/includes/module-presets.php`, `admin/api-keys.php`, `admin/cache-management.php`, `admin/includes/audit-functions.php`, `includes/system-logger.php`, `includes/page-guard.php`, `config/security.php` |
| 12 | **API** | key-auth JSON endpoints (rooms, bookings, reviews, POS, reports) behind `api/index.php`; plus `admin/api/` internal AJAX endpoints gated by `admin/api/api-init.php` | `api/` (20 endpoints), `admin/api/` (30 endpoints) |
| 13 | **Platform/PWA/perf** | service workers, manifests, offline queue + replay, image proxy, page cache | `sw.js`, `public-sw.js`, `admin/sw.js`, `manifest.php`, `admin/manifest.php`, `offline.php`, `admin/offline-log.php`, `admin/includes/offline-banner.php`, `admin/includes/offline-queue.js`, `config/cache.php`, `config/page-cache.php`, `config/base-url.php`, `includes/image-proxy{,-helper}.php`, `scripts/scheduled-cache-clear.php` |
| 14 | **Safety net** | smoke tests, backup/restore, drift patcher, migrations | `scripts/smoke_test_booking.php`, `scripts/smoke_test_finance.php`, `scripts/backup_database.php`, `scripts/restore_database.php`, `scripts/patch_amount_due_drift.php`, `admin/migrations/` |

## Users and what they need

- **Guests** — find the hotel, see rooms/menus/gym, book on a phone without phoning.
- **Front-desk / ops staff** — reservations, check-ins, housekeeping, POS, KDS, stock;
  used **standing up on a tablet**, so 768–1024px and 44×44px touch targets are functional
  requirements, not polish.
- **Management/owners** — finance, EOD, accounting, analytics, laptop-width data tables.

## The bar (what "good" means here)

Cloudbeds (booking engine + PMS depth) · Mews (fast tablet-first staff workflows,
automation of routine state changes) · Little Hotelier (dead-simple conversion-optimized
direct booking). Not a rewrite of any of them — "best small-hotel direct booking + PMS".

## Non-negotiable code conventions (all agents, all domains)

- Prepared statements always — never interpolate a variable into SQL.
- `htmlspecialchars()` / `sanitizeString()` on every echo of user data.
- CSRF on every POST — admin: `admin-init.php` `$csrf_token`; public: `includes/public-csrf.php`.
- Admin pages: `require_once __DIR__ . '/admin-init.php';` before ANY output.
- API endpoints: `API_ACCESS_ALLOWED` guard + `$auth->checkPermission()` + `ApiResponse::`.
- Money: compare via `BALANCE_TOLERANCE` (defined in `config/database.php`), never raw float
  equality. Refunds net out of paid.
- Reuse `includes/` helpers before writing new ones.
- Procedural PHP. No frameworks, no Composer additions, no build steps, no CDN deps.
  Composer is PHPMailer + TCPDF only (PHPUnit is declared in `require-dev` but there is no
  test suite — the smoke tests in `scripts/` are the real safety net).
- `php -l` every changed PHP file. `node --check` JS when available.
- Never read `vendor/`, `PHPMailer/`, `node_modules/`, `.git/`, `logs/`, `cache/`,
  `backups/`, `images/`, `Database/`, `invoices/`, `quotations/`, `marketing-ad/`.

## Front-end specifics (Liwonde's own layer)

The guest-facing design is Liwonde's, not Rosalyn's, and is the part of this repo under
active design work. Read tokens before writing any CSS value:

- `css/main.css` is the entry point; `css/base/variables.css` holds **all design tokens**
  (colours, fluid type scale, spacing, shadows, transitions). `css/base/critical.css` is
  inlined above-the-fold CSS.
- Structure: `css/base/` · `css/components/` · `css/sections/` · `css/utilities/`.
  BEM naming (`.block__element--modifier`) throughout — see `css/README.md`.
- **`css/sections/ballena.css` + `css/sections/bellhop.css` are the editorial design
  system** for the guest sections (the current design direction, added by the
  `ballena-sections` work), paired with `css/components/editorial.css` and
  `js/bellhop-sections.js`. Bellhop loads last in `css/main.css` and restyles the
  ballena blocks (rooms rail, facilities rows) — it is the newest layer.
  Guest-page visual work belongs in that vocabulary — do not introduce a competing one.
- Admin styling is separate: `admin/css/admin-styles.css` (shared components + badges),
  `admin/css/admin-responsive.css`, plus a per-page stylesheet.
- Largest pages (use targeted offsets, never whole-file reads): `booking.php` ≈86 KB,
  `restaurant.php` ≈55 KB, `gym.php` ≈54 KB, `submit-review.php` ≈47 KB,
  `conference.php` ≈39 KB.

## Hard safety rails (all agents, no exceptions)

Never commit or push. Never `DROP`/`TRUNCATE`/`DELETE`-without-`WHERE`. Never edit `.env`
or `config/*local*`. Never print credentials. Never delete files. Never create README or
documentation files unless the brief explicitly says so.

**Schema parity with Rosalyn is LOCKED (Liwonde-specific, from `MIGRATION_PLAN.md`).** The
database was rebuilt to be object-identical to Rosalyn's — 115 tables + 2 views, zero
additions. Any schema change, *including an additive column*, breaks that parity and is an
owner decision. This rail is stricter than Rosalyn's own: there, additive columns on a
sanctioned new table are ASSUME-class; here they are ESCALATE-class. Two features were
deliberately removed under this rule and must not be "helpfully" restored: guest-facing
**restaurant reservations** (§4.1) and the **secondary hero CTA** (§4.2). Campaign/UTM
attribution was dropped outright (§4.3).

## Escalation rule — what must be ASKED vs what must be ASSUMED

Autonomy is the default, but it is **not** a licence to redesign the system or change how it
behaves in ways the owner did not sanction. Classify every decision before acting:

**ESCALATE — stop and ask the owner precisely (never assume, never "improve" quietly):**

1. **Money semantics** — how a price, VAT, discount, deposit, refund, balance, commission or
   payout is calculated; changing `vat_pricing_mode` behaviour, net-vs-gross treatment, or
   what counts toward `amount_due`.
2. **Booking/availability rules** — overbooking tolerance, cancellation/no-show policy,
   minimum stay, capacity or occupancy limits, what blocks a room, lock/allocation strategy.
3. **Auth, permissions, or security posture** — who can see or do what, adding/removing a
   permission gate, session/cookie policy, API key handling, rate limits, relaxing any rail.
4. **ANY schema change** — additive or not. Schema parity with Rosalyn is locked; a new
   column is as much an owner decision as a dropped one.
5. **Guest-visible flow changes** — adding, removing or reordering a step or required field
   in the public booking flow; changing what a guest is charged, shown, or agreed to.
6. **Anything that sends real messages** — enabling an email/WhatsApp/SMS sequence against
   live guest data, or changing an existing template's meaning (not its typos).
7. **Brand/design system changes** — palette, typography, spacing scale, or the layout
   system of a page. Applying existing tokens from `css/base/variables.css` is polish;
   inventing new ones, or departing from the ballena editorial vocabulary, is a design change.
8. **Deleting or renaming files, or removing an existing feature/endpoint**, even if it
   looks dead. **Equally: restoring a feature the migration deliberately removed.**
9. **Anything with a cost or an external dependency** — new package, CDN, third-party
   service, subscription, OTA/channel integration.
10. **Enabling or disabling a module** in `admin/module-settings.php` — that is an
    operational decision about what this property runs, not a code decision.

**ASSUME — decide it yourself and log `ASSUMPTION: <one line>`:** naming, code placement
within an agreed file, which existing helper to reuse, copy wording, ordering of non-required
UI elements, applying existing design tokens, test data, log verbosity, and any reversible
internal implementation detail that touches no table.

**How to escalate (precision is the point):** never ask an open question. State it as
`BLOCKED: <domain #> — <the exact decision>` with (a) what triggered it, (b) 2–3 concrete
options with their consequence in one line each, (c) your recommendation. Subagents never
ask the owner directly — they report the `BLOCKED:` line and continue with other work; only
the orchestrator surfaces it, batched, using AskUserQuestion.

## Output discipline (all agents, no exceptions)

The owner does **not** want code in the terminal. Report back:
**files changed (paths only) · ≤4-line outcome · lint result · blockers.**
No code blocks, no diffs, no before/after snippets, no narration of what you are about to do.
