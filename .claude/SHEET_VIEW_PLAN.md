# SHEET_VIEW_PLAN: spreadsheet view for Stock Count and Menu (owner FEATURE 2026-11-26)

Label: FEATURE. Owner words 2026-11-26:
- "Amazing default excel view with cells and columns of our stock count and leave our current view as a secondary view."
- "Another UI from excel cell amazing view of our menu and able to edit as a view too. People with no permission to edit
  it can see the excel table view but not able to edit it, greyed out same as the stock view."
- "Lets skip the override permission and keep logic the same. On phones nothing changes. Meaning 1 and 3 to avoid any
  financial risks."
- Menu prices: "editable in the grid with the review list".
- "Make sure for these excels there is a guard showing who else is working on the same spreadsheets."

Out of scope (owner decided): no override permission, no edits to approved counts or to live stock, no new stock or
money logic, no change on phones. Also out: the Import review table (`admin/data-import.php`): a separate screen,
only if the owner asks. No formulas in cells.

## Ground rules for every phase
- Same save paths, same rules. The grid is a new way of typing into the existing forms, never a new write path.
- Phones (< 768 px): exactly as today. The grid is built by JS from the page's data at tablet/desktop width only;
  never render both views and hide one with CSS (a 400-line count would double the page).
- "Grid" is the default on tablet/desktop; a "List view" switch gives the current view back. Choice remembered per
  user in localStorage (try/catch; falls back to grid).
- Read-only cells: solid grey surface, `aria-readonly`, no caret, same look on both screens.
- UI rules (RULES.md): plain, solid, one accent, "?" helper on every column head, no AI wording.
- Each phase: tested, shipped (ship gate in DEPARTMENTS.md), ported to Rosalyn's on its own.
- `admin/includes/permissions.php` and `admin/includes/admin-nav-items.php` are uncommitted by another session today:
  any phase touching them waits until that session has shipped.

## Phase S1: shared sheet engine (NORMAL; owner: frontend-specialist / ui-builder, review: reviewer)
New `admin/js/sheet-grid.js` + `admin/css/sheet-grid.css`. Honest scope: `blocked-dates-grid.js` and
`room-timeline.js` are room x night selection grids, not row x field data entry, so this is new code. It reuses
their gesture conventions (click, drag rectangle, Shift-click range, Ctrl-click add, Esc clears, arrows) and
`admin/js/admin-context-menu.js` for right-click (Copy, Paste, Clear, Fill down).
- Data entry: type to replace, F2/double-click to edit in place, Enter saves cell + moves down, Tab moves right,
  Shift+Enter/Tab back, Esc cancels the cell edit.
- Sticky header row and first column (item name); column "?" helpers from a column definition.
- Paste a block from Excel (tab/newline text) into the selection; cells that are read-only or fail the column's
  type check are skipped and listed in one plain message ("3 cells not pasted: ...").
- Ctrl+Z / Ctrl+Y for edits not yet saved. Changed cells marked (accent left edge) until saved.
- Filter box + column filters (category, location, counted / not counted), 10/25/50/All pager per RULES.md.
- Column types: text, number (min, step), money (2 dp), select (fixed options), yes/no. Read-only per cell or column.
- No saving inside the engine: the page gives it an `onSave(changes)` callback, so every page keeps its own save.
- Test: `scripts/tests/browser_check.mjs` scenario on a static fixture page (keyboard, paste, undo, read-only skip).

## Phase S2: Stock Count in the grid (CRITICAL: quantities; owner: dept-stock-lead, review: qa-auditor Opus)
Page `admin/stock-count.php` (count view, table at ~2183-2330), `admin/css/stock-count.css`.
- Columns = today's table: Ingredient, In stock at start, Should be there, Counted, Unit cost (opening counts only),
  Difference, Value, Reason, Notes. Difference/Value stay computed and read-only, same JS figures as today.
- Editable exactly where the list view is editable today: a draft count, for the people allowed now
  (`scSaveCountLines`, ~498). Waiting for approval / approved / rejected = whole grid read-only, grey.
