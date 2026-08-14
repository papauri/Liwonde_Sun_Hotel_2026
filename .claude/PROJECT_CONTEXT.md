# PROJECT_CONTEXT — Liwonde Sun Hotel 2026

> Required reading for build-planner before any BUILD_PLAN.md phase.
> Every objective in BUILD_PLAN.md must state which gap below it closes.

## What this project is

A complete hotel website **and** property-management system (PMS) for Liwonde Sun Hotel,
built by ProManaged IT. Single vanilla-PHP codebase, three faces (public site, `admin/`
back office, `api/` JSON API) — see `.claude/CORE_SYSTEM_BRIEF.md` for the domain table.

**This codebase is a fork of the Rosalyn's Hotel 2026 platform.** The strategy (see
`MIGRATION_PLAN.md`) was: take Rosalyn's complete PMS as the base, transplant Liwonde's own
front end onto it, and rebuild the database to be **object-identical to Rosalyn's** — rather
than port 57 admin pages into Liwonde's smaller original codebase. Everything about how this
project should be worked on follows from that decision.

## Where the migration actually stands (verify before planning around it)

Confirmed from the repo as of 2026-08-14:

- **Phase 1 (codebase fork) — done.** All Rosalyn admin pages, `includes/`, `api/`,
  `config/`, `scripts/` are present. A repo-wide grep for "rosalyn" returns only
  `MIGRATION_PLAN.md` and one comment in `css/components/header.css`.
- **Phase 4 (front-end transplant) — substantially done and still moving.** Liwonde's guest
  pages, `css/` and `js/` are in place, and the last seven commits are an active design pass
  (the "ballena" editorial treatment) on the guest sections. Current branch:
  `ballena-sections`, ahead of `main`, working tree clean.
- **Phases 2 and 3 (schema rebuild, content import) — done.** Verified 2026-08-15 by running
  both smoke suites against the live database: they connected, read seeded rooms
  (`#2 Executive Suite`), inserted and cleaned up bookings, and exercised
  `finance_sequences`. 75 assertions green across the two suites.
- **Phases 5, 6, 7 — still unknown.** Branding/SMTP values live in `site_settings` /
  `email_settings` (the code reads them via `getSetting()`, so this is an admin-panel
  question, not a code one); module enablement is undecided; and cutover verification —
  including removing `109.78.91.146` from cPanel → Remote MySQL — cannot be checked from here.
- **`admin/migrations/` is empty.** The convention "write a migration and run it" has no
  precedent in this repo yet.
- **`sql_mode` is `NO_ENGINE_SUBSTITUTION`** — no `STRICT_TRANS_TABLES`, so over-length
  values are silently truncated rather than rejected. This already caused a false smoke-test
  failure and is logged as a blocker in `BUILD_PLAN.md`.

## Stack & conventions (verified by scan)

- PHP ≥ 7.4, no framework. One page = one file. Shared logic in `includes/*.php`
  (plain functions, no classes/namespaces in app code). Composer only for
  PHPMailer + TCPDF; PSR-4 `HotelWebsite\ → src/` declared but app code is procedural.
- DB: MySQL via PDO, `config/database.php` (creds from `.env` through
  `config/database.local.php`). Prepared statements everywhere. `BALANCE_TOLERANCE`
  (`config/database.php:57`) for money comparisons — never compare raw floats.
- Auth: `admin/admin-init.php` = session auth + CSRF + security headers + per-page
  permission checks (`admin/includes/permissions.php`). Public forms use
  `includes/public-csrf.php`. Input via `includes/validation.php`.
- Front-end: plain HTML/CSS/JS, no build step. Tokens in `css/base/variables.css`; BEM;
  the ballena editorial system in `css/sections/ballena.css` is the current guest-side
  design direction. Admin CSS in `admin/css/`.
- Tests: PHPUnit declared in `require-dev` but **no test suite**. The real safety net is
  `scripts/smoke_test_booking.php` and `scripts/smoke_test_finance.php`.

## Who uses it & core problem

- **Guests** — find the hotel, see rooms/menus/gym, book and pay without phoning.
- **Front-desk & ops staff** — reservations, check-ins, housekeeping, POS orders,
  kitchen tickets, stock.
