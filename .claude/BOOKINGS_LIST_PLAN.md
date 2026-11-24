# Plan: Bookings list overhaul (2026-11-24)
Owner FEATURE 2026-11-24 ("everything, do this to the best"). Test on Liwonde only; port to Rosalyn's after.
GOAL: Make the admin Bookings list one clear filter bar with chips and URL state, full server sort with remembered
choice, a short tab row plus a More menu, clearer rows (balance due, "?" helpers), a List | Calendar switch that reuses
the existing Room Timeline, saved filter sets per user, and a CSV export that follows the current filters and sort.
RISK: CRITICAL overall (time/availability: `admin/bookings.php` is on the map card's CRITICAL list; the Timeline can move
bookings). Ruled out: a new calendar; any change to booking save or payment logic.

## What exists today (verified 2026-11-24; line numbers are `admin/bookings.php` unless named)
Most of the scope is half built. Do not rebuild what is listed here.
- Filter inputs: ~3021-3060. `?search=` 3022, `?filter_status=` (alias `?status=`) 3026, date range + date field 3029-3030
  (`rh_bk_date_range`, `rh_bk_date_type`: arrival / departure / staying / booked), overdue arrival / departure / balance /
  since 3033-3046, More filters 3047-3063 (`?kind=`, `?room_type=` a rooms.id, `?pay=`). `$has_active_room_filters` 3093.
  `?filter=` tab shortcut via `rh_bk_filter_key()` 3097, `$current_page` 3105, page size 3107 (`rh_bk_page_size`).
- Rules live in `admin/includes/bookings-list-filters.php` (688 lines, pure, tested by `scripts/tests/bookings_filters.php`,
  whose last block cross-checks real SQL on Liwonde only, read-only): search `rh_bk_search_where` 31 (already covers guest
  name, reference, phone by digits, email, room number/name, room type, payment ref), date scope 99, sort map
  `rh_bk_sort_map` 286 (keys ref, guest, room, check_in, check_out, nights, guests, total, status, payment, created: every
  current column already sorts server-side), tab map 304, tab clauses + per-tab default order `rh_bk_tab_clauses` 366,
  page sizes 452-490 (10/25/50/100 + All up to 500 rows, else 100), More filters 531-688.
- Server sort: `admin/includes/server-sort.php` (shared by many lists; do not change its behaviour). Resolved at 3606-3609
  with default `b.created_at DESC` or the tab's own order (3601-3603). Header cells 4105-4117 via `rh_server_sort_th`; arrows
  are fa-sort icons at 0.55 / 0.95 opacity. Header click: `admin/js/admin-main.js` 691-740 fires `rh:server-sort` (ignores
  clicks on `.help`), handled at 5587-5596.
- Filter repetition (the main structural trap): the same filter list is restated in about 7 places: 3093 (active flag),
  CSV export scope 3121-3180, list scope 3296-3335, recount condition 3619, hidden keep-inputs 3871, notice 3958-3971,
  pager params 4557-4575.
- Toolbar (outside `#booking-results`): form 3866-3948 (search, status select, date field select, From / To, Filter /
  Clear, More filters `<details>` 3913-3947), actions 3949-3961 (Export CSV link, Tentative, New Booking). Live search swaps
  only `#booking-results` (`loadBookingResults` ~5440-5530), pushes the URL, refreshes tab counts (5483-5489) and the
  Export link (5510-5518). Back/forward: popstate 5552.
- Active-filter notice (inside `#booking-results`): 3966-3979, plain sentence + one Clear link (no chips).
- Tabs: 3981-4055, 13 `.tab-button[data-tab]` inside `.tabs-header` (All, Today's Check-ins, Today's Check-outs, In-House,
  Pending, Tentative, Expiring Soon (only when >0), Unpaid, Checked Out, Cancelled, No-Show, Group Bookings, Deleted (only
  when >0 or open)), each with `.tab-count`. Click handler `window.__pageTabHandler` 5560-5571 (requires the button to be
  inside `.tabs-header`), `navigateToTab` 5536-5549, client row filter `switchTab` / `filterBookingsTable` 5068+.
  Badge counts: one stats query 3355-3390 using the same `rh_bk_tab_clauses`.
- Table: 4101-4117 (`booking-table bookings-table`, `data-server-sort="1"`, `data-hotel-today`, bulk select column).
  Row cells 4175-4330: Total 4242 (works out `rh_booking_pay_state` + `rh_bk_left_to_pay`), Status 4270, Payment 4292
  (badge + "N left to pay" only when part paid), Created 4322 (+ `rh_created_by_html`). Nights already a column (4240).
  Phone row lines 4214, 4236, 4263. No "?" helper on any column header.
