# STATUS: Liwonde Sun Hotel 2026 (hotel PMS / HMS)

**Read this first.** Any agent (Claude, Cline, another provider) should be able to continue from this file alone. Keep it
current during work. Last updated: **2026-11-11**.

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

## Shipped 2026-11-21: POS drink tiles show the serving and other servings' prices (owner FEATURE)
- Owner: "show whether it's a shot or glass for drinks and how much, and in small how much the smaller item is" + "price
  of the smaller serving and also price of the bottle". Bar / coffee-bar tiles whose stock link is ONE stock item show
  "Shot · 25 ml" / "Bottle · 750 ml" / "Can · 330 ml" under the name and, in small under the price, the menu price of the
  same drink's other till items (real prices only, 86'd left out, max 3). Cart line shows the serving.
- `admin/includes/pos-drink-servings.php` (new), `admin/pos.php`, `admin/css/pos-overrides.css`. Liwonde 829c657,
  Rosalyn's 2274258. Test `scripts/tests/pos_drink_servings_test.php` 29/0; QA (opus) PASS; browser 1400 + 390.
- Data: tiles stay plain until a drink is linked to its stock item (one line) and Stock has the bottle size or the
  bottle/shot measure. Liwonde's menu has almost no drinks now, so it was checked with made-up drinks (removed).
- LATER: a bottle on room service only is not listed on the till tile.

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
