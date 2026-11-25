# Room packages fix plan (owner 2026-11-26) — PATCH

Owner: dept-rooms-lead. Money gate: dept-accounting-lead (one review per task, applies the qa-auditor checklist).
Build and test at **Liwonde only** (test hotel), then port to Rosalyn's (LIVE: real guests and money; no tests,
no writes, no emails there). No schema change (parity locked). Staff add/remove of packages in admin is NOT in
scope (owner) — no control for it anywhere in this plan.

Owner rules (2026-11-26), never re-decide:
- A stay change (nights, adults, room type) RECALCULATES per-night and per-person-per-night packages from the price
  saved on the `booking_packages` row (`price_type`, `price_amount`); per-stay stays as is.
- A package the new room type does not allow is FLAGGED to staff: not silently kept, not silently dropped.

Worked examples below assume VAT 16.5% and tourism levy 1% (both inside the all-in price), room K300,000 a night
all-in. Packages are stored all-in, carry VAT, never levy (`rh_stay_totals_with_package`, includes/pricing.php:787).

## Revision 2026-11-26 (spec check + as built at Liwonde, uncommitted for review)
- Price basis: `package_total` / `total_cost` are on the price basis; the requoted total goes through
  `rh_stay_totals($pkg,'price',false)['total_with_vat']` before it is used as the package gross (database.php and the
  JS previews via a `grossFactor`). Today that gross-up is an identity in every VAT mode (checked in the test, both
  modes); applied anyway so a future basis change cannot split preview from save.
- `rh_room_change_quote()` stays pure: optional 4th param `?array $pkgRows` (default null = no flags, pure callers
  unchanged). DB callers load the rows once with `rh_room_change_package_rows(PDO, $b)` (lead row only:
  `booking_packages` LEFT JOIN `room_packages` -> name + catalogue applies_to / room_type_ids, null if deleted) and
  pass them in: `rh_room_change_options()` and `rh_apply_room_type_change()` (re-quoted under the lock).
  The calendar drag to another type (admin/js/room-timeline.js `reasonFields`) shows the same flag + tick and posts
  `package_ack`; without it that move would be refused with no tick to give (found while building A3).
- `booking_packages.quantity` is `smallint unsigned`: no display-only fallback; units saved (clamped 1..65535).
- B3 unblocked (Liwonde tree clean): built in `buildInvoiceHTML` only, conference invoice code untouched.
- Signatures as built: `rh_pkg_requote(array $rows, int $nights, int $persons, ?callable $money, string $currency,
  int $oldNights)`, `rh_pkg_label(array $row, int $nights, ?callable $money, bool $withTotal, string $currency)`,
  `rh_reprice_at_booked_rate($b, $newNights, $addBack = 0.0, array $pkgOpts = [])` ('adults', 'skip').
- ASSUMPTIONS: party/closed-lead/unverified rules above (DECIDED section); an adults-only edit reprices only when a
  per-person package moves; the unused-nights fee base stays room-only; room-type change checks the lead row's
  packages only, package kept at its booked price with the tick; the AI room-type change (ai-actions.php, AI lead
  only) is refused while a package is flagged until it sends `package_ack` (GUIDES_AI_SYNC); a rare lock-order wait
  (secondary row then lead) is accepted.
- Tests: `scripts/tests/room_packages_test.php` + `room_packages_child.php`; DB cases run in inclusive AND exclusive
  VAT mode (covers tests 3, 6, 7, 11 and the group cases).

---

## Verified facts (Liwonde code, 2026-11-26)

- Packages are written once, at booking: booking.php:729-744 (single + auto-split, lead row `$createdBookingIds[0]`)
  and includes/mixed-booking.php:592-598 (lead row `$ids[0]`), `quantity` hard-coded `1`. `bookings.package_total` =
  the all-in package sum, lead row only (booking.php:608, mixed-booking.php:515). Per-person packages are costed on
  the WHOLE party's adults (booking.php:469 `$adult_guests`, booking.php:234 `$mxAdults`), not the lead row's.
