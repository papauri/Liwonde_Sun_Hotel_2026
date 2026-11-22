# GRID_UNIFY_PLAN: one grid for Calendar, Blocked Dates, Maintenance, Housekeeping (owner FEATURE 2026-11-23)

Owner words 2026-11-23: "We need the calendar, maintenance, blocked dates, housekeeping views and logic to be more uniform
and more robust regardless of whether in list view or calendar view, be able to select multiple selections, do bulk
actions, extend for more days or months, years, time and most importantly work well with current booking logic, no slip
ups. Also be able to do things like right click actions and select by dragging."

Owner answers 2026-11-23:
- Clash: a block or a room-closing maintenance job on a booked night = SKIP the booked nights, save the free ones, list
  the bookings (guest, ref, room, dates) so staff can move them. Never save over a booked night.
- Extend: blocks, maintenance jobs, housekeeping tasks only. Bookings keep their own Change dates (reprice) path.
- Housekeeping extend = repeat the task every day (or week) until a chosen date, same time of day.
- Rollout: shared engine first, then pages; each phase tested, shipped, ported on its own.
- Phase order (orchestrator ASSUMPTION 2026-11-23, safety): the clash check (was 3) ships BEFORE Extend (was 2), so no
  new save path can ever write over a booked night.

## State before (mapped 2026-11-23)
- Calendar `admin/calendar.php`: `admin/js/room-timeline.js` booking mode. Bar click = drawer, bar drag = move, end grip =
  dates, empty-cell drag = ONE room row only, popover "New booking" (`finishSel` ~1472). Blocks/maintenance drawn as
  `.tl-block` (~502-519), click = link only, not selectable (drag ignores `.tl-block` ~1782).
- Blocked Dates `admin/blocked-dates.php`: own grid `admin/js/blocked-dates-grid.js` (multi-select, rectangle drag,
  shift range, room-name = month, arrows+Space) -> `grid_apply` / `preview_block` (~449-746) under
  `rh_block_locked` (~408), logged by `rh_block_log_change`. List view `?view=list` with checkboxes + bulk Unblock.
- Maintenance `admin/room-maintenance.php` + Housekeeping `admin/housekeeping.php`: room-timeline.js modes via
  `admin/js/room-timeline-ops.js`; bar click = drawer (`stdActions` ~999, `postAction` ~661), count chip = `openMulti`.
  No timeline multi/drag select. Lists have checkboxes + bulk bars (`bulk_schedules`, `bulk_assignments`).
- No `contextmenu` handler anywhere in admin/. No Extend anywhere.
- Data: blocks are one row per night (`individual_room_blocked_dates`, `blocked_dates`; `rh_block_dates_in_range` caps
  366). Maintenance `room_maintenance_schedules.start_date/end_date` DATETIME, `block_room`. Housekeeping
  `housekeeping_assignments.due_date` DATE + optional `scheduled_time`, recurring columns exist.
- Bookings' own availability: `checkIndividualRoomAvailability` (config/database.php ~8498) sees bookings, individual
  blocks and block_room=1 maintenance. Blocks only WARN (`getBookingsOverlappingBlockDates` ~7216); maintenance and
  housekeeping saves never check bookings.

## Phase 1: one selection engine, same on every grid and list (UI + reuse of existing saves)
Goal: the same gestures and the same bulk bar / right-click menu on all four timelines and the three lists.
1. room-timeline.js, all modes: a cell selection set (room x night). Click = one cell; drag = rectangle across rows;
   Shift+click = rectangle from anchor; Ctrl/Cmd+click = add/remove; click a room name = its visible range; click a day
   head = that night for all visible rooms; Esc clears; arrows + Space/Shift for keyboard. Touch: tap toggles, long-press
   = menu, no drag. Booking-mode bar drag/grips keep working unchanged (bar gestures win over cell gestures).
2. Bars (ops modes): Ctrl/Cmd+click or Shift+click selects several jobs/tasks; selected bars get a clear outline.
3. Right-click (and the ContextMenu key / Shift+F10, long-press on touch) opens ONE shared menu `.tl-ctx` (reuse
   `.actions-more-menu` look + `rhPlaceActionMenu`) listing the mode's actions for the current selection (right-click
   outside the selection selects that cell/bar first). Same menu on List-view rows (acts on ticked rows, or the row).
