# STATUS: Liwonde Sun Hotel 2026 (hotel PMS / HMS)

**Read this first.** Any agent (Claude, Cline, another provider) should be able to continue from this file alone. Keep it
current during work. Last updated: **2026-11-22**.

This repo is a fork of the Rosalyn's platform (`../Rosalyns-hotel-2026`): most fixes go into both repos with the same
commit message.

Deeper trackers to read next:
- `CLAUDE.md` for the rules
- `.claude/PROJECT_CONTEXT.md`
- `.claude/BUILD_PLAN.md`
- Rosalyn's `.claude/TASKS.md`, the live task tracker shared by both hotels

## Token cost controls (owner 2026-11-17)
Measured 10-17 Nov: about 55 model calls per commit, 97% on Opus, department-lead runs averaging 60-105 steps with
250-470k context, main sessions up to 600k. Now enforced by config, not just rules:
- Agent frontmatter: `autoCompactWindow: 200000`; medium effort for ui-lead, ui-designer, artifacts-lead.
  NO turn caps: `maxTurns` removed from every agent 2026-11-18 (owner: caps left work half-done and cost more
  in the next session). Do not add them back (DEPARTMENTS.md cost rule 11).
- New `qa-quick` agent (Sonnet) for CSS/markup/docs-only diffs; it escalates anything with logic to `qa-auditor` (Opus).
- `.claude/settings.json`: `autoCompactWindow: 250000` for main sessions.
- `scripts/claude-hooks/read-guard.php`: blocks whole reads of files over 400 lines / 40 KB (use offset/limit).
- `scripts/port-commit.php` (in Rosalyn's): ports work already committed at Rosalyn's to Liwonde without an agent.
  Tested on 4 past agent-made ports (incl. drifted `config/database.php`): identical results.
- artifacts-lead (showcase) works the SYNC QUEUE once a week, not after each ship.
- Weekly usage report (owner 2026-11-18): `Rosalyns-hotel-2026/.claude/scripts/usage-report.js --save`
  (one copy; plain node, no AI calls) reads Claude Code's own session logs for ALL projects and writes
  `MSP/usage-reports/<date>.md` + a row in `MSP/usage-reports/HISTORY.md`. COST_LOG.md is retired.
  Baseline 10-16 Nov: 22,793 calls, 50 per hotel commit, 95% Opus, 53 agent runs past today's caps.

## Shipped 2026-11-22: Blocked Dates calendar - block / unblock single rooms on a month grid (owner FEATURE)
- Owner: "Fix the logic and UI for blocked rooms to also look like calendar so we can individually block individual rooms. Make sure logic is perfect."
- admin/blocked-dates.php opens on a Calendar view (List view kept at ?view=list): rows All rooms / each room type / each room
  (a joined room = one row, same lanes as the Calendar via rh_calendar_load), columns = the month's nights. Click, drag,
  shift-click or click a room name to select nights, then Block (type + reason) or Unblock. New action grid_apply: one
  transaction under the blocked_dates lock, all or nothing; past nights refused; joined room = both rooms; re-block updates
  type/reason; unblock removes only the row's own block and says when a room-type / all-rooms block still covers the night.
  Booked nights show a blue bar, booked + blocked a red ring. Block Rooms permission enforced (grid view-only without it).
- Logic fixes: inactive room refused with a clear message; List filter dates validated; filtering by one room no longer
  lists other room types' blocks; unblock id precedence bug; refresh after a modal save now also works from an empty list.
- New admin/js/blocked-dates-grid.js; CSS in admin/css/blocked-dates.css (44px targets on touch). Tests:
  scripts/tests/blocked_dates_grid.php 28/28 (not yet in run_tests.php: another profile has that file open), block_rooms 42/42.
  QA (opus): FAIL on 2 blockers (type-row unblock message, 44px touch targets) -> fixed in the one fix round, re-tested.
- Liwonde 8efdd57, Rosalyn's port e44ff36 (both pushed; owner deploys).
- Follow-up 2026-11-22: past nights mixed into a selection are now named in the message ("N past night(s) ... skipped");
  blocked_dates_grid.php (29/29) registered in run_tests.php. QA PASS. Liwonde bb127b6, Rosalyn's port 45595e0.
- LATER: the same past night posted twice for one row counts twice in that message (grid normally sends one item per row).

## Shipped 2026-11-21: Assistant Settings "Fit limits to the cap" + layout fixes (owner FEATURE)
- Liwonde 57e21db, Rosalyn's port 1b96445. Owner: "for Budgets and limits be able to auto set to the best capacity to fit
  budget and fix the UI in assistant settings".
- Budgets and limits: Monthly cap first; "Fit limits to the cap" fills Daily limit per person + Most tokens for one question
  (rh_ai_fit_limits): people with access + nightly checks, 31 days, one question past the daily limit, Primary price,
  10% kept back. Shows what the current limits could cost (Liwonde now: 2,000,000/day = up to ~US$106/month vs US$5 cap).
  Nothing saved until Save limits. The cap itself still never stops chat (owner decision).
- UI: pickers no longer cut off, sub-headings in the panel font, Models table readable and fits, prices $0.30,
  "Nightly checks" in Usage, dashboard link as a button.
- LATER: the 1,000-token floor can put a tiny cap over (shown as "over"); page is long (8 panels) - a jump bar if asked.

## Shipped 2026-11-21: Assistant Settings model prices automatic, "Monthly cap" (owner FEATURE)
- Liwonde 0637b87, Rosalyn's port 74871bb. Owner: "Check AI selector in AI settings and no need for us to set AI prices,
  you do it automatically, we just set max cap"; owner chose the cap stays information only (never stops chat).
- Models table: no price boxes; prices read automatically (Google page; public LiteLLM price list for Anthropic, OpenAI,
  xAI, DeepSeek and Google models the page lacks) on the daily refresh, Check for newer models and Test connection.
  A model with no price yet cannot be ticked. A known price replaces any old typed one. "Monthly aim" -> "Monthly cap".
- Selector check: tick/untick/role logic works. Liwonde's two Anthropic rows (Opus 5.5, Haiku 5.5) have no price because no
  Anthropic key is saved there (never synced); they get prices once a key passes Test connection.
- LATER: cache the 5 MB price list once per daily run (now read once per provider); Test connection waits up to 20 s more.

## Shipped 2026-11-22: Put stock items on the drinks menu (owner FEATURE)
- Owner: "be able to add menu items from stock imported in the simplest way"; chose BOTH: Stock Items list action and a
  Menu Management shortcut. (Same day: Rosalyn's "no add to menu in step 4" = job 780's file had no Selling price
  column, owner's slip; checked read-only on Rosalyn's import_jobs.)
- New page admin/stock-to-menu.php (+ admin/includes/stock-to-menu-lib.php, js/css): per item name, Sold as, price (VAT
  incl.), category, cost of one, profit, status (new / price change / same / cannot + why) -> "Add N to the menu".
  Stock Items: tick column + "Tick all shown" + "Put N on the menu"; Menu Management Drinks tab: "Add from stock".
  Permission menu + modules pos+stock. Import's write part refactored to import_drinks_menu_write(), shared.
- Liwonde 49ddf7d, Rosalyn's port 1af07fa.
- Tests stock_to_menu 27/0, import_drink_prices 105/0, permission_tiers 169/0, menu_room_service 23/0; menu_portions
  170/1 pre-existing (needs a stock item; test DB empty). QA PASS.
- Minor fixes (owner 2026-11-22 "Fix minor points"; Liwonde 17d28dc, Rosalyn's port fb2cf48): commas only as thousands groups ("1,50", "0,500" refused
  with a plain message), ticked ids POSTed in one field (cap 5000, said plainly), costs/profit only for stock_management
  (never sent to Menu-only users), import stop wording says "Nothing was imported" once. Tests 43/0, 106/0. QA PASS.
- LATER (was): price "1,50" reads as 150.00 (commas dropped as thousands); 1000+ ticks capped in the URL; import link error
  text now says "Nothing was saved"; Menu-only users see stock costs; staff-activity label for drink_menu_from_stock;
  Stock Items rows tall at 1400.

## Shipped 2026-11-21: Stock quantities shown as "bottles + shots" (owner FEATURE, display only)
- Owner asked why stock shows 4 decimals (stored 6 dp so a shot = 0.033333 bottle adds up), then: "show bottles + shots".
- config/database.php rh_stock_qty_words / rh_stock_qty_html (exact figure on hover) / rh_stock_qty_sort_attr (raw
  data-sort-value for the shared sorter) / rh_stock_measure_attach: "2 bottles + 7 shots" for items with a count
  measure, "short by ..." below zero, "over by / short by" for count differences, no measure = max 2 dp + unit.
- Screens: Stock Overview, Ingredients, Stock Reports, Reorder, Batches, Wastage, Stock Count (incl. live Difference),
  Dashboard low stock. Stored figures, inputs, CSV/Excel exports and logs unchanged. Tests stock_qty_words 39/39,
  stock_count_measures 103/0, stock_list_filters 85/0; stock_count_approval 124/1 pre-existing (0 stock items).
  QA: FAIL on sort order -> fixed in the one fix round (data-sort-value on every changed cell), checked.
- Liwonde dced1a0, Rosalyn's port 8686663 (config/database.php hunk applied with git apply; Rosalyn's RBH lines kept).
- LATER: Reorder "Suggested" and reports delta columns still plain numbers.

