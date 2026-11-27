# Booking pages step by step: New Booking (admin) + guest booking (plan + build spec)

Owner 2026-11-27: "make a plan for the make a booking page. It needs to be more directional and step by step. No time
wasting bugs." Then: "Status and Payment be one step. build A, B and C straight through. Its important we get this
correct especially because it has a lot to do with finances so use highest effort thinking and do same for guest
booking." FEATURE, approved. Agents on this work run at effort max (owner's words). Order: B -> A -> C (admin), then
G0 -> G-B -> G-A -> G-C (guest). One builder at a time on a given page. Every ship: Liwonde first, then port.
Rosalyn's is LIVE: no DB tests, writing scripts or test emails there. Test bookings on Liwonde are cancelled via the
app with the guest email off.

## Why it wastes time today (admin/create-booking.php, ~4.8k lines)
1. One long page with six stacked cards; "Next" only scrolls; the step bar follows the scroll, so steps get skipped.
2. Checks only on "Next" and on submit. A browser `required` field on a far card (overpay tick, credit reason,
   quotation fields) makes submit jump or silently do nothing.
3. The page total is JavaScript; the save is PHP. When they differ (x1.2 fault, fixed e9ecb8d) a right payment is refused.
4. A refused save reloads the page at the top (e9ecb8d keeps room types + payment; occupancy, quantity, override and
   the current step are still lost).
5. Status and Payment are separate though Tentative switches payment off; credit, notes, ticks are scattered.

## Target screen (admin)
One step on screen at a time, one "Continue" button each:
1. **Dates and guests**: check-in, check-out, total guests, children, special requests.
2. **Rooms**: room lines (type, quantity, occupancy, override for admin/manager, guest name per room), auto/pick room.
   Continue disabled while availability is loading; changing dates re-checks the rooms already picked.
3. **Guest**: returning-guest search first, then name, email, phone, country, address.
4. **Status and payment**: Confirmed / Pending / Tentative as three plain choice cards.
   Confirmed/Pending -> "Payment received now?", method, amount (filled from the SERVER total), overpay tick, guest
   credit. Tentative -> quotation options (send quotation PDF, valid for, note); payment fields cleared, not posted.
5. **Review and create**: read-only summary of 1-4 from the server quote (per room: rate, nights, plan, child
   charge; totals: before VAT, VAT, levy, total; paid now; credit to apply; still owed), each block with "Change";
   group reference, internal notes, guest email tick + Edit wording; one "Create booking" button.
Summary panel always visible (rooms, nights, total, paid, still owed): right column on desktop, bottom bar on phone.

## Phase B (admin): dry run of the real save ("price check")
Goal: the figures staff see in step 4 and Review are produced by the save's own code, so they cannot differ.
Do NOT copy the pricing into a new function. Add a dry-run mode to the existing POST handler
(`if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booking']))`, line ~332).

- Trigger: POST with `create_booking=1`, `csrf_token`, all normal fields, `dry_run=1`, `response=wizard`.
  CSRF checked as today. Same session/permission gate as the page.
- Run every line up to the end of the totals loop (~752), then STOP before the transaction (~785). Inserts,
  payments, assignments, emails, credit application never run.
- Side effects to switch off in dry run (verify by grep of everything above ~785 for INSERT/UPDATE/DELETE,
  rh_log_event, logBookingAudit, $_SESSION writes, GET_LOCK, header('Location')):
  a. idempotency block ~338-360 (no GET_LOCK, no existing-booking redirect, uuid neither reserved nor consumed);
  b. blocked price-override log ~392 (still refuse with the same message, just not logged);
  c. catch-block logging ~1496 (no rh_log_event for a dry-run refusal);
  d. anything else the grep finds.
- Availability: the locked check (~796-825) runs inside the transaction. Move its body into one local closure used
  in both places; in dry run call it without the transaction/lock so "no longer free" shows at step 4/Review.
- The overpay check (~755) and the email wording check (~779-783) do NOT throw in dry run: they come back as
  `warnings` with their step, alongside the full quote.
- Credit: applied after commit (~1125-1200). Move the eligibility filter (~1141-1175: status, expiry, balance, email
  match / audited override with reason) into ONE helper used by the save and the dry run. Preview: `will_apply` =
  min(sum of eligible balances, total_with_vat - cash collected), 0 when tentative. The save keeps applying per
  booking row exactly as now.
