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

- [x] `SYSTEM_MAP.md` has a section for all 14 domains — **built 2026-09-02.** Tokenizer-extracted table references validated against the live schema; includes per-domain table sets, the file-level breakdown, known traps (getSetting vs getEmailSetting, room_id vs individual_room_id, image-proxy does not resize), files that issue no SQL, and a 15-row orphan-table audit.
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
`<developer-ip>` from cPanel → Remote MySQL". If cutover happened and this was skipped, a
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
| 2026-09-02 | P0.1 (interim) — email recipients moved to Liwonde | Owner has no `liwondesunhotel.com` mailbox yet, so the **sending identity was deliberately left alone** (`email_from_email`, `smtp_host`, `smtp_username` still ProManaged — changing the from-address without matching SMTP would fail SPF/DKIM and push mail to spam). Changed on live instead: `site_settings.email_main`, `email_settings.email_admin_email`, `email_settings.invoice_recipients` → `bookings@liwondesunhotel.com` (the address already used by `conference_email`/`email_restaurant`/`gym_email`). `email_main` was the bigger find — it is rendered as a `mailto:` in the footer of **every guest page** (`includes/footer.php:199`) and served by the public `api/site-settings.php`, so the site was publicly advertising the developer's address. Applied in one transaction. **Live file cache holds settings for 1h — clear via Admin → Cache Management to see it immediately.** |
| 2026-09-02 | P2.3 — scheduled jobs + backups | `scripts/setup-cron.sh` rewritten: was installing **1** job (cache clear), now installs **5** — database backup (`0 2 * * *`), tentative-hold expiry (`*/15`), guest lifecycle emails (`10 8`), gym reminders (`15 8`), cache clear — each at the cadence its own header docblock documents, logging to `logs/`. `daily_reports.php` is included only when `REPORT_RECIPIENTS` is set, because it exits 2 without recipients and would otherwise email a cron failure every morning. Dry-run by default, `--install` writes a marked, deduplicated block; cPanel users can paste it. **Verified `backup_database.php` actually runs against live: 72 KB gzip written to `backups/2026/09/`.** Installing the crontab on the server remains an owner action. |
| 2026-09-02 | Security — `backups/` was not gitignored | `scripts/backup_database.php` writes dumps to `backups/`, and `.gitignore` covered `Database/*.sql` but **not** `backups/`. A dump contains the SMTP password and admin password hashes, so a routine `git add -A` could have committed credentials. Added `backups/` to `.gitignore`. (The script already writes a deny-all `.htaccess` there, but that only blocks web access, not commits.) |
| 2026-09-02 | P1.4 — interpolated SQL removed | `admin/deals.php:134` now prepares the toggle read-back instead of interpolating `$id`. `admin/gym-members.php:529` now selects its WHERE clause from a fixed whitelist keyed by filter name, so an unexpected `?filter=` falls back to `1=1` rather than reaching the SQL string. Neither was exploitable (one cast to `(int)` and rejected `<1`; the other interpolated only hardcoded literals) but both violated the prepared-statements rail. All four filter branches executed against live read-only to prove the rewritten SQL is valid. |
| 2026-09-02 | P1.8 — `.htaccess` file-access denial | The file had **no** Files/FilesMatch/Require directive at all. Added: deny-all on dotfiles (`^\.`) so a `.env` at the web root can no longer be served as plaintext; deny on `env|ini|log|sql|sql.gz|sqlite|db|bak|backup|old|orig|save|swp|swo|dist|lock|yml|yaml|json`; a re-allow for `manifest/site/browserconfig.json`; and `[F,L]` rewrites blocking `.git`, `.github`, `node_modules`, `vendor/bin`, `backups`, `logs`, `cache`. Both the Apache 2.4 (`Require all denied`) and 2.2 (`Deny from all`) forms are present so the rule cannot silently no-op. Checked nothing legitimate breaks: the only non-vendor `.json`/`.ini` files are `composer.json`, the two `.user.ini` files (server-side only) and `data/menu.json`, which **no PHP or JS references at all** — an orphan. `config/*.php` deliberately not denied: PHP executes those and they emit nothing. |
| 2026-09-02 | P1.6 — `.user.ini` hardening | Added `display_errors=Off`, `display_startup_errors=Off`, `log_errors=On`, `error_reporting=E_ALL`, plus `session.cookie_httponly`, `session.cookie_samesite=Lax`, `session.use_strict_mode` (verified no PHP code sets session cookie params, so there is nothing to conflict with). `session.cookie_secure` deliberately omitted — `.htaccess` already forces HTTPS and hard-setting it breaks any plain-HTTP local copy. **`expose_php` deliberately omitted and documented instead:** it is `PHP_INI_SYSTEM` and cannot be set from `.user.ini`, so writing it there would have looked like hardening while doing nothing. It needs the cPanel MultiPHP INI Editor. |
| 2026-09-02 | P2.4 — CI **written, then SCRAPPED at owner request** | `.github/workflows/lint.yml` was authored (php -l / node --check / bash -n plus a guard failing the build if `.env`, `config/*.local.php`, `backups/` or any `.sql` became tracked) and its guard logic was verified locally. It could never be pushed: GitHub refuses a token without the `workflow` scope, and three separate attempts to grant it did not take. After three push cycles spent rebasing the commit out of the way — one of which silently dropped it and needed reflog recovery — **the owner chose to scrap it entirely (2026-09-02).** Commit dropped, file and `.github/workflows/` removed. **There is no CI on this repo.** Nothing checks a push: no syntax gate, and no guard against a credential file being committed to what is a public repository. That guard mattered more than the linting — `.gitignore` is the only thing standing between a `git add -f` and a public database password. If CI is ever wanted again, the file is recoverable from this commit's parent. |
| 2026-09-02 | Checked — restaurant tables + stock setup | **No code defects; both are unfinished setup, and neither blocks the till for walk-in trade.** *Tables:* `restaurant_tables` is empty. `admin/pos.php` never reads it directly — the dine-in picker is fed by `rh_restaurant_active_tables()` via `includes/restaurant-location-locks.php` (`pos.php:1456`). So **walk-in and takeaway work today** (`order_type` defaults to `walk_in`, no location required), but **dine-in is impossible**: the dropdown is empty and `validateServiceContext()` refuses to send without one. `stock_orders.table_number` is a nullable varchar, not a FK, so a table is a label rather than a relation. Create them in Admin → Restaurant Tables (`restaurant-tables.php:795`, upsert). Room service additionally needs a checked-in guest, which is normal. *Stock:* all empty — 0 ingredients, recipes, batches, suppliers. **Recipes are optional by design**: `deductStockForMenuItem()` (`config/database.php:7655`) returns success with "No recipe — order is allowed, just no stock impact", so the 187 imported items sell fine and simply record no stock movement or cost-of-sales. Setup chain is ingredient (only `name` is required) → recipe (`menu_item_id` + `menu_type` + portions) → recipe ingredients (qty per portion + yield %) → batches for FIFO cost. **Verified `menu_type` is consistent**: both `pos.php` and `admin/stock-recipes.php:179` key on `mc.slug`, so the 14 new bar slugs from today's import (beer, whisky, non-alcoholic …) will match recipes correctly — this was worth checking, since a mismatch would have made every recipe silently never fire. **Operational caveat:** `ensureStockBatchCoverageForDeduction()` (`config/database.php:7726`) invents a `TMP-R-…` batch to cover any shortfall rather than blocking the sale, so service never stops on missing stock — but stock levels and food-cost figures become fictional if goods-received is not kept up. |
| 2026-09-02 | POS — menu imported to the till, refund ledger made symmetric | **(1)** Added `scripts/import_menu_to_pos.php` (dry-run by default, `--run` applies, insert-only, transactional) and ran it: 13 existing kitchen categories reused, **14 bar categories created**, **187 items inserted** (71 food + 116 drink). The POS till went from 0 to 187 sellable items. The website menu stays the source of truth — re-run after adding dishes. "Desserts" exists on both menus (kitchen puddings vs bar ice creams/shakes), so the bar one is created as "Desserts (Bar)"; station is part of the match key throughout, so a kitchen category is never reused for drinks. **Idempotency bug caught on the re-run check:** the disambiguated name was stored under a key the next run never looked up, so a second run wanted to create the category again and re-insert its 4 items. Fixed to resolve against both the source and display keys; re-verified 0 to create / 0 to insert / 187 skipped. **(2)** `admin/pos.php` refund now reverses `total_amount` only. It previously wrote `payment_amount = net + tip` and `total_amount = total + tip` while the sale passes `total_amount` alone, so refunding a tipped order left revenue negative by the tip. `refund_amount` moved to the same basis. `$refundTotal` still includes the tip for the audit entry, staff message and flash — that is the cash physically handed back, and `admin/pos-accounting.php` already counts tips separately for till reconciliation. Verified symmetric at three tipped/untipped amounts. No historical data affected: `stock_orders` is empty. |
| 2026-09-02 | Booking — VAT mode set to inclusive, window opened to 365 days | Owner decisions applied to live `site_settings`: `vat_pricing_mode` exclusive→**inclusive**, `max_advance_booking_days` 22→**365**. Inclusive was the choice that changes no guest-facing price — `vat_components()` extracts the tax from the priced figure rather than adding it on top, so a room advertised at MWK 165,000 still costs 165,000 and the 23,369.10 VAT is recorded as the embedded portion. Verified across all six live price tiers: `total_with_vat` equals the priced total at every one, zero price movement. Booking window verified bookable at 30/90/180/364 days and blocked at 400. **Code fix alongside it:** `booking.php` bound the same net figure to `total_amount`, `amount_due` and `total_with_vat` and never populated `vat_rate`/`vat_amount`, so web bookings carried no tax breakdown while admin ones did. It now calls `vat_components()` — the same shared helper `admin/create-booking.php` uses, already available since `booking.php` loads `config/database.php` — and stores rate and amount. INSERT widened 30→32 columns; columns, placeholders and bind values all verified at 32 and positionally aligned. Under inclusive the bound `total` is identical to the old value, so the change is recording-only. **Not backfilled:** booking #109 predates this and still holds `vat_rate=0.00`; it was quoted 165,000, which under inclusive is now the correct gross, so only its tax split is missing. |
| 2026-09-02 | Reviews — fabricated testimonials deleted | Owner approved option (a). All 3 rows in `testimonials` were Rosalyn seed data dated 2026-01-19 — "Sarah Johnson, London, UK", "Michael Chen, Singapore", "Emma Williams, New York, USA" (the last reading *"Met our expectations for a 2-star hotel"*) — displayed publicly on the homepage as real guest feedback with no admin route to remove them. Snapshotted to restorable `INSERT` statements first, then deleted in one transaction. `testimonials` now 0 rows; `reviews` untouched at 3. With the empty-guard added earlier, `#testimonials` now renders nothing at all, so the homepage carries exactly one guest-feedback section — the admin-managed `#reviews`. **`getCachedTestimonials()` caches for 30 min, so the live site keeps showing the old rows until Admin → Cache Management is cleared.** |
| 2026-09-02 | Reviews — scraped imports forced to pending | Owner approved option (b). `admin/api/review-scraper.php` now hardcodes `$status = 'pending'`; the client cannot request `approved`, and the now-unreachable status validation was removed. **The UI had to change with it or it would have lied:** `admin/reviews.php` offered two buttons, "Import Pending" and "Import & Approve" — the latter would have silently imported as pending. Both replaced with a single **"Import for Review"**, the `status` parameter dropped from `importScrapedFeedback()` and from the request payload. Nothing scraped can now reach the public site without a human approving it in the moderation list. Inline JS re-extracted and `node --check`ed. Note the 3 pre-existing rows are already `approved` and live — they are Facebook posts and a news item, not guest reviews, and still warrant an editorial pass. |
| 2026-09-02 | Reviews — CSRF gap on moderation endpoints | `admin/api/reviews.php` (POST insert / PUT status / DELETE) and `admin/api/review-responses.php` (POST) mutated review data on a session cookie alone, with **zero CSRF validation** — neither even loaded `config/security.php`, so `validateCsrfToken()` was not in scope. PUT/DELETE are shielded in practice by the CORS preflight (no `Access-Control-Allow-*` anywhere), but POST is a classic JSON-via-form-post target: the handler reads `php://input` and `json_decode`s it, which an attacker page can satisfy. Added the require plus a gate accepting `X-CSRF-Token` (so body-less DELETE is covered) or `_csrf` in the payload; GET is untouched. Updated all three `fetch()` calls in `admin/reviews.php` to send the header — `_pageCsrf` already existed at line 382 for the scraper, so the pattern was already in the page. Verified the only other caller, `js/room-reviews.js`, hits the **public** `api/reviews.php` with GET and is unaffected. Gate unit-tested across 7 method/token combinations. |
| 2026-09-02 | Reviews — scraper provenance leaked to guests | `admin/api/review-scraper.php` appends `Source: <url>` / `Source Date:` / `User Email:` into the `comment` body (there is no `source` column and the schema is locked). All three live reviews therefore rendered a raw Facebook URL inside the public quote, and the pattern would expose a guest email address too. Added `rh_public_review_text()` to `includes/reviews-display.php`, anchored to line-start so a guest writing "source: ..." mid-sentence is not truncated, and wired it into all three guest render points (`reviews-display.php:181`, `reviews-section.php`, `submit-review.php`). Admin screens keep the full text so provenance stays auditable. **Caught during verification:** `submit-review.php` did not load `reviews-display.php`, so the call would have fatalled the public review page — require added; `reviews-section.php` only loaded it inside its empty-branch, also fixed. Helper unit-tested on 5 inputs. |
| 2026-09-02 | Reviews — empty sections now render nothing | Owner rule: if there are no reviews, the section must not exist. `includes/reviews-section.php` now returns early when `$hotel_reviews` is empty (its "Be the first to share your experience" placeholder is removed as unreachable), and the `#testimonials` block in `index.php` is wrapped in `if (!empty($testimonials))`. Both previously rendered a heading over an empty grid. `index.php` if/endif verified balanced 11/11. |
| 2026-09-02 | Stray `}` in `booking.css` — FIXED | Earlier logged as "found, not fixed, needs a browser". Static analysis was enough after all: a brace walk with comments masked (newlines preserved so line numbers stay true) located exactly one stray closer at **line 3576**, with zero unclosed blocks at EOF. Context showed a simple doubled brace — `@media (max-width: 680px)` opens at 3554 and is correctly closed at 3575; 3576 was an orphan. Removed. File now 761/761, no strays, no unclosed; diff is one deleted line. Browsers discard a stray top-level `}` so the visible impact was nil, but it made the file fail structural validation, which the new CI would have flagged. |
| 2026-09-02 | `SYSTEM_MAP.md` built — all 14 domains | Was empty since the fork. Built by extracting table references with PHP's **tokenizer** (string literals only, so comment prose cannot be misread as a table name) and validating every name against the **live schema**, so only real tables are listed — chosen specifically because three earlier findings had to be withdrawn for treating a raw grep as evidence. Records per-domain table sets, the file-level breakdown, and things that cost real defects this session: the `getSetting()`/`getEmailSetting()` split-table trap, `room_id` vs `individual_room_id`, `image-proxy` not resizing, `visitor-tracker` tracking localhost. Also flags files that issue **no SQL at all** (`room-dashboard`, `cds`, `bds`, `station-settings`, `gym-packages`, `finance-schema`, `quotation-pdf`) and audits **15 orphan tables** — only `role_permissions` holds data (15 rows), and `v_active_tentative_bookings`/`v_tentative_booking_stats` are BASE TABLES despite the `v_` naming. COVERAGE_MATRIX Mapped column now `yes` for all 14. |
| 2026-09-02 | P1.3 — module-gate holes closed | Added 7 gates to `getModuleForPage()` (`admin/includes/permissions.php`): `gym-classes.php`→gym · `purchase-orders.php`, `stock-reorder.php`, `stock-suppliers.php`→stock · `facebook-settings.php`, `whatsapp-settings.php`, `visitor-analytics.php`→website_cms (that module's own `enabled_modules` description is "Gallery, pages, deals, reviews, **social settings**", so the integrations belong to it). Mapped pages 67→74; the remaining 18 unmapped are platform pages that must stay reachable regardless of module state. **Deliberately NOT mapped, against the original plan: `booking-settings.php`.** It looks like a bookings page, but it is the *only* page that edits SMTP/email settings — `updateEmailSetting()` is called nowhere else — so gating it behind `bookings` would lock an operator out of all email configuration whenever bookings is disabled. Reasoning left as a comment in the map so nobody "completes" the list later without deciding. Verified by **calling `getModuleForPage()` directly** rather than parsing: all 7 resolve to valid keys that `rh_module_key_enabled()` accepts; controls (`module-settings`, `login`, `dashboard`, `booking-settings`) still return NULL. **No behaviour change today** — all 12 modules are enabled on this install (R3), so this is correctness for whenever one is switched off. |
| 2026-09-02 | P2.1 — booking page onto the softened palette | Owner chose **safe swaps only**. Replaced 149 of 338 hardcoded colours in `css/sections/booking.css` with existing tokens: `#8B7355`→`var(--color-primary)` ×71 (the pre-softening brown — this is the swap that actually brings the page onto the palette from commit `b7a4fd5`), plus exact-value matches `#FFFFFF`/`#fff`→`--color-white` ×29, `#1A1A1A`→`--color-black` ×28, `#B18247`→`--color-lux-gold` ×11, `#5E554D`→`--color-japandi-ink-soft` ×7, `#F7F3EE`→`--color-background` ×3. Also updated the SVG chevron inside the one `data:` URI from `%238B7355` to `%238A775F` — `var()` is invalid inside a data URI, so it needed the literal, and it would otherwise have been the one element left on the old brown. **Deliberately skipped** as visibly different rather than like-for-like: `#6B5740` ×28, `#C9A961` ×18, `#736149` ×10, `#E8E4DD` ×12 — 189 hardcoded values remain by design. Verified: diff is exactly 147 insertions / 147 deletions, every changed line contains a colour, line count unchanged at 5,350. |
| 2026-09-02 | FOUND (not fixed) — stray `}` in `css/sections/booking.css` | Brace audit during the palette work: the file has 761 `{` against 762 `}`, and a depth walk shows the nesting **goes negative mid-file** before a later `{` rebalances it to 0. **Pre-existing — identical counts in `HEAD`, not introduced by the colour swap.** Effect is that some rules between the stray `}` and the next `{` sit at the wrong nesting level and may be escaping a media query, so they could apply at all breakpoints or none. Not fixed here on purpose: correcting CSS nesting blind, on the booking page, with no way to render and compare, is a worse risk than the bug. Needs a browser session to fix safely. |
| 2026-09-02 | P2.2 — REDIRECTED by owner: cap uploads, do not shrink | Owner decided **not** to re-encode the existing 39 MB (`images/` stays as-is) and to cap uploads instead, warn-only, no resizing. Two corrections that informed this: (1) `includes/image-proxy.php` **does not resize or convert anything** — it is a fetch-and-cache proxy for *remote* URLs, so the earlier plan note about routing images through it for performance was wrong; (2) there is **no image processing anywhere in the codebase** — all six upload handlers are bare `move_uploaded_file()`. Added `RH_IMAGE_MAX_BYTES` (4 MB) + `RH_IMAGE_WARN_BYTES` (1.5 MB) + `rh_check_image_upload_size()` to `config/security.php`, and wired all six handlers to it. Previous caps were inconsistent and one was absent: room-pictures 5 MB · gallery/events/conference 8 MB · room-management **20 MB** · media-management **no cap at all** (how the 8.1 MB JPEG got in). A size cap needs no GD/Imagick, so the unresolved server-capability question no longer blocks anything. **Caught during verification:** `admin/api/room-pictures.php` does not load `admin-init.php` or `config/security.php`, so the new call would have been a fatal error on every room-picture upload — added the missing `require_once`. Helper unit-tested across 8 size cases. ASSUMPTION: 4 MB / 1.5 MB thresholds chosen to reject the existing 8 MB outliers while still passing typical phone photos; both are constants in one place. |
| 2026-09-02 | P1.1 — broken image paths | All 5 repointed to files that exist: `hero/slide1.jpeg`→`slide1.jpg` (events.php ×5, guest-services.php ×2 — this one was both the `og:image` and the `onerror` fallback, so the fallback 404'd too); `gym/hero.jpg`→`gym/fitness-center.jpg` (gym.php ×2 incl. `og:image` + JSON-LD, gym-schedule.php ×1); `restaurant/hero.jpg`→`restaurant/image.png` (×2, `og:image` + JSON-LD); `restaurant/bar-area.jpg`→`restaurant/image.png`; `conference/conference_room.jpeg`→`conference/Conference_Room1.jpeg`. Sweep now reports every `images/...` path in `*.php` resolving on disk; the only two remaining hits are a PHPMailer docblock example and a form `placeholder` attribute. |
| 2026-09-02 | P0.1 investigation — email routing | **Two real defects found and fixed**, both from the same root cause: the keys `email_admin_email` / `email_from_email` exist as **empty rows in `site_settings`** AND as populated rows in `email_settings`, while `getSetting()` reads only `site_settings` and returns `''` for an existing-but-empty row (the `$default` is never reached). (1) `admin/api/end-of-day-send.php:551` — all three recipient fallbacks resolved empty, so **the EOD report email could never send**; now falls through `getEmailSetting()`. (2) `admin/booking-settings.php` ×3 — the `{{contact_email}}` placeholder resolved to an **empty string** in booking email templates; same fallback added. Both verified against live: now resolve to a real address. **The admin UI already exists** (Admin → Booking Settings → Email tab, `admin/booking-settings.php:940-995`) and covers all 13 live keys, so no new page is needed. `booking_admin_email` / `conference_admin_email` / `gym_admin_email` / `restaurant_admin_email` are **dead rows — zero code references**; left in place (deleting rows is an owner call, and they are inert). `email_development_mode=1` in `site_settings` is also inert: the live code reads it via `getEmailSetting()` (=0) and additionally gates on `$is_localhost`. |
| 2026-09-02 | P1.7a/b — stop the app self-migrating | All 11 connection-time `ensure*()` call sites in `config/database.php` gated behind `rh_auto_migrate_enabled()` (`RH_ALLOW_AUTO_MIGRATE`, default **off**), which also retires the three unconditional `UPDATE bookings` backfills. Added `admin/migrations/migrate.php` (CLI runner, records into the existing unused `migration_log` table) + `001_create_room_inspections.php`. Applied to live: `room_inspections` created with **`INT UNSIGNED`** FKs — the lazy DDL it replaces declared signed `INT` against `individual_rooms.id INT UNSIGNED`, so MySQL 8 would have rejected the FK (errno 3780) and the failure was swallowed by a catch block. Lazy DDL removed from `includes/room-management.php`. Verified on live: `Com_alter_table=0`, `Com_create_table=0` on a normal bootstrap; data unchanged (1 booking, 3 rooms, 15 individual rooms). Backup taken first (133 objects / 967 rows). `_expireStaleTentativeBookings()` deliberately left inline — business logic, not DDL. |
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

## ROUND 2 — System review 2026-09-02 (repo-wide, evidence-based)

Method: full `php -l` sweep (268 files) · `node --check` (33 files) · tokenised SQL/DDL grep ·
API auth-guard audit · CSRF audit · money-comparison audit · module-gate map diff ·
asset-path existence check · CSS motion audit. Every item below was confirmed by reading the
code, not inferred from `MIGRATION_PLAN.md`.

### What is already healthy (verified — no task needed)

- **Lint**: 268 PHP files, 33 JS files — zero syntax errors.
- **CSRF**: all 8 public POST pages generate *and* validate a scoped token via
  `pub_csrf_generate()` / `pub_csrf_validate()`. No gaps.
- **Money**: 26 `BALANCE_TOLERANCE` uses. A scan for raw float equality on
  amount/total/balance/paid/due/refund returned 17 hits, **all integer/count comparisons**.
  No float-equality defect in any money path.
- **API auth**: every sensitive endpoint is guarded. The 12 `api/*.php` files without
  `API_ACCESS_ALLOWED` are session-authenticated POS/KDS endpoints not routed through
  `api/index.php`; each carries its own session + permission + CSRF check
  (`cancel-order.php:182-201`, `void-order.php:34-36`, `pos-tab-detail.php:26`,
  `reports-export.php:13-23`). `api/health.php` is deliberately public and rate-limited.
- **Dropped-table references**: still clean (re-confirmed).

---

### P1 — Patch tasks (confirmed defects · no schema · no owner decision · dispatch now)
> **Accuracy note on the Round 2 sweep (added 2026-09-02).** Three of its findings did not
> survive verification and have been withdrawn or rewritten: **P1.2** (alt text — regex broke on
> `?>` inside PHP attributes), **P1.5** (reduced motion — counted per-file instead of checking for
> a global rule), and **P2.1** (measured editorial *class counts* rather than the actual palette
> drift). All three shared one cause: a **text-pattern count used as a proxy for a behaviour**,
> without confirming the behaviour. The findings that held up were the ones verified by executing
> something — calling `getModuleForPage()`, running a query, checking a path on disk.
> **Rule for future sweeps: a grep produces a hypothesis, not a finding. Confirm by execution
> before it enters this plan.**



- [x] **P1.1 — 5 broken image paths, 3 of them `og:image` + JSON-LD.** Referenced in PHP,
      absent from disk. Social previews and search thumbnails for three guest pages 404 today.
      - `images/hero/slide1.jpeg` — the file on disk is `slide1.jpg`. Breaks
        `events.php:141,153,213` (`og:image`) and `events.php:291,388` (`onerror` fallback,
        so the fallback 404s too); `guest-services.php:224`.
      - `images/gym/hero.jpg` — `gym.php:286,292` (`og:image` + JSON-LD), `gym-schedule.php:99`.
      - `images/restaurant/hero.jpg` — `restaurant.php:350,356` (`og:image` + JSON-LD).
      - `images/conference/conference_room.jpeg` — `guest-services.php:157`.
      - `images/restaurant/bar-area.jpg` — `restaurant.php:615` `onerror` fallback.
      Repoint to existing assets. **Do not invent new images.**
      Agent: `frontend-specialist`. Accept: every `images/...` path referenced in `*.php`
      resolves on disk.

- [x] **P1.2 — WITHDRAWN 2026-09-02. False finding, no defect exists.**
      The original claim ("every literal `<img>` renders without `alt`") was **wrong**, and the
      cause was my detection, not the code. The scan used `<img[^>]*>`, which terminates at the
      first `>` — and every one of these tags contains a PHP block, so the match ended at the `?>`
      inside `src="<?php ... ?>"` **before ever reaching the `alt=` on the following line**.
      Re-scanned with PHP blocks masked out first: **27 `<img>` tags, 25 carry `alt`.** The two
      that do not are a docblock example in `includes/image-proxy.php` and an `<img src="">`
      written inside an HTML comment in `index.php:315` — neither is markup.
      Alt quality also checked and is sound: values are data-driven (`$room['name']`,
      `$event['title']`, `$service['title']`), and the `alt=""` cases in `includes/header.php` are
      decorative logos inside an `<a aria-label="… - Home">`, which is the correct pattern rather
      than a defect. `index.php:313` even renders an `aria-hidden` placeholder instead of an empty
      `<img>` when a room has no photo.
      **Lesson recorded for future sweeps: never regex HTML attributes in PHP templates without
      masking `<?php … ?>` first.** PROJECT_CONTEXT gap 9 (accessibility) stays open on its other
      strands — contrast, keyboard flow, touch targets — but not on alt text. Every literal `<img>` in
      `index.php`, `booking.php`, `room.php` (x2), `restaurant.php`, `gym.php`, `events.php`,
      `includes/header.php` and `includes/hero.php` renders without `alt`. WCAG 1.1.1 failure,
      and a direct hit on PROJECT_CONTEXT gap 9. Decorative images get `alt=""`; content images
      get real text. Agent: `frontend-specialist`, then `ui-designer`.

- [x] **P1.3 — Module-gate map holes.** DONE 2026-09-02 — 7 added, `booking-settings.php` deliberately excluded (see Completed). `getModuleForPage()`
      (`admin/includes/permissions.php:1402`) omits pages that plainly belong to a gated module,
      so they stay reachable when that module is off:
      `gym-classes.php` (gym) · `purchase-orders.php`, `stock-reorder.php`,
      `stock-suppliers.php` (stock) · `facebook-settings.php`, `whatsapp-settings.php`
      (integrations) · `visitor-analytics.php` (website_cms) · `booking-settings.php` (bookings).
      The other 17 unmapped pages (login/logout/dashboard/password/manifest/index/admin-init/
      module-settings/system-logs/api-keys/backup/cache/user-management/ajax-receipt/
      video-upload-handler) are correctly ungated platform pages — leave them.
      Prerequisite for Phase 2's "disabled modules unreachable by direct URL".
      Agent: `backend-specialist`. Map additions only — no schema, no permission changes.

- [x] **P1.4 — Two interpolated SQL statements.** DONE 2026-09-02. Neither is exploitable (`deals.php` casts to
      `(int)` and rejects `< 1`; `gym-members.php` interpolates only hardcoded literals), but
      both violate the "prepared statements always" rail and will fail QA on any future edit.
      `admin/deals.php:134` (`WHERE id=$id`) · `admin/gym-members.php:529` (`WHERE $where`).
      Agent: `backend-specialist`. Accept: bound parameters, behaviour unchanged.

- [x] **P1.5 — WITHDRAWN 2026-09-02. False finding, already covered globally.**
      The original check counted `prefers-reduced-motion` occurrences **per file** and flagged
      `css/review-form.css` and `css/sections/confirmation.css` for having none. But
      `css/base/reset.css:226-235` carries the universal guard —
      `*, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1
      !important; transition-duration: .01ms !important; scroll-behavior: auto !important; }`
      inside `@media (prefers-reduced-motion: reduce)`. Because it targets `*` with `!important`,
      it beats any per-file declaration regardless of specificity or load order, so both files are
      already covered. Verified the delivery path too: `reset.css` is imported at `css/main.css:15`;
      `confirmation.css` at line 46 of the same file; and `review-form.css` is only ever loaded by
      `submit-review.php` and `review-confirmation.php`, **both of which also load `main.css`**.
      A per-file guard would be redundant. **The per-file count was the wrong metric** — the right
      question was whether a global rule exists, and it does.
      `css/review-form.css` and `css/sections/confirmation.css` declare `@keyframes` /
      `animation:` with no reduced-motion guard; the other 25 animated sheets have one.
      Agent: `ui-designer`. Match the existing guard pattern — do not invent a new one.

- [x] **P1.6 — `.user.ini` hardening.** DONE 2026-09-02. It sets upload/exec limits only, so
      guest pages inherit whatever the server default is; only 10 admin/API files set
      `display_errors` for themselves. Add `display_errors = 0` and `log_errors = 1`.
      Agent: `backend-specialist`. One file, two lines.

- [x] **P1.8 — `.htaccess` file-access denial.** DONE 2026-09-02. Confirmed 2026-09-02: no
      `<Files>`, `<FilesMatch>`, `Require` or `Deny` directive anywhere in the file. It sets
      compression, caching, security headers, HTTPS and `Options -Indexes`, but nothing stops a
      dotfile or config file being fetched over HTTP. If a `.env` ever exists at the web root it
      is served **as plaintext** — credentials included. `config/database.local.php` is less
      exposed (PHP executes it and it emits nothing) but should be denied too, along with
      `composer.json`, `composer.lock`, `*.sql`, `*.log` and `.git/`.
      Agent: `backend-specialist`. Add a `<FilesMatch>` deny block plus a `.git/` rule.
      Accept: requesting `/.env`, `/composer.json` or `/.git/config` returns 403.
      **Note this is a hardening measure, not evidence of a live leak** — see the credential
      trace below; the installation most likely has no `.env` at all.

> **Credential resolution, traced 2026-09-02 (answers "where are the DB creds?").**
> `config/database.php:19-30` resolves in two steps: include `config/database.local.php` if
> present (sets `$db_host`/`$db_name`/`$db_user`/`$db_pass` directly), then fall through to
> `getenv()` for anything still unset. **There is no `.env` parser anywhere in this codebase** —
> no `parse_ini_file`, no Dotenv package, no `putenv`, and composer requires only PHPMailer and
> TCPDF. PHP's `getenv()` reads real process environment variables, *not* `.env` files. So a
> bare `.env` at the project root does nothing here, and the comment at `config/database.php:13-16`
> claiming the loader "reads the .env file" is **inaccurate** unless the (gitignored, server-only)
> `database.local.php` contains its own parser. Live installs therefore connect via either
> `config/database.local.php` (MIGRATION_PLAN.md:130 has the step "Write Liwonde
> `config/database.local.php`") or Apache `SetEnv` / cPanel environment variables. Both that file
> and `.env` are gitignored, which is why neither appears in the repo.

### P2 — Structural gaps (larger, still no owner decision needed)

- [x] **P2.1 — CORRECTED, then DONE (safe swaps) 2026-09-02. The metric was wrong; the real defect is a stale palette.**
      The original finding counted `ballena`/`bellhop`/`editorial` class references per page and
      concluded `booking.php` (0 refs) was "missing the design system". **That metric was
      misleading**: those classes are largely *content-section components* (testimonials rail,
      facilities rows, rooms rail, about block), not a page-layout framework. A booking **form**
      legitimately would not use them, so a 0 there is not by itself a defect.
      The real, measurable inconsistency is the palette. `css/sections/booking.css` is 5,350 lines
      with **338 hardcoded hex colours** beside 403 token uses — and the owner-requested palette
      softening (commit `b7a4fd5`, 2026-08-15) touched **8 stylesheets and `booking.css` was not
      one of them**. So the booking page still renders the pre-softening colours: it hardcodes
      `#8B7355` **71 times** where `--color-primary` is now `#8A775F`, plus `#6B5740` ×28,
      `#C9A961` ×18, `#736149` ×10. **The most commercially important page is the one page still
      on the old palette.**
      Scope should therefore be *token alignment on `booking.css`*, which the rails classify as
      polish ("applying existing tokens from `variables.css`"), not an editorial restructure of
      the form. Note the mapping is not 1:1 for every colour — `#C9A961` vs `--color-lux-gold`
      `#B18247` differ enough to be a visible change — so the gold/accent values need an owner
      call, while `#8B7355 → --color-primary` is a clear like-for-like.
      **Superseded scope (do NOT do):** restructuring `booking.php` markup into editorial classes. Count of
      `ballena` / `bellhop` / `editorial` class references per guest page:
      `index.php` 63 · `events.php` 49 · `gym.php` 34 · `restaurant.php` 20 · `conference.php` 19 ·
      `room.php` 13 · `rooms-gallery.php` 4 · `rooms-showcase.php` 4 · `guest-services.php` 2 ·
      **`booking.php` 0** · `check-availability.php` 0 · `booking-lookup.php` 0 ·
      `contact-us.php` 0 · `faq.php` 0 · `gym-schedule.php` 0 · `privacy-policy.php` 0 ·
      `submit-review.php` 0.
      `booking.php` is the most commercially important page on the site and carries none of the
      current design system. This is Phase 3 item 1, now quantified.
      Sequence: `booking.php` → `check-availability.php` → `rooms-gallery.php` /
      `rooms-showcase.php` → the rest. One page per task, `frontend-specialist` → `ui-designer`.

- [~] **P2.2 — Image payload.** REDIRECTED 2026-09-02: owner chose an upload size cap over re-encoding; existing `images/` left untouched by decision. See Completed. `images/` is **39 MB
      across 44 JPEG/PNG files with zero WebP**. `images/gym/personal-training.jpg` is **8.1 MB**;
      hero slides are 3.9 MB (`slide1.jpg`) and 5.0 MB (`slide4.jpg`); `art.jpg` 3.4 MB;
      `family_suite.jpg` 2.9 MB. `.htaccess` caches images for a year and
      `includes/image-proxy.php` exists and is used by 5 pages — but the origin files are
      unoptimised. On a Malawian mobile connection the hero alone is a multi-second stall on the
      highest-bounce page. This is PROJECT_CONTEXT gap 7, and it is an asset task, not a code task.
      Recommended: resize to max 2560px, re-encode at ~q80, emit WebP siblings, and route the
      remaining direct `<img src>` references through `image-proxy`.
      **Flagged rather than dispatched: it rewrites binary assets under `images/`, which the
      standing rails place off-limits to agents.** See the `BLOCKED` entry below.

- [x] **P2.3 — Five scheduled jobs have no scheduling path.** DONE 2026-09-02 (install on server is an owner action). `scripts/setup-cron.sh` installs
      exactly one cron line (`scheduled-cache-clear.php`). Not installed by it:
      `guest_lifecycle_emails.php` · `gym_membership_reminders.php` · `daily_reports.php` ·
      `expire_tentative_bookings.php` · `backup_database.php`. So PROJECT_CONTEXT gap 6 is
      confirmed as a **real** gap rather than an unknown: pre-arrival and post-stay emails
      cannot fire, and **there are no automated backups**.
      Agent: `backend-specialist` — extend `setup-cron.sh` with the cadences each script already
      documents in its own header docblock. Installing the crontab on the live server stays an
      owner action.

- [ ] **P2.4 — CI. SCRAPPED 2026-09-02 by owner decision** (blocked on the `workflow` token scope; see Completed). Re-open only if the scope is granted. `.github/` holds Copilot agent prompts only; there is no `workflows/`
      directory, so nothing runs `php -l` or the smoke tests on push. Add a minimal workflow that
      lints changed PHP/JS. Agent: `backend-specialist`. No new dependencies.

---

`BLOCKED: 9 — switching the sending address needs Liwonde SMTP credentials.` The real domain is
**`liwondesunhotel.com`** — already present in `site_settings` as `conference_email`,
`email_restaurant` and `gym_email` (all `bookings@liwondesunhotel.com`) and as the receptionist
admin user (`reception@liwondesunhotel.com`). But the transport is still ProManaged's:
`smtp_host=<smtp-host>`, `smtp_username=info@promanaged-it.com`.
**Changing `email_from_email` alone would make deliverability worse, not better** — mail would
claim to come from `liwondesunhotel.com` while being sent by a server with no SPF/DKIM authority
for that domain, which is the classic spam-folder signature. The from-address and the SMTP account
must move together.
· options: (a) owner supplies mailbox + SMTP host/username/password for `liwondesunhotel.com`,
then set `email_from_email`, `smtp_*` and `email_admin_email` together in Admin → Booking Settings
→ Email; (b) keep sending via ProManaged for now and change only the notification recipients so
Liwonde staff at least receive booking/enquiry alerts; (c) leave entirely as-is.
· recommend: (a). If the mailbox does not exist yet, (b) is a safe interim — it fixes who *gets*
the mail without touching who *sends* it.

`RESOLVED 2026-09-02 (owner chose option a) — "What Our Guests Say".`
The homepage renders **two** guest-feedback sections, backed by different tables:
· `#reviews` ("Stories from Our Guests", `index.php:414` → `includes/reviews-section.php`) reads
  **`reviews`** — managed by `admin/reviews.php`, gated on the `website_cms` module. Working.
· `#testimonials` ("What Our Guests Say", `index.php:418`) reads **`testimonials`** via
  `getCachedTestimonials(3)` — and **no file in the codebase INSERTs, UPDATEs or DELETEs that
  table.** There is no `admin/testimonials.php`. It cannot be edited from the back office at all.
Live contents of `testimonials`: three seed rows dated 2026-01-19 — "Sarah Johnson, London, UK",
"Michael Chen, Singapore", "Emma Williams, New York, USA", one of which reads *"Met our
expectations for a 2-star hotel."* **These are fabricated demo testimonials from the Rosalyn
platform, publicly displayed on the live homepage as real guest feedback, with no way to remove
them from admin.** Meanwhile the genuine feedback (IBRAHIM, Hon. Dr. Michael Usi) sits in
`reviews` and is shown in the *other* section.
· options: (a) delete the three seed rows — with the new empty-guard the section then disappears
  by itself, no code change needed, and the managed `#reviews` section carries the real feedback;
  (b) repoint `#testimonials` at the `reviews` table so it is admin-managed — but the two sections
  would then show the same content twice; (c) build an `admin/testimonials.php` CRUD page — most
  work, and it keeps two parallel systems for one job.
· recommend: **(a)**. It removes fabricated social proof today, needs no schema or code change,
  and leaves exactly one admin-managed source of truth. (c) only if the property genuinely wants
  curated pull-quotes separate from guest reviews.

`RESOLVED 2026-09-02 (owner chose option b) — the review scraper imports search snippets, not reviews.`
`admin/api/review-scraper.php` queries DuckDuckGo HTML, Bing RSS and an `r.jina.ai` proxy, then
lets an admin import a snippet into `reviews`. It is a **web-search importer**, not a
Google/TripAdvisor review integration, so accuracy is bounded by what a search snippet contains.
Evidence from the three live rows: all are **Facebook page content, not guest reviews** — one is
a news item ("Hon. Dr. Michael Usi officially opening a revamped 42 room Liwonde Sun Hotel"), one
a generic recommendation. Each was stored as a **5-star guest review** (the rating is chosen at
import, not derived), and `title` holds the raw search-result title including the " - Facebook"
suffix. The provenance leak is fixed, but the classification problem is editorial, not technical.
· options: (a) keep it as an assisted-discovery tool and require staff to rewrite title/rating
  before approving; (b) restrict imports to `status='pending'` so nothing reaches the site without
  review; (c) replace with a real reviews API (Google Business Profile) — cost and credentials;
  (d) stop using it and rely on `submit-review.php`.
· recommend: **(b) now** — a one-line safety net — then (a) as policy. Note the three current rows
  are already `approved` and live; re-check them against option (a) before the next import.

`RESOLVED 2026-09-02 (owner chose inclusive) — the public booking flow applied NO VAT.`
Found 2026-09-02 while checking the booking engine. `booking.php` computes
`base + child supplement + tourism levy + packages` and then binds that **same net figure
to all three of `total_amount`, `amount_due` AND `total_with_vat`** (`booking.php:690-692`).
It never reads `vat_enabled`, `vat_rate` or `vat_pricing_mode` — a grep for those in
`booking.php`, `check-availability.php` and `includes/pricing.php` returns **zero** hits, and the
INSERT column list omits `vat_rate` and `vat_amount` entirely, so both default to `0.00`.
`admin/create-booking.php` **does** apply VAT correctly (25 references; computes `$vat_amt`,
`$twv` and stores all three columns).
Live settings: `vat_enabled=1`, `vat_rate=16.5`, `vat_pricing_mode=exclusive` — and the mode was
last written **2026-08-13 11:04:30**, over nine hours *before* the only live booking was created
at 20:41:45. So this is not a stale-setting artefact. That booking (#109) stores
`vat_rate=0.00`, `vat_amount=0.00`, `total_with_vat=165000.00` = its net total.
**Consequence: a guest booking online is billed 16.5% less than the identical booking entered by
reception.** On booking #109 that is 27,225 MWK. `tourism_levy_enabled=0`, so the levy is
correctly zero and is not part of this.
· options: (a) apply VAT in the public flow to match admin — correct, but it **raises what guests
are charged**, so prices shown on the site must be reviewed at the same time; (b) treat displayed
room prices as VAT-inclusive and set `vat_pricing_mode=inclusive`, back-computing the net —
changes no guest-facing price but changes the accounting split; (c) leave the public flow net and
add VAT at invoice time — means the guest's confirmation total and their invoice total differ.
· recommend: **owner decision, not an agent one.** This is money semantics and a guest-visible
price change — explicitly ESCALATE-class under CORE_SYSTEM_BRIEF. Whichever way it goes, note
booking #109 was quoted 165,000 and would need a decision of its own.

`RESOLVED 2026-09-02 (owner set 365) — guests could not book more than 22 days ahead.` `max_advance_booking_days = 22` in
`site_settings`, enforced on the guest path in four places: `booking.php:1003`,
`check-availability.php:442`, `includes/booking-functions.php:318` and `api/availability.php:96`.
The code defaults are 30/365, so 22 is a value someone set. For a hotel this silently blocks all
seasonal and advance booking — a guest planning a trip two months out is told there is no
availability. Likely a leftover test value rather than policy.
· options: (a) raise it to a normal horizon (180–365 days); (b) confirm 22 days is deliberate.
· recommend: (a), but it is an operational setting, so the owner sets the number.

`RESOLVED 2026-09-02 (owner approved import) — the POS till had ZERO sellable items.` Checked 2026-09-02. `admin/pos.php` builds
its catalogue from **`menu_items` JOIN `menu_categories`** in all 5 of its menu queries, and
`menu_items` has **0 rows** — the POS would list nothing, so no order can be rung up.
Liwonde's real menu (71 food + 116 drink = 187 items) lives in **`food_menu` / `drink_menu`**,
which only the *public website* reads (`restaurant.php`, `menu-pdf.php`). `admin/menu-management.php`
edits those same website tables. **Nothing in the codebase copies one into the other** — a grep for
writers of `menu_items` finds only `product-management-mode.php` (the non-restaurant editor,
shown *instead of* the Food/Drinks editor), `pos.php` and `stock-barcode-receive.php`.
Notably `menu_categories` **is** populated with 13 active `food_service` categories matching the
real menu (Breakfast, Starter, Chicken Corner … Liwonde Sun Specialities), all stationed to
`kitchen` — so the POS category structure was set up and the items step was never done.
· options: (a) import `food_menu`+`drink_menu` into `menu_items` mapped onto the existing
categories — one-off script, keeps the website menu as the source of truth; (b) enter the POS
catalogue by hand via Product Management; (c) point `pos.php` at `food_menu`/`drink_menu`
directly — rejected: they carry no station, barcode, recipe link or `show_pos` flag, all of
which the till and KDS depend on.
· recommend: (a). Note it is a **data** decision (which items are sellable, at what till price,
on which station), so the mapping needs owner input rather than an agent guess.

`RESOLVED 2026-09-02 (owner chose option a) — POS refunds reversed more than the sale recorded, by the tip.` The sale path
(`pos_syncPayment` → `rh_sync_restaurant_payment`) passes **`total_amount` only**, so the tip
never enters the `payments` ledger. The refund path (`admin/pos.php:937-968`) writes
`payment_amount = net + tip` and `total_amount = total + tip`. Simulated at the live VAT rate:
a 10,000 sale with a 1,000 tip records 10,000 gross, and refunding it reverses 11,000 — leaving
the ledger **-1,000** on a transaction that netted zero. Separately, `admin/pos-accounting.php`
counts `total_amount + tip_amount` for till reconciliation, so expected-cash already includes
tips while ledger revenue does not — the two disagree even without a refund.
· options: (a) make the refund reverse `total_amount` only, matching the sale — smallest change,
treats tips as outside revenue (the usual treatment, and consistent with the sale path);
(b) include the tip on both sale and refund — makes tips revenue, which changes VAT and EOD
figures; (c) leave.
· recommend: **(a)**, but it is money semantics and therefore an owner decision. **No damage
to date: `stock_orders` is empty, so no POS sale or refund has ever been recorded.**

### OWNER DECISIONS from this review — RESOLVED 2026-09-02

`RESOLVED — per-request DDL in config/database.php.` Owner chose **flag it AND migrate it to
the live database now** (both options, not either/or). Splits into two tasks:

- [x] **P1.7a — Gate the auto-migration behind an env flag.** DONE 2026-09-02. Wrap the nine `ensure*` calls at
      `config/database.php:94-116` in a single check for `RH_ALLOW_AUTO_MIGRATE=1`, **default
      off**, so a normal request performs zero `information_schema` probes and zero DDL. The
      three unconditional `UPDATE bookings` backfills at `config/database.php:238-244` go behind
      the same flag — they are one-time data repairs currently re-run on every request.
      **Leave `_expireStaleTentativeBookings()` inline and unflagged**: it is business logic, not
      DDL, and availability correctness currently depends on it running without cron
      (see P2.3, which adds the cron path).
      Agent: `backend-specialist`. Accept: with the flag unset, a page load issues no
      `information_schema` query and no `ALTER`/`CREATE`; with it set, behaviour is unchanged.

- [x] **P1.7b — Author `admin/migrations/` from the existing `ensure*` DDL.** DONE 2026-09-02 (runner + migration 001; the rest of the DDL needed no migration — already applied). Transcribe every
      `CREATE TABLE` / `ALTER TABLE ... ADD COLUMN` statement now held in the nine `ensure*`
      functions into numbered, idempotent migration files, preserving the existing
      `information_schema` existence guards so re-running is safe. Include a small CLI runner
      consistent with the other `scripts/` entry points. Fills the directory that has been empty
      since the fork, and gives P1.7a somewhere to point.
      Agent: `backend-specialist`. **Authoring only — agents must not execute these against the
      live database** (see the note below). Accept: `php -l` clean; every DDL statement in the
      `ensure*` functions has a corresponding migration file; no statement invents a column or
      table that the `ensure*` code did not already create.

> **⚠ Execution against live is an owner action, and this is the one gap in the round.** The
> owner authorised migrating the live database, but also declined to place a `.env` /
> `config/database.local.php` in this working tree (decision below). With no credentials, no
> agent in this loop can reach the live database — so P1.7b ends at *authored and linted*
> migration files, and the owner runs the runner on the server. Back up first
> (`scripts/backup_database.php`), which P2.3 is separately trying to get onto a schedule.

`RESOLVED — image asset re-encoding (P2.2).` Owner authorised **a one-off agent pass over
`images/`**, lifting the standing rail against writing under `images/` for this task only.
Scope: resize to max 2560px on the long edge · re-encode at ~q80 · emit `.webp` siblings ·
**preserve every original under `images/_originals/` before overwriting** · then repoint the
remaining direct `<img src>` references through `includes/image-proxy.php`.
Priority targets: `gym/personal-training.jpg` (8.1 MB) · `hero/slide4.jpg` (5.0 MB) ·
`hero/slide1.jpg` (3.9 MB) · `hotel_gallery/art.jpg` (3.4 MB) · `rooms/family_suite.jpg`
(2.9 MB) · `rooms/Deluxe_Room.jpg` (2.6 MB). Combine with **P1.1**, which repoints five broken
image paths — same files, one pass. The rail lift is **scoped to this task**; `images/` returns
to off-limits afterwards.
Agent: `frontend-specialist`. Accept: no source image over ~600 KB; a `.webp` sibling exists for
every JPEG/PNG still referenced; every original preserved; no guest page references a missing path.

`UPDATED 2026-09-02 — `config/database.local.php` supplied, and it points at PRODUCTION.`
The owner located the file and added it to the working tree (correctly gitignored, `.gitignore:8`,
so it will not be committed). Probed without including `config/database.php`, so no DDL fired:
the target is a **remote raw IP on port 3306 with cPanel-style `p6..._` database and user names**
— i.e. the live production database, not a staging copy. This workstation **cannot reach it**:
TCP 3306 times out after 8s because the machine's public IP (redacted — this workstation, not the documented developer IP) is not in
cPanel → Remote MySQL. Note it is one range away from the `<developer-ip>` that MIGRATION_PLAN.md
Phase 7 says to remove — a dynamic-IP drift, not the same address.
**Consequence: the smoke tests still cannot run here, so the QA gate stays lint-only.**
**Do NOT resolve this by whitelisting this IP.** Both smoke suites INSERT and DELETE bookings,
and merely including `config/database.php` fires the `ensure*` DDL and the three `UPDATE bookings`
backfills — against live guest data. The correct fix is a staging copy or a local MySQL instance
restored from `scripts/backup_database.php`, pointed at by a second local config.

> **This makes P1.7a urgent rather than tidy.** The credentials now sitting in the working tree
> are production credentials. Any script anyone runs from this repo that includes
> `config/database.php` — a smoke test, a one-off query, an agent's verification step — will
> attempt ~20 `ALTER TABLE`/`CREATE TABLE` statements and 4 writes against the live database the
> moment the IP is reachable. The env flag is the guard that makes this repo safe to work in.

`RESOLVED — QA gate is lint-only.` Owner declined to supply a local or staging `.env`. The build
loop therefore gates on `php -l` and `node --check` **only**; `scripts/smoke_test_booking.php`
and `scripts/smoke_test_finance.php` are owner-run, offline from this loop.
**Consequence to keep visible in every future cycle:** in this project "QA-passed" now means
"it parses and matches the conventions" — not "it runs". No agent-side change can be claimed as
runtime-verified. P2.4 (CI) would have partly offset this, but it was scrapped 2026-09-02, so the
money, booking and DDL paths
the ones where a reviewer, not a test, is the last line of defence.

## ROUND 3 — Live database verification 2026-09-02

Owner supplied `config/database.local.php` and whitelisted this workstation in cPanel → Remote
MySQL. All checks below were run **strictly read-only, with a hand-built PDO connection that
never includes `config/database.php`**, so the `ensure*()` DDL bootstrap and the three
`UPDATE bookings` backfills did not fire. No smoke test was run — both suites write.

Target: MySQL 8.0.46-cll-lve · remote raw IP · 131 base tables + 2 views · **live production**.
Scale: 1 booking (id=109, 2026-08-13, `pending`) · 0 payments · 3 rooms · 15 individual rooms ·
2 admin users · `admin` last login 2026-09-02. **Live but effectively unused — so the risk
window for fixing the DDL problem is open now and will not stay open.**

### RESOLVED by live data

- **`BLOCKED: 4/5/7 — module enablement` → RESOLVED: everything is ON.** All 12 rows in
  `enabled_modules` have `is_enabled=1`: bookings · housekeeping · pos · stock · conference ·
  gym · finance · website_cms · station_kds · station_bds · station_cds · station_room_service.
  POS, stock and the four station modules were switched on 2026-08-16. MIGRATION_PLAN.md §6's
  guess ("likely off initially") is **wrong** — nothing was ever disabled.
  **Consequence for planning: the build scope is the full admin surface, not a reduced one.**
  No module can be pruned as "not operated", and P1.3's gate-map holes matter for correctness
  rather than for hiding anything today.

- **`BLOCKED: 12 — API key rotation` → RESOLVED: `api_keys` is empty (0 rows).** No key exists,
  so no stale Liwonde/Rosalyn key survived the migration. Nothing to rotate. Note the live table
  has no `key_retrievable` column (see below) and its columns are
  `api_key`/`api_key_plain`/`client_*` — `admin/api-keys.php` should be checked against that
  shape before anyone creates the first key.

- **Migration Phase 5 (branding) → DONE for identity.** `site_settings` holds Liwonde's own
  values: `site_name` = "Liwonde Sun Hotel", `address_line1` = "Liwonde National Park Road",
  `hotel_logo` = `images/logo/logo.png`, `invoice_prefix` = `INV`.

- **Schema parity → no new breaks.** A tokenizer-based sweep of every SQL string literal in the
  codebase against the live schema returns exactly the **three** absent tables Round 1 already
  identified, and no others: `room_inspections` (`includes/room-management.php`),
  `stock_payments` (`admin/includes/reports-extra-tabs.php`), `room_features`
  (`api/spatial-loading.php`). The admin-side table audit of 2026-08-15 is confirmed accurate.

### NEW findings from live data

- [~] **P0.1 — Guest email goes out from the developer's address, not Liwonde's.** Plumbing fixed 2026-09-02 (see Completed); the address change itself is **BLOCKED on SMTP credentials** — see below.
      `email_settings` holds `email_from_email` = `info@promanaged-it.com`,
      `smtp_username` = `info@promanaged-it.com`, `smtp_host` = `<smtp-host>`.
      Only `email_from_name` was updated ("Liwonde Sun Hotel"). So every booking confirmation,
      pre-arrival and review-request email a Liwonde guest receives is **sent from ProManaged
      IT's mailbox on ProManaged's mail server**. Migration Phase 5 SMTP is **not** done.
      This is an owner/admin-panel change, not a code change — `config/email.php` reads these via
      `getEmailSetting()`. Fix in the admin email settings once Liwonde's mailbox exists.
      **Deliverability note:** sending as `@promanaged-it.com` from a Liwonde-branded site also
      risks SPF/DKIM misalignment and spam filing.

- **The `ensure*()` DDL had already run against production — and is now switched off.**
  Confirmed present on live before the fix: `rooms.child_price_multiplier` ·
  `bookings.adult_guests` · `bookings.child_supplement_total` · `bookings.room_combination_id` ·
  `individual_rooms.housekeeping_status` · `individual_rooms.max_guests_override` ·
  `rooms.single_occupancy_enabled`.
  **Correction to an earlier note in this section:** a first pass claimed
  `api_keys.key_retrievable` was absent and would fire an `ALTER` on the next page load. That
  was a bad probe on this side — the column the code actually adds is `api_key_plain`, and it
  **is** present. A full sweep of every DDL target declared in `config/database.php` found
  **40/40 ADD COLUMN and 14/14 CREATE TABLE already applied — nothing was pending.** The live
  schema had already absorbed the whole self-migration; what remained was the per-request cost
  and the standing ability to do it again. Fixed 2026-09-02 — see Completed.

- **Two orphan objects: `v_active_tentative_bookings` and `v_tentative_booking_stats` exist as
  BASE TABLES, not views.** Both hold 0 rows and **no PHP file references either name**. Harmless
  dead objects from the rebuild (the two real views are `v_media_by_page` and `v_room_media`).
  Removing them is a schema change — owner decision, and not worth one on its own. Logged only so
  the next table-count audit does not treat them as a discrepancy.

- **Two guest pages have no content behind them.** `events` = 0 rows and `guest_services` = 0 rows,
  while `events.php` and `guest-services.php` are live pages. `events.php`'s empty-state fallback
  points at `images/hero/slide1.jpeg`, **one of the broken paths in P1.1** — so the Events page
  currently renders empty *and* with a broken image. Folding this into P1.1 is not enough: the
  pages need either content or a proper empty state. Content entry is an owner task; the empty
  state is a build task. Well-populated by contrast: `food_menu` 71 · `drink_menu` 116 ·
  `gallery` 26 · `page_heroes` 9 · `site_pages` 11 · `facilities` 6 · `reviews` 3.

- **`finance_sequences` is empty.** No invoice/receipt/credit-note numbering has been issued yet.
  Expected for a property with 0 payments, and it means the sequence-prefix decision can still be
  made cleanly before the first real document — but it also means numbering is **untested against
  real data**, and the finance smoke test is the only thing that has ever exercised it.

- **`sql_mode` confirmed `NO_ENGINE_SUBSTITUTION`** — still no `STRICT_TRANS_TABLES`. The Round 1
  `BLOCKED` on silent truncation stands, now verified against the live server rather than inferred.

### QA gate — revised

The loop can now run **read-only** verification against production (schema checks, reference
audits, content counts) using a hand-built PDO connection. It must **not** run the smoke tests
there: both write bookings, and `config/database.php` fires DDL on include. Until a staging copy
exists, the gate is `php -l` + `node --check` + read-only schema assertions.

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
