# Housekeeping + Room Maintenance layout plan (READY, NOT STARTED)

Any session/profile can pick this up. Read this file only; every fact needed is here. Label: PATCH (owner-reported
fault: "messy, unclear, not uniform"), with owner-approved answers below. Liwonde first, then port to Rosalyn's.

## 0. Start condition (owner 2026-11-24) - check FIRST, do not start until all three are true
1. `node C:/Users/john-paul.chirwa/OneDrive/MSP/AI-Setup/scripts/board.mjs show` -> ClaudeWorkTwo is `free`
   (its claim was: Bookings page overhaul + calendar grid click/selection PATCH).
2. `git status` -> none of ClaudeWorkTwo's files modified/untracked (admin/bookings.php, admin/css/bookings.css,
   admin/js/room-timeline*.js, admin/js/blocked-dates-grid.js, admin/calendar.php, admin/includes/calendar-*.php,
   admin/includes/bookings-*.php, admin/api/bookings-saved-filters.php, scripts/tests/bookings_*, grid_select_test.mjs,
   view_only.php).
3. `git fetch && git status` -> local main == origin/main (their work is pushed).
If any is false: stop, tell the owner, do nothing.
Then claim: `board.mjs set Liwonde_Sun_Hotel_2026 "HK + Maintenance layout (HK_MAINT_LAYOUT_PLAN.md)" "<files of the phase>"`.

## 1. Owner words + answers
- 2026-11-24: "The logic and UI for assigning room housekeeping tasks looks very messy. Cards taking up too much space
  and not too clear what's happening in housekeeping and room maintenance and it's not uniform to our amazing flow."
  "Our amazing flow" = the Calendar timeline grid (GRID_UNIFY_PLAN.md: select on grid -> docked side panel).
- Q1 Assigning: KEEP a per-row Assign dropdown in the list, as well as grid select + side panel.
- Q2 Maintenance list: YES, only rooms that actually need work (not every occupied room).
- Q3 Calendar's Housekeeping / Maintenance tabs: YES, same layout as the full pages.

## 2. What is wrong today (headless check 1440 wide, 2026-11-24)
- housekeeping.php: 5 KPI cards wrap to 2 rows (Unassigned orphaned). Then "Occupied Rooms" = big cards (~170px,
  full-width Quick Assign + Guest has left buttons, "Click to select"), 10 per page. The grid only starts ~1,800px
  down. Then Staff Workload table, All Assignments table, Add form. 3 different assign paths, 3 places showing tasks.
- room-maintenance.php: "Rooms Needing Maintenance (15)" lists every OCCUPIED room (all tagged "Occupied") while Open
  jobs = 0, because getRoomsNeedingMaintenance() includes `b.status = 'checked-in'`. Then a Staff Workload table of
  zeros, then the grid.
- calendar.php?tab=housekeeping|maintenance: a 3rd version (KPI cards + a wide 8-column table, no grid).

## 3. Target layout (both pages, top to bottom, same shape as Calendar)
1. Header: title + ONE primary button ("+ Add Assignment" / "+ Add Maintenance"); the secondary button
   ("Create Checkout Cleanup" / "Create Tasks for Rooms") becomes a plain secondary button or a menu item beside it.
