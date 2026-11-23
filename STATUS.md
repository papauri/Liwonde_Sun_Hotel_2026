# STATUS: Liwonde Sun Hotel 2026 (hotel PMS / HMS)

**Read this first.** Any agent (Claude, Cline, another provider) should be able to continue from this file alone. Keep it
current during work. Last updated: **2026-11-24**.

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

## In progress 2026-11-24: dashboard first-login information (owner FEATURE, ClaudeWorkOne)
- Owner: dashboard misses vital info for someone who first logs in; mock-up approved
  (https://claude.ai/artifact/VGrVZnzhATWGmQeJDRUXWp). Plan + spec-check fixes + owner answers:
  `.claude/DASHBOARD_FIRSTLOGIN_PLAN.md` (binding).
- Owner decisions 2026-11-24: handover note goes to EVERYONE until each marks it read; welcome card shows until "Got it";
  VIP chip dropped (no VIP field); maintenance notice stays the existing top bar (task A5 not built).
- Phase A SHIPPED 2026-11-24, Liwonde 3102daa / port Rosalyn's 5d944bb (last sign-in line, End of Day day chip, Android/iOS login labels): reviewer-critical PASS;
  tests login_email 27/0, log_pager_test 125/0, dashboard_firstlogin 22/0 + --db 34/0, dashboard_figure_perms 89/0.
  LATER: timed send time read from automated_email_log.updated_at, not sent_at; a manual send during day D counts as closed.
- Phase B SHIPPED 2026-11-24 (since line + ?since= filters on Bookings/Payments, guest chips, donut free tonight, Money
  "Taken this week", "?" tips): reviewer-critical PASS; dashboard_attention --db 362/0, bookings_filters 253/0,
  finance_filters 168/0, dashboard_firstlogin 32/0 + --db 48/0, dashboard_figure_perms 98/0, dashboard_counts 143/0,
  guest_history 21/0. KNOWN OLD FAIL: guest_history --db "refunds shown in full (40,000)" fails on HEAD too (predates
  Ship M2 test update; not touched). LATER: "?" button nested inside card links; no "?" on since line/day chip;
  rh_dash_pay_scope_types duplicates the payments.php type-scope rule; $moneyShort unused.
- NEXT: Phase C (C1 migration 091 on Liwonde only, owner confirms before Rosalyn's; C2-C4 handover + welcome).

## Shipped 2026-11-23: calendar side panel opens like the Assistant chat (owner PATCH, layout only)
- Owner: panel should squeeze the page like the chat panel, not cover it, and not sit under the Assistant / notice
  buttons (they hid Open booking / More). Covers Calendar timeline + Housekeeping / Maintenance / Room Service grids.
- How: `admin/js/room-timeline.js` `syncDock()` (shared via `RoomTimeline.util`, used by `room-timeline-ops.js`) sets
  `html.rh-tl-open` (buttons move left of the panel) and `html.rh-tl-docked` (1025px+ and the page keeps 600px:
  `#rh-admin-page` padding-right = `--rh-tl-w`); the grid redraws to fit, keeping the selected bar + focus.
  CSS in `admin/css/room-timeline.css`; notice pill re-places on `rh-dock-change` (`admin/includes/ai-panel.php`).
- Checked: headless browser at 1476x1197 (docked) and 1100x800 (overlay, buttons clear), Calendar + Housekeeping;
  reviewer-critical PASS. LATER: with both the Assistant and this panel docked, alerts/bulk bar clear only the
  Assistant's width; docking the Assistant while this panel is open does not redraw the grid.

## Also shipped 2026-11-23 by ClaudeWorkTwo (lines added for them)
- Maintenance windows from the panel + hotel backups skip cp_ tables: Liwonde 46efbca / Rosalyn's 974003a.
- Update from Remote, hotel side: Liwonde 3fccfba / Rosalyn's 1bad20c. ClaudeWorkTwo is continuing (git pull instead
  of uapi) in includes/licence.php, scripts/deploy-check.php: see the board.

## Shipped 2026-11-23: one grid for Calendar / Blocked Dates / Maintenance / Housekeeping (owner FEATURE)
- Plan, owner words + answers, phases: `.claude/GRID_UNIFY_PLAN.md` (read it first). Phases: 1 selection engine (SHIPPED),
  1b right-click everywhere in admin, 2 booking-clash check (skip booked nights), 3 Extend.
- Phase 1 shipped: Liwonde 0bb811a, Rosalyn's port 790990b (both pushed): new admin/js/admin-context-menu.js (`RhContextMenu.open/register`),
  admin/js/room-timeline-select.js, test scripts/tests/grid_select_test.mjs 13/0. Calendar: rectangle drag across rooms,
  Shift/Ctrl, room name, day head; right-click New booking / Block / Unblock (preview_block + grid_apply) / New maintenance
  job. HK + Maintenance timelines: multi-select bars -> list bulk actions; picked days -> add form prefilled (one POST
  per room/day, form read once). Lists: Shift range + right-click rows. No save handler / SQL changed.
  Tests: calendar_timeline 50/0, calendar_timeline_actions 55/0, ops_timeline 114/0, blocked_dates_grid 62/0, block_rooms 42/0.
  QA FAIL (multi-add re-read live form) -> fixed inline -> pages load headless.
- NOT done in Phase 1: Blocked Dates still on its own grid (blocked-dates-grid.js; moving it breaks blocked_dates_grid
  test's markup checks) - with menu + bulk bar added.
- ASSUMPTIONS: new maintenance jobs 08:00-17:00; max 60 HK tasks / 30 maintenance rooms per add; a mouse click on a room
  name or calendar block now selects (Enter opens the link).
- LATER: maintenance multi-room add uses earliest..latest picked day for every room; a retry after a lost reply can add
  a duplicate task/job.
- SHIPPED 2026-11-23: Phase 1b Liwonde 3addb8a / Rosalyn's 6464350; Blocked Dates patch Liwonde 53c7d34 / Rosalyn's
  2bd5082 (both QA'd, fixes applied: copy toast escaped, class-disabled actions skipped, tap/keyboard picks store records).
- Phase 2 SHIPPED 2026-11-23 per plan + Fable check + owner answers 2026-11-23 (in GRID_UNIFY_PLAN.md: maintenance refuse;
  type block = block only the free rooms; generated jobs block_room=0 on booked nights; booking drag YES in Phase 3).
  2026-11-23 Phase 2 SHIPPED (see commit; QA FAIL on XSS -> fixed: clash messages shown as text) : new includes/room-night-clash.php (helper, locks,
  maintenance check) + includes/block-save.php (one block engine; API create/move; rh_block_room_ids moved here);
  admin/blocked-dates.php (grid_apply/preview/block_date/block_multiple via engine, Block Date Range confirm),
  blocked-dates-grid.js + room-timeline.js confirm text, api/blocked-dates.php, admin/room-maintenance.php (add/update
  refuse; auto + bulk block_room=0 under locks), includes/maintenance-recurring.php, api/maintenance-schedules.php,
  includes/ai/ai-actions.php (card preview/verify). New tests room_night_clash_test, block_clash_test,
  maintenance_clash_test, block_booking_race (+ clash_test_lib, race child), registered in run_tests.php.
- Full suite 2026-11-23 (run twice, killed at 42/73 and 46/73 by low laptop memory; 47-73 run one by one): every grid,
  block, booking and clash suite green (clash 26/0, block clash 22/0, maintenance clash 17/0, race 17/0, Blocked Dates
  grid 63/0, Block Rooms 42/0). Failures OUTSIDE Phase 2 code, not caused by it: import_export_test 100/1 (xlsx
  endpoint returns the HTML page), stock_count_bigform 28/1 (420-line draft saves as approved, not submitted),
  stock_count_approval 124/1 + Menu portions Recipes check (both pre-existing), documents_flow flaky (97/6 once, clean
  once). Owner told; not fixed here.
- 2026-11-23 SHIPPED Phase 2b (stay date/room changes lock + clear clash refusal): Liwonde 77536cd / Rosalyn's dca1e25
  (ported as a patch: Rosalyn's RBH prefix + its database.php drift kept). Critical review PASS.
- 2026-11-23 SHIPPED test fixes (owner: "all of them fixed, no compromise"): the six failing suites above fixed at the
  cause, no check weakened, no app code changed: Liwonde 10bc317 / Rosalyn's 69f6f31. documents_flow: one silent child
  death never captured; now retried once on lock/connection errors only, real errors printed (3 runs 103/0).
  Untracked test PDFs in invoices/ deleted 2026-11-23 (owner). LATER: test runs still write PDFs there (numbers reused after rollback).
- 2026-11-23 SHIPPED Phase 3 Liwonde f7fa487 / Rosalyn's a7a8340 (Extend for blocks / maintenance / housekeeping, Excel-style end handles, booking drag ->
  Change dates prefilled): critical review PASS; extend_test 35/0 + grid, clash, stay-change, timeline suites green;
  headless check of the four pages at 1280 + 390. Blocked Dates: no handle on a month's last night (run may continue
  into next month; Extend... instead). LATER (review): HK preview over-counts two tasks sharing room+type;
  maintenance/HK bulk + reconcile lock in another order (deadlock rolls back safely); Extend has no undo.
- NEXT after Phase 2 (owner OK 2026-11-23): Phase 2b, stay date changes lock + clear clash message (GRID_UNIFY_PLAN.md). Was Fable LATER:
  needs owner OK). Room picker change listener not guarded against double add (harmless).
- 2026-11-23 (ClaudeWorkOne) Phase 1b BUILT, uncommitted, in QA: admin/includes/admin-footer.php loads admin-context-menu.js
  + new admin/js/admin-row-context.js on every admin page (right-click a list row = that row's own buttons / More items,
  Open, Open in new tab, Copy reference; native menu on inputs, text, links, Shift, [data-no-ctx]; POS/KDS excluded).
- 2026-11-23 Blocked Dates PATCH (owner reports), uncommitted, in QA: blocked-dates-grid.js keeps picks across months
  (SPA month swaps; sessionStorage + rh:content-updated); Block Date Range picker rebuilt after SPA swaps (admin-spa
  _cleanup removed its calendar); Block Date Range Individual level = tick several rooms -> preview_block + grid_apply.
  Weekends "not selectable": not reproduced on Blocked Dates or the Calendar timeline (asked owner which screen).
  Tests: blocked_dates_grid 62/0, block_rooms 42/0, grid_select 13/0, headless checks.
- Phase 2+3 spec written (.claude/GRID_UNIFY_PLAN.md "Phase 2+3 build spec"), incl. owner's drag-to-extend/shorten
  (2026-11-23). Fable spec check running; then owner answers to its open questions, then Opus build Phase 2.

## Shipped 2026-11-23: import reads "Shots per bottle" / "Bottle size (ml)" / "Shot size" columns (owner FEATURE, #8)
- Owner 2026-11-23: "Is the stock import able to recognise a column that mentions how many shots in a bottle or glasses in a casket ...?" -> "yes". Spec: `.claude/IMPORT_PLAN.md` last section.
- Files: includes/import/{default-aliases (seed v11), mapper, measure, validator, analyze, resolve, review, drinks, commit-stock}.php, admin/includes/import-schema.php (kind ask_phrase), admin/js/data-import.js, tests (engine, import_stock_child, import_drink_prices_child).
- Tests 2026-11-23: engine 655/0, import_stock 265/0, drink prices 162/0, stock_count_measures 103/0. Job 2402 unchanged (read-only snapshot before/after).
- QA fix round 2026-11-23 (done, uncommitted): open count lines = item keeps its measure even for a corrected N (resolve + commit re-check);
  typed step 3 sizes vs file values = blocking measure-file question (import_file_typed_check); "Volume (ml)"/"Size ml" etc. asked (seed v12).
  Tests: engine 661/0, import_stock 267/0, drink prices 162/0, stock_count_measures 103/0, stock_measure_bulk 65/0. Job 2402 untouched.
- QA re-check: 1 more blocker ("use the file's size" not followed on the menu side) -> fixed inline in drinks.php
  ($fileUnitOverTyped / $fileMeasOverTyped first in the shot/glass/bottle order) -> PASS. engine 661/0 (one run showed
  660/1, not reproduced: possible flaky check), drink prices 162/0.
- LATER: not-in-a-measure path (resolve.php:2052): a typed size beats the file size without a question (menu box shows
  "file", so visible); a confirmed "No of shots" is asked again next import (#10 learning covers it); N column not saved
  on rows counted in bottles; import messages say "Stock Items" where the hotel says "Ingredients" (fix in #11).

## Shipped 2026-11-22: import saves the count measure (N shots/glasses per bottle) on the item, no guess (owner FEATURE)
- Owner: "Yes as long as the counts are set like shot, double, glass whatever update these accordingly. No guess work".
- At commit, a row converted from a measure saves big = stock unit, small = measure word, exact N via rh_stock_measure_save
  (same transaction) only when N is certain: answered N, or unit ml / measure ml with both from the file, typed in step 3 or
  the Drink measures list entry of that exact name, exact at 4 dp (750/25, 750/60 yes; 750/35, 1000/30 no + reason).
  Two measures on one item = none. Existing different measure = blocking Keep / Change (not applied while open count lines
  use the old measure). Step 4 lists the items + reasons. Replaces the ASSUMPTION "typed shot size is import-only".
- QA FAIL (from a768fe6): served-by "Shot 30", "Tot 35", "Double", "Glass of wine", "Wine glass" skipped the bottles-or-shots
  question (count read as bottles silently) -> fixed (measure.php import_count_in_read/_exact, pack-rule.php:128 measure
  word without unit word = portion, resolve.php matched item unit) -> PASS. Tests engine 634/0, import_stock 264/0,
  drink_prices 155/0, stock_count_measures 103/0, stock_qty_words 39/0, stock_to_menu 43/0.
- Liwonde e9bb924, port dbd8e05. (6)+(7): Liwonde 75ef370, port 28288a2 (database.php hunk via git apply). All pushed.
- Job 2402: owner answered its 3 questions 2026-11-22 13:49-13:53 (159 ready, can_confirm; saves 25 shots a bottle).
- LATER (do with the next import build): "Shot 30" ignores the 30 and uses the 25 ml list shot (should not save / should
  ask when the cell shows a different number); question wording "glass of wines".
- OWNER RULE 2026-11-23: never touch Rosalyn's DB / run migrations until the owner confirms the exact list ("just about
  stocks"). Approved: stock_counts.import_job_id (auto on first confirmed import); import_aliases seed top-ups v10-v12
  (new word rows only, auto on first Import page use; owner "ok" 2026-11-23). NEVER run admin/migrations there.
  Flow (owner 2026-11-23): build, review, commit to Liwonde first; THEN list the Rosalyn's DB changes at the end; port
  after the owner's yes. Don't ask mid-build.
- (11) SHIPPED 2026-11-23: Liwonde 512c702, Rosalyn's port 28b55bf (owner OK for the column). Was: Rosalyn's port WAITING for owner OK on the runtime column
  stock_counts.import_job_id (nullable, added by code on first use like is_opening; owner asked 2026-11-23; if no, link via
  the "Import #id" in the count notes instead). New includes/import/diff-report.php; report frozen in
  import_jobs.result_json.diff_report (built in the commit transaction, try/catch: a report failure never stops a valid
  import); shown on stock-count.php (opening counts from an import: chips, pager, Excel/CSV download, formula-safe) + import
  result page; import messages use import_stock_page_name() ("Ingredients" / "Stock Items"). Tests import_diff_report 32/0,
  engine 661/0, import_stock 267/0, drink prices 162/0, count measures 103/0; stock_count_approval 124/1 pre-existing (no
  stock items on Liwonde). QA PASS. LATER: cap rows while adding (5000); two imports adding the column at once -> second
  count unlinked; delete.php:12 comment stale; CSV negatives get a leading apostrophe.
- (11) ASKED (owner 2026-11-23: "when the import is submitted for review it reports any discrepancies with the file that
  was uploaded ... in summary"): at commit build + store a "Differences from the uploaded file" report (counts converted,
  costs worked out from packs, fixes, rows left out / not in opening count + why, value check file vs imported totals,
  answers given, typed vs file menu prices, count measures saved/kept/not saved); shown on the opening count (stock-count.php,
  for the approver) and the import result page, with a spreadsheet download. Frozen at commit.
- (10) SHIPPED 2026-11-23: Liwonde daddac2, Rosalyn's port 6ecd0a1 (owner yes). (QA FAIL: learned answer lost to another field's seed alias; grammar question dropped
  pre-#10 options -> fixed (person's latest answer wins; any other field match keeps its exact pre-#10 question; 2719-heading
  before/after check) -> PASS. Tests engine 680/0, ai_import_suggest 43/0, import_stock 277/0, drink prices 162/0, diff
  report 32/0. Rosalyn's DB changes: NONE (rows only when a person answers). LATER: col_use no lock; no screen to undo a
  learned "not this". Was (spec IMPORT_PLAN.md A92-A94): includes/import/header-grammar.php (new) +
  mapper/structure/analyze/review/review-view/drinks/measure.php, admin/includes/import-schema.php, includes/ai/ai-import-suggest.php,
  admin/data-import.php (POST col_use), admin/js/data-import.js. No new table/column, no seed bump; new import_aliases kind
  `field_not` / `field_yes` written only by a person's answer. Tests engine 677/0, ai_import_suggest 43/0, import_stock 275/0,
  drink prices 162/0, diff report 32/0; headless step 2 at 1400/400 PASS (test job + learned rows removed). Job 2402 untouched.
- (10) was QUEUED after (8) QA (owner 2026-11-23: "extend it to read other wordings too ... not always hard coded ... with AI it
  can also help ... works perfectly without it"): header grammar "[measure word] per/in/to/from/each [container word]" from
  the hotel's own word lists + abbreviations (btl, gls, cskt, ctn); size/volume/capacity/ml/cl -> unit_ml / measure_ml; no
  container named = ask. A person's column answer saved as a hotel-learned alias (import_aliases is_hotel_learned=1).
  Assistant: unread headers + sample values in the SAME single call -> "Suggested: ..." + "Use this" (validated, never auto).
  Value cross-checks unchanged whatever the source.
- (9) SHIPPED 2026-11-23 (owner "Build #2"): Stock Items bulk "Set count measure" (tick / Tick all shown -> bigger unit,
  measure, N, kept in; preview set / same / different (only with "Change these too") / skipped + reason; rh_stock_measure_save
  per item; refused while open count lines use the old measure; stale preview refused). Why: Rosalyn's 2026-11-21 import
  items have no measure, so counts show "2.28 bottle". New admin/includes/stock-measure-bulk-lib.php +
  admin/js/stock-ingredients-measure-bulk.js. Tests stock_measure_bulk 65/0, stock_count_measures 103/0, stock_qty_words
  39/0, stock_list_filters 85/0. QA PASS. LATER: skip/warn kg/litre units; warn when typed bottle ml != item volume_ml;
  report Apply failures apart from skips.
- (8) SHIPPED (see the Shipped 2026-11-23 #8 block above): import reads columns "Shots per bottle" / "Glasses per casket" / "Tots per bottle"
  (measures per unit, word from header), "Bottle/Casket size (ml)", "Shot size / ml per shot" as certain sources; a row whose
  own cells disagree = ask; "No of shots" must not map to row_no. Fold in LATER "Shot 30" (cell number != list ml = ask).
- (6)+(7) SHIPPED 2026-11-23: (6) rh_stock_qty_words adds the total in smalls once a whole big shows ("2 bottles + 7 shots
  (67 shots)", "short by 1 bottle + 3 shots (33 shots)"), stock-count.php JS the same; (7) "Show profit without VAT" tick box
  on import step 3 + stock-to-menu (vat_mode inclusive: round(price/(1+r),2) - cost; exclusive = same; off = no box; server
  computes; Stock permission gate kept on stock-to-menu). Profit itself stays VAT-included (owner). Tests stock_qty_words
  39/0, count_measures 103/0, import_drink_prices 160/0, stock_to_menu 47/0, import_engine 634/0. QA PASS.
  LATER: dashboard.php:1593 low-stock detail shows brackets inside brackets; import step 3 sends profit_ex_vat to every
  data_import user (as cost/profit there already).
- QUEUED (owner 2026-11-22): (6) stock screens show both units "2 bottles + 7 shots (57 shots)" via rh_stock_qty_words +
  stock-count.php live Difference JS; (7) profit stays VAT-inclusive (owner decision) + a "Show profit without VAT" tick box
  on import step 3 menu table and admin/stock-to-menu.php (price / (1 + hotel VAT rate) - cost; display only).

## Shipped 2026-11-22: import asks "bottles or shots?" for measure-served drinks + "57 shots = 2 bottles + 7 shots" (owner)
- Owner: "how come it just says 2.8 bottles everywhere instead of saying how many shots". Owner answers 2026-11-22: in their
  bar sheet the count for shot drinks is SHOTS LEFT; show "57 shots = 2 bottles + 7 shots" (exact on hover).
- FOUND: job 2402 (Bar Nov) read premier brandy 57 as 57 BOTTLES (Stock Value = 57 x 4,000 shot price) -> opening count
  25x too high for 66 shot rows. Job 2402 now shows 3 blocking questions (60 shot, 9 casket, 1 carton), suggestion = shots.
- One BLOCKING question per served-by group when the count cell is a plain number (row override: POST count_row); Stock
  Value evidence = a suggestion needing a click; "in shots" reuses Step U2 d (count measure, else step 3 shot/bottle ml,
  else ask); "66 shots" cells / measure headings skip it (no double conversion); costs never divided by N.
- Tests engine 613/0, drink_prices 150/0, import_stock 259/0, ai_import_suggest 38/0, stock_to_menu 43/0. QA (opus) PASS.
- Liwonde a768fe6, Rosalyn's port 80f4a9b (both pushed; owner deploys).
- LATER: unanswered rows still show "57" next to the question; N from step 3 sizes is NOT saved as the item's count measure
  (after import, stock screens show e.g. "2.28 bottles", not bottles + shots); count_row has no row lock (as answer).

## Shipped 2026-11-22: admin phone + tablet polish (owner PATCH, CSS + class tagging only)
- Owner: Ingredients and other pages not mobile friendly; cards should be compact like the Bookings rows; ticks and buttons
  uniform; NOTHING changes above 1024px.
- Round 2 (ClaudeWorkTwo): 32b8840 / port 1f483b2 (details in the commit message + GUIDES_AI_SYNC.md). LATER: Bookings
  rows ~713px at 820 tablet; row-button names go stale if a page swaps button text in place; Credit Notes sort by Issued
  has no visible column on phones.
- admin/js/admin-mobile.js: markCompactRows() tags card-mode rows only (tick / primary / status / money / actions cells,
  data-short labels, icon-button counts). Nothing is moved or renamed. Guarded to <=1024px. Opt-outs: table class
  bookings-table, receipts-table, rh-own-rows, and tables inside .pos-acct-close-form / .pos-acct-sales-wrap (own rules).
  Page opt-in classes on a td: rh-ph-primary, rh-ph-status, rh-ph-meta, rh-ph-money, rh-ph-skip.
- admin/css/admin-uniform.css (appended, every rule inside max-width media): compact list rows for table.mobile-enhanced
  (tick left, primary bold + status, muted "Label value" lines, up to 3 icon buttons on line 1 else a wrapping row,
  "Tick all" slim row from the thead), 18px ticks (bulk tick keeps its 44px area), 40px / 8px toolbars and header buttons,
  chip/tab rows scroll in one row at <=640px, stock-toolbar grid.
- housekeeping.css, room-maintenance.css: room cards as compact rows (Quick Assign + Guest has left side by side).
- stock-ingredients.php: Yield % cell tagged rh-ph-skip (hidden in card rows only; value stays in Edit).
- Test targets: Liwonde only. Test DB has no ingredients: checked with a client-side fixture (no DB writes).
- Ship gate: php -l + node --check, independent review PASS, browser check 68 pages at 390 (no sideways scroll, 0 console
  errors), 20 at 820, 8 at 1440 (desktop unchanged). Commit 90602cb, port c0b6177 (Rosalyn's).
- LATER: side-row icon buttons hide their text label on touch (name only in title); title attrs stay after resize to
  desktop; an icon-only value cell is treated as empty (product-management-mode.php:429); Individual Rooms / Invoices /
  Stock Clear rows still ~190px; some 44px buttons remain (Bookings, Backup, Restaurant Tables); Menu Management rows
  unseen with data. Untracked invoices/*.pdf and the deleted Database/*.txt are not part of this.

## Shipped 2026-11-22: global admin UI - uniform controls + toolbar rhythm (owner FEATURE, CSS/markup only)
- Owner: "fix the UI of these sort of things globally, the spacing ... make sure its uniform" (Blocked Dates month bar,
  raw "Room type" select).
- admin/css/admin-components.css: :where() base for bare selects/inputs/textareas in .content/.admin-content/modals
  (40px, 44px touch/narrow, one radius/border/font, custom arrow, native arrow off) + .rh-toolbar / .rh-toolbar-notes;
  old .filter-bar/.toolbar/.search-bar/.table-toolbar rows on the same spec. blocked-dates.php (classes only) + .css,
  bookings.css (filter bar), room-timeline.css (label + select on one line). POS/KDS do not load this sheet.
- Checked headless 1400/400 (+1920 Blocked Dates): blocked-dates, payments, calendar, ingredients, stock dashboard/reports,
  accounting, menu-management, bookings. qa-quick: FAIL (double arrow where a sheet sets appearance:auto) -> fixed -> checked.
- LATER: table-cell selects/inputs now 34px; Payments KPI cards very tall (was so before); ai-settings.php not checked
  (blank in headless run); room-calendar day width varies per load (JS, not this change).
- Liwonde dc4ce68, Rosalyn's port 5308220 (both pushed; owner deploys).

## Shipped 2026-11-22: Import step 3 "Put items on the menu" - drinks AND food, grouped, editable price (owner FEATURE)
- Owner: "easily add all imported stock to the menu with the price ... math logic correct ... steps as simple ... especially
  step 3"; then "Not just drinks, any sort of menu item ... harnesses AI if available but works perfectly without it ... 100%
  confident ... group accordingly". Owner chose: ONE table in step 3, nothing ticked by default, "Put all N on the menu".
- Step 3 section groups rows by the file category; group Menu (Drinks/Food) + menu category; row: Sold as, sizes (drinks),
  cost of one, selling price (editable, drinks_set mode price, parsed like stock-to-menu), profit, status, On menu tick;
  Put all / Put all in this group. Food rows go to the food menu + till with stock link. Step 4 = one summary line.
- Certain signals only (already on a menu > file/stock category match > drink-only measures); unclear = "Choose: Drinks or
  Food", untickable until answered; an item already on a menu never changes menu (row answer for the other menu = blocked).
  Ambiguous words (can, bottle, water...) decide nothing. Assistant: menu + category suggested inside the same single call,
  shown "Suggested ...", applied only by "Use this"; the served-by reading is a suggestion only.
- Math: profit = price - cost rounded 2 dp (as stock-to-menu); money shown 2 dp (2,521 vs 2,521.01 were both right).
  REPORTED, not changed: profit compares a VAT-inclusive price with cost (owner decides).
- Tests import_drink_prices 142/0, ai_import_suggest 38/0, import_engine 592/0, stock_to_menu 43/0. QA (opus): FAIL on 1
  blocker (group answer beat "already on a menu") -> fixed in the one fix round -> PASS. Job 2402 still loads (152 rows).
- Liwonde 78f3802, Rosalyn's port 275badb (both pushed; owner deploys). Alias seed v10 (menu words) applies itself.
- LATER: kilo food item with blank served-by takes 1 kg per sale; seed v10 alias words; supplies with a served-by cell but
  no price show "Needs price".

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
- Gaps fixed 2026-11-22 (owner "fix gaps and check block logic again and individual room logic"): a past night is counted
  once per row (also when a joined room is posted as both members); the unblock "none were blocked" message now names
  skipped past nights; a half-blocked joined room (old data) counts as newly blocked when completed, not "already blocked".
  Re-audit: room-type / all-rooms blocks stop type sales (checkRoomAvailability), room blocks take one room off the
  type count and stop that room (checkIndividualRoomAvailability), stay changes re-check both (rh_validate_booking_stay_change),
  same-type room moves rightly ignore type blocks (the booking already holds the type). Tests: blocked_dates_grid.php 43/43
  (new "Availability follows the blocks" section, rolled back), block_rooms 42/42. QA PASS. Liwonde 8b60ae5, Rosalyn's port c3fccbe.
- Public booking calendar 2026-11-22 (owner "fix the calendar so fully blocked types show greyed out and the other issue"):
  booking.php now also greys a night when every room of a type is off (each blocked, Maintenance / Out of Order, or a
  maintenance window; joined type = every pair has a room off), via new includes/blocked-nights.php. js/booking.js check-out
  picker: check-out ON a blocked date allowed (night before counts), dates crossing a blocked night greyed once check-in is
  picked. "Other issue" read as the test that could skip: the availability tests now search for free nights. Tests 50/50,
  browser check passed (seeded + removed 16 ZZ blocks on Liwonde), QA PASS. Liwonde 7f33c58, Rosalyn's port 959faa4.
  LATER: clearing check-in does not redraw the check-out picker; getDateRange (pre-existing) uses toISOString.
- Messages name rooms + dates 2026-11-22 (owner "when blocking dont say blocked rows, specify the room names and the date"):
  calendar summary, Block/Unblock confirm and the result now read "Blocked 6 night(s): Villa 2 on 3 to 5 Jan 2028; ..."
  (back-to-back nights joined, "Room 101" for numbered rooms); List-view form messages name the room/type and date; "row"
  wording removed from legend/help/tooltips. api/blocked-dates.php (key API) messages unchanged. Tests 53/53, block_rooms
  42/42, browser check passed. QA PASS. Liwonde c319a62, Rosalyn's port c10fd66.
- Logs + booking-aware logic 2026-11-22 (owner "make sure there is proper logic and logs for these things as it works
  directly with bookings"): new includes/block-log.php. Every block / unblock (calendar, List-view forms, bulk unblock,
  integration API) writes one Staff activity row ('Blocked rooms' / 'Unblocked rooms': who, rooms + nights, type, reason,
  bookings already on those nights, "via the integration API (client)") and a note on each affected booking's History
  ("Room blocked on a night of this stay" / "Block removed ..."). Calendar saves log inside their transaction. Block now
  asks the server first (preview_block, read only) and the confirm lists the bookings that will NOT be moved. API PUT
  (delete + re-create) is one transaction now (a failed re-create used to lose the old block). Tests 62/62, block_rooms
  42/42, booking_history 88/88, system_logs_activity 116/116, browser check passed.
  QA: FAIL on 1 blocker (API date-list unblock read type blocks as the room's own -> false log/note), fixed.
  Liwonde 8593d48, Rosalyn's port 25eb701. LATER: the API still blocks one member of a joined pair on its own (the
  screen blocks both); a same-date API PUT notes the booking twice; generic page_action row still written too.

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