## Shipped 2026-11-21: Import page UI - clear step plan, foldable sections, pinned footer (owner FEATURE, UI only)
- Owner: "fix the UI of the import process ... cannot fold things ... endless scrolling with an unclear step plan ...
  Logic is fine but the UI can be better".
- Step bar with one-line descriptions + ticks; "On this step" box per step (numbered actions, live counts, Open all /
  Fold all); every big block a native <details> fold with Required/Optional/Done badge + one-line summary (needs-you
  open, optional/done folded; remembered per job in localStorage); Back/Continue/Confirm and import pinned in a footer
  (same single confirm handler and can_confirm rule); one-sentence intros + "More about this". No post()/payload change.
- Tests import_engine 592/0, import_drink_prices 105/0; import_export_test 100/1 = pre-existing (test DB has 0 stock
  items after the owner's Clear Stock Data 2026-11-20 18:00). QA PASS.
- Liwonde 79d0a4a, Rosalyn's port d14bca5.
- LATER: Assistant bubble can overlap the footer's right end on desktop; footer ResizeObserver may loop briefly when
  pinning; scroll/resize listeners never removed; pagers 10/25/50/100 not All.

## Shipped 2026-11-21: Import step 3 sizes - tidy table + mass set + per-row "Sold as" (owner FEATURE)
- Owner: "fix the UI table perfectly and also be able to mass set like if default shot size is 30ml and you have a lot of
  bottles to bundle or even change within whether by glass or shot".
- Compact table (short "Menu in step 4" badge, dashes for not-applicable cells, cards on narrow screens); ticks per row +
  "Tick all shown" (search/Show/Group, all pages) + bar "Change several drinks at once" (Sold as, shot, glass, bottle
  size) -> POST sizes_bulk, each value checked as on its row, one transaction, changed/skipped reported.
- "Sold as" override (answers _sizes.r.<key>.sold; shot / glass / whole unit / Drink measures list names) is read only
  in import_drinks_build: menu side only (cost, link, tickability). Opening count, count measure and the Drink measures
  list are untouched (tested). Tests import_drink_prices 105/0, engine 592/0. QA PASS. Rosalyn's port 6fb1abe.
- LATER: Tick all shown posts every key (PHP max_input_vars 1000 would cut past ~995 drinks: send all=1 + filter);
  card-mode tick box 22px (<44px); a bulk save builds the list ~5 times; rows-per-page is 10/25/50/100, not All.

## Shipped 2026-11-21: Import step 4 "Drinks for the menu" - Tick all / Untick all (owner FEATURE)
- Owner: "Be able to select all and add to menu during a stock import".
- New drinks_set mode `tick_all` (includes/import/drinks.php): ticks every tickable drink the list shows (Search + Show,
  every page); Untick all clears them. Menu permission still required; commit re-checks ticks. Tests in
  import_drink_prices (67 pass). Port to Rosalyn's: drinks.php, admin/data-import.php, admin/js/data-import.js.
- Ported to Rosalyn's 3c3b30e (owner OK 2026-11-21, together with the Shot size below).

## Shipped 2026-11-21: Import step 3 "Shot size (ml)" in the sizes section (owner FEATURE)
- Owner: "set the shot size in advance in step 3 ... same as Glass size (ml) ... without messing up any stock count logic
  ... dynamic ... especially if we also have AI assistance ON".
- Shot rows (new alias kind shot_words: shot, tot; aliases seed v9) get a group + row shot size; order row > served-by
  cell > Assistant reading (same single call per import) > group > item's count measure > Drink measures list (25 ml).
  Shot rows are listed even when the bottle size is known. Spec + order: .claude/IMPORT_PLAN.md "Shot size in Step 3".
- Link: list size = today's "shot" link; other size = ml line (shot ml / bottle ml per sale; till tile "35 ml").
  Never changes the opening count, measure_per_big or the Drink measures list (tested). Tests: import_drink_prices 87/0,
  import_engine_test 592/0. QA PASS. ASSUMPTION: a typed shot size is for this import only, not hotel-wide.
- LATER: group shot size beats a count measure (such rows then need a bottle size); no warning when a bottle typed later
  is smaller than the shot; "Tot 35ml" now groups as m:shot (an in-progress import's old ml:35 group answer is dropped);
  till tile says "35 ml" not "Shot · 35 ml".
- Ported to Rosalyn's 3c3b30e (owner OK 2026-11-21).

## Shipped 2026-11-21: Menu Management Room Service tab + drink categories (owner FEATURE)
- Liwonde 7c341f4, Rosalyn's port 6ee2bc2.
- Owner: "missing a room service menu in menu management; drinks need to be split in categories within the drinks menu".
  Owner answers: live drinks all sit in ONE category; Room Service tab = all items with an "On room service" switch.
- Third tab "Room service" (?tab=room-service): food + drinks by category, switch saves at once (POST
  `toggle_room_service`: web row + till copy, one transaction), filter On / Not on / All. Drinks tab: category buttons +
  "Move selected to category" (POST `menu_move_category`, via `rh_menu_pos_sync_item` so the till copy + stock link
  follow; same-name in target skipped; one transaction per item; logged). Drinks moves open to anyone with page access;
  food moves + Delete selected/all stay with Clear Stock Data holders (server-enforced).
- Tests: new scripts/tests/menu_room_service.php 23/0; menu_portions.php 170/1 (the 1 = pre-existing stock-recipes
  inactive-category check). qa-auditor: 1 blocker (server check on food moves) fixed + test added. Browser check
  desktop + 390px. Test clean-up also removed Liwonde's empty old "ZZ Test Category" (id 988177).
- LATER: move log text is cut to 140 chars in System Logs; the menu lock is held for a whole bulk move (an add/update
  made at the same time waits up to 10 s).

## Shipped 2026-11-21: POS top bar spacing (owner PATCH)
- Owner: top bar "very zoomed, too squeezed, not enough spacing". At 1181-1700 CSS px (e.g. 1920 screen at 125%) the
  stats row was a 6-column grid with 7 stats (Card wrapped) and 7px labels. Fix: appended block at the end of
  `admin/css/pos-overrides.css` (roomier brand/tiles; stats one row, one column each). Liwonde 733adeb, Rosalyn's
  2505b27. Review PASS; browser 1536/1300/1200/1920/1024.

## Shipped 2026-11-21: POS drink tiles show the serving and other servings' prices (owner FEATURE)
- Owner: "show whether it's a shot or glass for drinks and how much, and in small how much the smaller item is" + "price
  of the smaller serving and also price of the bottle". Bar / coffee-bar tiles whose stock link is ONE stock item show
  "Shot · 25 ml" / "Bottle · 750 ml" / "Can · 330 ml" under the name and, in small under the price, the menu price of the
  same drink's other till items (real prices only, 86'd left out, max 3). Cart line shows the serving.
- `admin/includes/pos-drink-servings.php` (new), `admin/pos.php`, `admin/css/pos-overrides.css`. Liwonde 829c657,
  Rosalyn's 2274258. Test `scripts/tests/pos_drink_servings_test.php` 29/0; QA (opus) PASS; browser 1400 + 390.
- Data: tiles stay plain until a drink is linked to its stock item (one line) and Stock has the bottle size or the
  bottle/shot measure. Liwonde's menu has almost no drinks now, so it was checked with made-up drinks (removed).
- Follow-up (owner 2026-11-21 "fix the room service bottle gap too"): other servings carry show_pos/show_rs and the tile lists the ones for the till's mode, so a Room-Service-only bottle shows in Room Service mode. Test 34/0, QA PASS, browser both modes.

## Shipped 2026-11-21: Assistant "Invalid function name" fault (PATCH, owner report)
- Gemini refused every question (HTTP 400): the privacy vault's last pass rewrote tool NAMES when a vaulted guest
  word matched one (`group_breakdown` -> `[GUEST_1]_breakdown`). Fixed in `includes/ai/ai-vault.php` (names, schemas,
  call ids/signatures left alone; all text still scrubbed) + guard in `includes/ai/ai-providers.php` (invalid-named
  tools dropped and logged). Liwonde 687dab0, Rosalyn's port 4b42333. qa-auditor PASS; 4 new checks pass.
- Follow-up 450455f (port 79287ed): briefing links wrap in the panel, docblock + once-per-request warning,
  hidden username field on the key forms, Gemini 4 test fixed. Its unpriced-model rule cut the Models top 10
  to 4 and was undone in bdd6519 (port 004aab9); the stale "not announced" test now checks notice == table.
- `ai_assistant_test.php` is >1h against the remote DB; run sections with AI_TEST_OWNER_ROUND / *_ONLY flags and
  SKIPSW=1 SKIPRQ=1 (the claim-check self-test then fails only on its count). 2026-11-21: owner round to end 514/1.
- Shipped (owner screenshot): named guest "money unpaid" said none, "(ID [number removed])" in booking lists,
  "total for all rooms" = MWK 0.00. Now answered locally from the booking-screen folio figures (no provider call);
  booking-id-only "(ID n)" dropped before the scrub; claim check no longer swaps in a guest-credit 0.00 or cuts
  names at "DR.". Files: includes/ai/ai-money.php, ai-desk.php, ai-claims.php, ai-agents.php + test
  (AI_TEST_MONEY_1121_ONLY: 234/0; sync 292/0; owner round 524/1 count floor). qa-auditor PASS.
  Liwonde f933bd8, Rosalyn's port de2daae.
  LATER: (1) claim check: a money claim whose label has no money word now accepts any number in the evidence
  ("total for all rooms is MWK 0.00" passes if a 0 is anywhere); move the money-word rule into
  rh_ai_claim_candidate (ai-claims.php ~1226), not rh_ai_claim_labelled (~1080). (2) Same-name guests are merged
  under "N bookings under this name" (references listed). (3) rh_ai_money_last_search_ids does not re-check
  search_bookings (answer never names the guest). (4) Hotel-wide "total unpaid" with a booking in focus answers
  for that booking only.

## Shipped 2026-11-21: Housekeeping, Maintenance, Room Service like the calendar (owner FEATURE)
- Owner answers 2026-11-21: pages = "All three"; scope = "Same look + room timeline". Design:
  `.claude/CALENDAR_UI_DECISION.md` §10.
- Each page: calendar toolbar, filter cards, Timeline | List (phone opens List), side drawer with the page's own
  buttons. Housekeeping = tasks by due day; Maintenance = every open job From..To (hatched if it blocks the room; the
  calendar still draws only block_room = 1); Room Service = today's orders by room x hour, late edge = KDS 600 s rule,
  still one timer. Calendar maintenance blocks open `room-maintenance.php?id=N&view=timeline`.
- Files: `admin/js/room-timeline-ops.js`, `admin/css/room-timeline-ops.css`, `admin/includes/ops-timeline-{view,data}.php`,
  `admin/api/{housekeeping,maintenance,room-service}-timeline.php` (read-only GET, page permission + module gate),
  seam `cfg.mode` in `admin/js/room-timeline.js`. Test `scripts/tests/ops_timeline_test.php` 114/0 (read-only);
  calendar_timeline 50/0, calendar_timeline_actions 55/0.
- qa-auditor (opus) PASS; browser 1400/768/390 no console errors (Liwonde had no open maintenance jobs or RS orders).
  LATER items fixed same day (owner: "fix the left for later changes"): phone `.tl-hk-link` 44x44 with the phone
  room column 64 -> 92px (CSS `--tl-room-w` + `roomW` in room-timeline.js / room-timeline-ops.js, keep all three
  equal), room numbers wrap; "Hover for insight" / "Quick Insights" -> "Click to see these" / "Details". No change
  needed: compact rows are mouse-only; the RS list already gets the shared pager. qa-quick PASS.
  Model: owner 2026-11-21 keeps Sonnet leads; Opus only for money/stock/bookings/auth/security logic (RULES.md).
- Commits: git log "Ops pages like the calendar" (Liwonde) / "Port of Liwonde" (Rosalyn's).

## Shipped 2026-11-21: Room Timeline calendar (owner FEATURE)
- `admin/calendar.php` now opens a room x date timeline. The old month grid is at `?view=month`. Tabs: Calendar / Housekeeping / Maintenance. Phone (3 days + Agenda) and tablet (7 days) layouts.
- Design: `.claude/CALENDAR_UI_DECISION.md`. Data: `admin/includes/calendar-data.php`, shared by both views. JSON: `admin/api/calendar-timeline.php`. JS: `admin/js/room-timeline.js`.
- Step B: moves, assigns and nights changes go through the existing bookings.php actions, with a confirm before each save.
- Liwonde f011c65 + 1f3658e, Rosalyn's cadda61 + f8a9ec2. Tests `scripts/tests/calendar_timeline_test.php` (50) and `calendar_timeline_actions_test.php` (55), both read-only.
- LATER items are in Rosalyn's TASKS.md. The guides and Assistant entries are pending in `.claude/GUIDES_AI_SYNC.md`.

## Shipped 2026-11-21: Menu Management Delete selected + Delete all (owner FEATURE)
- Tick boxes, Select all shown, Delete selected and Delete all (food/drinks/both, typed DELETE) on admin/menu-management.php,
  only for an active administrator with Clear Stock Data (data_reset, rh_sc_authorize); server re-checks (403).
  Same per-item rule as one-item Delete (rh_mm_delete_one). System Logs rows menu_items_bulk_deleted / menu_items_all_deleted.
- Liwonde d2aff50, Rosalyn's port 5ac2f4a. Tests menu_portions.php section 4 (rolled back): 170/1; the 1 fail is the
  pre-existing "Stock > Recipes: inactive category" check (stock-recipes.php, untouched). qa-auditor PASS; browser check desktop + phone.
- ASSUMPTION: product-catalog mode (non-restaurant presets, includes/product-management-mode.php) not covered.
- LATER: 44px hit area for the tick boxes / phone buttons; "nothing was changed" wording if logActivity ever throws after deletes.

## Clean-up 2026-11-21 (owner-approved, `cleanup` skill)
- Nothing sensitive exposed in git. Secret-looking values in `scripts/tests` are made-up test data (kept).
- To the Recycle Bin (local, not in git): 1,134 old `scripts/tests/sessions` files, 1 log older than 30 days, the 7
  August migration dumps in `Database/` (incl. a copy of Rosalyn's live schema + seed), 8 backups older than 30 days.
- Kept: `images/gym/personal-training.jpg` (used here; 8.1 MB, could be compressed), `includes/test-platform-mail.php`
  (real feature), all tests (per-area regression tests), invoices, cache, vendor.

## Hard rules
- **Liwonde is LIVE**, and so is Rosalyn's.
  - The local `.env` points at the live database.
  - Run only pure (no database) tests and `php -l`.
  - Do not run `scripts/tests/licence_client.php`: it rewrites the `licence_*` rows in `site_settings`.
  - Never edit `.env`.
  - The schema is locked: any DDL is the owner's decision.
- There is uncommitted POS / stock work in this repo that belongs to another session. Never stage it together with
  other work, and never stash it:
  - `admin/includes/pos-shift-totals.php`
  - `restaurant-payment-sync.php`
  - `staff-activity-lib.php`
  - `admin/pos*.php`
  - `shift-close-report.php`
  - `stock-dashboard.php`
  - `stock-orders.php`
  - `includes/station-hours.php`
  - migration `079`
  - two tests

## Shipped 2026-11-10: "View only" permission and view-only free trials
Another session also works in this folder: always stage files by name. Files:
- `includes/view-only.php` (new)
- `scripts/tests/view_only.php` (new, 32 pure tests)
- `includes/licence-guard.php`: `rh_view_only_guard()` runs after the staff-session guard; `rh_licence_write_allowed()`
  takes an optional allowlist.
- `admin/includes/admin-header.php`: banner and greying.
- `admin/includes/permissions.php`: the new permission `view_only`, listed last, in no role's defaults.

**What it does:**
- A view-only person can open and read everything, but every change is refused by the server. Change buttons are greyed
  out by a small script.
- Everyone is view only while the licence panel has set the business's free trial to view only (`trial_view_only` in the
  signed answer). Otherwise only people with the `view_only` permission explicitly ticked are view only.
- **Inert until switched on:** no role has it. Liwonde's trial has had view only switched ON on the panel since
  2026-11-10 10:43.
- Still to do: the Assistant-lead check, and a staff-guide page in `docs/guides/` (no guide yet).

## Shipped 2026-11-10: view-only trial fix, after the owner's report
The owner reported that, on Liwonde's view-only trial, Export and New booking could still be clicked. Causes:
- Exports were allowed on purpose.
- The greying skipped every `<header>` and `<nav>`, which hold the dashboard's "New booking" button.
- It only looked at `a.btn` links, so it missed the Bookings toolbar links.

The fix touches `includes/view-only.php` and `scripts/tests/view_only.php` (80 pure tests). It is identical in Rosalyn's:
- **Server:** during a view-only TRIAL, exports and downloads are refused (`rh_view_only_is_export`). That covers the CSV
  exports, any `?export=` / `?download=` on a staff page (system logs, backups, quotations), reports-export and the
  end-of-day PDF. Opening one invoice or receipt (`document-download.php`) still works. People who are view only by
  permission may still export.
- **Greying:** it now skips only the site's own header and menu. It checks every link: by its target (create- / add- /
  new- / edit- pages, exports in a trial) and, for button-like links, by its words, title or icon (trash, plus, pen).
- **Known gap:** POS and KDS do not load the admin header, so they show no banner or greying. The server still refuses
  every change there. `admin/pos.php` has another session's uncommitted edits, so it was left alone.
- Pushed with the owner's OK (both hotels).

## Shipped: the Assistant set up from the licence panel (owner 2026-11-11)
The owner sells the Assistant as a package with the HMS. The licence panel now holds each hotel's AI provider key and
models, tests them there, and sends a tested set-up (panel `public/ai.php`, see `../msp-control-panel/STATUS.md`).
The same change is in both hotels (identical files):
- `includes/licence-ai.php` (new): runs a staff command of kind `ai_setup` from the signed licence answer. The AI key in
  the answer is encrypted for this site only (key derived from its licence key). The hotel tests the key and every chosen
  model ITSELF first and changes nothing if that fails; then saves the key the Assistant's usual way (`rh_ai_store_key`,
  needs `APP_ENCRYPTION_SALT`), refreshes the model list, gives the chosen models their roles (`rh_ai_save_selection`),
  sets the monthly aim and, if asked, switches the Assistant on. Activity log: "by the system provider (licence panel)".