2. KPI strip on ONE row (5 HK / 4 Maint), compact (count + label + "?" helper, no big icon tiles, no "Show these
   rooms >" line; whole chip clickable). Click still filters grid + list exactly as today (data-ops-card stays).
3. Grid toolbar + ops timeline grid (`includes/ops-timeline-view.php`) directly under the strip: in the first screen.
   Select bars/cells -> existing side panel / add form prefilled (no change to the JS).
4. "Needs action" list replaces the room cards: a dense table, 10/25/50/All picker (RULES.md), sticky bulk bar.
   - HK columns: [tick] | Room | Status pill | Guest / checkout | Assigned to | Assign (dropdown, per row, posts the
     same action Quick Assign posts today) | "Guest has left" (small button, same guest_left form).
   - Maint columns: [tick] | Room | Room status (Maintenance / Out of order / open job) | Open job | Assigned |
     Assign (dropdown, per row) | Open.
   - Bulk bar keeps the current bulk-assign POST (staff + priority [+ type for maint] + Assign selected).
   - Every column header gets the "?" helper (RULES.md UI).
5. Staff Workload -> one slim chip row under the list: "admin 0 open · test.staff 0 open" (+ high-priority count if
   >0). Same data, no table.
6. The "All Assignments" / "All Maintenance Tasks" table stays ONE place: below the grid, using the existing table
   (paginated, filters as now). Do not duplicate it elsewhere. (If the grid's List toggle already shows the same rows,
   keep the table and remove nothing that tests rely on; note it as LATER.)
7. Status words + colours identical on KPI strip, grid bars and list pills (reuse existing pill classes; no new
   colours; tokens from admin CSS).
8. Phone width: strip scrolls horizontally, list becomes stacked rows, no horizontal page scroll.
UI rules (RULES.md): no gradients/glows, solid surfaces, one accent, plain icons and words.

## 4. Phases, files, agents
### Phase A - full pages (no ClaudeWorkTwo files)
Files: admin/housekeeping.php, admin/room-maintenance.php, admin/css/housekeeping.css, admin/css/room-maintenance.css.
Do NOT touch: admin/js/room-timeline*.js, admin/includes/ops-timeline-*.php, admin/includes/calendar-*.php.
Where things are (line numbers as of 2026-11-24, re-grep before editing; never read these 155k files whole):
- housekeeping.php: getOccupiedRooms() :134 · guest_left POST :1215 · ops data :1574 · KPI strip :1642-1716
  (#hkStatsDashboard) · Occupied Rooms cards + bulk form + Quick Assign :1718-1805 · Staff Workload :1807-1838 ·
  grid include :1840 · All Assignments table :1842 (#hkAssignmentsCard) · confirm modal :2090 · hkNewTaskFor :2369.
- room-maintenance.php: getRoomsNeedingMaintenance() :223-257 · list build + ?status=out_of_service :1332-1356 ·
  KPI strip :1544-1600 (#rmStatsDashboard) · Rooms Needing Maintenance cards :1646-1704 · Staff Workload :1706-1731 ·
  grid include :1733 · Maintenance table :1741 (#maintenanceSchedulesContainer) · Maintenance Log :1869.
Steps:
A1 (Sonnet, ui-builder): HK page - steps 1-5,7,8 of section 3. Move the grid include up to just under the KPI strip.
   Keep every form action, field name, CSRF token, id used by JS/tests (bulkAssignForm, bulkAssignTo, bulkAssignBtn,
   hkStatsDashboard, hkAssignmentsCard, data-ops-*); markup/layout only.
A2 (Opus, dept-rooms-lead or builder-critical): Maintenance list logic (Q2). In getRoomsNeedingMaintenance() list only
   rooms with ir.status IN ('maintenance','out_of_order') OR an open room_maintenance_schedules job (status NOT IN
   completed/cancelled/verified). Drop the `b.status = 'checked-in'` arm; keep guest name as info when the room is
   occupied. Rename heading to "Rooms needing work (N)"; empty state "No rooms need work right now." Keep the
   ?status=out_of_service filter + its Dashboard-count note working.
A3 (Sonnet, ui-builder): Maintenance page layout - steps 1-5,7,8, same as A1.
Review: qa-auditor (Opus) on the whole diff (A2 is decision logic). One fix round max.
Tests (only these): php -l on the 2 PHP files; php scripts/tests/ops_timeline_test.php;
php scripts/tests/housekeeping_guest_left.php; php scripts/tests/rooms_housekeeping_12a.php;
php scripts/tests/maintenance_clash_test.php; php scripts/tests/extend_test.php (uses the HK/maint grids).
Browser check: `php -S 127.0.0.1:8000 -t . scripts/tests/dev-router.php` + `node scripts/tests/browser_check.mjs
<steps.json>` (set CDP_PORT, nav `{base}/__devlogin?to=housekeeping.php`, `after` >= 20000: remote DB is slow and the
loader covers the page before that). Check 1440x900 (grid visible in first screen, KPI one row), 390x844 (no page
side-scroll), per-row Assign dropdown present, bulk bar works on tick. READ-ONLY: never submit an assign/guest-left
form against the DB in the browser check (Liwonde DB is shared with live-style data; use tests for write paths).
Stop the php server after.
Commit: Phase A one commit, files by name. Message: "Housekeeping + Maintenance: grid first, compact list with per-row
Assign, maintenance list shows only rooms needing work".

### Phase B - Calendar tabs (needs ClaudeWorkTwo's calendar files free; check board again)
Files: admin/includes/calendar-timeline-view.php (HK tab :185-260 renders $calHk rows; maint tab after it),
admin/includes/calendar-data.php (rh_cal_hk_tab :1060, rh_cal_maint_tab :1096), admin/css/calendar.css.
B1 (Sonnet, ui-builder; Opus review if calendar-data.php changes): same KPI strip + same compact list (with per-row
Assign dropdown posting to housekeeping.php / room-maintenance.php actions, CSRF token) + "Open full page" link. If the
tabs should also show the grid, reuse includes/ops-timeline-view.php exactly as the full pages do.
Tests: php scripts/tests/calendar_timeline_test.php, calendar_timeline_actions_test.php, ops_timeline_test.php,
browser check calendar.php?tab=housekeeping and ?tab=maintenance at 1440 + 390.
Commit separately: "Calendar HK/Maintenance tabs match the full pages".

## 5. After each phase ships
- Port to ../Rosalyns-hotel-2026 (standing OK): copy the same files, `php -l`, commit "Port of Liwonde <hash>".
  No tests at Rosalyn's (LIVE; the guard refuses them).
- Update STATUS.md (one shipped line + hashes), .claude/GUIDES_AI_SYNC.md Pending (housekeeping + maintenance guides,
  Manager Guide "Housekeeping & maintenance" screenshots/wording), and Showcase SYNC QUEUE (screen changed).
  Do NOT run the guides sync (owner prompts it).
- `board.mjs clear`. Mark this file "SHIPPED <date> <hashes>" at the top.

## 6. LATER (not in scope)
- Merging the assignments table into the grid's List view (only if the owner asks).
- Any change to the grid JS or the side panel.
