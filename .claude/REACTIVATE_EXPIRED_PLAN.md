# Reactivate an expired booking (FEATURE, owner 2026-11-29)

Owner's words: "Would we benefit from re-activating an expired automatic booking if the room and dates are available
and if yes have the option" + decision "get re-priced at today's rate" (2026-11-29).
Status: NOT STARTED. Build in its own fresh session (one feature per session). CRITICAL (bookings/time + money):
spec-check first, builder-critical / dept-rooms-lead (Opus), accounting lead reviews the price, qa-auditor, full suite tier.

## What it does
A "Reactivate" action on an expired booking's page (admin/booking-details.php) puts it back to `pending` with the
same reference, guest and history, after a fresh availability check, re-priced at today's rate.

## Rules (all must hold)
1. Only status `expired`, not deleted. Opens the terminal state `'expired' => []` (config/database.php ~2822) only
   through this action, never through the general status change.
2. Check-in today or later; past check-in = refused with the reason.
3. Availability: the same check as create-booking (joined rooms / room_combinations, blocked dates, the same room or
   individual room). Taken = refused, name what clashes. Row lock / transaction so two staff cannot double-book.
4. Price: re-priced at TODAY's rate (owner 2026-11-29) for the same room type, dates, guests, children and rate plan
   through the existing pricing path (includes/pricing.php / the create-booking quote), VAT + tourism levy recomputed,
   then recalculateBookingFinancials(). Show old vs new total in the confirm dialog before saving.
5. Expiry clock: _expireStalePendingBookings() (config/database.php ~342) expires `pending` bookings
   `pending_expiry_hours` (default 48) after `created_at`. A reactivated booking would lapse again on the next page
   load, so the clock must restart (decide: a reactivated_at column honoured by the job, or the job keys on
   GREATEST(created_at, reactivated_at)). Migration = owner-visible, test on Liwonde only.
6. Group no-show / expiry fee (rh_noshow_fee_tag charge) on the booking: voided on reactivation.
7. Quotations set to `expired` by the expiry: leave as history; a new one can be sent.
8. Permission: new `reactivate_booking` (default admin + manager) or reuse `edit_booking`; decide in spec-check.
9. History: booking_audit_log + timeline row "Reactivated by <staff>, price old -> new". System Logs via the hook.
10. Guest email: none automatically (outbound is muted at Liwonde anyway); staff can resend confirmation as today.

## Tests
expired future booking + room free -> pending, new price = today's quote, amount_due right, does not expire on the
next load; room taken -> refused, nothing changed; past check-in -> refused; tagged group room -> fee voided;
no permission -> refused; two parallel attempts -> one wins.
