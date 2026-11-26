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
   (Fable) after Opus passes on S9 (stock + money: RULES.md Fable rule).
8. Work is bundled (see "Build sequence" at the end); a bundle starts only after the bundle it depends on has shipped.

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
S10. DROPPED (owner 2026-11-26: "Lets leave rooms out of this. Too high risk"). No room prices, rate plans or any
    rooms screen in the grid. Do not bring it back without the owner's words.
S11. Reports, read-only grid (`admin/reports.php` tables): sort, filter, copy a selection to Excel. No editing.

Not in any phase (owner agreed 2026-11-26): rooms (room prices, rate plans, room screens), bookings, payments, invoices, receipts, refunds, credit notes, the till,
staff permissions, small lists (tables, gym packages, deals).

## Build sequence: BUNDLES (owner 2026-11-26: "bundle what can be done together"; replaces one phase per session)
Grouped by owning lead (RULES.md: a planned bundle groups by owning lead, reuse that lead with SendMessage). Each
lead reads its area once and builds all its screens; one QA gate per ship; one commit per screen inside a ship.

BUNDLE A: shared parts (ui-builder for the grid; backend-specialist for the helper; gate qa-auditor Opus). Ships first,
everything else builds on it.
- S1 sheet engine (`admin/js/sheet-grid.js`, `admin/css/sheet-grid.css`).
- The shared "review changes" modal (one component, used by every money column in B and C).
- One shared old -> new change-log helper (`admin/includes/value-change-log.php`: who, when, screen, item, field,
  old, new; admin_activity_log, same pattern as `includes/settings-change-log.php`), so B and C never build their own.
- Fixture page + browser check for keyboard, paste, undo, read-only skip, review modal.

BUNDLE B: stock (dept-stock-lead, ONE lead reused with SendMessage; gate qa-auditor Opus; accounting lead on cost
and budget columns). Starts after A ships. Two ships by the same lead, because a seven-screen stock diff is too big
to review properly in one pass:
- Ship B1 (screens that do not add or remove stock): S2 Stock Count, S4 Stock Items, S5 Reorder list, S6 Budget.
- Ship B2 (screens that move stock or set cost): S7 Wastage, S8 Recipes, S9 Receiving. Fable final review after Opus.
  If the lead's context passes ~200k after B1, B2 starts with a fresh dept-stock-lead (RULES.md), not a resume.

BUNDLE C: menu + reports (dept-restaurant-lead for S3, accounting lead reviews prices; ui-builder for S11; gate
qa-auditor Opus). Starts after A ships and may run AT THE SAME TIME as B (different files; max 2 specialists at once).
- S3 Menu grid + presence keys `menu-food` / `menu-drink`.
- S11 Reports read-only grid. `admin/reports.php` is uncommitted by another session today: S11 waits until that
  session has shipped; if it still holds it when S3 is ready, S3 ships alone and S11 follows.

## Autonomous run (owner 2026-11-26: "do it all in 1 session", "when we reach our 5hr limit and it resets it
## continues building always automatically", "bypass RULES.md this time around")
- One session builds A -> B1 -> B2 -> C (+ S11) end to end. Waived for THIS run only: RULES.md "one feature per
  session, /clear after each ship" and the short-updates-only wait for owner replies between ships (each ship
  continues straight into the next).
- NOT waived (they protect money, stock and Rosalyn's live guests; the hooks enforce most of them anyway): Rosalyn's
  is LIVE (no tests, scripts or DB writes there; ports by copy + `php -l` only), no destructive SQL, secrets never
  staged, no hook bypass (`--no-verify`), no paid AI calls from the shell, the ship gate (tests, qa-auditor, browser
  check), safety rails 1-8 above, Fable final review on B2.
- Auto-continue: the build session's FIRST step creates a session cron (CronCreate, recurring, every ~20 min, off the
  :00/:30 marks) with this prompt: "Sheet view auto-continue: read the 'Sheet view build log' block in STATUS.md.
  If an agent of this run is still working, or a step is mid-review, do nothing. Otherwise carry on with the next
  unfinished step of .claude/SHEET_VIEW_PLAN.md. When C has shipped and been ported, delete this cron job." A fire
  that lands during the 5-hour usage limit fails; the first fire after the reset picks the work up again.
  Limits: the cron lives only while that Claude window stays open (closing it or /clear ends it) and expires after
  30 days. If the window was closed, a new session reads STATUS.md and continues by hand.
- The build session keeps a "Sheet view build log" block in STATUS.md, updated at every step (step, agent running,
  last commit), so the cron and any new session know exactly where it stopped.

Totals: 4 ships (A, B1, B2, C) instead of 10 phases. Every ship: rails 1-8 above, then ported to Rosalyn's.
Guides + Assistant lines in `.claude/GUIDES_AI_SYNC.md` at each ship (the sync itself only when the owner prompts).
