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
- No phase touches `admin/includes/permissions.php` or `admin/includes/admin-nav-items.php` (access unchanged).
  Before each phase, check `coord.mjs status`: never edit a file another live session holds uncommitted.

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

## Owner decision 2026-11-26: access stays exactly as today
"Staff without permission should not be able to see this. That's the whole point of permissions."
- No new view permissions. A person without `menu` / `stock_count` still cannot open the page (page guard in
  `admin/admin-init.php`) and never sees the grid.
- The greyed read-only grid is only for people who can already open the page but not change it: view-only users
  (`includes/view-only.php`), and counts waiting for approval, approved or rejected.
- So no phase touches `admin/includes/permissions.php`.

## Safety rails for EVERY phase (owner 2026-11-26: "without breaking any logic or messing up any finances or stock")
1. The grid never writes to the database. It only fills and submits the screen's EXISTING save action. Server-side
   changes are limited to: moving an existing save block into a function with no behaviour change, the presence keys,
   and new old -> new log lines. Anything else = stop and ask the owner.
2. Read-only, always: quantity on hand, anything computed (difference, value, totals, VAT), and anything that has its
   own guarded flow (Change unit, opening stock, approval). Those stay on their existing buttons.
3. Every money cell (price, cost per unit, budget, room price) goes through the review list before saving and is logged
   old -> new (room prices already are, `includes/price-history.php`; menu and stock cost get the same).
4. Saves are all-or-none in one transaction, with the screen's stale check; a refused save names the rows and saves
   nothing.
5. The grid is built by JS on top of today's list. If the grid script fails, the list is still there and works.
   Phones keep the list only.
6. Before and after each phase, the area's existing test suites run and must give the same result (named per phase
   below); plus the phase's new tests and a headless browser check. Liwonde test data only, then port.
7. Review: qa-auditor (Opus) on every phase with logic; dept-accounting-lead on every money column; final-review
   (Fable) after Opus passes on S9 and S10 (stock + money, booking prices: RULES.md Fable rule).
8. One phase per session; the next starts only after the previous is shipped and ported.

## Later phases (owner 2026-11-26: "can we do it all in phases"), in this order, lowest risk first
S4. Stock Items grid (`admin/stock-ingredients.php`, action `update`). Editable: the Edit form's plain fields (name,
    category, location, supplier, reorder/par levels, barcode). Cost per unit: review list + log (rail 3). Read-only:
    quantity, unit (own Change unit flow), batches. Tests: stock_list_filters, stock_change_unit, stock_core_8c.
    Owner: dept-stock-lead. CRITICAL (cost).
S5. Reorder list grid (`admin/stock-reorder.php` -> `purchase-orders.php` action `create_from_reorder`): adjust the
    suggested amounts, then the existing "create order" button. Nothing is stock until received. NORMAL+stock review.
S6. Stock budget grid (`admin/stock-budget.php`, action `set`): month x category. Review list + log. Test: stock_budget.
S7. Wastage, several lines at once (`admin/stock-wastage.php`, its existing save in one transaction, ~76-174): one
    row per item, same reasons, same checks. Tests: stock_loss, stock_expiry_fifo. CRITICAL (stock).
S8. Recipes grid (`admin/stock-recipes.php`, action `save_recipe`): amounts per dish, shown with the cost of one sale
    as today. Changes only future sales. Tests: menu_portions, pos_kds_stock_accounting. CRITICAL.
S9. Receiving a delivery (`admin/purchase-orders.php`, action `receive` via `rh_po_read_receive_post`): type quantity
    received and price per line from the supplier's invoice. Adds stock and sets cost, so: review list, all-or-none,
    existing receive code only. Tests: stock_receiving, stock_expiry_fifo, stock_packs. CRITICAL + Fable final review.
S10. Room prices grid (`admin/room-management.php` price fields: price per night, single/double/triple) and the rate
    plan list (`admin/rate-plans.php`, action `save`, with its `preview`). Room price history already logged. A
    per-DATE rate calendar is NOT included: there is no per-date price in the system, so it would be new pricing
    logic (owner: logic stays the same). Tests: rate_plan_rules_rp3, rate_plan_room_adjustments,
    rate_plan_surcharge_display, booked_rate_line_test. CRITICAL + Fable final review.
S11. Reports, read-only grid (`admin/reports.php` tables): sort, filter, copy a selection to Excel. No editing.

Not in any phase (owner agreed 2026-11-26): bookings, payments, invoices, receipts, refunds, credit notes, the till,
staff permissions, small lists (tables, gym packages, deals).

## Order
S1 -> S2 -> S3 -> S4 ... S11, one session each, each shipped and ported before the next. Guides + Assistant lines in
`.claude/GUIDES_AI_SYNC.md` at each ship (sync itself only when the owner prompts).
