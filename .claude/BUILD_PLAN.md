# BUILD PLAN — Liwonde Sun Hotel 2026

Owned by `build-planner`. Application-code agents never edit this file.
Read `.claude/PROJECT_CONTEXT.md` and `.claude/CORE_SYSTEM_BRIEF.md` before touching it.

**Status: seeded, not yet started.** No build-loop cycle has run against this repo. The
checklist below is the *proposed* first round — the owner approves rounds, the planner does
not self-approve them (see `OPEN OWNER DECISIONS`, which gates most of Phase 2 onward).

---

## Definition of "built to completion" for this project

Liwonde Sun Hotel is built to completion when:

1. The Rosalyn-platform migration is **verified complete or explicitly closed out**, with no
   unknown-status phase remaining and no stale reference to a dropped table.
2. Every module the property actually operates is mapped, exercised end-to-end, and passing
   its acceptance criteria; every module it does not operate is **disabled in
   `admin/module-settings.php` and unreachable by direct URL** — not half-built.
3. A guest can complete a booking on a phone, receive a correctly-branded confirmation from
   the Liwonde address, and appear in the admin — verified, not assumed.
4. Staff can run the day on a tablet for the enabled modules: reservations, check-in,
   housekeeping, and whichever of POS/KDS/stock survive the module decision.
5. Money is provably correct: both smoke tests green, no raw-float comparison in any money
   path, invoice/receipt/quotation sequences correct for Liwonde's legal entity.
6. The guest site is consistent in the ballena editorial vocabulary across all pages, with
   no horizontal scroll 320–2560px and 44×44px touch targets throughout.
7. All 14 domains in `COVERAGE_MATRIX.md` are `swept`.

## PROJECT COMPLETE WHEN — Round 1 (proposed, awaiting owner approval)

Phase 0 — Learn (no code changes; safe to run before any owner decision)

- [ ] `SYSTEM_MAP.md` has a section for all 14 domains, built by `codebase-scout`
- [x] Migration Phase 4 reconciliation items confirmed landed — **clean.** A repo-wide grep
      over `*.php` and `*.js` for `room_units`, `hero_slides`, `restaurant_inquiries`,
      `marketing_campaigns`, `marketing_campaign_clicks`, `room_promotions`,
      `room_maintenance_tasks`, `deleted_records_backup`, `employee_titles`,
      `employee_activity_log`, `booking_additional_charges`, `campaign-attribution`
      returns **zero matches**. (2026-08-15)
- [x] Every table referenced in PHP exists in the Rosalyn schema — **one break found and
      fixed.** `admin/end-of-day-report.php` joined a non-existent `room_types` table *and*
      joined `individual_rooms.id` to `bookings.room_id` (wrong column: `bookings.room_id`
      points at `rooms.id`; the physical-unit FK is `bookings.individual_room_id`). The query
      sat in a `try/catch` that only wrote to `error_log`, so the "Room type revenue
      breakdown today" panel was **silently empty on every EOD load**. Rewritten to match its
      own working siblings, `admin/api/end-of-day-pdf.php:256` and
      `admin/api/end-of-day-send.php:350`, which both use `INNER JOIN rooms rt ON rt.id =
      b.room_id`. **The identical defect exists in Rosalyn's `admin/end-of-day-report.php`
      and has NOT been fixed there** — it is out of scope for this repo. (2026-08-15)
- [x] Both smoke tests run and are **green: booking 54/54, finance 21/21.** They were not
      green on first run — booking failed 4/54, all in idempotency + guest lookup. Cause was
      a **defect in the test, not the app**: `booking_reference` is `varchar(20)` and the
      server's `sql_mode` is `NO_ENGINE_SUBSTITUTION` (no `STRICT_TRANS_TABLES`), so the
      27-char fixture reference was silently truncated on insert and never matched on
      read-back. Fixture references now fit the column. (2026-08-15)