- Pager: 4553-4616, page links + "Showing a-b of n" + rows picker (offers 100 too). `data-bk-page-size` JS 5574-5585.
- CSV export: exists, 3120-3260, follows search, status, dates, overdue / balance / since, More filters and the open tab,
  all rows (no LIMIT); "Export selected" via `?ids=`. BUT its ORDER BY is fixed `b.created_at DESC` (3202) and it runs
  before the sort is resolved (3606). Access: page permission + the view-only trial refuses exports
  (`includes/view-only.php` `rh_view_only_is_export` 49). No column-level money permission on this page.
- Who created a booking: no column on `bookings`. `includes/booking-created-by.php`: `rh_created_by_pick` (order: audit
  'created' row with staff id -> timeline 'create' row by type admin/staff/guest/api -> audit note "Booking made online" ->
  audit name only -> nothing) and `rh_created_by_map` (one query per page). No source / channel column anywhere
  (checked inserts in `admin/create-booking.php` 871 and `booking.php` 557).
- Groups: no group name field; a group = lead booking (`primary_booking_id` NULL) + rooms pointing at it
  (`rh_bk_group_sql` 545).
- Per-user settings table: `admin_user_preferences` (user_id, preference_key, preference_value TEXT, unique per key)
  exists on BOTH hotels: created by migration `admin/migrations/091_shift_handover_notes.php` (run on Liwonde and, owner
  OK, on Rosalyn's 2026-11-24) and by `admin/api/nav-favorites.php`. Pattern for a personal endpoint:
  `admin/api/dashboard-seen.php` (POST + csrf, caller's own rows only).
- Room Timeline: `admin/calendar.php` (timeline default; filters `filter_room_type` / `filter_room_id` / `filter_search` /
  `start` / `days` -> `$tlInitial` 160-189), view `admin/includes/calendar-timeline-view.php` (toolbar filters type /
  floor / status 61-100, scripts 172-175), shell `admin/includes/calendar-shell.php` (tabs, no List link), data
  `admin/includes/calendar-data.php` `rh_cal_timeline_payload` 865 (room-type group ids = `ir.room_type_id` = rooms.id,
  the same ids as `?room_type=`), JSON `admin/api/calendar-timeline.php` (requires the `calendar` permission + module),
  JS `admin/js/room-timeline.js` init 89-135: `filters.status` is hard-coded '' (initial status ignored); its status
  filter is a ROOM state (free / occ / arr / dep / dirty / oos), not a booking status. Moves POST to bookings.php actions
  with a confirm (938-960). Design: `.claude/CALENDAR_UI_DECISION.md`.
- Tests today: `scripts/tests/bookings_filters.php`, `scripts/tests/bookings_bulk_test.php`,
  `scripts/tests/booking_created_by.php`, `scripts/tests/calendar_timeline_test.php`,
  `scripts/tests/calendar_timeline_actions_test.php`, `scripts/tests/grid_select_test.mjs`, `scripts/tests/view_only.php`,
  `scripts/tests/help_map.php`; HTTP pattern `scripts/tests/view_only_http.php`; browser `scripts/tests/browser_check.mjs`
  + `scripts/tests/dev-router.php`. Runner `scripts/run_tests.php`.

## Decisions taken in this plan (owner may overrule; none blocks the build)
1. Source / channel: there is no stored channel and booking save is ruled out, so the filter is DERIVED with the exact
   `rh_created_by_pick` order as SQL: Staff (front desk) / Online (website or booking link) / System / Not recorded.
   The staff filter (`?created_by=<admin id>`) uses the same audit / timeline rows. No migration unless T2 proves an
   index is needed (then it is migration 092, flagged for the owner and Rosalyn's confirm list).
2. Group drop-down: one entry per group, labelled by its lead booking ("BK-123 - guest name, check-in"), listing groups
   with a not-deleted room checking out within the last 30 days or later (keeps the list short); filter
   `b.id = ? OR b.primary_booking_id = ?` on the lead id. The `?group=` whitelist accepts ANY existing lead id (not only
   the shortened drop-down list), so older saved sets and links keep working; an id outside the list shows as a chip.
3. Saved filter sets: stored in `admin_user_preferences` under key `bookings_saved_filters_v1` (JSON list, max 20 per
   user, name max 60 chars, query rebuilt server-side from whitelisted keys only). NO MIGRATION, on either hotel.
   Not added to the view-only / licence read-only allowlists (no edit to `includes/view-only.php` or
   `includes/licence-guard.php`). bookings.php reads the user's own row directly while it renders (so view-only users
   still see and open their saved sets); the endpoint does save and delete only; for view-only users Save / Delete are
   hidden and the guard refuses the write.
4. Calendar (OWNER 2026-11-24: "reuse the existing Room Timeline INSIDE the bookings page"; overrides the planner's
   link-out idea): the List | Calendar switch on bookings.php swaps the list area for the EXISTING timeline shown in a
   same-origin `<iframe>` of `calendar.php?embed=1&<mapped filters>` (X-Frame-Options is SAMEORIGIN in .htaccess:76 and
   config/security.php:103, so this is allowed). The iframe keeps the timeline's own document, so its grid, dock panel
   and move-confirm scripts never load into bookings.php's 11,793-line SPA (no double binding on the flow that moves
   bookings) and calendar.php stays the one tested host. Embed mode = calendar.php without the admin sidebar / top
   header / calendar tabs (styles and scripts kept), timeline view only, links that leave the timeline (Open booking,
   booking-details, edit) use `target="_top"`; moves and their confirm unchanged. The switch state is in the URL
   (`?view=calendar`), the iframe is lazy (src set only when Calendar is first shown) and fills the viewport height
   below the bookings toolbar; changing a mapped filter while Calendar is shown reloads the iframe src. Phone: the
   timeline's own phone layout (3 days + Agenda) inside the frame. Calendar view shows only when
   `rhCanLinkTo(user, 'calendar.php')`; embed=1 goes through admin-init.php exactly like the full page (same login +
   permission check). Mapping: room type -> `filter_room_type`, room ->
   `filter_room_id`, search -> `filter_search`, Arriving today -> room state arr, Leaving today -> dep, In house -> occ,
   From date (arrival or staying) -> `start`. Booking-status, payment, source, staff and group filters do not apply to
   the room grid: the switch says "Calendar shows rooms; status, payment, source, staff and group filters apply to the
   list only" when any of them is set.
5. Default sort: upcoming views (All, Arriving today, Leaving today, In house, Pending, Unpaid, Tentative, Expiring,
   Groups) = arrivals from today on, soonest first, then past arrivals, latest first; the three tabs that already have
   their own order keep it (today check-ins, today check-outs, expiring). History views (Checked out, Cancelled, No-show,
   Deleted, or status filter one of those) = newest first (created_at DESC).
6. Remembered sort: localStorage key `rh-bk-sort:v1:u<userId>` holding `{upcoming:{sort,dir}, history:{sort,dir}}`
   (front-desk devices are shared, so the user id is in the key). The URL always wins; the stored sort is applied only
   when the URL has no `sort`. All access in try/catch.
7. Pager: the picker offers 10 / 25 / 50 / All (owner rule); `?size=100` from old links and the "All above 500 rows"
   fallback keep working.
9. Balance due rule (one rule, PHP cell + SQL twin + CSV, never a new money rule): blank for deleted rows; 0 for an
   expired row UNLESS it carries the group no-show charge tag (today's test at ~4124-4137: a `booking_charges` row whose
   description ends "[noshow-fee:<booking id>]") and amount_due > BALANCE_TOLERANCE, then amount_due; otherwise
   `rh_booking_pay_state()['left']` (0 when paid / refunded, else ROUND(GREATEST(0, amount_due), 2); so cancelled and
   no-show rows show what they still owe, as `rh_bk_left_to_pay` 494-513 does today, and fully unpaid active rows now show
   their due too). Today's "left to pay" line only covers part-paid rows; the column widens that on purpose.
8. Date presets use the hotel date (server `date('Y-m-d')`, as `data-hotel-today`), never the device clock. This week =
   Monday to Sunday of the hotel week; Next 7 days = today to today+6; This month = 1st to last day. Presets keep the
   chosen date field (check-in / check-out / staying on / booked on).

DONE WHEN:
- [ ] One filter bar: search, date field + From / To + presets (Today, Tomorrow, This week, Next 7 days, This month),
      drop-downs room type, room, status, payment, source, group, staff; booking kind kept in More filters.
- [ ] Active filters show as removable chips + Clear all inside the results; chips stay correct after a tab click,
      live search, page change and Back.
- [ ] Every filter, tab, sort, page size and page is in the URL; refresh, Back / Forward and a pasted link reproduce
      the same rows (HTTP test).
- [ ] List, tab badges, page count and CSV export use one shared scope helper (no second copy of the filter list).
- [ ] Every data column sorts server-side over the whole list incl. new Balance due; active arrow is clear; defaults as
      Decision 5; last sort remembered per user per device (Decision 6).
- [ ] Tab row: All, Arriving today, Leaving today, In house, Pending, Unpaid + More menu (Tentative, Expiring, Checked out,
      Cancelled, No-show, Groups, Deleted) with counts; More shows the active hidden tab's name; clicking any tab or
      filter card lands on the filtered rows.
- [ ] Rows: plain status + payment pills, nights, Balance due column (Decision 9), "?" helper on
      every non-obvious column; pager 10 / 25 / 50 / All; phone layout unchanged in structure.
- [ ] List | Calendar switch reaches the existing timeline with mapped filters and back; booking click opens the booking;
      moves keep their confirm (timeline tests unchanged and passing).
- [ ] Saved filter sets: save, open, delete, per user, in `admin_user_preferences`; no migration.
- [ ] CSV export = current filtered + sorted list, all rows, same order as the screen, Balance due column added,
      page permission + view-only trial refusal unchanged.
- [ ] Owner UI rules: no AI-looking UI, no gradients / glows, solid surfaces, one accent; desktop 1920 / 1280 and 390
      phone checked in headless Chrome.
- [ ] All proofs below pass; reviewer-critical PASS on the full diff; STATUS.md updated; ported to Rosalyn's with NO
      migration (unless T2 adds 092, which waits for the owner's confirm there).

TASKS:
Paths marked NEW do not exist yet (written without backticks so the path check skips them).

| # | What | Files (exact) | Risk | Owner | Depends | Proof (command -> expected) |
|---|------|---------------|------|-------|---------|-----------------------------|
| T0 | Board claim: extend ClaudeWorkTwo's claim to every file below (it lists only bookings* files today). | `C:/Users/john-paul.chirwa/OneDrive/MSP/AI-Setup/scripts/board.mjs` | NORMAL | orchestrator | - | `node C:/Users/john-paul.chirwa/OneDrive/MSP/AI-Setup/scripts/board.mjs` -> claim names bookings-list-filters.php, calendar.php, calendar-shell.php, room-timeline.js, admin/api/bookings-saved-filters.php |
| T1 | Shared scope + URL helpers (pure): `rh_bk_filters_from_get(array $get, array $known)` (one place that cleans every list param incl. new `room`, `group`, `source`, `created_by`), `rh_bk_scope(array $f, bool $hasCombos)` returning all shared clauses/params (search, dates, overdue, balance, since, More, new filters) in a fixed order, `rh_bk_keep_params(array $f, array $drop = [])` (pager, keep-inputs, export, chip remove links), `rh_bk_chips(array $f, array $names)` (label + params without that filter), `rh_bk_date_presets(string $today)` (Decision 8), `rh_bk_view_kind(tab, status)` + `rh_bk_default_order(tab, status)` (Decision 5), `rh_bk_balance_due(array $row, bool $taggedExpired)` (PHP) + `rh_bk_balance_due_sql()` (SQL twin, tag as an EXISTS on `booking_charges`), both exactly Decision 9, + sort key `balance`, room / group whitelists. Justified refactor: needed so list, count, badges and export cannot disagree when 4 filters are added. | `admin/includes/bookings-list-filters.php` (add after 688; sort map 286-301), `scripts/tests/bookings_filters.php` (pure asserts + Liwonde read-only block ~323+: for every booking the balance SQL equals the PHP rule, with fixture rows for deleted, expired untagged, expired tagged, cancelled with fee, no-show, part paid, unpaid, paid, refunded) | CRITICAL (decision logic, data integrity, money display) | builder-critical | spec-check S1 | `php -l admin/includes/bookings-list-filters.php` -> no errors; `php scripts/tests/bookings_filters.php` -> 0 FAIL (old 253 asserts still pass) |
| T1b | Source + staff SQL (pure): `rh_bk_source_options()`, `rh_bk_source_filter()`, `rh_bk_creator_filter()` (positive admin id), `rh_bk_source_sql()` / `rh_bk_creator_sql()` as correlated subqueries on the FIRST `booking_audit_log` 'created' row and FIRST `booking_timeline_logs` 'create' row, same order as `rh_created_by_pick`; clauses fed into `rh_bk_scope`. | `admin/includes/bookings-list-filters.php`, `includes/booking-created-by.php` (read only, the rule to mirror), `scripts/tests/booking_created_by.php` (Liwonde read-only cross-check: SQL source / creator equals `rh_created_by_pick` for every booking) | CRITICAL (decision logic, people's data) | builder-critical | T1 | `php scripts/tests/booking_created_by.php` -> 0 FAIL incl. cross-check rows > 0 |
| T2 | Read-only EXPLAIN on Liwonde of the list query with source / staff filters and with sort `balance`; report indexes on `booking_audit_log(booking_id, action)` and `booking_timeline_logs(booking_id, action_type)`. Only if a full scan is shown: write migration 092 (index only, no data change), dry run on Liwonde, flag for owner + Rosalyn's confirm list. Never run on Rosalyn's. | NEW scripts/tests/bookings_list_explain.php (Liwonde guard like `scripts/tests/bookings_filters.php` 326-328); only if needed NEW admin/migrations/092_booking_log_indexes.php | CRITICAL (data, live system via port) | db-builder | T1b | NEW script: php scripts/tests/bookings_list_explain.php -> prints plans, "no full scan" or the 092 proposal; refuses when site_name is not Liwonde |
| T3 | Server wiring in bookings.php: parse via `rh_bk_filters_from_get` (3021-3063); replace the 7 restatements with the helpers (3093, export 3121-3180, list 3296-3335, recount 3619, keep-inputs 3871, pager 4557-4575); move sort resolve before the export block and give export the list's ORDER BY + tiebreak `b.id DESC`; default order from `rh_bk_default_order` (3598-3609); add Balance due CSV column (3211-3255) from the same Decision 9 rule (SQL twin selected as a column); room / group / staff option lists (one query each, near 3049-3055). No change to any POST action. | `admin/bookings.php` (ranges named), NEW scripts/tests/bookings_list_http.php (Liwonde only, read-only, pattern `scripts/tests/view_only_http.php`: php -S + dev-router, signs in, fetches list + export for 10+ param sets; checks row count = SQL count, export rows = list count, export order = screen order across pages, Back URL reproduces rows, unknown params ignored; every existing deep link listed explicitly and still landing on the same rows: `?filter=` each key of `rh_bk_tab_map`, `?status=` alias, `?payment_status=paid|unpaid`, `arrival=overdue`, `departure=overdue`, `balance=owing` (+ `filter=checkout_today`), `since`+`since_kind`, `action=assign-room&booking_id=`, and the `bookings_list_return` session value) | CRITICAL (time/availability list, data integrity) | builder-critical | T1, T1b | `php -l admin/bookings.php` -> no errors; `php scripts/tests/bookings_filters.php` + `php scripts/tests/bookings_bulk_test.php` -> 0 FAIL; NEW: php scripts/tests/bookings_list_http.php --db -> 0 FAIL |
| T4 | Filter bar markup: toolbar form 3866-3948 becomes one bar: search; date field select + From / To + preset links (from `rh_bk_date_presets`, keep other params); drop-downs room type, room, status, payment (`?pay=`), source, group, staff; More filters keeps Booking kind only; Filter / Clear all. Replace the notice 3966-3979 with chips (one per active filter, x = link without it) + Clear all, INSIDE `#booking-results` so live swaps keep them current. "?" helper on each drop-down. Phone: drop-downs fold under a Filters toggle (existing `<details>` pattern). The form sits outside `#booking-results`, so after every `loadBookingResults` (~5478) and on popstate (5552) re-sync search, date field, From / To and every drop-down from the URL (else a removed chip comes back on the next Filter press). | `admin/bookings.php` 3866-3979 | CRITICAL (time: CRITICAL path) | builder-critical | T3 | `php -l admin/bookings.php`; `php scripts/tests/help_map.php` -> 0 FAIL; browser B1 steps 1-6 (incl.: remove a chip, press Filter, the filter stays removed; Back restores both chips and drop-downs) |
| T5 | Styles for bar, chips, presets, More menu, Balance due column, clearer sort arrow (bookings table only: active `th[aria-sort] .admin-sort-icon` full opacity + accent, inactive lighter; no change to `admin/includes/server-sort.php`). Solid surfaces, one accent, no gradients / glows. 1920 / 1280 / 390. | `admin/css/bookings.css` | NORMAL | ui-builder | T4, T6, T7 | browser B1 shots at 1920 / 1280 / 390 -> no overflow, chips wrap, phone row unchanged |
| T6 | Tabs: main row All, Arriving today, Leaving today, In house, Pending, Unpaid; "More" button opening a menu with Tentative, Expiring (when >0), Checked out, Cancelled, No-show, Groups, Deleted (when >0 or open). Menu items stay `.tab-button[data-tab]` INSIDE `.tabs-header` (handler 5560-5571 and count refresh 5483-5489 depend on it); More shows the active hidden tab's label + count; keyboard + Escape close; counts unchanged. Same data-tab values (deep links and `rh_bk_tab_map` unchanged). | `admin/bookings.php` 3981-4055, 5068-5085, 5536-5571 | CRITICAL (time: CRITICAL path) | builder-critical | T3 | `php -l admin/bookings.php`; browser B1 steps 7-9 (each tab lands on its rows, More label, counts equal badges) |
| T7 | Rows + headers: Balance due column after Payment (th + td, sortable key `balance`, value `rh_bk_balance_due()` per Decision 9 using the page's `$bk_tagged_expired`, blank when 0), the "left to pay" line leaves the Payment cell (shown once); "?" helper (`<i class="help" data-tip>`) on Room, Nights, Total, Status, Payment, Balance due, Created; status / payment pills read plain words (existing labels); phone row 4236 gains the balance only when > 0. | `admin/bookings.php` 4101-4117, 4242-4330 | CRITICAL (money display, time path) | builder-critical | T3 | `php -l admin/bookings.php`; `php scripts/tests/booking_pay_state.php` -> 0 FAIL; browser B1 step 10 |
| T8 | Sort memory + defaults client side: on `rh:server-sort` (5587-5596) store `{sort,dir}` under the current view kind (server prints `data-view-kind` and `data-user-id` on `#booking-results`); on init (5598-5622) and after a tab load, when the URL has no `sort` and a stored one exists for that view kind, replaceState + `loadBookingResults` once. try/catch on every localStorage call. Pager picker shows 10 / 25 / 50 / All (4604-4610). | `admin/bookings.php` 3964, 4604-4610, 5587-5622 | CRITICAL (time path) | builder-critical | T3, T6 | `php -l admin/bookings.php`; browser B1 steps 11-12 (sort kept after reload as same user; other user id sees default; ?sort in URL wins) |
| S1 | Spec-check (Fable) of Decision 9 (check each case: deleted, expired untagged, expired with the no-show charge tag, cancelled with kept fee / extras, no-show, part paid, unpaid, paid, refunded, credit balance) and the T9 endpoint spec (auth, csrf, own rows only, whitelist rebuild, size caps, view-only refusal of writes). | this plan, `includes/booking-pay-state.php`, `admin/api/dashboard-seen.php` | CRITICAL (money display, access) | spec-check | - | written PASS / fixes folded into T1 and T9 briefs |
| T9 | Saved filter sets endpoint: pure helpers (sanitize name, rebuild query from `rh_bk_filters_from_get` + `rh_bk_keep_params`, cap 20, dedupe by name) + POST endpoint (actions save / delete only; the list is read by bookings.php itself, csrf, signed-in user's own `admin_user_preferences` row only, never a user id from the request, `innodb_lock_wait_timeout = 3` like nav-favorites, plain refusal if the table is missing). Not on the view-only allowlists (Decision 3). | NEW admin/includes/bookings-saved-filters.php, NEW admin/api/bookings-saved-filters.php, NEW scripts/tests/bookings_saved_filters.php (pure + --db Liwonde round trip on a test user, restores state), `scripts/tests/view_only.php` (add: write to bookings-saved-filters.php refused under view only, trial and licence read-only) | CRITICAL (access, security) | builder-critical | S1, T1 | `php -l` both NEW files; NEW: php scripts/tests/bookings_saved_filters.php --db -> 0 FAIL; `php scripts/tests/view_only.php` -> 0 FAIL |
| T10 | Saved filter sets UI: "Saved filters" menu in the toolbar (list read server-side from the user's own `admin_user_preferences` row via the T9 helper = links to `bookings.php?<saved query>`, Save current filters (name field in a small dialog, not prompt()), delete x with confirm); Save / Delete hidden when `rh_view_only_reason()` is not ''. | `admin/bookings.php` toolbar 3949-3961 + page script near 5598 | CRITICAL (time path) | builder-critical | T9, T4 | `php -l admin/bookings.php`; browser B1 steps 13-15 (save, reload, open, delete) |
| T11 | Calendar switch, timeline side: `calendar.php` accepts `tl_status` (whitelist free / occ / arr / dep / dirty / oos) and `from=bookings` into `$tlInitial` (160-189); `room-timeline.js` init (111-135) reads `ini.status` only when whitelisted (today `''`); `embed=1` mode (Decision 4): no admin sidebar / header / calendar-shell tabs, timeline view forced, leave-the-timeline links `target="_top"`, a small body class for full-height layout; non-embed calendar.php unchanged. Grid, moves, panel logic untouched. | `admin/calendar.php` 160-189, `admin/js/room-timeline.js` 111-135, `admin/includes/calendar-shell.php` 20-44 | CRITICAL (time/availability: the screen that moves bookings) | builder-critical | T1 | `php -l admin/calendar.php admin/includes/calendar-shell.php`; `node --check admin/js/room-timeline.js`; `php scripts/tests/calendar_timeline_test.php` -> 50/0; `php scripts/tests/calendar_timeline_actions_test.php` -> 55/0; `node scripts/tests/grid_select_test.mjs` -> 0 FAIL |
| T12 | Calendar switch, list side: pure `rh_bk_calendar_params(array $f, string $tab)` (Decision 4 mapping + the "list only" note) in the filters helper with asserts; "List \| Calendar" control at the top of bookings.php (above the toolbar, ~3865), only when `rhCanLinkTo(user, 'calendar.php')`; Calendar swaps `#booking-results` + pager for a lazy same-origin iframe of `calendar.php?embed=1&<rh_bk_calendar_params>`, `?view=calendar` in the URL (refresh/Back keep it), mapped-filter changes reload the src, the "list only" note shows above the frame. | `admin/includes/bookings-list-filters.php`, `scripts/tests/bookings_filters.php`, `admin/bookings.php` ~3863-3866 | CRITICAL (time path) | builder-critical | T11, T3 | `php scripts/tests/bookings_filters.php` -> 0 FAIL; browser B1 steps 16-18 (switch carries room type + In house -> occ; booking click opens booking-details; move shows its confirm, cancelled, nothing saved) |
| B1 | Browser check script covering steps 1-18 above at 1920, 1280, 390 (admin + a receptionist login if dev-router allows), console errors = 0. Server: `php -S 0.0.0.0:8000 -t . scripts/tests/dev-router.php`, stopped after. | NEW scripts/tests/fixtures/bookings_list_steps.json, `scripts/tests/browser_check.mjs` | NORMAL (test only) | ui-builder | T4-T12 | node scripts/tests/browser_check.mjs scripts/tests/fixtures/bookings_list_steps.json -> every eval true, 0 exceptions |
| R1 | Review: `node C:/Users/john-paul.chirwa/OneDrive/MSP/AI-Setup/scripts/risk-route.mjs` on the real diff -> reviewer-critical (Opus) on the whole diff; one fix round max; then full `php scripts/run_tests.php` (shared helper + permissions touched). | all of the above | CRITICAL | reviewer-critical | B1 | reviewer PASS; `php scripts/run_tests.php` -> no new FAIL vs HEAD (known old fail: guest_history --db refunds line) |
| P1 | STATUS.md entry; port the same files to `../Rosalyns-hotel-2026` (standing OK once Liwonde passes), `php -l` there; NO migration (unless 092 exists: then stop and list it for the owner). Tell ClaudeWorkOne that `docs/guides/07-reception-bookings.html` needs the new bar, tabs, Calendar switch, saved filters (do not edit `docs/guides/` or `includes/ai/` here). | `STATUS.md`, Rosalyn's copies of the files above | CRITICAL (live system) | orchestrator | R1 | `php -l` on each ported file -> no errors; `git diff --cached --stat` lists only these files |

Build order: S1 -> T1 -> T1b -> T2 -> T3 -> (T4, T6, T7, T11 in that order; all touch bookings.php except T11, so one builder,
sequential) -> T8 -> T9 -> T10 -> T12 -> T5 -> B1 -> R1 -> P1. One builder-critical session reused (SendMessage) for
T1-T12; ui-builder for T5 + B1.

## Orchestrator additions (2026-11-24, binding)
- OWNER 2026-11-24: "make sure all work has mobile UI too": every ship is checked by touch at 390 and 768 (targets
  >= 40px, no page side-scroll, menus/dialogs fit, embedded calendar uses its phone layout inside the frame).
- THREE SHIPS, each its own fresh builder-critical, gate, commit, push and port: (A) T1, T1b, T2, T3 (helpers, server
  wiring, CSV); (B) T4, T6, T7, T8, T9, T10, T5 (list UI); (C) T11, T12 (embedded calendar). B1 steps split to match.
- Existing deep links into bookings.php (grep 2026-11-24 over admin/, includes/, api/): params `action`, `booking_id`,
  `filter`, `status`, `type`, `search`, `flash`, `date_from`, `date_to`, `date_type`, `arrival`, `departure`,
  `payment_status`, `balance`, `export`. Sources: admin/accounting-dashboard.php, admin/api/global-search.php,
  admin/booking-details.php, admin/calendar.php, admin/charge-add.php, admin/dashboard.php, admin/edit-booking.php,
  admin/end-of-day-report.php, admin/includes/dashboard-attention.php, admin/includes/dashboard-firstlogin.php,
  admin/js/payment-add.js, admin/js/room-timeline.js, admin/payment-add.php, admin/process-checkin.php,
  admin/room-dashboard.php, includes/ai/ai-actions.php, ai-decisions.php, ai-insights.php, ai-tools.php (the
  includes/ai files are ClaudeWorkOne's: their links must keep working UNCHANGED). T3's bookings_list_http.php covers
  every one of these param shapes.
- T11 DONE WHEN adds: (1) embed mode suppresses the global widgets from admin-header.php / admin-footer.php (Assistant
  chat launcher, notification polling, session keepalive/timeout, SW registration, offline banner) so the page never
  runs two of them; (2) moves / assigns / nights changes from room-timeline.js are fetch/XHR (no full-page redirect
  that would render bookings.php inside the frame), proven by file:line; (3) session expired inside the frame breaks
  out to the top window to login.php; (4) after a change in the frame, switching back to List reloads the rows and tab
  counts (postMessage from the frame or refetch on toggle); (5) sw.js / config page-cache never cache
  `calendar.php?embed=1` HTML (file:line).
- Reviews: hotel ship gate = `qa-auditor` (opus) per ship (it is stricter than the shared reviewer); Decision 9 / T1
  balance rule and the CSV Balance due column also go past `dept-accounting-lead` (hotel rule: money from any
  department is reviewed by the accounting lead). S1 spec-check covers Decision 9, T9 AND Decision 4 / T11 / T12.
- Shared tree: ClaudeWorkOne has uncommitted includes/ai/*, docs/guides/*, scripts/tests/ai_assistant_test.php. Any
  run_tests.php failure there is compared with HEAD before blaming this work. Stage by name; own STATUS.md section only.

## Spec-check S1 fixes (2026-11-24, binding; override anything above that disagrees)
1. T11 embed KEEPS admin-header.php 507-522 (`#rh-admin-page`, `rh_view_only_render` 511, `window._rhCsrf` 520-522:
   room-timeline.js 943-945 postAction + admin-extend.js 46 need it) and footer `admin-components.js` (`window.Alert`,
   room-timeline.js 181/931). Embed DROPS header 258-339 (SW reg + manifest), 350-360 (boot loader), 362-486 (header +
   nav), 495-501 (SPA script, help, Assistant panel), footer 36/40/47. Proof: `_rhCsrf` non-empty inside the frame AND
   one move confirmed on Liwonde then reversed (B1).
2. Embed `<head>` gets `<base target="_top">` (no href, so the fetch at room-timeline.js 948 is unchanged);
   room-timeline.js 1856 `window.location.href` -> `window.top.location.href` when framed. Covers linkBtn 797,
   840-861 (Check in / Check out GET links 843/845), 1146, 1413, 1575, 1888-1900, admin-context-menu.js 99.
3. admin/admin-init.php joins T11 (and T0): with `embed=1` do not store the after-login target (49-51). bookings.php
   checks the frame's pathname on every `load`; not calendar.php -> top window to
   `login.php?redirect=bookings.php?view=calendar` (also catches deny / module-off redirects 103, 125).
4. admin/sw.js joins T11 (and T0): `isPage` (46) skips URLs with `[?&]embed=1` (30-entry page cache, 34/165).
   config/page-cache.php: no change (calendar.php has no page-cache calls).
5. Decision 9 SQL twin is built from the rounded expressions in `rh_bk_pay_key_sql`
   (bookings-list-filters.php 348-354), 0 first when `ROUND(due,2) <= BALANCE_TOLERANCE` (as line 498), tag test
   `LIKE CONCAT('%[noshow-fee:', b.id, ']')` exactly as bookings.php 4135, same rounding. Proof runs the twin on literal
   derived-table rows per case (no writes) AND reports real-row counts per case; must equal rh_booking_pay_state()
   (includes/booking-pay-state.php 34-38) / rh_bk_left_to_pay (bookings-list-filters.php 494).
6. T9 save / delete: transaction; `INSERT IGNORE` seed '[]'; `SELECT ... FOR UPDATE`; delete by name not index; cap
   each saved query length and the whole stored value (refuse over cap); never overwrite a stored value that fails
   json_decode.
7. (promoted from LATER, access) T9 endpoint also checks the user may open bookings.php (same page-permission +
   module check as the page; api-init.php 62-75 does not).
8. (promoted from LATER) Calendar "list only" note also names phone / email search (timeline search matches guest, ref,
   room only, room-timeline.js 219-224); room-timeline.js init sets the status select's value (133-136).
LATER: company-billed rows (bookings.php 4301-4308) "company owes" note next to Balance due; php -S ignores .htaccess,
so live framing headers are checked by reading .htaccess:76 + config/security.php:103/115 only.

LATER (not in this round): view-only users saving filter sets (needs the allowlists).

OPEN QUESTIONS FOR THE OWNER: none blocking. Decision 4 is the owner's (2026-11-24, embedded). Owner 2026-11-24: a new
table/migration is approved on both hotels if needed (Liwonde first, Rosalyn's after Liwonde passes).