- Saving: the grid writes into the existing hidden form, sets each changed line's `touched` flag, and submits through
  the existing `lines_json[]` 50-line chunk packer (~2655-2692). No new server action, no change to
  `scSaveCountLines` or approve/reject.
- Count measures (bottle / shot etc.): the Counted cell follows the line's mode select. Mode "big" or "small" = one
  number in that measure. Mode "both" = the cell opens the existing two small inputs in place; pasting one column into
  a "both" line is refused for that line and listed (never guessed).
- Imported counts (draft or later) are ordinary counts on this page, so they get the grid automatically.
- Who else is here: the existing "Also open right now" box (`data-edit-presence`, key `stock-count`, id = count id,
  ~2140) moves into the grid toolbar, same script `admin/js/edit-presence.js`. Warning only, never blocks.
- Known limit, unchanged on purpose (owner: logic stays the same): two people typing the same line of the same draft =
  the later save wins; there is no per-line stale check in `scSaveCountLines`. The presence box makes that visible.
- Tests: `php scripts/tests/<stock count suite>` unchanged and passing (proves save path untouched); browser check:
  type 5 lines by keyboard, paste 20 from a fixture, save, reload, values match; approved count shows read-only grid;
  390 px viewport shows today's list unchanged.

## Phase S3: Menu in the grid (CRITICAL: prices are money; owner: dept-restaurant-lead, money review by
## dept-accounting-lead, gate qa-auditor Opus)
Page `admin/menu-management.php` (Food / Drinks tabs), `admin/css/menu-management.css`.
- Columns: Name, Category, Price, Available, Station, Show on till, Show on room service. Description, tags, recipe,
  image stay in the existing Edit form (row "Open" button / double-click the name).
- Save = new action `grid_save` that loops over the changed rows and calls the SAME code as action `update`
  (~282-423: validation, duplicate check, food_menu/drink_menu update, the till copy in `menu_items`, the stock link).
  That block is moved into one function first with no behaviour change, so `update` and `grid_save` share it.
- Stale guard: each changed row carries its `row_hash` (`rh_stale_hash`, `includes/stale-edit.php`). All or none: if
  any row fails its stale check or validation, nothing is saved and the rows are listed ("Chicken curry was changed
  by Mary at 10:42: reload to see it"). One transaction.
- Review list before saving: a modal lists every change, prices first, "Chicken curry 8,500.00 -> 9,000.00", with a
  count ("12 changes, 5 prices"). A price that moves by more than 50% either way is marked "Check this price". Save
  only from the modal.
- NEW (beyond today's logic, needed for the review promise): each saved price change is logged old -> new, who,
  when, item, in `admin_activity_log` (same pattern as the Hotel Settings change log), shown in System Logs. Today a
  menu save is logged only as "Saved on Menu". dept-logs-lead reviews the log line.
- Who else is here: add presence keys `menu-food` (id 1) and `menu-drink` (id 2) to the allowed list in
  `admin/api/presence.php` and `admin/includes/presence-lib.php`; box in the grid toolbar. Warning only; the stale
  guard above is the real protection.
- Tests: new `scripts/tests/menu_grid_save.php` on Liwonde: 3-row save; stale row 2 -> nothing saved; invalid price ->
  nothing saved; till copy price matches after save; price log row old -> new. Browser check: edit 3 prices, review
  modal lists them, save, reload; 390 px unchanged.

## blocked: owner decision (asked 2026-11-26)
- "Can see but not edit, greyed out": today a person without the `menu` permission cannot open the Menu page at all
  (page guard in `admin/admin-init.php`), and the same for Stock Count without `stock_count`. So the greyed grid only
  reaches people who already open the page read-only (view-only users, counts waiting for approval or approved).
  For others to see a greyed grid, a new view permission is needed (`menu_view`, `stock_count_view`): page opens,
  every cell grey, server refuses every save. Owner to choose: (a) add the view permissions, or (b) greyed grid only
  for those who can already open the page. Phase S1 and the editing parts of S2/S3 do not wait on this.

## Order
S1 -> S2 -> S3, one session each, each shipped and ported before the next. Guides + Assistant lines in
`.claude/GUIDES_AI_SYNC.md` at each ship (sync itself only when the owner prompts).