- `rh_booked_stay_split()` (config/database.php:11987) derives the booked nightly rate as
  (total_with_vat - package_total) / nights. **`rh_write_booking_stay_totals()` (database.php:12032) never writes
  `package_total`**, so a package change without updating `package_total` would corrupt every later booked rate.
- Every nights change except three goes through `rh_reprice_at_booked_rate()` (database.php:12064), which adds the
  OLD package gross unchanged (line 12072), then `rh_write_booking_stay_totals()`:
  admin/bookings.php:167 (stay_price_preview), :895 (quick modify), :1527 (check out & settle via late-stay),
  :2778 (extend), :2898 (change checkout / early checkout); includes/stay-nights.php:92/291; includes/late-stay.php:203;
  config/database.php:12115/12259 (`calculateDateAdjustmentAmount` / date adjustment; booking-details.php:1650 preview);
  includes/cancellation-policy.php:819 (unused-nights cap fallback); includes/ai/ai-actions.php:453 (AI preview).
- The three that bypass it: admin/edit-booking.php:305 `edit_booking_reprice()` (line 311), includes/booking-room-change.php:194
  `rh_room_change_quote()` (line 210), includes/stay-correction.php:227 (changed extra-night-rate branch).
- Change-preview text uses the OLD package: includes/booked-rate-change.php:49 (`rh_booked_rate_change_for_booking`),
  stay-correction.php:262-263. JS previews hold a fixed package: admin/edit-booking.php:739 `packageGross`
  (used at :1140-1155 and :1263), admin/booking-details.php:4444 `bookedPackageGross`.
- edit-booking.php:513 `$occChanged` = occupancy tier or children only: an adults-only edit never reprices.
- Allowed-type rule = `room_packages.applies_to` / `room_type_ids` (`getActivePackages`, pricing.php:672-707, which also
  drops inactive packages). admin/packages.php delete is a hard DELETE (:198-201), so `package_id` may point at nothing.
- B: config/invoice.php:1380-1386 (PDF) and :1791-1797 (HTML) print `quantity`, `price_amount`, `total_cost` as stored;
  config/email.php:2394-2401 (guest) and :3502-3506 (admin) print "/night" next to the stay total.
- C: booking.php:465-479 (single) and :231-238 (mixed) keep only ids still in `getActivePackages()`; a missing one is
  silently skipped.
- D: admin/packages.php:189-196 toggle has no `rh_log_event` (save :126/:160 and delete :201 do).
- includes/booked-rate.php needs **no edit**: `rh_booked_rate_line()` reads the stored `package_total`, which this
  plan keeps current.

---

## Shared helpers (new, includes/pricing.php next to `calculatePackageCost`, all inside `function_exists` guards)

1. `rh_pkg_units(string $type, int $nights, int $persons): int` PURE — per_night = nights; per_person_per_night =
   persons x nights; per_stay / unknown = 1. Used for the saved `quantity` (B) and the requote (A).
2. `rh_pkg_row_units(array $row): int` PURE — the units a stored row was costed on: `round(total_cost / price_amount)`
   when price_amount > 0 and `|units x price_amount - total_cost| <= BALANCE_TOLERANCE`; else `max(1, quantity)`.
   Works for old rows (quantity 1) and new rows alike.
3. `rh_pkg_label(array $row, int $nights, string $cur): string` PURE — the wording (B), built from
   `rh_pkg_row_units()`: "K50,000 per night x 2 nights = K100,000"; "K20,000 per person per night x 3 guests x
   3 nights = K180,000"; "K30,000 per stay = K30,000". Falls back to "K... (package)" when units do not divide by
   nights (old drifted row).
