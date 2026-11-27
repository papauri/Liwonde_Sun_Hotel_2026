# New Booking page: step by step (plan, owner 2026-11-27)

Owner's words: "make a plan for the make a booking page. It needs to be more directional and step by step. No time
wasting bugs." FEATURE. Status: PLAN ONLY, waiting for the owner's go. Page: `admin/create-booking.php` (~4.8k lines,
form `#createBookingForm`, step JS ~4600-4750).

## How it works today (the causes of wasted time)
1. One long page: all six cards (Stay, Rooms, Guest, Status, Payment, Review) are shown at once. "Next" only scrolls,
   and the step bar just follows the scroll, so staff can scroll past a step without it being checked.
2. Checks run only on "Next" and on the final submit. A browser "required" field (e.g. the overpay tick) makes the
   browser jump somewhere on the long page with no clear message.
3. The total on the page is worked out in JavaScript; the save works it out again in PHP. When they differ (the x1.2
   fault fixed in e9ecb8d), the save refuses a payment the page said was right.
4. A refused save reloads the whole page at the top. e9ecb8d now keeps the room types and payment, but occupancy,
   quantity, price override and the step staff were on are lost.
5. Order: Payment depends on Status (Tentative switches payment off), but they are separate steps, and guest credit,
   notes and the email ticks are spread over Payment and Review.

## Target
One step on screen at a time, in this order, each with one clear "Continue" button:
1. **Dates and guests**: check-in, check-out, adults, children, special requests.
2. **Rooms**: only rooms free for those dates; cannot continue until every guest is seated and nothing is over-booked.
3. **Guest**: returning-guest search first, then name, email, phone, country, address.
4. **Status and payment**: Confirmed / Pending / Tentative as three plain choice cards. Confirmed or Pending ->
   "Payment received now?" + method + amount + guest credit. Tentative -> quotation options instead of payment.
5. **Review and create**: read-only summary of steps 1-4, each with a "Change" link; internal notes, group
   reference, guest email tick + Edit wording; one "Create booking" button.

Always visible: a summary panel (rooms, nights, total, paid, still owed): right column on a computer, a bar at the
bottom on a phone.

## Rules that stop the time-wasting bugs
- The step bar lets you go back, or forward only to steps already passed. Going forward always runs the check.
- Each check names the field and puts the cursor on it, inside the current step. No browser pop-up jumps.
- The total staff see on Review is the one the save will use (Phase B). The payment amount is filled from it.
- A refused save never reloads the page: staff stay on the step that needs fixing with everything as they left it.
- The "Create booking" button is disabled while saving (no double booking from a double click).
- Field names and the POST stay the same, so the save logic, the Assistant's `response=json` replay and the Calendar
  "New booking here" links keep working.

## Phases (one ship each, Liwonde first, then port to Rosalyn's)
| Phase | What | Who | Review |
|---|---|---|---|
| A | Wizard shell: one step visible, order above (Status + Payment merged into step 4), step bar rules, in-step error messages, summary panel. JS + markup + CSS only; no change to what is posted. | frontend-specialist (`model: opus`, booking flow JS) then ui-designer | qa-auditor |
| B | Server total on Review: move the save's pricing (create-booking.php ~640-760: rates, plans, child supplement, joined rooms, VAT/levy, credit) into one function used by BOTH the save and a new read-only "price check" call. Review and the amount use its answer. | dept-rooms-lead (Opus), money reviewed by dept-accounting-lead | qa-auditor |
| C | Save without reload: the form posts in the background; a refusal returns the message plus the step it belongs to; success opens Booking details with the usual message. Double-click guard. | dept-rooms-lead (Opus) | qa-auditor |
| D | Guides + Assistant lines (only when the owner prompts the sync) | guides-kb-lead / dept-ai-lead | - |

## Test cases (headless browser on Liwonde, every phase)
1 room 2 nights pay in full; pay part (Partially Paid); pay over without tick (stopped in step 4 with a message) and
with tick (credit note); group of 2 room types; child supplement; rate plan room; joined room; Tentative with
quotation; guest credit applied; returning guest; Calendar prefill link; refused save (stays on the step, nothing
lost); change dates after paying (amount follows); phone width 390; reduced motion. Test bookings cancelled via the
app with the guest email off. Never on Rosalyn's.

## Owner decisions needed before Phase A
1. Merge "Status" into the Payment step (step 4), as above? (Recommended: yes, payment depends on it.)
2. Run all three phases A -> B -> C one after another, or ship A first and look at it before B and C?