4. One sticky bulk bar component used by timelines AND lists: "N nights in M rooms" / "N jobs selected", the actions,
   Clear. Lists: Shift+click on a checkbox ticks the range.
5. Actions per mode (Phase 1 only reuses EXISTING server actions; permission-gated exactly as today):
   - Calendar: New booking (only when the selection is one room, consecutive nights, all free); Block nights / Unblock
     nights (calls blocked-dates.php `preview_block` then `grid_apply`, same confirm text as Blocked Dates); New
     maintenance job (opens the existing add form prefilled with room(s) + dates). Block cells become selectable.
   - Blocked Dates: its Calendar view moves onto room-timeline.js (new mode `blocks`; rows All rooms / types / rooms as
     today). Block / Unblock exactly as `grid_apply` does now. `blocked-dates-grid.js` retired only when every
     behaviour in its header comment works in the new mode.
   - Maintenance timeline: selected bars = the list's bulk actions (`bulk_schedules`); selected cells = New job
     prefilled (rooms + From/To).
   - Housekeeping timeline: selected bars = the list's bulk actions (`bulk_assignments`); selected cells = New task
     prefilled (rooms + due date).
6. No change to any save handler, table, or booking rule in this phase.
Tests: calendar_timeline_test, calendar_timeline_actions_test, ops_timeline_test, blocked_dates_grid, block_rooms (all
must stay green); browser check 1920 / 1280 / 390 on the four pages (right-click, drag rectangle, bulk bar).

## Phase 1b: right-click everywhere in admin (owner 2026-11-23)
Owner: "Extend the right click gestures wherever we can globally to bring up several other options easier."
- Phase 1 builds the menu as an admin-wide component (`RhContextMenu`); 1b wires it across admin.
- ASSUMPTION (orchestrator 2026-11-23): right-click on any admin list row / record card shows THAT row's own actions,
  read from what the page already renders (row buttons, links, `.actions-more-menu` items), so permissions stay as the
  server drew them and no new server action exists. Plus safe extras where they apply: Open, Open in new tab, Copy
  reference/number. Native browser menu kept on inputs, text selection, and Shift+right-click. POS/KDS stations excluded
  (touch tills). One shared hook in the admin layout, page opt-out attribute `data-no-ctx`.
- UI-only tier: no DB tests; lint + review + browser check on a sample of list pages.

## Phase 2: one booking-clash check (logic, Opus, full suite)
- One helper in includes/ (e.g. `rh_room_nights_clash($pdo, $roomNights)` -> booked nights + the bookings) using the
  same rules as `checkIndividualRoomAvailability` (statuses, joined rooms, type-level blocks vs typed bookings).
- Used by: blocked-dates.php `grid_apply` / `block_date` / `block_multiple`, api/blocked-dates.php POST, maintenance
  add/update when `block_room = 1` (job saves only if no booked night; otherwise save is refused with the bookings
  listed, since a job is one From..To range; or split, decide in spec check), all inside the existing locks.
- Result text names rooms, nights and bookings ("Skipped Room 4 on 3-5 Jan: booked by J. Banda LSH-123").
- Fable spec check before build (RULES: before a bookings build). Opus build, qa-auditor, full run_tests.php.

## Phase 3: Extend
- Blocks: extend selected blocks by N days / weeks / months / years or to a date. Per-night rows; cap per action
  (ASSUMPTION to log: 3 years = 1096 nights per room; the 366 cap in `rh_block_dates_in_range` raised for this path
  only). Goes through the Phase 2 clash check.
- Maintenance: extend end date + time by N units or to a date+time; block_room jobs go through the clash check.
- Housekeeping: repeat until a date, daily or weekly, same `scheduled_time`; no duplicate task for the same room +
  day + type.
- Bulk: each action works on a multi-selection; one transaction, all or nothing per action, one log row.