- [x] Branding/config audit reported — **not a code task.** `config/email.php` and
      `config/invoice.php` read identity from the database via `getSetting()` /
      `getEmailSetting()` (`email_from_name`, `email_from_email`, `address_line1..country`,
      `hotel_logo`, `invoice_prefix`), and `config/base-url.php` derives the host from the
      request rather than hardcoding one. So there are no Rosalyn strings to purge in code;
      whether the *values* are Liwonde's is an admin-panel check the owner must make in
      `admin/module-settings.php` → site settings. (2026-08-15)

Phase 1 — Stabilise (unblocked by Phase 0; no schema, no module changes)

- [ ] Both smoke tests exit 0
- [ ] Every `BLOCKED:` and `ASSUMPTION:` raised in Phase 0 is recorded below
- [ ] No PHP notice or warning on any guest page load
- [ ] No page references a table dropped by the migration

Phase 2 — Complete · **gated on OWNER DECISION 2 (module enablement)**

- [ ] Enabled modules each pass an end-to-end pass at their acceptance criteria
- [ ] Disabled modules are unreachable by direct URL and absent from the sidebar
- [ ] Guest booking → confirmation email → admin visibility verified end-to-end

Phase 3 — Polish

- [ ] Ballena editorial treatment consistent across all guest pages
- [ ] No horizontal scroll 320–2560px on any guest or staff page
- [ ] 44×44px touch targets on all staff screens for enabled modules
- [ ] Admin list views remain data tables at ≥1024px

---

## OPEN OWNER DECISIONS — these gate the plan

**These are questions, not tasks. The loop surfaces them batched via AskUserQuestion and
keeps building everything that doesn't depend on them.**