- `includes/licence-staff.php`: accepts `ai_setup` (it has no username); the staff report carries an `ai` block (on/off,
  provider, key's last 4, models, monthly aim, this month's usage). Never the key.
- `scripts/tests/licence_ai.php` (new, 30 pure tests; `licence_staff.php` still 53/53).
- Admins here may still change the Assistant in Assistant Settings; the panel never re-applies anything until the owner
  sends a new set-up. No schema change.
- Pushed with the owner's OK (both hotels first, then the panel). Not yet run against a real provider: the first
  real send from the panel is that test.

## Live now
The site runs `origin/main` at `d32ba8f`.

## In progress: licence modules + staff managed from the licence panel (owner 2026-11-10)
This work was shipped on 2026-11-10 after an independent review; its fixes are included. The same change is in Rosalyn's.

New files:
- `includes/licence-staff.php`: runs the staff changes the licence panel sends inside its signed answer, then reports the
  results and the staff list back to the panel's `api/hms-staff.php`. Never a password.
- `scripts/tests/licence_staff.php`: 50 pure tests.

Edits:
- `includes/licence.php`:
  - `rh_licence_module_allowed()` / `rh_licence_module_in()`: the licence is the ceiling for modules.
  - The staff step runs after the hourly check and after the panel's nudge.