## Status
- 2026-11-23: plan written, owner answers recorded. Phase 1 dispatched (dept-rooms-lead, Opus).
- 2026-11-23: Phase 1 SHIPPED Liwonde 0bb811a / Rosalyn's 790990b. Blocked Dates kept its own grid (+ menu + bulk bar). Next: Phase 1b.

## Phase 2+3 build spec (draft for Fable check)
Written 2026-11-23 (read-only investigation; nothing built). Line refs = Liwonde HEAD at writing.

### Facts found (cite before building)
- Availability rules (`config/database.php` 8498-8730 `checkIndividualRoomAvailability`): night model = a stay sleeps
  check_in .. check_out-1 (8487-8490). Statuses `getBookingStatusesThatBlockAvailability(true)` (2733) = pending,
  tentative, confirmed, checked-in; tentative with `tentative_expires_at < NOW()` excluded (8652). Checkout via
  `rh_effective_checkout_sql` (4836): an overdue checked-in guest holds the room through tomorrow. Room held by
  `b.individual_room_id` OR `booking_rooms` with `released_at IS NULL` (8640-8672). Maintenance blocks when
  `block_room=1`, status not completed/cancelled/verified, nights DATE(start) .. DATE(end - 1s) (8562-8569).
  Typed bookings (no room yet) are NOT seen by this function; they use up type capacity in
  `getRoomTypeNightlyAvailability` (5711; unassigned count 5866-5877, joined-type holds 5880-5915, clamps at 0, 5933).
  Type/all-rooms blocks (`blocked_dates`) are checked by `checkRoomAvailability` (5430), not per room.
- `getBookingsOverlappingBlockDates` (7216): same statuses/checkout/booking_rooms; its type branch also catches joined
  bookings holding a room of that type; it ignores capacity (typed bookings on other rooms of the type).
- Today blocks only WARN: `preview_block` (blocked-dates.php 458-477), the save message "Note: N booking(s) already
  sleep..." (351-355), and `rh_block_overlap_note` (418) on `block_date`/`block_multiple`. Maintenance add/update
  (room-maintenance.php 539-741, 742-946) and api/maintenance-schedules.php never look at bookings.
- Locks: blocks use the named lock `rh_block_locked` -> `rh_with_create_lock('blocked_dates')` (blocked-dates.php 408,
  includes/form-validation.php 128). Booking creation takes ROW locks: `rh_related_room_type_ids` before BEGIN, then
  `rh_lock_room_types` (database.php 4136, 4185; admin/create-booking.php 779-781, booking.php 537, api/bookings.php
  303); assignment adds `individual_rooms ... FOR UPDATE` (5071, 8940) + `rh_individual_room_locked_conflict` (9113).
  RACE TODAY: a block/maintenance save holds no row lock, so a booking committed between its read and its write is
  seen by neither side. api/blocked-dates.php POST (239-395) and PUT (396-465) take no lock at all.