- Error -> step: keep a stage marker as the handler advances (1 stay, 2 rooms, 3 guest, 4 status/payment, 5 review/
  save) instead of matching message text. Split the combined basic validation (~430-438) so each message carries its
  own step (rooms -> 2, guest name/email/phone -> 3, dates/guests -> 1).
- `response=json` (the Assistant's replay) stays byte-for-byte as it is. `response=wizard` is new.
- JSON shape (dry run):
  ```
  { "ok": true|false, "step": 1-5 (when ok=false), "message": "...", "field": "<input id or ''>",
    "quote": { "nights": n, "currency": "MWK", "vat_mode": "...", "vat_rate": x, "levy_rate": y,
      "lines": [ { "room_id": id, "name": "...", "qty": q, "occupancy": "double",
                   "rows": [ { "room_price", "list_rate", "rate_plan_label", "child_supplement", "total_amount",
                               "vat_amount", "levy_amount", "total_with_vat", "price_source" } ],
                   "line_total_with_vat": t } ],
      "totals": { "total_with_vat", "vat", "levy", "child_supplement" },
      "payment": { "received": bool, "amount": a, "status": "paid|partial|unpaid", "over_by": o },
      "credit": { "will_apply": c, "notes": [ { "id", "number", "balance", "override" } ] },
      "still_owed": s, "email_key": "..." },
    "warnings": [ { "step": 4, "message": "..." } ] }
  ```
  Every figure is the `$saved` value the save would store (2 dp), summed the same way.
- Proof: no-write test on Liwonde: count rows in bookings, payments, booking audit/history, system log, credit_notes,
  credit applications before and after 10 dry runs (incl. refused ones): identical. Static test that dry run returns
  before `beginTransaction`. Totals equal to a real save of the same form for: double with no tier price, triple,
  child supplement, rate plan, joined room, manager override, 2 room types, tentative.

### Spec-check corrections (2026-11-27, these OVERRIDE the Phase B / C text where they differ)
1. Side effect (d): `admin/includes/activity-hook.php:132-156` writes an admin_activity_log row on every POST; a dry
   run calls `rh_sa_hook_mark_refused()` (:95). The no-write proof runs over HTTP (the hook is off in CLI) and also
   counts admin_activity_log, and system-log rows with source create-booking only (presence/scheduler write always).
2. Idempotency in dry run: no GET_LOCK, but KEEP the read-only `idem_find_existing_booking` (~354): if the uuid already
   made a booking, answer `{ok:true, duplicate:true, redirect}` and set NO flash (else a quote after a Create that saved
   would count that booking against the room).
3. Create (Phase C) posts `quote_total` (the total_with_vat staff saw). The save refuses at step 5 when its own grand
   total differs by more than BALANCE_TOLERANCE ("The price changed since you checked it: <old> -> <new>. Check the
   Review again.") — rate plans count from today (includes/pricing.php:118) and the joined pair is picked at save time.
   Only when `response=wizard`; the Assistant's `response=json` and no-JS posts are unchanged.
4. Step mapping: availability -> 2, but past / blocked dates (config/database.php:5556-5616) -> 1; wording (~782) -> 5;
   CSRF, booking disabled, lock conflict (~335/363/1495) -> step 0 = shown where staff are. The split basic validation
   must still throw the SAME combined text for non-wizard posts (response=json unchanged).
5. `credit.will_apply` = max(0, min(eligible, total_with_vat - cash)) using cash AFTER ~769-773 (0 when no payment).
6. Add `net` (ex VAT, ex levy) per row and in totals (total_amount includes levy, ~718). `payment.status` stays
   cash-only (as the save sets it); add `final_status` from still_owed (credit application re-derives it,
   config/credit-notes.php:445).
7. Phase C wording: after the commit (~1088+) a failure is NOT "nothing was saved"; and admin/includes/offline-queue.js
   :270-292 may catch the submit (form ~2274). Non-JSON / network reply says "It may have saved. Check Bookings before
   pressing Create again" — the same client_uuid makes a re-press safe. `dry_run` is honoured ONLY together with
   `response=wizard`, added by the JS (never a form field).
8. Credit threshold: the save skips credit at <=0.005 (~1159/1188) but applyCreditNote refuses at <=0.01
   (credit-notes.php:274/321): the shared eligibility helper uses the same 0.01 so preview = applied.
9. Static tests to keep green: scripts/tests/ai_assistant_test.php:1557 (exactly 4 json exits),
   scripts/tests/email_wording_test.php:240-242.

## Phase A (admin): the step-by-step screen
- Show one step at a time; remove the IntersectionObserver that sets the active step from the scroll.
- Step bar: back to any earlier step; forward only to steps already passed; forward always runs the check.
- `novalidate` on the form; `validateSection(n)` covers every rule a hidden `required` used to enforce (overpay tick,
  credit override reason, quotation fields, wording). Errors show inside the step, name the field, focus it.
- Step 4 fetches the dry-run quote on entry; Review fetches it again; any change to steps 1-4 marks it stale and
  Review refuses to create from a stale quote (fetches again). Amount collected is filled from `quote.totals.
  total_with_vat - credit.will_apply` until staff type their own; paid/partial/over is judged against the quote with
  BALANCE_TOLERANCE 0.01, exactly like the save.
- Changing status or payment after the email wording was edited clears the edited wording (with a plain note), so the
  9b81189 key check never refuses at the end.
- Tentative clears and disables the payment fields (not posted).
- Start step: Calendar prefill -> step 1 filled, rooms prefilled; refused save (no-JS fallback) -> the step of the error.
- Update `scripts/tests/create_booking_payment_test.php` strings to the new code; keep every check's meaning.

## Phase C (admin): create without reload
- "Create booking" posts in the background with `response=wizard` (not dry run). Button disabled while saving.
  The same `client_uuid` is reused on a retry so the idempotency code returns the existing booking.
- Success: `{ok:true, redirect:"booking-details.php?id=N"}`; the success flash message is kept (unlike response=json).
  Duplicate -> same as success. Still saving (409) -> JSON message.
- Refusal: `{ok:false, step, message, field}` -> open that step, show the message, nothing lost.
- Non-JSON reply (expired session / login page, CSRF, PHP fatal, network): "Nothing was saved. <reason>. Sign in again
  in a new tab, then press Create again." The button comes back; the form is untouched.
- No JavaScript: the normal POST and the e9ecb8d restore still work.

## Guest booking (booking.php 1.8k lines + js/booking.js 2.8k; no payment taken online)
Today: sections When / Who / Room / Guest / Rooms & requests / Booking type / rate plan / packages / submit on one
page; JS shows a total (partly from check-availability.php `split_pricing`); the save prices again (~432-620:
applyDynamicPricing, rh_stay_totals room + child, packages rh_stay_totals(...,'price', false)).
booking.php differs from Rosalyn's in ~31 lines (branding); js/booking.js identical. Port by patch, not copy.
- **G0 (first, read-only)**: on Liwonde, compare the total the guest page shows with what the save stores for: double
  with no tier price, single, child, rate plan, package, two rooms, joined room, tentative. A mismatch is a LIVE fault
  at Rosalyn's: ship it first as a PATCH.
- **G-B**: dry run of the guest save, same rules as Phase B, plus: public CSRF (`includes/public-csrf.php`), its own
  light rate limit (not the booking-attempt counter ~61), no existing-booking redirect (~50), no logging, no email.
  Review shows the server's figures.
- **G-A**: steps on screen one at a time: 1 When, 2 Who, 3 Room (and rate plan / packages), 4 Your details (+ booking
  type standard/tentative), 5 Review and book. Editorial design system (`css/sections/ballena.css`,
  `css/components/editorial.css`), honours prefers-reduced-motion, phone first.
- **G-C**: background submit; refusal returns to its step with everything kept; success -> booking-confirmation.php;
  double-click guard; non-JSON reply handled.
- Builder: dept-guest-site-lead (`model: opus`, bookings). Review: qa-auditor; final-review on G-B and G-C.

## Test cases (headless browser on Liwonde, every phase)
1 room 2 nights pay in full; pay part (Partially Paid); pay over without tick (stopped in step 4) and with tick
(credit note); 2 room types; child supplement; rate plan; joined room; manager override; under-tier refusal for front
desk; Tentative with quotation; guest credit applied (own + override with reason); returning guest; Calendar prefill
link; refused save (stays on its step, nothing lost); change dates after paying (quote stale -> refreshed, amount
follows); expired session at Create; double click; phone width 390; reduced motion. Guest: the same where they apply.

## Reviews
spec-check before Phase B and G-B (money). qa-auditor (Opus) on every phase; dept-accounting-lead reads B's money
paths; final-review on B, C, G-B, G-C after qa-auditor passes. One fix round; non-blockers -> LATER lines.