`RESOLVED 2026-08-15 by evidence — migration status.` Phases 2 and 3 are **done**: the
smoke tests connected to the live database, read seeded rooms (`#2 Executive Suite`),
inserted and deleted bookings, and exercised `finance_sequences` — 75 assertions green
across both suites. Phase 1 and Phase 4 were already proven by the repo. What remains
genuinely unknown is narrower than first thought: **Phase 5 (are the branding/SMTP values in
`site_settings`/`email_settings` Liwonde's?), Phase 6 (module enablement — see below), and
Phase 7 (cutover verification + the cPanel Remote MySQL step).**

`BLOCKED: 0 — post-cutover security step.` `MIGRATION_PLAN.md` Phase 7 ends with "remove
`109.78.91.146` from cPanel → Remote MySQL". If cutover happened and this was skipped, a
developer IP still has remote database access.
· options: (a) confirm it was removed; (b) remove it now; (c) confirm it is intentionally
still allowed for now. · recommend: (a) or (b) — this is a live exposure, not a cleanup task.

`BLOCKED: 4/5/7 — module enablement.` Which modules does Liwonde Sun actually operate?
`MIGRATION_PLAN.md` §6 guesses "likely off initially: POS · KDS/CDS/BDS · stock/inventory ·
procurement · gym memberships · Facebook/WhatsApp" — roughly a third of the admin surface.
· options: (a) owner names the enabled set now; (b) enable everything and prune later;
(c) defer. · recommend: (a) — planning or polishing a module the property never opens is
the single largest waste available in this project.

`BLOCKED: 6/14 — MySQL sql_mode has no STRICT_TRANS_TABLES.` The server reports
`sql_mode = NO_ENGINE_SUBSTITUTION`, so any value longer than its column is **silently
truncated on insert instead of raising an error**. This is what made the smoke test fail
against a booking reference that had never been stored, and it applies equally to real guest
data — an over-length name, email or special request is quietly cut rather than rejected.
· options: (a) enable `STRICT_TRANS_TABLES` on the server and fix whatever then starts
erroring — correct, but may surface latent over-length inserts across the app;
(b) leave as-is and rely on application-side length validation;
(c) enable it on a staging copy first and measure the fallout.
· recommend: (c) then (a). Not urgent, but it means "it saved fine" is not currently proof
that the data is intact.

`BLOCKED: 4/5 — two missing tables behind live admin features.` Both masked by catch blocks,
so they look like empty data rather than broken queries. Missing in Rosalyn too.
· `stock_payments` (F&B payment-split panel, `admin/reports.php`): (a) create and populate
from the POS payment path; (b) repoint at the existing `payments` table filtered to POS/F&B;
(c) remove the panel. **recommend (b)** if POS payments already land in `payments` — confirm
first; else (c), rather than keep a panel that reads as "no payments" when it is broken.
· `room_features` (`api/spatial-loading.php`, **not routed by `api/index.php`** — likely dead):
(a) confirm dead and remove; (b) create the table; (c) leave. **recommend (a).**
Note both options touch the locked schema, hence owner-only.

`BLOCKED: 2/3 — pre-create `room_inspections` via migration?` Now that the deadlock is fixed,
a room moving to `inspection` status will run `createRoomInspection()`'s lazy `CREATE TABLE`.
· options: (a) add a proper migration in `admin/migrations/` and remove the lazy DDL;
(b) let the app self-provision as written. · recommend: (a) — app-code DDL is exactly what
the locked-schema rail exists to prevent, and `admin/migrations/` is currently empty.

`BLOCKED: 12 — API key rotation.` `MIGRATION_PLAN.md` §1.4 requires `api_keys` be
regenerated rather than carried across. Unverified.
· options: (a) confirm regenerated; (b) rotate now in `admin/api-keys.php`.
· recommend: (b) if there is any doubt — rotation is cheap.

---

## Completed

| Date | Task | Outcome |
|---|---|---|
| 2026-08-15 | P0 — dropped-table sweep | Clean; zero references in `*.php`/`*.js` |
| 2026-08-15 | P0 — schema parity check | 1 break found and fixed (`room_types` in `admin/end-of-day-report.php`) |
| 2026-08-15 | P0 — smoke tests | Booking 54/54, finance 21/21; fixed a varchar(20) truncation defect in the booking fixture |
| 2026-08-15 | P0 — branding/config audit | Identity is DB-driven; no code changes needed |
| 2026-08-15 | P3 — guest palette softening | Dark surfaces warmed/lightened across 6 stylesheets (owner request) |
| 2026-08-15 | P3 — card hover simplification | Room + editorial card hovers reduced to lift/shadow; one shared `--landing-lift-y` (owner request) |
| 2026-08-15 | P1 — `updateRoomStatus()` bootstrap deadlock | `LEFT JOIN room_inspections` (table absent, created only by code sitting behind that same query) made every room status update fail; unused joined column dropped, no schema change. Fixed in both repos |

### Admin-side table audit (2026-08-15)

Every table name in SQL across `admin/`, `includes/`, `api/`, `config/`, `scripts/` was
extracted with PHP's tokenizer and diffed against `information_schema`. **Three** referenced
tables are absent — identical in both repos, confirming these are platform defects inherited
from Rosalyn rather than migration damage:

- `room_inspections` — **fixed** (above), no schema change needed.
- `stock_payments` — `admin/includes/reports-extra-tabs.php:65,205`. Masked by `rp_safe()`,
  so the F&B payment-split panel in `admin/reports.php` permanently shows
  "No payments recorded". Needs an owner decision — see `BLOCKED` below.
- `room_features` — `api/spatial-loading.php:267`. Catches and returns `[]`. That file is
  **not routed by `api/index.php`** and may be dead code. Needs an owner decision.

⚠️ With the deadlock cleared, moving a room to `inspection` status will now reach
`createRoomInspection()`, which executes a lazy `CREATE TABLE room_inspections` — **app-code
DDL, which this project's locked-schema rail treats as an owner decision.** It could never
fire before. Pre-creating the table via a proper migration is the cleaner option.

## Parked / failed-twice

_(none yet)_

## ASSUMPTIONS log

_(none yet)_

## Future Ideas (not in scope)

- Restore guest-facing restaurant reservations (removed by migration §4.1 — needs a table,
  an admin page, and a form; explicitly an owner decision).
- Restore the secondary hero CTA (removed by migration §4.2 — two nullable columns; cheap
  but breaks schema parity).
- OTA / channel manager sync (PROJECT_CONTEXT gap 10 — cost and rate-parity decision).
- Online payment capture at booking time (PROJECT_CONTEXT gap 5 — verify current behaviour
  in Phase 0 first; may be a defect rather than an idea).