- `room_maintenance_schedules.block_room` DEFAULTS TO 1 (database.php 525). Inserts that leave it out close the room
  silently: auto-create (room-maintenance.php ~404), `bulk_assign_rooms` (1040-1075), the recurring generator
  (includes/maintenance-recurring.php 110-135, does not copy the parent's block_room). API POST defaults to 1 (api 323).
- Caps: `rh_clean_block_dates` slices 366 per row (blocked-dates.php 44); grid 300 rows / 5000 nights (97, 167);
  `rh_block_dates_in_range` 366 (database.php 7192, API only).
- Bookings on the timeline: end grip only, only for checked-in (admin/includes/calendar-data.php 813-818); it extends
  AND shortens (room-timeline.js 1005-1013, floor = check-in+1 and today) and reprices via `stay_nights_quote` /
  `stay_nights_apply` (room-timeline.js 841-842, admin/bookings.php 2572). No start-edge grip; confirmed stays say
  "open the booking" (booking-details.php Adjust Stay Dates, `openDateAdjustModal`, ?open=dates at 2064/4850).

### Phase 2: helper `includes/room-night-clash.php`
`rh_room_nights_clash(PDO $pdo, array $targets, array $opt = []): array`
- `$targets[i]` = `['scope'=>'room'|'type'|'all', 'room_ids'=>int[] (pair = both), 'type_id'=>?int, 'label'=>string,
  'nights'=>string[] Y-m-d]`. `$opt['exclude_maint_id']` (the job being edited). Throws RuntimeException when
  `!$pdo->inTransaction()` unless `$opt['advisory']=true` (preview only).
- Returns `['clash'=>bool, 'targets'=>[i=>['free'=>nights[], 'booked'=>nights[]]], 'bookings'=>[id=>['id','ref','guest',
  'room_text','ci','co','status','no_room'=>bool,'where'=>[label=>nights[]]]]]` (`where` = the existing
  `rh_block_affected_bookings` shape, so `rh_block_bookings_text` / `rh_block_where_text` print it unchanged).
- Rules (plain SELECTs, valid ONLY because the caller took the row locks first, see Locks):
  R1 live booking = status IN getBookingStatusesThatBlockAvailability(true), not an expired tentative; sleeps n iff
     `check_in_date <= n < rh_effective_checkout_sql`.
  R2 room scope: n booked if a live booking holds any of room_ids on n (individual_room_id OR unreleased booking_rooms).
  R3 room scope, typed capacity: for each room R not already off sale on n (no `individual_room_blocked_dates` row,
     no blocking maintenance other than exclude_maint_id), units lost L = 1 for R's own type; for every active joined
     type C with an active pair (both rooms active) containing R, L = those pairs whose other room is free on n. If
     `getRoomTypeNightlyAvailability(type, first, last+1)[n] < L` -> n booked; list that type's live bookings with
     `individual_room_id IS NULL` and no unreleased booking_rooms sleeping n (`no_room`=true). Types returning null
     (legacy, no rooms) skip R3. One nightly call per distinct type per action, never per night.
  R4 type scope T: n booked if a live booking sleeps n with `room_id = T`, or holds a room of type T, or is an
     unassigned booking of a joined type that has an active pair using a room of type T (conservative).
  R5 all scope: n booked if any live booking sleeps n.
  R6 never checked: unblock, shortening, housekeeping (does not take rooms off sale, database.php 8492-8496).
- Text: `rh_room_nights_clash_text(array $bookings, int $max = 8)` -> "J. Banda LSH-123, Room 4, 2-6 Jan (booked
  3-5 Jan)"; typed: "P. Phiri LSH-130, Deluxe, no room yet, 9-11 Jan".