4. `rh_pkg_requote(array $rows, int $oldNights, int $newNights, int $adultsDelta, int $fallbackAdults, ?int $newTypeId, array $catalogue): array`
   PURE. Per row: persons = per_person_per_night ? `rh_pkg_row_units()/oldNights` when that is a whole number >= 1,
   else `$fallbackAdults` (flag `persons_fallback`); new persons = max(1, persons + adultsDelta); new_qty =
   `rh_pkg_units()`; new_total = round(price_amount x new_qty, 2) (same rounding as `calculatePackageCost`);
   `not_allowed` = $newTypeId given AND the catalogue row exists AND applies_to = 'room_types' AND $newTypeId is not
   in room_type_ids (is_active is ignored: a switched-off package is not a room-type issue; a deleted package
   (no catalogue row) is never flagged). Returns `{rows[], old_total, new_total, changed, not_allowed_names[], history}`;
   history = one line per changed row: "Breakfast: K50,000 per night x 5 nights = K250,000 -> x 2 nights = K100,000".
5. `rh_booking_package_rows(PDO $pdo, int $bookingId): array` — `booking_packages` rows + their `room_packages`
   catalogue rows (LEFT JOIN, `applies_to`, `room_type_ids`); [] on error.
6. `rh_booking_packages_write(PDO $pdo, int $bookingId, array $requote, ?array $actor): void` — inside the caller's
   transaction: `UPDATE booking_packages SET quantity = ?, total_cost = ? WHERE id = ? AND booking_id = ?` per changed
   row; `UPDATE bookings SET package_total = ? WHERE id = ?`; one `logBookingEvent(..., 'package_repriced', ...)` with
   old -> new from `history` and the actor (given, else the admin session user the same way `rh_log_event` reads it,
   else 'system'); one History line per flagged row when the requote carries `not_allowed_names` + `acknowledged`.