- `includes/licence-guard.php`: `rh_staff_session_guard()` runs first on every staff request. A deactivated user is
  signed out, and a role change counts at once.
- `config/database.php`: `moduleEnabled()` is ANDed with `rh_module_licence_allows()`, which applies only to signed-in
  staff. The guest site and cron jobs are never limited.
- `admin/admin-init.php` and `admin/includes/permissions.php`: administrators now follow the licence for modules.
- `includes/ai/ai-core.php`: the Assistant follows the licence.
- `scripts/licence-check.php`: runs the staff step and prints counts.
- `admin/licence.php`: wording.
- `admin/includes/staff-invite-lib.php`: an invitation made for the panel always links to `site_url`.

Owner decisions (2026-11-10):
- The licence limits modules for admins too.
- The staff list may go to the panel.
- The hotel admin can undo a deactivation made from the panel.
- `finance` is always on.

Next steps:
1. After the push, the panel's `staff.php?id=2` should show Liwonde's staff after the next check-in.

## Incident note
On 2026-11-10 `scripts/tests/licence_client.php` was run against Liwonde by mistake.
- It rewrote and then restored the `licence_*` rows.
- The status was checked afterwards: active / ok, with 13 modules.
- At worst, staff saw a "licence could not be verified" banner for about a minute.
