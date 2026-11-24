# Fill count measures from bottle sizes + "Shots per bottle" column (FEATURE, owner 2026-11-24)

Status: SHIPPED 2026-11-24: Liwonde 2cdb039, Rosalyn's d089216 (port). Change after the spec: an item sold in more than
one measure is decided in the smallest but its row starts UNTICKED with "Also sold as ... check before ticking".
LATER: stock-measure-bulk-lib.php:476 (Set count measure apply) shows raw exception text like the one fixed here.

## Owner words (2026-11-24)
- Asked: "Why isn't the ingredients table for rosalyns showing the shot count per bottle if the ingredients are bought as
  bottles but sold in shots?" Cause: Rosalyn's 2026-11-21 import ran before measures were saved on import (11-22), so
  items have no count measure (measure_big_name / measure_small_name / measure_per_big / measure_stock_side all NULL) and
  rh_stock_qty_words() shows "2.28 bottle".
- Agreed design (owner answers): "If you build the above will I still be able to set by hand" -> yes, hand-set always wins.
  "What if I don't want to use 25ml as a measure or 500ml as a pint" -> sizes come only from the hotel's Drink measures list
  (rh_drink_measures_list(), editable on Stock Items). Option A chosen: a BUTTON WITH PREVIEW, not silent.
  "Yes let's do this as a priority" / "Yes lets do this as top priority".

## What to build
1. Stock Items (admin/stock-ingredients.php) toolbar button "Fill from bottle sizes" (next to "Set count measure",
   same permission $canBulkMeasure). Opens a modal: preview (nothing saved) -> per-row tick boxes -> Apply.
2. For every active item (whole list, not only the page shown) work out ONE candidate measure:
   - Skip (with reason) when: unit is ml/litre or a weight (rh_drink_unit_ml / rh_drink_unit_is_weight); no size known
     (rh_drink_item_ml() null) -> reason "No bottle size: set Bottle size on the item, then run again."; item sold by no
     till/menu link -> "Not sold in a drink measure on any menu item"; the measures it is sold in are not all whole
     multiples of the smallest (e.g. shot 25 + glass of wine 175 -> "Sold as shot and glass of wine: set it yourself");
     N not exact (rh_smb_n_from_ml rule, exact at 4 dp, 1..RH_STOCK_MEASURE_MAX_N) -> its reason text.
   - "Sold in" = the item's stock_recipe_ingredients lines (via menu items' recipes). Per line, the serving =
     rh_pos_serving_of($line, $ing, $list) (admin/includes/pos-drink-servings.php): typed entry_measure first, else the
     hotel measure of the same size as quantity_per_portion x item ml. A serving named after the item's own unit
     (a whole bottle) is ignored. Candidate = smallest listed measure used; N = item ml / that measure's ml.
   - Spec per item: big = item's own unit (spec big '' as in the bulk tool), small = measure name, n, side = 'big'.
3. Decision per item via rh_smb_decide() (admin/includes/stock-measure-bulk-lib.php): set / same / different / skip
   (incl. open count lines guard). Preview rows: set = ticked; different = UNticked, shows "currently X, would become Y";
   same = shown greyed, nothing to do; skip = reason. Person can untick any row.
4. Apply: only ticked rows, each through rh_stock_measure_save() in its own transaction (as rh_smb_apply), re-deciding
   under lock; stale preview refused by fingerprint (same pattern as the bulk tool). Result lists saved / skipped + why.
   Quantities, costs, batches, recipes never change.
5. Column "Shots per bottle" in the Stock Items table: "30 shots / bottle", "4 glasses / bottle" (rh_stock_measure_get +
   rh_stock_measure_plural), "–" when none. "?" helper. class col-optional-lg (drops on narrow screens). Screen only:
   CSV export unchanged. Sorts on N.
6. No migration, no new settings. If a column seems needed: stop and ask.

## Files
admin/stock-ingredients.php (button, modal, column, two POST actions preview_measure_auto / measure_auto_apply as JSON,
mirroring preview_measure_bulk at :77), admin/includes/stock-measure-auto-lib.php (new, pure-ish helpers + plan/apply),
admin/js/stock-ingredients-measure-auto.js (new), admin/css (reuse existing smb modal classes), scripts/tests/stock_measure_auto.php
(new; fixtures in a rolled-back transaction: Liwonde has 0 stock items).
Do not touch: admin/bookings*, calendar/timeline files (ClaudeWorkTwo), STATUS.md (ClaudeWorkTwo has uncommitted edits).

## Ship gate
Tests: stock_measure_auto (new), stock_measure_bulk, stock_count_measures, stock_qty_words, stock_list_filters.
qa-auditor (Opus). Browser check of Stock Items: column + button + preview modal opens; NEVER press Apply.
Commit files by name. Port to ../Rosalyns-hotel-2026 (copy files, php -l, "Port of Liwonde <hash>"); owner/staff run it there.
Then GUIDES_AI_SYNC.md Pending line + Showcase SYNC QUEUE note.