ASSUMPTION: `$fallbackAdults` = sum of `adult_guests` over the live group (`rh_booking_group_members()` ids, lead
included); a booking not in a group = its own `adult_guests`. Only used for old/drifted rows whose units do not
divide by the nights.
ASSUMPTION: when `bookings.package_total` differs from SUM(`booking_packages.total_cost`) by more than
BALANCE_TOLERANCE (inconsistent old row), nothing is recalculated (today's behaviour) and the quote carries
`package_unverified` so the preview says "Package figures on this booking do not match; the package was not
recalculated. Tell a manager." No silent change either way.

---

## Fault A — stay changes carry packages unchanged (tasks A1-A3)

### A1 core seam (config/database.php, includes/pricing.php, includes/booked-rate-change.php, includes/stay-correction.php, admin/edit-booking.php server side)

- `rh_reprice_at_booked_rate(array $b, int $newNights, float $addBackNight = 0.0, array $pkgOpts = [])`
  (database.php:12064). Signature stays backward-compatible. `$pkgOpts`: `adults_delta` (int, default 0),
  `new_type_id` (?int), `rows` (preloaded, optional). When `$b['id'] > 0` and `package_total > 0`, load rows via
  global `$pdo` (`rh_booking_package_rows`) and `rh_pkg_requote(...)` with old nights = `$split['nights']`.
  New gross = per_night x newNights (+ addBack) + **new** package gross; `rh_stay_totals_with_package($newGross, $newPkg, levy)`.
  Return adds `package_gross_old`, `package_gross`, `packages` (the requote). Put the requote and the new
  `package_total` into the returned `totals` array under key `packages` so the writer sees it.
  Every caller listed in "Verified facts" then gets the right figure without its own edit, including the AI preview
  (ai-actions.php is the AI lead's: not edited; logged in GUIDES_AI_SYNC).
- `rh_write_booking_stay_totals(..., ?float $childSupplement = null, ?array $actor = null)` (database.php:12032):
  when `$tt['packages']` is present and changed, call `rh_booking_packages_write()` in the same transaction.
  Grep every caller and every reader of the `totals` array (json_encode / audit metadata) so the extra key does
  not leak into stored JSON; strip it there if it does.
- `rh_booked_rate_change_for_booking()` (booked-rate-change.php:49): use `$rp['package_gross']` when set, else the
  split's (keeps old callers right). Update the header comment at :12 ("never multiplied by nights" is no longer true).
- stay-correction.php:227-228 (changed-rate branch): take the new package from `rh_reprice_at_booked_rate($b, $newNights, $addBack)['package_gross']`
  (call it once above the branch) and carry its `totals['packages']` into `$tt`; :262-263 text uses the new package.
  Its re-quote-under-lock check (refuses when the figures differ) then covers packages too.
- edit-booking.php: `edit_booking_reprice()` (:305) gains `int $adultsDelta` and returns the package requote;
  gross = newNight x nights + new package gross; totals via `rh_stay_totals_with_package` with the new package and
  `packages` key. Trigger (:513/:562): add `$adultsChanged = $adult_guests !== (int)$locked['adult_guests']`, used
  ONLY when the booking has a per_person_per_night package (otherwise behaviour is byte-identical to today).
  The History `$changes` array gets `package` old -> new.
- INVARIANT: `total_with_vat` and `package_total` are only ever written together. Checked 2026-11-26: no reprice
  path (bookings.php, edit-booking.php, booking-details.php, stay-correction, stay-nights, late-stay,
  booking-room-change, date adjustment) has its own `UPDATE bookings ... total_with_vat`; all go through
  `rh_write_booking_stay_totals()` (database.php:12034 is the only such SET). The builder re-greps after the build;
  any new exception is listed in the ship report. includes/ai/ai-actions.php is checked by the AI lead.
- The requote is always redone at save time under the booking row lock (FOR UPDATE), from the locked row and its
  package rows; a preview's figures are never carried into the save.
- Payments, credit, overpay: unchanged; every path already calls `recalculateBookingFinancials()` after the write.
- No-show, cancel, cancel-one-room, delete: nights do not change, nothing to do (verified: they do not reprice).

ASSUMPTION: the unused-nights fee stays on the ROOM rate only (`rh_unused_nights_quote` base = room per_night x unused,
cancellation-policy.php:811); package nights not used are fully removed. The cap (old total - new total) grows by the
package reduction, which never raises the fee above its base.
ASSUMPTION: the adults trigger follows the existing `$can_edit_stay` gate (reservation mode), same as the room price.

### A2 previews that must equal the save (admin/edit-booking.php JS, admin/booking-details.php JS)

- Both pages pass the package rows to JS: `[{name, type, unit, persons, units}]` (from `rh_pkg_requote` inputs) and
  one small JS function mirroring `rh_pkg_requote` totals (per_stay fixed, per_night unit x nights, ppn unit x
  persons x nights). edit-booking: replace `P.packageGross` at :1140-1155 and :1263 (adults = guests - children from
  the form); booking-details: replace `bookedPackageGross` at :4444 in the date-adjust preview.
- edit-booking `un_fee_quoted` and stay-correction re-quote must not refuse a correct save: the preview figure must
  equal the server figure to the cent (test case 9).

### A3 room type change flag (includes/booking-room-change.php, admin/bookings.php)

- `rh_room_change_quote()` (:194): package part = requote with `new_type_id` (nights/adults unchanged, so the money
  is the same as today); add `package_flags` = `not_allowed_names`. `rh_room_change_options()` carries it per option.
- `rh_apply_room_type_change()` (:321) gains `bool $packageAck = false`; when the quote (re-made under the lock) has
  flags and no ack: refuse "Breakfast is not offered for Suite. Tick 'I have told the guest' to keep it on the bill,
  or choose another room type. Nothing was changed." With ack: proceed, package kept at its price, History line
  "Breakfast is not offered for Suite; kept on the bill (K150,000), confirmed by <staff>".
  Only the row that carries the packages (the lead) can flag; a secondary group room never flags.
- bookings.php upgrade modal (`rc_*` JS ~:10780): a warning line + one required tick under the price summary when
  the picked option has `package_flags`; `upgrade_room_type` action (:2966) passes `package_ack`.
  UI_REQUEST to ui-lead only if the existing warning pattern does not fit.
ASSUMPTION: "flagged" = warning in the quote + one required tick + History line; no keep/remove control (owner
excluded add/remove). The AI room-change action will refuse with the same message until the AI lead adds the tick
to its confirm card (GUIDES_AI_SYNC entry).

### DECIDED (owner 2026-11-26): groups reprice from the whole party
When any room of a group or mixed booking changes nights or adults, the lead row's package is worked out again with
the inputs used at booking: the party's total adults x the LEAD room's nights. Staff see old -> new; History on the
lead row ("Package recalculated") and on the changed room ("Party package recalculated"). Built inside
`rh_reprice_at_booked_rate()` (callers not edited) via `rh_booking_package_requote()` / `_write()` (database.php).
- Party = group rows (`primary_booking_id` = lead) not deleted / cancelled / no-show / expired. A closed lead is never repriced.
- A secondary room's nights change uses the lead's nights, so it moves the package only when adults change too.
- The secondary write locks the lead row and refuses if the lead's package_total moved since the quote
  ("The party package on REF changed while this was being saved. Reload and try again.").
- Rows where package_total != SUM(total_cost) ("unverified") are never recalculated; staff see a note instead.

### Money examples

1. Per night K50,000, 5 -> 2 nights. Before: room 5 x 300,000 = 1,500,000 + Breakfast 50,000 x 5 = 250,000 =
   **1,750,000** (total_amount 1,503,953.97, VAT 246,046.03, levy 12,765.96). Today's fault: 600,000 + 250,000 =
   850,000. Fixed: 600,000 + 50,000 x 2 = 100,000 = **700,000** (total_amount 601,581.59, VAT 98,418.41, levy
   5,106.38). Row: quantity 2, price_amount 50,000, total_cost 100,000; package_total 100,000. Delta -1,050,000.
   Then extend 2 -> 7: 2,100,000 + 350,000 = **2,450,000** (levy 17,872.34); booked rate still K300,000.
2. Per person per night K20,000, 2 adults x 3 nights = 120,000; room 900,000; total **1,020,000** (levy 7,659.57).
   3 adults, same occupancy tier: package 20,000 x 3 x 3 = 180,000; total **1,080,000** (total_amount 928,123.46,
   VAT 151,876.54, levy 7,659.57 unchanged: no levy on packages). Row quantity 9. Today's fault: nothing reprices
   (adults-only edit). If the tier changes too, the room part moves by the rack difference exactly as today.
3. Upgrade Standard -> Suite (+K100,000 rack), 3 nights, Breakfast per night K50,000 allowed for Standard only.
   Quote: 400,000 x 3 + 150,000 = **1,350,000** (levy 10,212.77), flag "Breakfast is not offered for Suite".
   No tick: refused, nothing changed. Tick: saved at 1,350,000, package 150,000 kept, History line.

---

## Fault B — "Qty 1 x K50,000 = K150,000" and "(/night) MWK 150,000" (tasks B1, B2, B3)

- B1 (source, no invoice.php edit): booking.php:731-744 and mixed-booking.php:593-598 save
  `quantity = rh_pkg_units(price_type, nights, party adults)` instead of 1 (both rows already carry the same
  nights/adults used for `total_cost`). New invoices then read "Qty 5 x K50,000 = K250,000" unchanged in code.
- B2 (config/email.php:2394-2401 guest, :3502-3506 admin): the label becomes `package_name` + " (" +
  `rh_pkg_label()` + ")", total unchanged. Old rows read right too (units derived from total / price).
- B3 (LAST, config/invoice.php:1380-1386 PDF, :1791-1797 HTML): quantity column = `rh_pkg_row_units()`, sub-label
  "Package add-on: per night x 5 nights" / "per person per night x 3 guests x 3 nights" so OLD rows (quantity 1) add
  up too. Only this task touches invoice.php. booked-rate.php: no edit in any task.
- Old rows: no data backfill (a write to Rosalyn's live rows is an owner call). Offer only: one UPDATE setting
  `quantity = ROUND(total_cost / price_amount)` where it divides exactly — not planned unless the owner asks.
- LATER: exclusive mode prints the unit as `price_amount x f` (rh_doc_pkg_rows_on_basis, pricing.php:1037) while
  the total foots to the document; qty x unit can differ from the line total by cents (e.g. 42,918.45 x 5 =
  214,592.25 vs 214,592.27). Not fixed here.
- LATER: booking-confirmation.php:282-292 (guest page) shows the same "/night" / "x qty" wording; after B1 it reads
  "x5 /night"; switch it to `rh_pkg_label()` only if the owner asks (guest-site lead).

## Fault C — package switched off or not valid for the type between availability and submit (task C1)

- New helper `rh_packages_for_booking(PDO, int $typeId, array $ids, int $nights, int $adults): array`
  {rows, total, missing_names} in pricing.php, used by booking.php:465-479 and :231-238 (replaces both loops).
- Any missing id: `throw new Exception('Sorry, the ' . names . ' package is no longer available for this room. Please review your extras and book again. Nothing was booked.')`
  (names from `room_packages` by id, prepared IN list; a deleted id reads "a selected package"). Thrown before the
  transaction, so nothing is written; the form re-renders with the guest's details (existing catch path).

## Fault D — packages toggle not logged (task D1)

- admin/packages.php:189-196: read `name, is_active` first; after the UPDATE,
  `rh_log_event('room_packages', 'info', "Package activated: {name}" / "Package deactivated: {name}", ['id' => $id, 'old' => old, 'new' => $val, 'user' => $user['username']])`.
  Tell dept-logs-lead (write path list).

---

## Tests — scripts/tests/room_packages_test.php (new; Liwonde DB only, every write inside a rolled-back transaction or page_harness.php)

Pure:
1. `rh_pkg_units`: per_night 5 nights = 5; ppn 3 x 3 = 9; per_stay = 1.
2. `rh_pkg_row_units`: old row qty 1, 50,000 / 250,000 -> 5; new row qty 5 -> 5; price 0 -> qty; non-dividing -> qty.
3. `rh_pkg_requote` example 1 (5 -> 2 = 100,000), extend 2 -> 7 (350,000), example 2 (+1 adult = 180,000),
   per_stay unchanged on both, drifted ppn row uses `$fallbackAdults` and flags `persons_fallback`.
4. Allowed check: room_types excluding the new type -> flagged; applies_to all -> not; inactive -> not; deleted
   (no catalogue row) -> not.
5. `rh_pkg_label` strings for the three types and a drifted row.
DB (rolled back, Liwonde):
6. Example 1 through `calculateDateAdjustmentAmount`/date adjustment write: bookings total_amount / vat_amount /
   tourism_levy_amount / total_with_vat / package_total and the row's quantity / total_cost match the example; levy
   unchanged by the package part; then extend 2 -> 7: `rh_booked_stay_split` per_night still 300,000.
7. Extend (bookings.php extend path or `rh_reprice_at_booked_rate` + writer), early checkout (change checkout),
   stay-nights, late-stay leave-date: package follows the new nights each time; amount_due / credit_balance from
   `recalculateBookingFinancials` (paid 1,750,000 then shorten -> credit 1,050,000, same as today's paths).
8. edit-booking via page_harness: adults 2 -> 3 only (example 2) reprices; a booking with no ppn package and an
   adults-only edit stays byte-identical; History has the package old -> new line.
9. Preview = save: edit-booking JS formula figure (computed in PHP from the same passed data) equals the saved
   total; stay-correction re-quote does not refuse.
10. stay-correction changed-rate branch: 3 -> 4 nights at another extra-night rate, package x 4.
11. Room change: flagged without ack -> refused, nothing written; with ack -> saved, package kept, History line;
    a type that allows the package -> no flag; a secondary group room -> no flag.
12. Group/split and mixed (owner 2026-11-26: party rule): a secondary room's adults change reprices the lead's
    package (party adults x lead nights), History on both rows; a secondary nights-only change leaves it; the lead's
    own nights change reprices it; a stale quote is refused. Inclusive and exclusive VAT mode.
INVARIANTS asserted after EVERY DB case 6-13 (not only case 6): `rh_booked_stay_split()['per_night']` equals the
value before the change (unless the case changes the rate on purpose: 10, 11), and `bookings.package_total` equals
SUM(`booking_packages.total_cost`) within BALANCE_TOLERANCE; total_with_vat = room part + package part; levy equals
the room-part levy only.
13. `package_unverified`: package_total != SUM(total_cost) -> nothing recalculated, flag present.
14. C: page_harness POST to booking.php with a package switched off in the same transaction -> error message,
    no bookings / booking_packages row; mixed path the same.
15. B1: a new booking (rolled back) saves quantity 5 (per night, 5 nights) and 9 (ppn 3 x 3).
16. D: toggle via page_harness writes one system log row with old/new.
Also run (area suites): stay_nights.php, extend_test.php, late_stay_options.php, group_stay_correction_test.php,
stay_totals_all_in.php, booking_total_breakdown.php, stay_change_clash_test.php, booking smoke + linked_rooms +
concurrent_booking. config/database.php + money -> full `run_tests.php` once at ship, at Liwonde.

## Browser checks (headless, 1400 + 390, Liwonde, dev-router)

- admin/edit-booking.php?id=<booking with a per-night and a ppn package>: shorten dates and add an adult; "After this
  change" line and total equal the save.
- admin/booking-details.php?id=<same>: date-adjust modal preview; package rows after the save.
- admin/bookings.php upgrade modal on a booking whose package excludes the target type: warning + tick, submit
  disabled until ticked.
- admin/packages.php: toggle still works (no visible change).
- B3 only: invoice preview from booking-details (PDF/HTML) shows "Qty 5 x K50,000 = K250,000" and the wording.
- booking.php (C): the error message shows with the form kept (desktop + phone).

## Task order (one commit each; each passes the ship gate before the next)

0. Read-only at Liwonde (no writes): `SHOW COLUMNS FROM booking_packages` — `quantity` must hold persons x nights
   (e.g. 10 x 30 = 300). DONE: smallint unsigned, no fallback needed.
   Count rows with quantity 1 and a per-night/ppn type, and lead rows where package_total != SUM(total_cost).
1. D1 — admin/packages.php (Sonnet; logic-light; qa-auditor).
2. C1 + B1 — booking.php, includes/mixed-booking.php, includes/pricing.php helpers 1-3 + `rh_packages_for_booking` (Opus).
3. A1 — includes/pricing.php helpers 4-6, config/database.php, includes/booked-rate-change.php,
   includes/stay-correction.php, admin/edit-booking.php server side (Opus; accounting-lead gate; full suite).
4. A2 — admin/edit-booking.php JS, admin/booking-details.php JS (Opus: money previews).
5. A3 — includes/booking-room-change.php, admin/bookings.php (Opus).
6. B2 — config/email.php wording (Sonnet: display only, no money logic; qa-auditor). email.php only loads
   includes/pricing.php inside one function (:6973): add `require_once __DIR__ . '/../includes/pricing.php';` at the
   top next to :16 (or in both package blocks) before calling `rh_pkg_label`. Test emails: Liwonde test mode only.
7. B3 — config/invoice.php (unblocked 2026-11-26: Liwonde tree clean). Room invoice code only.
Each ship: GUIDES_AI_SYNC entry (A1/A3: the AI lead — reprice previews now include the package; room-change
refuses without the package tick); port to Rosalyn's with port-commit.php.

includes/booked-rate.php: no task.