- **Management/owners** — finance reports, EOD, accounting, analytics.

The core problem: replace manual/fragmented hotel operations with one integrated,
self-hosted system with **no per-booking commission** (vs OTAs) and no SaaS fees.

## Best-in-class bar

1. **Cloudbeds** — PMS + booking engine. Does that this codebase doesn't yet:
   integrated online payment capture at booking time; channel manager (OTA sync);
   automated guest-communication lifecycle.
2. **Mews** — modern PMS UX. Does better: fast task-oriented staff workflows that work
   on tablets/phones at the desk; heavy automation of routine state changes; API-first
   design with webhooks.
3. **Little Hotelier (SiteMinder)** — small-property focus. Does better: dead-simple
   direct-booking widget with conversion-optimized 3-step checkout; rate plans &
   promotions surfaced in the booking flow; mobile app for owners.

## Gaps vs that bar — ranked by impact

1. **Migration completion is unverified.** Six of seven migration phases have unknown
   status, including the destructive schema rebuild and the post-cutover security step
   ("remove `109.78.91.146` from cPanel → Remote MySQL"). Until an owner confirms, every
   plan that assumes a working live database is built on sand. **This is the first thing to
   resolve, and it is an owner question, not a build task.**
2. **The inherited platform is unproven against Liwonde's data.** The admin half of this
   repo has never run a real Liwonde booking end-to-end (all Liwonde data was test data —
   3 bookings, 1 payment — and none was migrated). Rosalyn's own smoke tests exist here but
   there is no evidence they have been run against this install.
   → Highest-impact *build* fix: run and green the two smoke tests, then extend them to the
   paths the migration touched.
3. **Front-end/back-end seams from the transplant.** Phase 4 was the only phase the
   migration plan called genuinely uncertain, and it is the phase still being edited. The
   reconciliation items it names (`check-availability.php` `room_units`→`individual_rooms`,
   `index.php` `hero_slides`→`page_heroes`, restaurant reservation form removal, UTM
   removal) each need confirming as actually landed, not assumed.
4. **Module enablement is undecided.** `MIGRATION_PLAN.md` §6 lists "likely on" and
   "likely off" but nothing is settled. Liwonde Sun may not operate POS, KDS, stock or
   procurement at all — that is a third of the inherited surface area. Planning build work
   for a module the property doesn't run is pure waste, so **settle this before Phase 2 of
   the build plan.**
5. **Payment capture at booking time** — verify whether the public booking flow takes
   online payment or only records bookings for manual settlement. If manual, this is the
   biggest conversion/revenue gap vs Cloudbeds/SiteMinder.
6. **Guest communication lifecycle** — `scripts/guest_lifecycle_emails.php` and
   `admin/includes/guest-lifecycle-lib.php` exist; whether they are scheduled and pointed
   at Liwonde's SMTP is unconfirmed (migration Phase 5).
7. **Performance & page weight** — `booking.php` ≈86 KB of PHP per render; audit output
   size, image proxying, cache hit rates. Direct-booking conversion is latency-sensitive.
8. **Staff UX on mobile/tablet** — verify responsive behaviour of the highest-frequency
   workflows (POS, KDS, check-in, housekeeping) against the Mews bar, for whichever
   modules gap 4 leaves enabled.
9. **Accessibility & polish** on public pages — booking widget keyboard/screen-reader flow,
   contrast, touch targets, and consistency of the ballena treatment across all guest pages.
10. **OTA/channel sync** — deliberate scope decision, not a defect. Parked: needs owner
    input before any work (subscription costs, rate parity). Do not build without a decision.

## Out of scope unless the owner asks

Framework rewrites, OTA integration (gap 10), multi-property support, replacing the
procedural style with OOP, and **any divergence from Rosalyn's schema** (see the parity rail
in CORE_SYSTEM_BRIEF.md). Restoring the two features the migration removed — guest-facing
restaurant reservations and the secondary hero CTA — is also out of scope without a decision;
`MIGRATION_PLAN.md` §4.1–4.2 records what reversing each would cost.

The bar is "best small-hotel direct-booking + PMS", not "rebuild Mews".
