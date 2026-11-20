# UI_DECISION: Room Timeline calendar (admin/calendar.php)

Owner FEATURE 2026-11-21. Author: ui-lead. Builder: dept-rooms-lead. Liwonde only (Rosalyn's is LIVE, port later).
Block prefix: `.tl-` (BEM: `.tl-block__element--modifier`). New sheet: `admin/css/room-timeline.css`, loaded after
`calendar.css`. Also link `css/admin-kpi.css` (its `:root` holds the `--ck-*` tokens) and `css/admin-cockpit.css`
(for `.ck-tabs`/`.ck-tab`/`.ck-kpi`/`.ck-rows`) exactly as `dashboard.php` lines 2081-2082 do. Checked: every
top-level selector in admin-cockpit.css is `.ck-*`, so it cannot restyle the page. `help-tooltips.css` is already
loaded by the header (admin-header.php line 500 -> includes/help-tooltips.php). Font Awesome 6 names (already used).

## 0. Ground rules for this screen
- No gradients, glows, shimmer, bouncing, emoji. `--admin-success-bg`, `--admin-danger-bg`, `--admin-warning-bg`,
  `--admin-info-bg` are `linear-gradient` tokens: BANNED here. Use the flat `.badge-*` values below.
- One allowed exception: hard-stop diagonal hatching for blocked / out-of-service cells (owner asked for it, it is a
  stripe pattern, not a colour gradient). Written once as `--tl-hatch`; QA must not flag it.
- Only motion: drawer slide (200ms transform) and hover colour (150ms). Both off under `prefers-reduced-motion`.
- `--tl-*` below are component layout variables and ALIASES of existing colours. No new palette, type or spacing
  scale is introduced. Spacing uses the 8px rhythm (4/8/12/16/24).

## 1. Component variables (declared on `.tl`)
| Variable | Value | Source |
|---|---|---|
| `--tl-room-w` | 208px desktop, 168px tablet, 64px phone | layout |
| `--tl-day-w` | (scroller width minus room col) / days, min 44px | layout, set by JS on resize |
| `--tl-row-h` | 44px comfortable, 36px compact (compact only on `pointer: fine`) | 44px touch rule |
| `--tl-head-h` | 52px | layout |
| `--tl-line` | `var(--ck-line)` | admin-kpi.css |
| `--tl-surface` / `--tl-soft` / `--tl-softer` | `var(--ck-surface)` / `var(--ck-soft)` / `var(--ck-softer)` | admin-kpi.css |
| `--tl-today` | `var(--gold-light)` column tint, `var(--admin-accent)` line | admin-styles.css |
| `--tl-hatch` | repeating-linear-gradient(135deg, var(--tbl-border) 0 6px, var(--admin-bg) 6px 12px) | admin-styles.css |
| `--tl-focus` | 2px solid `var(--admin-accent)` | as `.help:focus-visible` |
Status colours: aliases of `.badge-*` in admin-styles.css 3349-3409 so a state looks the same as its pill on Bookings.

## 2. Page shell
- Title row: `<h2 class="section-title"><i class="fas fa-calendar-days"></i> Calendar</h2>` (drop the emoji; "Calendar"
  matches the sidebar label in admin-nav-items.php line 23, so it is not confused with the Bookings list).
  Right side of the row: primary `.btn.btn-primary` "New booking" (only visible primary on the page).
- Tab strip under the title: `<nav class="ck-tabs tl-tabs" role="tablist">` with three `.ck-tab` links:
  Calendar | Housekeeping | Maintenance. `?tab=calendar` (default) / `?tab=housekeeping` / `?tab=maintenance`.
  Active tab `.is-active` + `aria-selected="true"`; counts in `.ck-tab__count` (open HK tasks, open maintenance).
  Tabs honour the existing permissions/module gates of housekeeping.php and room-maintenance.php (hidden, not disabled).
- View toggle (Calendar tab only), right end of the toolbar: segmented `.tl-view` with two buttons
  "Timeline" (default) | "Month". Month = existing view at `calendar.php?view=month`, unchanged. Same `.ck-tabs`
  look at small size (`.ck-tabs.tl-view`). Phone adds a third option "Agenda" (see 8).

## 3. Toolbar `.tl-toolbar`
One row on desktop, wraps to two on tablet. All controls use `--rh-toolbar-control-height` and
`--rh-toolbar-control-font-size` (admin-styles.css 4204), gap 8px, aligned on one baseline.
Order, left to right:
1. Date group `.tl-toolbar__nav`: `.btn.btn-secondary` "Today" · icon buttons prev/next (`fa-chevron-left/right`,
   `aria-label` "Previous 7 days" etc., step = current range) · `<input type="date" class="tl-toolbar__date">`
   labelled "From".
2. Range `.ck-tabs.tl-range`: 3 / 7 / 14 / 30 days (buttons, not a slider). Default: desktop 14, tablet 7, phone 3.
   Phone shows 3 and 7 only; tablet 7 and 14 (30 is hidden below 1025px).
3. Filters `.tl-toolbar__filters`: selects Room type, Floor (only if floors exist), Status (Free tonight / Occupied /
   Arriving / Departing / Dirty / Out of service). Reuse `.cal-filter-select` styling. Active filters show
   `.cal-filter-clear` "Clear filters".
4. Search `.tl-toolbar__search`: guest name, booking ref or room number; matching bars get `.tl-bar--match`,
   others dim to 35% opacity; Enter scrolls to the first match.
5. Density `.tl-density` icon toggle (`fa-bars` / `fa-grip-lines`, desktop only).
6. Legend: `.btn.btn-secondary` "Legend" opening a popover `.tl-legend` (reuse `.legend-item`/`.legend-dot` markup),
   listing every bar state, payment flag, room state and housekeeping icon in plain words.
Tablet/phone: filters + density move into one "Filters" button that opens the bottom sheet (8).

## 4. Grid `.tl-grid`
- ONE scroll container `.tl-scroller` owns both overflows (`overflow: auto`), height
  `calc(100dvh - var(--admin-header-height) - toolbar height - 24px)`, min 420px. Sticky works only inside it:
  no `overflow` on any ancestor between it and the sticky cells.
- Corner cell `.tl-corner` (sticky top+left, z 4): "Rooms" + collapse-all chevron.
- Date header `.tl-head` (sticky top, z 3), cell `.tl-head__day`: three stacked lines weekday "Fri" (11px, muted,
  uppercase), day "04" (16px, 600), month "Oct" (11px). Weekend `.tl-head__day--weekend` + column background
  `--tl-softer`. Today `.tl-head__day--today`: background `--tl-today` tint, day number in `var(--gold)`.
- Summary rows `.tl-sum` (sticky under the head, part of the head block): three rows, each with a label cell
  carrying `.help` + `data-help="Title|Body"` (existing pattern from help-tooltips.css):
  - "Occupancy" %: "Rooms with a stay that night / rooms you can sell (out of order excluded), this hotel, per night."
  - "Arrivals": "Bookings checking in that day (pending, tentative, confirmed), all room types."
  - "Departures": "Bookings checking out that day (in-house and due), all room types."
  Values 12px tabular-nums; occupancy 90%+ in `#9a4a08` (badge-warning text), 100% bold.
  Clicking an Arrivals/Departures value applies that filter (filter-card rule) and scrolls to the first row.
- Room column `.tl-room` (sticky left, z 2, width `--tl-room-w`, right border `--tl-line`):
  room number (600) + room name (muted, truncated) + housekeeping icon `.tl-hk` (5). Click opens the room in
  individual-rooms.php (link, `aria-label` "Room 104, dirty").
- Room-type group header `.tl-group` (full-width row, `--tl-soft` background, 36px): chevron button
  (`aria-expanded`), type name, `.badge.badge-neutral` "4 of 6 free tonight". Collapsed state remembered in
  localStorage per type.
- Unassigned lane `.tl-row--unassigned` first row of each group, label "Unassigned (n)" with `fa-inbox`;
  hidden when n = 0 in the visible range. Bars here get `.tl-bar--unassigned` (dashed 1px border). This replaces
  the old "Bookings without a room this month" box in the timeline view (kept in Month view).
- Day cells `.tl-cell`: borders `--tl-line`; weekend `--tl-softer`; hover on empty cell `--admin-hover-bg`.
- Today line `.tl-now`: 2px `var(--admin-accent)` vertical line at the middle of today's column through all rows
  (the turnover point, matching the half-day bars), z 1, `pointer-events: none`.

## 5. Booking bar `.tl-bar` (a `<button>` positioned in its row)
Half-day offset (DECIDED): bar starts at the middle of the check-in cell and ends at the middle of the check-out
cell: `left = (startIndex + 0.5) * --tl-day-w + 2px`, `width = nights * --tl-day-w - 4px`. Why: a departure and an
arrival on the same day sit side by side in one row, so same-day turnovers and real gaps are visible at a glance.
Bars clipped by the range edge get `.tl-bar--cut-start` / `--cut-end` (square corner + `fa-angle-left/right`).
Shape: radius 6px, height `--tl-row-h` minus 8px, 1px border, padding 0 8px, solid fill, no shadow at rest.

| DB status | Modifier | Fill | Text | Border | Contrast |
|---|---|---|---|---|---|
| pending | `--pending` | #9a4a08 | #fff | #9a4a08 | 6.6:1 |
| tentative | `--tentative` | rgba(245,158,11,.14) on white | #9a4a08 | 1px dashed #9a4a08 | 5.9:1 |
| confirmed | `--confirmed` | #047857 | #fff | #047857 | 5.5:1 |
| checked-in | `--checked-in` | #1d4ed8 | #fff | #1d4ed8 | 6.7:1 |
| checked-out | `--checked-out` | rgba(107,114,128,.14) | #374151 | rgba(107,114,128,.25) | 9:1 |
| no-show | `--no-show` | rgba(120,113,108,.14) | #57534e | 1px dotted #57534e | 6.9:1 |
| checked-in past check-out | `--overdue` (with `--checked-in`) | as checked-in | #fff | 3px right edge #b91c1c | |
Status strings are the stored ones (hyphenated: `checked-in`, see calendar.php line 203). Cancelled is not drawn.

Content (two lines, each `white-space: nowrap; overflow: hidden; text-overflow: ellipsis`):
- Line 1 `.tl-bar__name`: guest name, 13px 600.
- Line 2 `.tl-bar__meta`: rate plan or package name, else "N nights", then `fa-user` + guest count; 12px, 85% opacity.
- Width rules: under 96px hide line 2; under 56px show no text (surname only on hover title); the full sentence
  always sits in `aria-label` and `title`: "Jane Banda, confirmed, 4 to 7 Oct, 3 nights, Room 104, part paid".
Flags `.tl-bar__flags` (right end, gap 2px): 16px white circle chips `.tl-flag` with a coloured icon, so they read
on every fill. Paid shows nothing (no noise).
- `.tl-flag--part` `fa-circle-half-stroke` #9a4a08 · `.tl-flag--unpaid` `fa-circle-exclamation` #b91c1c
- `.tl-flag--note` `fa-note-sticky` #374151 (special request or notes present)
- `.tl-flag--joined` `fa-link` #374151 (room_combinations) · `.tl-flag--group` `fa-users` #374151 (multi-room booking)
States: hover border-color `var(--admin-text)` + z 2 (no lift, no shadow); focus-visible `--tl-focus` offset 2px;
selected `.is-selected` 2px outline `var(--admin-text)` offset 2px (stays while the drawer is open); search match
`.tl-bar--match` keeps full opacity; non-draggable `.tl-bar--locked` (cursor pointer only, no grips).

## 6. Room states on the grid
- Out of order / maintenance block `.tl-block--maintenance` and `.tl-block--ooo`: full cells (not half-day; a room
  is out for whole nights), background `--tl-hatch`, left border 3px #6b7280, label pill inside
  (`.tl-block__label`, white background, #374151 text, 11px): `fa-screwdriver-wrench` "Maintenance" /
  `fa-ban` "Out of order" + reason if short. Clicking opens room-maintenance.php for that entry.
- Blocked dates `.tl-block--blocked`: same hatch, `fa-lock` "Blocked"; room-type blocks hatch every room in the group.
- Housekeeping icon `.tl-hk` on the room cell: 18px circle, icon + colour (never colour alone):
  clean `fa-check` #047857 · dirty `fa-exclamation` #b91c1c · cleaning `fa-ellipsis` #9a4a08 ·
  inspection `fa-eye` #1d4ed8. Each with `.help`-style title "Dirty: checkout cleaning not started".
  Derivation (rooms lead owns it): dirty = open task `pending` or room status `cleaning` with no task in progress;
  cleaning = task `in_progress`; inspection = room status `inspection` or task completed and not verified;
  clean = otherwise. A task with status `blocked` (cannot be done, e.g. guest asked not to be disturbed) shows
  `fa-ban` #374151 "Cleaning blocked" and wins over dirty.

## 7. Interactions (visual spec; behaviour and saves are rooms lead's)
- Click/Enter on a bar: drawer `.tl-drawer` (right side, desktop + tablet). New component because no shared drawer
  exists; built from the modal parts: `.modal-header` / `.modal-body` / `.modal-footer` inside, fixed,
  `top: var(--admin-header-height)`, bottom 0, width min(420px, 100vw), `--tl-surface`, border-left 1px
  `--admin-surface-border`, `box-shadow: var(--shadow-lg)`. No backdrop on desktop (grid stays usable); Esc and the
  close button (`fa-xmark`, 44px) close it; focus moves into it and back to the bar on close.
  Body, top to bottom: guest name (h3) + status `.badge.badge-<status>` · ref · dates + nights · room / room type ·
  guests · rate plan/package · total, paid, balance + `.badge-paid/.badge-partial/.badge-unpaid` · notes ·
  joined/group line ("Joined with Room 105", "Group of 3 rooms, ref ...").
  Footer (right-aligned, one baseline, same height): `.btn.btn-secondary` "Open booking" then ONE state primary:
  "Check in" (confirmed, arrival today or overdue) / "Check out" (checked-in) / "Assign room" (unassigned) / none.
  Everything else in the shared `.actions-more-menu` (placed with `rhPlaceActionMenu`): Move room, Change dates,
  Record payment, Assign room (when not primary). Locked bars show the reason in the body in plain words.
- Drag (desktop only: `pointer: fine` and width over 1024px): whole bar = move (cursor `grab`/`grabbing`), 8px grip
  `.tl-bar__grip--end` on the right edge = change check-out (cursor `ew-resize`). While dragging: original stays at
  40% opacity, `.tl-bar--ghost` follows snapped to cells; target cells `.tl-cell--drop-ok` rgba(59,130,246,.12) or
  `.tl-cell--drop-bad` rgba(239,68,68,.12) + `not-allowed`. On drop nothing saves: AdminConfirm shows
  "Move Jane Banda (LSH-...) from Room 101 to Room 104, 4 to 7 Oct, 3 nights. Price: X to Y." Cancel snaps back.
- Not draggable (`.tl-bar--locked`): checked-out, no-show, stays already ended, joined and group bookings. Checked-in:
  end grip only (extend/shorten), no move.
- Click-drag on empty cells in one room row: selection `.tl-sel` (`var(--gold-light)` fill, 1px dashed
  `var(--admin-accent)`, label "3 nights"); on release a small popover with `.btn.btn-primary` "New booking" that
  opens create-booking prefilled (room, check-in, check-out). Single click on an empty cell = 1-night selection.

## 8. Tablet (600-1024px) and phone (under 600px)
Same parts, same colours, same bars: only sizes change.
- Tablet: `--tl-room-w` 168px, range 7 (14 option), toolbar on two rows, drawer as on desktop (width 380px).
- Phone: `--tl-room-w` 64px: room number + `.tl-hk` icon only (name in `aria-label`); range 3 (7 option); horizontal
  `scroll-snap-type: x mandatory` on `.tl-scroller`, `scroll-snap-align: start` on day cells; summary rows collapse to
  one "Occupancy" row (arrivals/departures shown in Agenda).
- Touch (`pointer: coarse`, any width): no drag, no grips. Tap bar = sheet; tap empty cell = sheet with
  "New booking from 4 Oct". Move room / Change dates sit in the sheet's More menu and open the existing forms.
- Sheet `.tl-drawer--sheet` (phone): bottom, max-height 85dvh, top corners `--admin-radius`, backdrop
  rgba(17,24,39,.45) (same as `.admin-modal-overlay`), 36x4px handle bar, sticky footer with the same buttons in the same order
  as desktop (Open booking, state action, More) on one row, each 44px high.
- Agenda view `.tl-agenda` (phone toggle "Agenda", optional on tablet): three sections with `.ck-rows`/`.ck-row`:
  Arriving today · In house · Departing today, each row = room number, guest, nights, status badge, payment badge,
  one action button (Check in / Check out). Section headers carry counts + `.help`.
- Every interactive target 44x44px (bars at 44px row height; icon buttons padded; `.help` already has the coarse
  pointer hit area).

## 9. Housekeeping and Maintenance tabs
Compact versions of the existing screens; reuse their markup and the shared pager, do not invent new lists.
- Filter cards row: `.ck-kpi` cards (admin-cockpit.css), each with `.help`. Clicking a card applies that filter and
  scrolls to the table (filter-card rule), active card `.is-active`.
  Housekeeping: Dirty · Cleaning · Inspection · Clean · Unassigned tasks. Maintenance: Open · In progress ·
  Out of order now · Due this week.
- Table `.tl-list` using the standard admin table classes from housekeeping.php / room-maintenance.php, every column
  header with `data-help`. Housekeeping columns: Room, Type, Status, Task, Assigned to, Priority, Due, Actions.
  Maintenance columns: Room, Issue, Status, Priority, From, To, Blocks room, Actions.
  Row actions: at most 2 visible (Start/Done or Edit) + `.actions-more-menu`.
- Paginate 10 per page with the shared auto pager + `.pg-size` 10/25/50/All (admin-components.css 2305).
- Phone/tablet: the shared card mode + `.rh-col-help` "What the columns mean" disclosure.
- Link "Open full Housekeeping page" / "Open full Maintenance page" top right (secondary).

## 10. Operations pages (Housekeeping, Maintenance, Room Service)
Owner FEATURE 2026-11-21 "same look + room timeline". Builder: dept-rooms-lead (HK, Maintenance), dept-restaurant-lead
(Room Service). Same rules as 0-9: no new tokens, no gradients, `.badge-*` colours, 44px targets. Existing POST
handlers, CSRF, permissions and module gates stay; the drawer only re-presents the same forms/buttons.
(Old section 10 Accessibility is now 11 and applies to these grids too.)

### 10.1 Shared layout (all three pages, top to bottom)
1. Title row (`section-title`, icon) + right-side primary button the page already has (New task / Report issue / New order).
2. Figure cards `.ck-kpi` (section 9 sets): each has `.help`; click = filter + scroll to rows; `.is-active`.
3. Toolbar `.tl-toolbar` (section 3): Today / prev / next / date, range tabs, filters, search, Legend. Page filters
   (status, assignee, priority) reuse `.cal-filter-select`. Range tabs and the density toggle only on the two date grids.
4. View toggle `.ck-tabs.tl-view` right end of toolbar: "Timeline" | "List". Remembered per page in localStorage
   (`rh_ops_view_<page>`); default Timeline on tablet and desktop, List on phone.
5. Body: `.tl-grid` (Timeline) or the existing list (List, paginated 10 + `.pg-size`, columns with `data-help`).
6. Drawer `.tl-drawer` (sheet on phone). Old modals for view/edit remain only as a fallback when JS fails.

### 10.2 Reuse: one engine, three modes (DECIDED)
- `RoomTimeline.init(root)` gains an optional `cfg.mode`: `booking` (default, current code path, untouched),
  `housekeeping`, `maintenance`, `service`. Read it in one place; each non-default mode supplies a small adapter
  object in a new file `admin/js/room-timeline-ops.js` (loaded after room-timeline.js, registers
  `RoomTimeline.modes.<name> = {rows, bars, drawer, columns}`). Booking drag, grips, selection, agenda stay booking-only.
- Reused as is: toolbar and nav code, range tabs, rooms column + `.tl-group` headers, date head, `.tl-now`, today tint,
  `.tl-bar` shape and sizing, search/dim, roving keyboard model, drawer + sheet shell and `data-tl-drawer-*` hooks,
  `.actions-more-menu`, `.tl-status` live region, localStorage helpers.
- New CSS: only `admin/css/room-timeline-ops.css` (after room-timeline.css) holding the bar modifiers below. Calendar
  behaviour and its existing classes do not change. Fallback if the mode seam proves costly: the adapter file may carry
  its own thin renderer, but it must still emit the same `.tl-*` classes.
- Data: each page embeds `[data-tl-json]` like the calendar (`{mode, data, api, initial}`); range reload via a read-only
  `GET` endpoint per page (handback). Escape all strings server-side as the calendar does.

### 10.3 Housekeeping (`admin/housekeeping.php`)
- Cards: Dirty - Cleaning - Inspection - Clean - Unassigned (same five and the same derivation as section 9/6).
- Grid: rows = rooms, grouped by room type (same `.tl-group`, collapse state shared with the calendar); columns = days.
  A bar = one task on its due date, drawn as a FULL-day cell (a cleaning is a day job, not a stay: no half-day offset),
  class `.tl-bar.tl-task`, two lines: task type (13px 600) + assignee initials/name (12px). Several tasks one room-day
  stack as a count chip "3 tasks" that opens the drawer list. Overdue = due before today and not done: 3px right edge
  #b91c1c (same as `--overdue`). Occupied nights are NOT drawn; instead a faint `--tl-softer` cell when a guest is
  in house (read-only context, so staff see "can I clean now?"), from the calendar JSON.
- Task state colours (existing `.badge-*` values, same as the pills in the list):
  pending `.tl-task--pending` #9a4a08 solid white text (matches booking pending) · in progress `--progress`
  #1d4ed8 solid · completed `--done` rgba(107,114,128,.14)/#374151 · verified `--verified` #047857 solid + `fa-check` ·
  blocked `--blocked` `--tl-hatch` + `fa-ban`. Priority urgent adds a `.tl-flag` `fa-exclamation` chip, never colour alone.
- Drawer: room + type + `.badge` status · task type, priority, due, assigned to · checklist/notes · linked booking
  (guest, check-out time) as a link. Footer (right, one baseline): ONE state primary (Start / Mark done / Verify,
  whichever the current handler offers for that user), `.btn-secondary` "Open on calendar", rest (Assign, Reassign,
  Edit, Cancel task) in `.actions-more-menu`.
- Reads `?due=now` and `?room=` keep working; they preselect the filter and the room's lane.

### 10.4 Maintenance (`admin/room-maintenance.php`)
- Cards: Open - In progress - Out of order now - Due this week (section 9).
- Grid: rows = rooms (same grouping), columns = days. A bar = one job from its From to its To date, whole cells
  (rooms are out for whole nights), class `.tl-bar.tl-job`. EVERY open job shows (ASSUMPTION in BUILD_PLAN), including
  `block_room = 0`. Blocking jobs (`block_room = 1`) get `--tl-hatch` + 3px left border #6b7280 + `fa-ban` "Blocks
  room", exactly the calendar's `.tl-block--maintenance` look; non-blocking jobs are solid with a `fa-screwdriver-wrench`
  and no hatch, so "guests can still stay" reads at once. Open-ended job (no To) runs to the range edge with
  `--cut-end`. Overlapping jobs in one room use a second sub-lane (row grows to 2 x 36px, never overlap).
- States: open `.tl-job--open` rgba(245,158,11,.14)/#9a4a08 dashed border · in progress `--progress` #1d4ed8 solid ·
  done `--done` grey (shown 7 days, then hidden) · priority high/urgent = `fa-exclamation` flag chip.
- Drawer: room + `.badge` status · issue, category, priority · From/To, blocks room yes/no · reported by/assigned to
  · notes · "Affects bookings" line (count of stays overlapping the dates, link to the calendar lane). Footer: state
  primary (Start / Mark done, as today), `.btn-secondary` "Open on calendar", More menu (Edit, Reassign, Cancel).
- Reads `?status=out_of_service` unchanged.

### 10.5 Room Service (`admin/room-service-dashboard.php`)
- Cards: New - Preparing - Ready - Delivered today (existing order states, same filter-card rule).
- Toolbar is day based: Today / prev / next / date step ONE DAY; range tabs replaced by an hour window tabs
  "All day | Breakfast | Lunch | Dinner" (6-11, 11-16, 16-23, plain words, hide if the hotel has no such sittings);
  filters: status, floor, search (room or guest).
- Grid `.tl-grid.tl-grid--hours`: rows = ONLY rooms that have an order that day (an empty 100-row grid is noise) plus
  a "Show all rooms" toggle; columns = hours (`--tl-day-w` becomes hour width, min 56px; head shows "07" / "AM"
  style per hotel clock). A bar = one order from placed time to delivered time (or to "now" while open), class
  `.tl-bar.tl-order`, min width 44px, text: order no. + item count. Colours: new `--new` #9a4a08 solid · preparing
  `--preparing` #1d4ed8 solid · ready `--ready` #047857 solid · delivered `--done` grey · cancelled `--void`
  dotted grey (shown only when the status filter says so). Late (open over the configured target) = right edge
  #b91c1c. `.tl-now` marks the current hour only when the day is today.
- Drawer: room, guest, order no., placed/ready times, items with qty, notes/allergies (visible, not hidden), total
  and charge-to-room state. Footer: ONE next-state primary (same transitions as today's buttons), "Room on calendar"
  secondary, More (Cancel order, Print ticket). Keeps its role/permission checks.
- Live data: the existing `check_ready` poll stays the trigger. It may refresh the grid only if the drawer is closed
  and no filter text is being typed, and announces via `.tl-status` ("1 new order, Room 104"). No sound, no flashing;
  a count badge on the tab title is allowed. Do not add a second timer: reuse the page's one `setInterval`.

### 10.6 Phone and tablet
- Tablet (768-1024): grid as the calendar (room col 168px, 7 days; service: 8 hours). Drawer 380px. Touch targets 44px.
- Phone: default to List. Timeline stays available: room col 64px (number + state icon), 3 days / 6 hours, snap-x,
  bars at 44px row. Tap = `.tl-drawer--sheet` with the same buttons in the same order, 44px each. List uses the
  shared card mode + `.rh-col-help`. No drag anywhere in these modes (nothing in them is draggable).

### 10.7 Links both ways (exact URL params)
- Calendar to pages: maintenance blocks and the HK icon become
  `room-maintenance.php?id=<entry id>&view=timeline` and `housekeeping.php?room=<room id>&view=timeline`
  (the HK icon keeps `?due=now` semantics unchanged when `room` is absent). Calendar tab "Open full ..." links keep
  plain URLs.
- Pages read `id` (open that entry's drawer on load, scroll its row into view, clear the param with
  `history.replaceState`), `room` (highlight + scroll to lane; also on room-maintenance and room-service), `date`
  (YYYY-MM-DD, start of range; for service the day), `view` = `timeline`|`list`, `days` = 3/7/14/30. Unknown or
  invalid values are ignored; ids must belong to the user's permitted data.
- Pages to calendar: `calendar.php?view=timeline&start=<YYYY-MM-DD>&filter_room_id=<room id>` (parameters the
  calendar already reads: `start` line 166, `filter_room_id` line 39, `days`). Anchor text "Open on calendar", icon
  `fa-calendar-days`. A booking shown in a drawer ("Affects bookings", linked stay) links to the calendar with the same
  params, `start` = stay check-in.
- No new calendar param is needed; calendar code and booking mode stay unchanged.

### 10.8 Motion
Same as 0: drawer 200ms slide and 150ms hover colour only; under `prefers-reduced-motion` no slide, no smooth scroll,
and the Room Service refresh swaps content without any fade or highlight animation.

## 11. Accessibility
- `.tl-grid` `role="grid"` + `aria-label="Room timeline, 4 to 17 Oct"`, `aria-rowcount`/`aria-colcount`;
  rows `role="row"`, room cells `role="rowheader"`, date cells `role="columnheader"` (full date in `aria-label`),
  day cells `role="gridcell"`; bars are buttons inside the check-in gridcell.
- Roving tabindex: one Tab stop into the grid (first bar of the first visible row). Arrows Left/Right = previous/next
  bar in the row, Up/Down = nearest bar in the row above/below, Home/End = first/last bar, Enter/Space = drawer,
  Esc = close drawer/cancel drag. Keyboard users move/change dates through the drawer actions (drag is never the only way).
- All bar and flag colours meet AA (table in 5); states also differ by border style or icon, never colour alone.
- Focus ring `--tl-focus` on every control; `prefers-reduced-motion`: no drawer slide, no smooth scroll;
  `prefers-contrast: more`: bars get a 2px #1f2a37 border, hatch darker.
- Live region `.tl-status` (`aria-live="polite"`) announces range changes and saved moves.

## UI_DECISION lines
UI_DECISION | Timeline default, Month secondary (`?view=month`), tabs Calendar/Housekeeping/Maintenance on `.ck-tabs` | admin/calendar.php | owner FEATURE 2026-11-21, reuse dashboard tab strip
UI_DECISION | bar colours = `.badge-*` values, no gradient tokens | timeline bars, drawer, agenda | one style per state across screens
UI_DECISION | half-day bars | timeline | same-day turnovers and gaps visible
UI_DECISION | Month view recoloured to the same values: `.legend-dot.reserved` and reserved cells -> #047857, `.legend-dot.occupied` and occupied cells -> #1d4ed8 (CSS only, admin/css/calendar.css) | Month view | blue must not mean "occupied" in one view and "reserved" one click away; existing colours, no new token
UI_DECISION | drawer/sheet from modal parts, 2 visible actions + `.actions-more-menu`, AdminConfirm before any save | timeline | held row-actions + modal patterns
UI_DECISION | `.help` + `data-help` on summary rows, cards and columns; `.pg-size` pager; `.ck-kpi` filter cards | timeline + tabs | owner list/helper rules
UI_DECISION | no drag on touch; 44px rows; phone Agenda | phone/tablet | touch-first staff screens

## UI_HANDBACK lines
UI_HANDBACK | dept-rooms-lead | admin/api (new range endpoint) | one JSON per range: bookings (status, room, dates, guests, rate/package, paid state, notes flag, joined/group flags), blocks, maintenance, housekeeping state per room, per-day occupancy/arrivals/departures
UI_HANDBACK | dept-rooms-lead | move/resize save | reuse existing room-assignment and date-change paths with per-room FOR UPDATE locks, availability + price recalculation shown in the confirm; accounting lead reviews price changes
UI_HANDBACK | dept-rooms-lead | create-booking.php | accept prefill params room, check_in, check_out
UI_HANDBACK | dept-rooms-lead | housekeeping state | derive clean/dirty/cleaning/inspection as in 6; action buttons follow existing permissions
UI_DECISION | Operations pages reuse the calendar shell via `RoomTimeline.init` `cfg.mode` (`housekeeping`/`maintenance`/`service`), adapters in `admin/js/room-timeline-ops.js`, bars in `admin/css/room-timeline-ops.css`; booking mode untouched | housekeeping.php, room-maintenance.php, room-service-dashboard.php | owner FEATURE 2026-11-21 "same look + room timeline"; prefer reuse
UI_DECISION | task/job/order bars use the `.badge-*` colours; blocking jobs use the calendar hatch, non-blocking solid; day-job bars are full cells | the three grids | one style per state, no new tokens
UI_DECISION | Timeline/List toggle `.ck-tabs.tl-view`, default List on phone; drawer/sheet with one state primary + "Open on calendar" + More menu | the three pages | held row-action and drawer patterns
UI_DECISION | deep links: pages read `id`, `room`, `date`, `view`, `days`; back to calendar via existing `start`, `filter_room_id`, `view=timeline` | calendar + three pages | no calendar change
UI_DECISION | Room Service grid = today's orders by room x hour, `check_ready` poll reused, no second timer, no sound or flash | room-service-dashboard.php | logged assumption
UI_HANDBACK | dept-rooms-lead | housekeeping.php, room-maintenance.php, calendar-data.php | read-only range endpoints (tasks by room/due date; all open jobs with from/to, block_room, status, priority); `?id=`/`room`/`date`/`view`/`days` parsing with permission check; drawer reuses existing POST handlers; calendar HK icon and maintenance blocks link to the new URLs (section 10.7); "Affects bookings" count
UI_HANDBACK | dept-rooms-lead | admin/js/room-timeline.js | add `cfg.mode` seam only; booking path byte-for-byte behaviour unchanged; run the calendar's tests after
UI_HANDBACK | dept-restaurant-lead | room-service-dashboard.php | read-only endpoint of today's orders (placed/ready/delivered times, items, notes, state) by room; reuse `check_ready` poll and existing status POSTs in the drawer; late-order target setting if one exists
