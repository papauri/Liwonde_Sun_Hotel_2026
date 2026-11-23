# Plan: Dashboard first-login information (2026-11-24)

GOAL: When someone signs in, the dashboard shows what changed since their last visit, whether yesterday's End of Day
ran, their last sign-in, an unread handover note, guest flags on arrivals, free rooms tonight, the 7-day takings and
(first sign-ins) a welcome card. Everything goes into places that already exist and hides when it has nothing to show.
Owner-approved FEATURE 2026-11-24 (mock-up https://claude.ai/artifact/VGrVZnzhATWGmQeJDRUXWp).

RISK: CRITICAL overall (access, money, people's data, data integrity, time). Build and test on Liwonde only.
Rosalyn's (`../Rosalyns-hotel-2026`) is LIVE: no tests, scripts or DB work there. A code port only after Liwonde
passes the ship gate. Migration 091 runs on Rosalyn's DB only after the owner confirms it (standing memory rule).
Every dispatch brief must repeat this paragraph.

Phases (owner order): A = items 2, 3, 9. B = items 1, 5, 6, 7 + "?" helpers. C = items 4, 8.
`/clear` between phases (one feature per session; reuse the same builder within a phase).

## DONE WHEN
- [ ] Phase A: hero shows the day chip and the last sign-in line; maintenance notice shows in the dashboard slot
      (only while a window is announced, and only once on the page)
- [ ] Phase B: "Since you were last here" line replaces the lede; each count links to a list with exactly that many
      rows (tested); guest chips on arrival rows; donut centre = free tonight; Money headline = 7-day total;
      every figure card has a visible `?` dot
- [x] Phase C (shipped 2026-11-24; 091 on Rosalyn's waits for the owner): migration 091 applied on Liwonde; handover notes write / show / mark read / expire, logged in System Logs;
      welcome card shows until "Got it"
- [ ] `php -l` clean on every changed file; the tests below pass; qa-auditor passes each phase's diff; headless browser
      check of the dashboard as admin and as a receptionist
- [ ] STATUS.md + `.claude/GUIDES_AI_SYNC.md` updated per ship; port to Rosalyn's (code only)

## Data sources (every figure; verified in code 2026-11-24)
| Figure | Source (table.column) | Rule / where verified |
|---|---|---|
| Previous sign-in time, browser, OS | `admin_activity_log` rows `action='login_success'`: `created_at`, `user_agent`, `user_id` | written only in `admin/login.php:262-263`; label from `rh_login_browser_label()` in `admin/includes/login-bundles.php:10-38` |
| This session's sign-in (the anchor) | new session key `admin_login_log_id` = that row's id | set in `admin/login.php` right after line 263 (task A2) |
| New bookings since | `bookings.created_at`, `bookings.deleted_at` | same column as the "booked" date type, `admin/includes/bookings-list-filters.php:98-130` |
| Cancellations since | `booking_timeline_logs.new_value='cancelled'`, `.action_type IN ('status_change','financial','cancellation')`, `.created_at`; `bookings.status='cancelled'`, `bookings.deleted_at IS NULL` | the EOD rule `rh_cancellations_between()` in `config/database.php:4685-4706`, with a datetime instead of a date |
| Room moves since | `booking_audit_log.action`, `.old_values`, `.new_values`, `.performed_at` | `room_assigned` with an old room (`admin/includes/booking-row-actions.php:320-327`), `modified` with `individual_room_id` in old values (`admin/edit-booking.php:594-615`), `upgraded`/`downgraded` (`includes/booking-room-change.php:544-548`) |
| Payments received since (count + amount) | `payments.created_at`, `.total_amount`, `.payment_status`, `.payment_type`, `.deleted_at` | `status='received'` rule of `rh_fin_payments_where()` in `admin/includes/finance-filters.php:191-224`; `p.created_at` used in `admin/payments.php:268` |
| Day closed | last ended trading day `rh_pos_last_ended_trading_date()` (`admin/includes/pos-shift-totals.php:860`), `rh_eod_day_is_open()` (`admin/includes/eod-revenue.php:529-539`) | EOD contract `.claude/EOD_SPEC.md` §1.2 |
| End of Day sent HH:MM | (a) `automated_email_log` `job='daily_reports'`, `account_type='trading_day'`, `account_id=YYYYMMDD`, `status='sent'`, `updated_at` (key: `includes/daily-reports-send.php:155-163`; table: `admin/migrations/040_create_automated_email_log.php`); (b) `system_event_log` `source='eod-report'`, `message='EOD email send'`, `context_json` date + success, `created_at` (`admin/api/end-of-day-send.php:581-588`, `includes/system-logger.php:72-104`) | earliest successful send of that day |
| Maintenance window | `site_settings.licence_maintenance` via `rh_maint_read()` / `rh_maint_decide()` / `rh_maint_window_words()` | `includes/licence-maintenance.php:64-125` |
| Returning guest, Nth stay | `bookings.guest_email`, `.status='checked-out'`, `.primary_booking_id`, `.deleted_at` | the one guest-history rule, `includes/guest-history.php:8-26, 93-107` |
| Special request | `bookings.special_requests` | already in `SELECT b.*` at `admin/dashboard.php:2189` and `:254` |
| Deposit unpaid | `bookings.deposit_required`, `.deposit_paid`, `.deposit_amount`, `.amount_paid` | rule copied from `includes/ai/ai-decisions.php:989` |
| VIP | NONE: no VIP column or flag exists anywhere in the schema | see OPEN QUESTIONS |
| Free tonight | `$outlookTonightRow['free']` from `rh_rooms_night_outlook()` | `admin/dashboard.php:2251-2253`, shown today at `:2791` |
| 7-day takings | `$trendTotal = array_sum($revenueTrend)` | `admin/dashboard.php:2305`, shown today at `:2737` |
| Handover notes, read state | NEW tables `shift_handover_notes`, `shift_handover_reads` (migration 091) | task C1 |
| Welcome dismissed | `admin_user_preferences` row `preference_key='dashboard_welcome_dismissed'` (no schema change to `admin_users`) | table DDL in `admin/api/nav-favorites.php:22-43` |
| Role name / what it can do | `rh_role_display_label()` + `getAllRoles()[role]['description']` | `admin/includes/permissions.php:1560-1567`, `:23-300` |

## Decision: where the previous sign-in comes from (item 3)
Option 1, no `login.php` change: read the user's two newest `login_success` rows and take the second. Fails when the
same person signs in on another device during this session (the "previous" row shifts and the since-window moves).
Option 2 (CHOSEN): one added line in `admin/login.php` after the `login_success` insert (line 263):
`$_SESSION['admin_login_log_id'] = (int)$pdo->lastInsertId();`. The dashboard then reads the newest `login_success` row
for this user with `id <` that anchor. It changes no auth logic, no redirect and no column, and `last_login` (line 259)
stays as it is. Sessions without the key (signed in before the deploy, or the dev-router `/__devlogin`) fall back to
Option 1. `admin_login_log_id` is the only sign-in path (`$_SESSION['admin_user_id'] =` is set only at `admin/login.php:244`).

## Migrations
| File | What | Run |
|---|---|---|
| NEW admin/migrations/091_shift_handover_notes.php | `shift_handover_notes` (id, body VARCHAR(1000), created_by INT UNSIGNED, created_by_name VARCHAR(255), created_at DATETIME, expires_at DATETIME, KEY(expires_at), KEY(created_at)); `shift_handover_reads` (note_id, user_id, read_at DATETIME, PRIMARY KEY(note_id,user_id), KEY(user_id)); `admin_user_preferences` CREATE TABLE IF NOT EXISTS with the exact DDL of `admin/api/nav-favorites.php:32-42`. Additive only; `check` = all three tables exist. Shape as `admin/migrations/090_stock_unit_changes.php` (return name / check / up). | `php admin/migrations/migrate.php` (dry run) then `--run`, Liwonde only |
ASSUMPTION: 091 is the next free number in both repos. The db-builder lists Rosalyn's `admin/migrations` folder
(read-only `ls`, nothing else) before naming the file; if 091 is taken there, use the next number free in both.

## TASKS
| # | What | Files (exact) | Risk | Owner | Depends | Proof (command -> expected) |
|---|---|---|---|---|---|---|
| A1 | Spec check of A2 (sign-in anchor) and A4 (which day counts as "yesterday", which sends count) | this plan | CRITICAL (access, time) | spec-check | - | written OK or a list of blockers |
| A2 | Sign-in anchor + previous-sign-in reader + browser label | `admin/login.php:258-264`; `admin/includes/login-bundles.php:25-35`; NEW admin/includes/dashboard-firstlogin.php (pure); NEW admin/includes/dashboard-since-data.php (SQL readers) | CRITICAL (access, security, people's data) | builder-critical | A1 | `php scripts/tests/login_email.php` -> 0 FAIL; `php scripts/tests/log_pager_test.php` -> 0 FAIL; php scripts/tests/dashboard_firstlogin.php (NEW test) -> 0 FAIL |
| A3 | Last sign-in line in the hero | `admin/dashboard.php:2323-2327`; `admin/css/admin-cockpit.css:41-63` | CRITICAL (security, people's data) | builder-critical | A2 | php scripts/tests/dashboard_firstlogin.php --db (NEW test) -> 0 FAIL |
| A4 | Day status chip next to the date | `admin/dashboard.php:2324`; dashboard-since-data.php (A2); `admin/css/admin-cockpit.css:41-60` | CRITICAL (time, decision logic) | builder-critical | A1, A2 | php scripts/tests/dashboard_firstlogin.php --db (NEW test) -> 0 FAIL |
| A5 | Planned maintenance bar in the system-health slot, printed once | `admin/dashboard.php:2367-2381`; `includes/licence-guard.php:366-381`; `admin/includes/admin-header.php:510` (read only, for the hook point) | CRITICAL (security, live system) | builder-critical | owner answer Q2 | php scripts/tests/dashboard_firstlogin.php (NEW test) -> 0 FAIL |
| B0 | Spec check of B1/B2 (since-window SQL, payments amount) | this plan | CRITICAL (money) | spec-check | Phase A shipped | written OK or blockers |
| B1 | `?since=` filters on Bookings and Payments, one clause per kind, notice with Clear | `admin/includes/bookings-list-filters.php:96-135` (new `rh_bk_since_filter`, `rh_bk_since_clause`); `admin/bookings.php:3025-3045, 3294-3318, 3140-3160` (export scope), `:3946-3956` (notice); `admin/includes/finance-filters.php:191-260` (new `since` key, `p.created_at >= ?`); `admin/payments.php:217-244` + its filter notice | CRITICAL (money, data integrity, time) | builder-critical | B0 | `php scripts/tests/dashboard_attention.php --db` -> 0 FAIL; `php scripts/tests/bookings_filters.php` -> 0 FAIL; `php scripts/tests/finance_filters.php` -> 0 FAIL |
| B2 | "Since you were last here" line replaces the lede | `admin/dashboard.php:2288-2301` (build), `:2326` (render); dashboard-since-data.php; dashboard-firstlogin.php | CRITICAL (money, access) | builder-critical | B1 | php scripts/tests/dashboard_firstlogin.php --db (NEW test) -> 0 FAIL; `php scripts/tests/dashboard_figure_perms.php` -> 0 FAIL |
| B3 | Guest flag chips on arrival rows | `includes/guest-history.php:78-107` (new batch function after it); `admin/includes/front-desk-counts.php:232-240` (new pure `rh_fd_deposit_unpaid`); `admin/dashboard.php:2185-2199, 2534-2544` (Today's movements), `:253-265, 2867-2870` (Week ahead); `admin/css/admin-cockpit.css:336-339` | CRITICAL (people's data, money) | builder-critical | B0 | `php scripts/tests/guest_history.php` -> 0 FAIL; `php scripts/tests/dashboard_counts.php` -> 0 FAIL |
| B4 | Donut centre "N free tonight"; Money headline = 7-day total | `admin/dashboard.php:2806-2810`, `:2733-2747`, JS `:3413-3427` | CRITICAL (availability, money display) | builder-critical | - | `php scripts/tests/dashboard_figure_perms.php` -> 0 FAIL |
| B5 | Visible `?` dot on every figure card (title= text moves into `data-tip`) | `admin/dashboard.php:2384-2480` (KPI labels 2389-2471), `:2669`, `:2737`, `:2791`, `:2808`, `:2840`; component `admin/css/help-tooltips.css:238-290`, loaded by `admin/includes/help-tooltips.php` | NORMAL (wording/markup only) | ui-builder | B2, B3, B4 | `php scripts/tests/dashboard_figure_perms.php` -> 0 FAIL |
| C0 | Spec check of C1/C2 (tables, expiry, who sees what, view-only) | this plan | CRITICAL (data integrity, access) | spec-check | Phase B shipped | written OK or blockers |
| C1 | Migration 091 + run on Liwonde | NEW admin/migrations/091_shift_handover_notes.php; runner `admin/migrations/migrate.php` | CRITICAL (data integrity) | db-builder | C0 | `php admin/migrations/migrate.php --status` -> 091 applied |
| C2 | Handover endpoints + readers + activity labels + view-only allowlist | NEW admin/api/handover-notes.php (write); NEW admin/api/dashboard-seen.php (mark read, welcome Got it); pattern `admin/api/nav-favorites.php`, `admin/api/api-init.php`; `admin/includes/staff-activity-lib.php:450-466` (labels); `includes/view-only.php:36-42` + `includes/licence-guard.php:107-112` (add `dashboard-seen.php` to admin_api 'any') | CRITICAL (access, people's data, state) | builder-critical | C1 | php scripts/tests/dashboard_firstlogin.php --db (NEW test) -> 0 FAIL; `php scripts/tests/system_logs_activity.php` -> 0 FAIL |
| C3 | Handover item at the top of Needs attention + "Handover note" button in the hero actions | `admin/dashboard.php:2328-2364` (button), `:2664-2729` (panel + its condition), JS near `:2967` (`_dashCsrf`); `admin/css/admin-cockpit.css` | CRITICAL (people's data, access) | builder-critical | C2 | `php scripts/tests/dashboard_attention.php` -> 0 FAIL; browser check |
| C4 | First-login welcome card | `admin/dashboard.php:2321-2365` (card under the hero intro), `:2347-2363` (guide links built once as an array, used by the menu and the card); dashboard-firstlogin.php; NEW admin/api/dashboard-seen.php (C2) | CRITICAL (access) | builder-critical | C2 | php scripts/tests/dashboard_firstlogin.php --db (NEW test) -> 0 FAIL; browser check |
| S | Ship gate per phase: `php -l` changed files; the tests named in the phase; qa-auditor on the diff; browser check (`php -S 0.0.0.0:8000 -t . scripts/tests/dev-router.php` + `node scripts/tests/browser_check.mjs`, stop the server after); STATUS.md; GUIDES_AI_SYNC line; port code to Rosalyn's | `.claude/DEPARTMENTS.md` ship gate; `STATUS.md`; `.claude/GUIDES_AI_SYNC.md` | CRITICAL (live system on port) | orchestrator | each phase | all named tests 0 FAIL; qa-auditor PASS |

## Task details

### A2 sign-in anchor (builder-critical)
- `admin/login.php`: after `$log_stmt->execute(...)` at line 263 add the session key (wrapped so a failure never blocks
  the sign-in). Nothing else in the file changes.
- `rh_login_browser_label()`: check Android and iPhone/iPad BEFORE Linux (an Android user agent contains "Linux", so
  today every phone shows "Linux"). Also fixes System Logs > Recent Logins (the only other caller).
  ASSUMPTION: this one-line order fix is in scope (the line must say the right OS).
- dashboard-since-data.php: `rh_dash_prev_signin(PDO $pdo, int $uid, int $anchorId): ?array` returns
  `['at' => created_at, 'ua' => user_agent]` of the newest `login_success` for this user with `id < $anchorId`
  (anchor 0 = fallback: second-newest). Prepared statements; any fault returns null and the line is hidden.
- dashboard-firstlogin.php (pure): `rh_dash_last_signin_text(?array $prev, string $today): string` ->
  "Last signed in Mon 23 Nov, 18:40, Chrome on Windows." / "First sign-in on this account." ("Today"/"Yesterday" for
  those days).
- Tests: extend `scripts/tests/login_email.php` (harness `session_keys: ['admin_login_log_id']` + verify SQL): after a
  good sign-in the key equals `MAX(id)` of that user's `login_success`; after a wrong password the key is absent.
  Extend `scripts/tests/log_pager_test.php`: Android Chrome UA -> "Chrome / Android", iPhone Safari -> "Safari / iOS",
  Windows Edge -> "Edge / Windows" (unchanged). NEW scripts/tests/dashboard_firstlogin.php part 1 (no DB): text for
  null (first sign-in), today, yesterday, older date, unknown UA ("Unknown browser" wording, never blank).

### A3 last sign-in line (builder-critical)
- A small muted line under the lede: text from A2 + " Not you? " + link `change-password.php` (always openable: the
  view-only allowlist keeps it, `includes/view-only.php:36`). Escaped with `htmlspecialchars`.
- Test (dashboard_firstlogin.php `--db`, Liwonde only, rolled back through `scripts/tests/page_harness.php` `grep`):
  seed two `login_success` rows for a zz user; dashboard shows the older one's time and "Chrome on Windows"; with one
  row it shows "First sign-in on this account."; the link `change-password.php` is present.

### A4 day status chip (builder-critical)
- `rh_dash_eod_status(PDO $pdo, DateTimeImmutable $now): array` -> `['day' => D, 'sent_at' => ?string, 'test' => bool]`,
  D = `rh_pos_last_ended_trading_date($now)`. sent_at = earliest of source (a) and (b) in the data table.
- Chip text: sent -> "Yesterday closed · End of Day sent 07:12"; not sent -> "Yesterday's End of Day not run" linking to
  `end-of-day-report.php?date=D`. When D is not calendar yesterday, the day name replaces "Yesterday" ("Sat 21 Nov closed").
- Shown only when `$can('end-of-day-report.php')`.
- ASSUMPTION: a TEST-mode send (stage `test:day:D`, or `test_mode` true in the event context) counts and the chip adds
  "(test)", so Liwonde (test mode on) shows a real state.
- ASSUMPTION: "closed" = the trading day has ended (opening -> next opening); there is no separate "close day" action.
- Tests (dashboard_firstlogin.php): pure part: chip text for sent / not sent / test / D two days back. `--db` part:
  seed an `automated_email_log` row `daily_reports` / `trading_day` / D / `sent` and a `system_event_log`
  `eod-report` row (rolled back): sent_at = the earlier; a `failed` row alone -> not run; a receptionist without
  End of Day access sees no chip (harness grep).

### A5 maintenance bar (builder-critical; waits for Q2)
- The header already prints the maintenance notice on EVERY admin page from `announce_from`
  (`includes/licence-guard.php:372-381`, called from `admin/includes/admin-header.php:510`). A second bar on the
  dashboard would show it twice.
- Default (if the owner says "dashboard slot"): dashboard sets `$GLOBALS['rh_maint_inline'] = true` before the header
  include (`admin/dashboard.php:2089`); `rh_licence_render_banner()` skips only the maintenance part when that flag is
  set; the dashboard renders it in a `dashboard-health-alert`-style block placed right after `:2381`, visible to every
  signed-in user (not only `system_logs`), text from `rh_maint_banner_text()`, only while `rh_maint_decide()` is
  `announce`. The licence-overdue banner is untouched.
- Tests (dashboard_firstlogin.php part 1, pure): `rh_maint_banner_text` for announce / offline / none is unchanged;
  source check that the dashboard sets the flag before the header and that `licence-guard.php` still prints the
  licence banner when the flag is set.

### B1 since filters (builder-critical)
- `rh_bk_since_filter($raw)`: accepts only `YYYY-MM-DD HH:MM:SS` (regex + `DateTime::createFromFormat`), else ''.
  `rh_bk_since_kind($raw)`: whitelist `booked | cancelled | moved`.
- `rh_bk_since_clause(string $since, string $kind): ?array` returns `['clause' => sql, 'params' => [...]]`:
  booked = `b.created_at >= ?`; cancelled = `b.status = 'cancelled' AND b.id IN (SELECT t.booking_id FROM
  booking_timeline_logs t WHERE t.new_value = 'cancelled' AND t.action_type IN ('status_change','financial','cancellation')
  AND t.created_at >= ?)`; moved = `b.id IN (SELECT a.booking_id FROM booking_audit_log a WHERE a.performed_at >= ? AND
  (a.action IN ('upgraded','downgraded') OR (a.action IN ('room_assigned','modified') AND old individual_room_id is set
  and not 0 AND it differs from the new one)))` (JSON_EXTRACT on old_values / new_values).
- Wired into the shared scope of `admin/bookings.php` next to the `?balance=` clause (3309-3313) AND the export scope
  (3140-3160) so badges, list, count and CSV agree; notice line "Booked / Cancelled / Moved to another room since
  Mon 23 Nov, 18:40" with Clear.
- `rh_fin_payments_where`: optional `since` -> `p.created_at >= ?` (validated the same way). `admin/payments.php` passes
  `$_GET['since']`; its notice names it.
- ASSUMPTION: "payments received" = rows the Payments page calls received (`status=received`: completed / paid /
  refunded / partially_refunded, refund rows out, deleted rows out), all enabled booking types as the page's default
  scope, counted on `payments.created_at` (when it was recorded; `payment_date` is a date only and may be back-dated).
  Amount = SUM of those rows' `total_amount` (gross money in, refunds not netted), the same as the Payments page card
  for that filter.
- ASSUMPTION: a room-type upgrade/downgrade counts as a room move; a first room assignment does not.
- Tests: extend `scripts/tests/bookings_filters.php`: since filter rejects junk (`2026-13-01`, `x' OR 1`, empty) and
  each kind returns the clause with exactly one bound param. Extend `scripts/tests/finance_filters.php`: `since` adds
  `p.created_at >= ?`, junk adds nothing. Extend `scripts/tests/dashboard_attention.php` part 2 (`--db`, Liwonde
  guard at lines 230-250): for since = now - 7 days, each dashboard count (B2 readers) == the row count of the
  Bookings / Payments list built with the page's own helpers, for all three booking kinds and for payments
  (count and SUM, compared with `BALANCE_TOLERANCE`).

### B2 since line (builder-critical)
- `rh_dash_since_counts(PDO, string $since, array $show)` (dashboard-since-data.php) uses the B1 clause builders only
  (no second copy of the SQL). `$show` gates: bookings / cancellations / moves need `$mod_bookings`; payments need
  `$show_takings` (finance module + `dashboard_takings`, `admin/dashboard.php:163`). A link only when
  `$can('bookings.php')` / `$can('payments.php')`, else plain text.
- Text (pure, dashboard-firstlogin.php): "Since you were last here (Mon 18:40): 3 new bookings, 1 cancellation,
  MK 245,000 received in 4 payments, 2 room moves — 5 things need you." Zero items are left out; all zero ->
  "Nothing new since you were last here (Mon 18:40)". The existing tail (`:2300-2301`, `$attnTotal`) is kept as is.
- ASSUMPTION: the window is previous sign-in -> now (it keeps counting during this session; the links use the same
  `since` so they still match). No previous sign-in -> today's existing lede (`:2289-2299`) stays.
- Tests: dashboard_firstlogin.php pure: wording, plurals, zero items dropped, all-zero text, the money item absent
  when the payments gate is off, link absent when `$can` is false. Extend `scripts/tests/dashboard_figure_perms.php`
  (source check): the payments-since item sits behind `$show_takings`.

### B3 guest flag chips (builder-critical)
- `rh_guest_history_stays_for(PDO, array $bookings): array` in `includes/guest-history.php`: ONE query for all
  listed arrivals; per booking id the completed stays of that email excluding the booking's own group (same rule as
  `other_completed_stays`, lines 93-107). Chip "Returning guest (3rd stay)" when >= 1 (Nth = stays + 1).
- `rh_fd_deposit_unpaid(array $b): bool` (pure, front-desk-counts.php): `deposit_required = 1` AND `deposit_paid <> 1`
  AND `deposit_amount > BALANCE_TOLERANCE` AND `amount_paid + BALANCE_TOLERANCE < deposit_amount`.
  ASSUMPTION: uses the stored `bookings.amount_paid` (kept by `recalculateBookingFinancials`), not a folio read per row.
- "Request: <text>" chip: `special_requests` trimmed, cut to 40 characters with "…", full text in the chip's `?`
  `data-tip`; escaped.
- Chips show only when true, on both lists (`:2534-2544`, `:2867-2870`), style `ck-chip--muted` (one accent rule).
- Tests: extend `scripts/tests/guest_history.php`: batch result == `rh_guest_history($email, $id)['other_completed_stays']`
  for 5 seeded bookings incl. a group and a deleted booking; blank email -> 0. Extend `scripts/tests/dashboard_counts.php`:
  deposit rule cases (not required; required and paid flag set; paid below amount by 0.005 -> not unpaid; paid 0 ->
  unpaid; amount 0 -> not unpaid).

### B4 donut + money headline (builder-critical, display only)
- Donut centre: `(int)$outlookTonightRow['free']` + "free tonight" when `$roomOutlook['ok']`; otherwise the current
  occupancy % stays (ASSUMPTION: fallback when the outlook cannot be read). The ring keeps showing the physical state.
- Money: headline label "Last 7 days", value `$money($trendTotal)`; no bar `is-active` at load; leaving the bars puts the
  7-day total back (JS `:3413-3427`). Panel sub line (`:2737`) becomes "Money in less refunds paid out"
  (ASSUMPTION: the old sub repeated the headline).
- Tests: extend `scripts/tests/dashboard_figure_perms.php` source checks: donut hole prints `$outlookTonightRow['free']`;
  money headline prints `$trendTotal` and still sits inside `if ($show_takings)`.

### B5 "?" helpers (ui-builder)
- `<i class="help" data-tip="...">?</i>` (`admin/css/help-tooltips.css:238`) after every `ck-kpi__label`, the Money
  headline, the donut, the Rooms and Week-ahead sub figures, the Needs-attention badge, the since line and the day
  chip. Text = what it counts, from where, which period (reuse today's `title=` text; drop the `title=` so there is one
  tip). Inside the `<a class="ck-kpi">` cards the dot must not navigate (check the help-tooltips click handling).
- Test: extend `scripts/tests/dashboard_figure_perms.php`: every `ck-kpi__label` in `admin/dashboard.php` is followed by
  `class="help"` before its closing `</a>`; no `ck-kpi__sub` keeps a `title=`.

### C2 handover endpoints (builder-critical)
- NEW admin/api/handover-notes.php POST `action=write`, `body` (1-500 characters after trim), CSRF (`validateCsrfToken`
  as `admin/api/nav-favorites.php:129`), any signed-in user; inserts the note with `expires_at = NOW() + 48 hours` and a
  read row for the author; `logActivity($uid, 'handover_note_written', 'Note #ID')`.
- NEW admin/api/dashboard-seen.php POST `action=handover_read` (`note_id`) -> INSERT IGNORE read row +
  `logActivity(..., 'handover_note_read', 'Note #ID')`; `action=welcome_dismissed` -> upsert `admin_user_preferences`.
  Only the caller's own rows. Added to the admin_api 'any' allowlists (view-only and licence read-only) like
  `nav-favorites.php`; the write endpoint stays refused while view only.
- `rh_dash_handover_unread(PDO, int $uid): array` -> newest unread, unexpired note + count of the others.
- ASSUMPTION: notes expire 48 hours after writing (a weekend shift still sees Friday night's note; someone back after
  weeks sees nothing old).
- ASSUMPTION: the System Logs entry names the note id only, never the note text (it may hold guest details).
- ASSUMPTION: the welcome "Got it" is not logged (personal setting).
- Labels in staff-activity-lib: "Wrote a handover note", "Read a handover note".
- Tests (dashboard_firstlogin.php `--db`, harness rolled back): write as user A -> A sees nothing unread, B sees it;
  B marks read -> gone for B, still unread for C; a note with expires_at in the past is not shown; body 0 or 501
  characters refused; missing CSRF -> refused; B cannot mark a note for another user; two activity rows written.
  Extend `scripts/tests/system_logs_activity.php`: the two new actions get their plain labels.

### C3 handover UI (builder-critical)
- Needs attention panel condition (`:2664`) becomes `$attn || $handover`; the note is the first `<li>` (tone amber,
  icon `fa-clipboard`), shows text (`nl2br(htmlspecialchars())`), "From Grace, 22:10", "and 1 more" when more, and a
  "Mark read" button. It is NOT part of `$attnTotal` (badge and "N things need you" unchanged).
- "Handover note" ghost button in the hero actions (`:2328-2364`) opens a small form (textarea 500, Save, Cancel);
  refusals shown with the existing `Alert.show`.
- ASSUMPTION: the write button lives in the hero (every user has the hero; some roles have no Needs-attention panel).
- ASSUMPTION: only users who open the dashboard see notes (POS / KDS staff land on their station, `admin/login.php:84-104`).
- Tests: extend `scripts/tests/dashboard_attention.php` part 1 source checks: still 18 items; the handover item is
  outside `rh_dash_attention_items`; the panel condition includes the handover. Browser check: write as admin, sign in
  as a receptionist, item shows, Mark read, item gone.

### C4 welcome card (builder-critical)
- Shown under the hero intro while no `dashboard_welcome_dismissed` row exists for the user.
- Content: role (`rh_role_display_label`, needs `is_super_admin` from `admin_users`), role description, up to 3 main
  pages from `rh_dash_welcome_pages(string $role, callable $can)` (pure, ordered list per role, only pages `$can()`
  allows; files verified: `admin/bookings.php`, `admin/calendar.php`, `admin/room-dashboard.php`,
  `admin/housekeeping.php`, `admin/payments.php`, `admin/accounting-dashboard.php`, `admin/end-of-day-report.php`,
  `admin/reports.php`, `admin/stock-dashboard.php`, `admin/conference-management.php`, `admin/gym-checkin.php`,
  `admin/user-management.php`), and the guide links: the menu at `:2347-2363` is built from one array so the card and
  the menu use the same conditions. "Got it" posts to `dashboard-seen.php` and hides the card.
- Tests: dashboard_firstlogin.php pure: receptionist with bookings + calendar access -> Bookings, Calendar, Rooms;
  user without calendar access never gets Calendar; a role with no openable candidate -> empty list (card shows role
  only); never more than 3. `--db`: after `welcome_dismissed` the harness grep no longer finds the card.

## ASSUMPTIONS (to copy into `.claude/BUILD_PLAN.md` per the hotel rule)
- ASSUMPTION: sign-in anchor via one added session line in `admin/login.php` (Option 2 above).
- ASSUMPTION: Android/iOS checked before Linux in `rh_login_browser_label()`.
- ASSUMPTION: since-window = previous sign-in -> now; no previous sign-in keeps today's lede.
- ASSUMPTION: payments received = Payments page "received" rows by `created_at`, gross amount, only with `dashboard_takings`.
- ASSUMPTION: room moves include type upgrades/downgrades, exclude first assignments.
- ASSUMPTION: TEST-mode End of Day sends count, chip marked "(test)"; "closed" = trading day ended.
- ASSUMPTION: deposit unpaid uses stored `bookings.amount_paid`.
- ASSUMPTION: handover notes expire 48 h after writing; author auto-read; log names the id only; not counted in the badge.
- ASSUMPTION: welcome dismissed stored in `admin_user_preferences`; not logged.
- ASSUMPTION: donut falls back to occupancy % when the night outlook cannot be read.
- ASSUMPTION: migration number 091 is free in both repos (checked read-only before naming).

## SPEC-CHECK FIXES (2026-11-24, binding: they override the task details above)
- A2: the new session line goes BETWEEN login.php:263 and :264. `rh_log_event` at :264 inserts on the same connection
  (includes/system-logger.php:101), so any lastInsertId() read must happen before it.
- B1 room moves: first assignments are logged with JSON null as old room (admin/includes/booking-row-actions.php:324,
  :359; config/database.php:3218) and values mix "12"/12. On both sides use
  `CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(..)),'null') AS UNSIGNED)`, require old > 0 AND old <> new. Test: null-old row
  is NOT a move.
- B2: `$money` returns HTML (admin/dashboard.php:2135); the lede is printed via htmlspecialchars (:2326). Escape each
  part of the since line separately; never pass `$money` output through htmlspecialchars.
- B3 deposit chip: stored amount_paid counts only the booking's own payments (config/database.php:10644-10659). Skip
  bookings billed to a company account (`rh_booking_billing`, admin/bookings.php:1613) and group child rooms; chip only
  on pending/confirmed rows. Tests for company-billed, group child, checked-in rows (no chip).
- C1 migration 091: before running, back up the Liwonde DB, check the DB named in `.env` is not in `.claude/LIVE_TARGETS`,
  write the rollback (drop only the 2 new tables, owner OK). utf8mb4 on both tables. Check admin_users.id type before
  the FK in admin_user_preferences. CREATE ... IF NOT EXISTS.
- C2: add handover-notes.php to `rh_sa_hook_skip_pages` (admin/includes/activity-hook.php:29-41) so one note = one log
  row; dashboard-seen.php checks CSRF; handover_read checks the note exists and is not expired; Save button disabled
  after the first click (no double save).

## OWNER ANSWERS (2026-11-24)
- Q1 VIP chip: DROPPED for now. Build returning guest, request, deposit unpaid only.
- Q2 Maintenance: KEEP the existing top bar. Task A5 is NOT built.

## OPEN QUESTIONS FOR THE OWNER (answered above)
1. VIP chip: there is no VIP field anywhere (bookings, guests or settings). Drop the VIP chip, or add a "VIP" tick on
   the booking (a new column + edit field = a separate feature)? Plan builds the other three chips; VIP waits.
2. Maintenance notice: it already shows as a yellow bar at the top of every admin page (including the dashboard) from
   the announce time. Keep that as is (item 9 then needs no build), or move it into the dashboard slot on the dashboard
   only (task A5, touches the licence banner code)?