### Locks (every Phase 2/3 write)
Same order as booking creation, so there is no deadlock cycle: [the page's named lock, if any] -> room ids + type ids
(`rh_related_room_type_ids` over the rooms' types; 'all' scope = every room type id) worked out BEFORE BEGIN -> BEGIN ->
`rh_lock_room_types` (first statement) -> `SELECT id FROM individual_rooms WHERE id IN (...) ORDER BY id FOR UPDATE`
-> clash helper -> writes -> log -> COMMIT. No plain read between BEGIN and the locks (it would open the snapshot
early). Maintenance update must move its `SELECT ... existing` (room-maintenance.php 807) after the locks (load the
row's room id before BEGIN for the lock set, read it again inside). Lock wait/deadlock: catch with `rh_is_lock_conflict`
(4200) -> "Someone is booking these rooms right now. Nothing was changed. Try again."

### Phase 2 call sites
1. `rh_block_grid_apply` op=block (blocked-dates.php 245): lock as above inside the existing `rh_block_locked`; each
   target saves only `free`; booked nights go into `$skippedBy[label]`. A booked night that already has a block keeps
   its old row untouched (not updated). Nothing free at all -> ok=false. Unblock path unchanged.
2. `preview_block` (458): same helper, `advisory=true`, no locks; JSON adds `skip_nights` and `free_nights`, keeps
   `booking_count` + `bookings` (now = the bookings whose nights will be skipped). JS confirm text in
   blocked-dates-grid.js ~499 and room-timeline.js ~1596: "N booked night(s) will be skipped and stay open: <list>.
   The other M night(s) will be blocked." M=0 -> no confirm, show "Every selected night is booked. Nothing to block."
   (Only the confirm string in blocked-dates-grid.js; its selection storage belongs to the orchestrator.)
3. `block_date` / `block_multiple` (492-579, 668-760): build one target and call `rh_block_grid_apply` (one save
   engine; type-scope writes become transactional, the log moves inside). Drop the `rh_block_overlap_note` calls.
4. api/blocked-dates.php POST: wrap in `rh_with_create_lock('blocked_dates')` + the locks; save free nights; the
   response adds `skipped: [{night, room|type, booking_reference, guest_name}]`; nothing free -> 409. PUT (moves a block
   to a new night): booked -> 409, old block kept. `warnings` key kept (empty) for existing clients.
5. Maintenance add/update + api/maintenance-schedules.php POST/PUT, when the saved job has `block_room=1` and an open
   status: DECISION = REFUSE with the bookings listed (no split). Reasons: a job is one range with one
   status/assignee/audit trail; splitting clashes with the one-open-job-per-room+title rule
   (`maintenanceFindOpenDuplicate`, 524) and would create jobs staff never asked for; a refused save leaves nothing
   half-done. The message lists the free runs so staff can re-date. Update checks only NEWLY covered nights (new nights
   minus old nights when the old row was an open block_room job on the same room), so editing the assignee/notes of an
   old clashing job still saves. API -> 409. Multi-room add (rmSaveMulti, room-maintenance.php 2300) already reports
   per room: no JS change needed.
6. Generated jobs (auto-create ~404, `bulk_assign_rooms` 1040-1075, recurring 110-135): set `block_room`
   explicitly (recurring copies the parent's). If the job's nights are booked it is still created (it is a work
   reminder) with `block_room=0`, and the run's message says "Room 4 is booked tonight, so the job does not close
   the room."
Messages: block "Blocked 9 night(s): Room 4 on 6-14 Jan. Skipped 3 booked night(s): Room 4 on 3-5 Jan (J. Banda
LSH-123, 2-6 Jan). Move those bookings first, then block those nights." Maintenance "Not saved: Room 4 is booked on
3-5 Jan (J. Banda LSH-123, 2-6 Jan). Free: 6-12 Jan. Change the dates, untick Block room, or move the booking first."

### Phase 3: Extend and drag-to-resize
Entry points: the right-click menu + bulk bar item "Extend..." (Phase 1 `RhContextMenu` + bulk bar) on selected block
cells/runs, maintenance bars/rows, housekeeping bars/rows. Extend form: By N [days|weeks|months|years] or Until
<date> (maintenance: date+time). Months/years count from the last night, clamped to month end (31 Jan + 1 month =
28/29 Feb). One transaction per action; a DB error rolls everything back; clash-skipped items are reported, the rest saved.
- Blocks: new action `extend_blocks` (blocked-dates.php, perm `block_rooms` via `rhCanBlockRooms`, add it to the list at 447).
  Items `[{t,id,last}]` (`last` = last night of the selected run). Extension nights last+1 .. new last, copying the
  source night's block_type + reason. Cap 1096 nights per row per action (ASSUMPTION 3y) via a `$cap` param on
  `rh_clean_block_dates` (default 366 unchanged elsewhere); 10000 nights per action (ASSUMPTION). Goes through
  `rh_block_grid_apply` -> clash skip. One `rh_block_log_change` call per distinct (type, reason), normally one.
- Maintenance: `bulk_schedules` bulk_op `extend` (perm `room_maintenance`; reuse `adminBulkIds` / `adminBulkSkip`
  / `adminBulkOutcome`). New end = end_date + N units (time of day kept) or the posted datetime; must be later than the
  old end; cap end <= start + 1096 days. Completed/cancelled/verified are skipped. block_room jobs: clash check on the
  newly covered nights; a clashing job is skipped and listed, the rest extend. due_date unchanged.
  `logMaintenanceAction` ('updated') per job + one `logActivity` (admin/includes/permissions.php 2051) per action.
- Housekeeping: `bulk_assignments` bulk_op `extend` (perm `housekeeping`), Repeat [daily|weekly] until <date>.
  New rows from due_date+step .. until copy room, assignment_type, priority, assigned_to, scheduled_time,
  estimated_duration, notes; status pending; `is_recurring=0` (so `createRecurringAssignments` 484 never spawns
  more). Skip any day that `hkFindOpenDuplicateAssignment` (629) finds (count reported). A source with is_recurring=1 ->
  skipped: "Already repeats; change its repeat end date instead." Runs under the add's lock `rh_form_hk_assignment_`.
  Cap until <= today + 366 and 2000 new rows per action (ASSUMPTION). `logHousekeepingAction` per new row
  ('created', note "Repeated from task #id") + one `logActivity`. No booking check (R6).
- Drag-to-resize (owner 2026-11-23 "like an excel"): a grip on the end edge of block runs (`.tl-block`, Calendar)
  and of maintenance bars (ops timeline), desktop pointer only, same `canDrag()` rule as booking bars. Drag right =
  extend (blocks -> `extend_blocks`, clash skip; maintenance -> `extend`, clash refuses that job); drag left = shorten
  (blocks -> grid_apply op=unblock on the trimmed nights: only the row's own block; a type/all-rooms block stays and
  is reported; maintenance -> new end, floor = start + 1 hour). A confirm sheet shows the nights added/removed before
  saving; the bar snaps back on refusal. Blocked Dates grid: extend/shorten through Extend... and cell selection only
  (no grip there). Housekeeping: n/a (one-day tasks; use Extend... repeat).
- Bookings: no new save path. Gaps only: (a) no start-edge grip, (b) no date drag on confirmed stays. Proposal: on
  drop, open booking-details.php?id=X&open=dates&ci=..&co=.. with the Adjust Stay Dates modal prefilled (reprice
  shown there, nothing saved on the timeline). Needs owner OK (FEATURE, see questions). Checked-in end grip unchanged.

### Tests (Liwonde test DB; page_harness rollback unless noted)
New `scripts/tests/room_night_clash_test.php` (helper): booking ci=D+10 co=D+13 -> D+10..12 booked, D+13 and D+9
free; pending + live tentative booked, expired tentative / cancelled / checked-out / no-show free; overdue checked-in
-> today booked; booking held only via booking_rooms booked, released row free; type T with k free rooms and k
unassigned bookings on n -> any free T room booked (no_room listed), k-1 -> free; a room already blocked on that n is
not flagged by R3; type scope: typed T booking booked, other type free, joined booking holding a T room booked; all
scope: any booking booked; pair target with a booking on one member -> booked for both; throws outside a transaction.
New `scripts/tests/block_clash_test.php` (admin/blocked-dates.php): grid_apply 5 nights with a booking on 2 -> 3 rows,
message has "Skipped 2 booked", guest + ref; all booked -> success false, 0 rows; old block on a booked night untouched;
preview skip/free counts; block_date on a booked night -> 0 rows; block_multiple type scope over a typed booking ->
skipped; joined pair with a booked member -> both rooms skipped; user without block_rooms refused; unblock unchanged.
New `scripts/tests/maintenance_clash_test.php` (admin/room-maintenance.php): block_room=1 over a booked night refused,
0 rows, ref in message; block_room=0 saved; end 00:00 on the guest's check-in day saved; start 14:00 on the guest's
check-out day saved; update extending over a booking refused, row unchanged; assignee edit on an old clashing job saved;
status completed saved; bulk_assign over a booked night -> row block_room=0.
New `scripts/tests/block_booking_race.php` (two child processes, pattern + cleanup of concurrent_booking.php; commits
on Liwonde test rows only): a block and a booking for the same room/night at once -> exactly one wins, never both.
Phase 3 `scripts/tests/grid_extend_test.php`: block extend 2 weeks copies type/reason; crossing a booking skips +
lists; 1097 nights refused; 31 Jan + 1 month ends end of Feb; shorten unblocks the trimmed own nights, type block stays;
maintenance extend 3 days keeps the time; block_room job over a booking skipped, the other job extended; completed
skipped; HK daily until D+6 -> 6 rows same scheduled_time, is_recurring 0; existing dup on D+3 -> 5 + 1 skipped;
weekly; recurring source skipped; until > today+366 refused; one logActivity row per action.
Must stay green: block_rooms, blocked_dates_grid (its "booking already sleeps... warns" case ~199 is rewritten to
"skipped"), calendar_timeline_test, calendar_timeline_actions_test, ops_timeline_test, rooms_housekeeping_12a,
housekeeping_guest_left, inhouse_move, linked_rooms, joined_on_demand, concurrent_booking, mixed_room_booking,
dashboard_counts, grid_select_test.mjs; full run_tests.php at the Phase 2 ship (bookings logic).

### Open questions for the owner
1. Maintenance closing a room over a booked night: refuse with the bookings + free dates listed (recommended) instead
   of splitting the job around the booking. OK?
2. A room-type / All-rooms block skips the whole night when ANY booking of that type sleeps there (strict reading of
   your rule). Busy nights will mostly be skipped; staff can block single free rooms. OK, or block the type's free
   rooms instead?
3. Generated jobs (recurring, auto, bulk-assign) on booked nights: create them without closing the room (proposed)?
4. Bookings drag: add start-edge drag and date drag on confirmed stays, handing off to the booking's own Change dates
   (prefilled, reprice shown, nothing saved on the timeline)?
LATER: room-maintenance add/update does not require `block_rooms` to close a room (permissions.php 176 suggests it should).

## Fable spec check (2026-11-23)
Verdict: **PASS WITH CHANGES**. Rules R1/R2/R4/R5 match the code: statuses (database.php 2737), expired
tentative (8652), effective checkout (4839), direct + unreleased booking_rooms (8650/8665), check-out day free
(8609/8653), maintenance nights DATE(start)..DATE(end-1s) (8567-8569). Lock order holds: booking paths go
[booking row] -> types -> individual_rooms (create-booking 780-781, booking-room-change 336-344, assign
5041/5048/5071). Standalone single-room assign locks only the room (8918/8940), so there is no cycle. The block path
never locks booking rows, because with skip no booking sleeps on a saved night, so rh_block_log_change writes no
booking notes. Locks-then-read is valid because the snapshot opens at the first plain read (4128-4131).

Required changes (blockers):
1. R3 must be cumulative per action. Today each room is checked on its own, so blocking 3 rooms of a type with 2
   free units in one save (the Block Date Range modal now posts several rooms to grid_apply) would pass all 3 and
   oversell the typed bookings. Keep a running budget per (type, night) across every room and target in the call: a
   pair target uses 2 units; a joined type's budget drops once per pair touched. Go in ascending room id so the
   result is the same every time.
2. Generated jobs (call site 6) must choose block_room under the same locks. auto_create opens BEGIN before its
   reads (room-maintenance.php 997-998 -> 340-349), bulk_assign_rooms the same (1021-1037), and recurring has no
   transaction at all (maintenance-recurring.php 91-136). Work out the rooms and type ids before BEGIN, take the
   locks, run the helper, then insert. Otherwise a booking can commit between the check and the insert, and the job
   lands with block_room=1 (the DB default, database.php 525).
3. Extend blocks must be ONE transaction. rh_block_grid_apply takes a single blockType/reason and opens its own
   BEGIN/lock (blocked-dates.php 245-257). Split it into a core that takes a type/reason per target and opens no
   transaction, called once inside one lock. Extension nights that already have their own block keep their row
   untouched; the "updated" path at 273-277 must not rewrite them; report them as "already blocked". An item whose
   `last` night has no OWN block row (inherited type/all block, or nothing) is refused. Calendar spans merge own and
   type/all blocks and carry no scope or job id (calendar-data.php 551-577), so add scope + source to the span and
   show the grip only on `tl-block--blocked` spans that end on the lane's own block. Calendar maintenance spans:
   either no grip, or route by job id to bulk_schedules extend. Two items for the same row with different reasons
   are refused.
4. Confirm/verify sites the spec misses: (a) the Block Date Range modal's inline JS (blocked-dates.php 1754-1761)
   still says "will NOT be moved... History will get a note" and must use skip_nights/free_nights. (b) The AI block_dates card:
   rh_ai_vf_block_dates (ai-actions.php 2334-2350) needs every night blocked, so a correct partial save logs an
   action_failed incident (2539). Verify only the free nights: the preview (1683-1728) runs the helper in advisory
   mode and stores the free nights in `block.dates`, and the card text says booked nights are skipped. Log it in
   GUIDES_AI_SYNC.md.
5. Define "open job" as `COALESCE(status,'') NOT IN ('completed','cancelled','verified')` (database.php 8567), not
   the form/API names pending/in_progress (room-maintenance.php 610, api 547). Compute job nights as
   DATE(start)..DATE(end-1s), in SQL or in a PHP function tested against that SQL.
6. Add tests: R3 cumulative (k free units with j typed bookings, block k-j+1 rooms in one save -> exactly k-j saved,
   no_room listed); generated jobs (booked -> block_room=0; free recurring copies the parent's block_room);
   extend_blocks with 2 reasons in 1 transaction plus a forced failure -> nothing saved; an existing block on an
   extension night is left as it was; an item with no own block is refused; extend_blocks without block_rooms is
   refused; maintenance update that changes room or reopens a completed job is checked on every night; API POST
   partial (skipped[]) / all booked 409 / PUT 409; AI verify passes on a partial save.

LATER:
- LATER: stay-date changes take no type/room locks (processBookingDateAdjustment database.php 12051-12116; rh_stay_nights_apply stay-nights.php 214-229), so a stay extension and a block can still both commit. Separate bookings PATCH, needs the owner's OK. Do not claim "no race" until then.
- LATER: R3 counts L=1 even for rooms already off sale (out_of_order status, room_maintenance_blocks, family pair holds), so it can over-skip. Safe, not wrong.
- LATER: "Block room" is ticked by default (room-maintenance.php 1928), so in-house repairs will often be refused. Offer "save without closing the room" in the message.
- LATER: room status maintenance/out_of_order (database.php 8455; reconcile 453-456) takes the room off sale for every night with no booking check. That is outside the owner's block/job rule.
- LATER: API PUT that only edits type/reason on the same night: allow it, don't 409.
- LATER: the API individual block does not widen joined-only pairs (api 275 vs rh_block_room_ids). This is old behaviour.
- LATER: set `innodb_lock_wait_timeout` to about 10 s for these saves (the named lock is held while waiting on row locks).
- LATER: the helper calls functions that use the global $pdo. Assert it is the same connection.

Open questions, recommendations:
1. Refuse (no split): agree.
2. Strict skip for type/all blocks: agree. It is the owner's literal rule, and turning it into room blocks would
   change meaning (a type block also covers rooms added later). The message should point to blocking single free rooms.
3. Generated jobs saved without closing the room: agree, with required change 2.
4. Booking drag hands off to the booking's own Adjust Stay Dates (prefilled, reprice through adjust_dates_preview,
   nothing saved on the timeline): safe, and the owner's 2026-11-23 words support asking. It is still a FEATURE: get
   the owner's yes and ship it as its own step after Phase 3. The checked-in end grip stays unchanged.

## Owner answers to the open questions (2026-11-23, binding)
1. Maintenance closing a room over a booked night: REFUSE, nothing saved, list the bookings + the free dates.
2. Room-type / All-rooms block on a night where some rooms of that type are booked: BLOCK ONLY THE FREE ROOMS
   (owner chose this over skipping the whole night). Nights with no booking of the type keep the normal type-level
   block. On a night with bookings, save individual-room blocks on the rooms that are free that night, keeping
   enough free rooms for typed-but-unassigned bookings (Fable R3, a running budget per type x night across the whole
   save). Joined rooms/pairs follow the existing rules. The result text lists the bookings and says which rooms were
   blocked singly. Unblocking a type later does not remove those single-room blocks (say so in the result).
3. Generated jobs (recurring, auto, bulk-assign) on booked nights: CREATE them with block_room = 0 on those (decided
   under the same locks), never close the room over a booking.
4. Bookings drag: YES. Start-edge drag, end-edge drag and whole-stay drag on confirmed stays open the booking's own
   Change dates screen prefilled (new dates + reprice shown); nothing is saved on the timeline. (Phase 3.)
Also from the owner 2026-11-23: an Excel-style drag handle on block runs and maintenance bars to extend AND shorten
(Phase 3; shortening a block = unblock the trimmed nights; extending goes through the clash check).
