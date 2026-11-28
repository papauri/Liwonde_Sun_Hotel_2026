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

## Spec check 2026-11-29 (VERDICT: CHANGES) - this BUILD SPEC overrides the rules above where they differ
1. Migration `admin/migrations/093_booking_reactivated_at.php` (083 format): `bookings.reactivated_at DATETIME NULL
   DEFAULT NULL`, additive. Back up, `--run` on Liwonde only, never a LIVE_TARGETS DB. No runtime ALTER. Never rewrite created_at.
2. Helpers in config/database.php next to :321: `rh_reactivated_at_present(PDO)` (cached information_schema probe, as :2899),
   `rh_pending_expiry_hours()` (rule of :359-366), `rh_pending_clock_sql('b')` (GREATEST(b.created_at, COALESCE(b.reactivated_at,
   b.created_at)) when the column exists, else b.created_at), `rh_pending_lapse_at(array $b)`. Used at :370 (sweep),
   admin/includes/booking-status-info.php:333 and admin/bookings.php:4367-4368. The sweep catches Throwable: a missing column
   must never reach its SQL.
3. No column = no feature: button hidden, action answers 409 "Run migration 093 first."
4. Notice job (includes/auto-email-jobs.php:1043): stage `$prefix.'expired'` when reactivated_at empty/missing, else
   `$prefix.'expired:r'.strtotime(reactivated_at)` (<= 40 chars, :458 prefix mapping still matches). Second lapse -> notice once.
5. Eligibility (every row): status expired, deleted_at NULL; tentative_expires_at NULL (ASSUMPTION: expired tentative holds not
   reactivated); check-in today or later; no payments (rh_booking_has_payment_sql; ASSUMPTION: refuse, not credit). Refuse when an
   active booking has the same email + room type + dates (booking.php:656 rule), naming its reference.
6. Group: every expired non-deleted row under the root (primary_booking_id ?: id), all or nothing (ASSUMPTION). Packages
   re-priced only on the row holding booking_packages rows.
7. New `includes/booking-reactivate.php`: `rh_reactivate_quote($pdo,$id)` pure; `rh_reactivate_apply($pdo,$id,$expectedNewTotal,$user)`
   throws RuntimeException with a plain message.
8. Apply order: rh_related_room_type_ids before begin; begin; lock booking rows FOR UPDATE in id order; rh_lock_room_types;
   availability per (type, dates) via checkRoomAvailability(type,in,out,null,children,roomsWithChildren) + ga_inventory_error
   (+ rh_joined_members_stock_error for joined); UPDATE status='pending', is_tentative=0, expired_at=NULL, reactivated_at=NOW()
   WHERE id=? AND status='expired' AND deleted_at IS NULL, rowCount != 1 -> "Already reactivated"; restore rooms from the latest
   released booking_rooms rows (joined: same pair via assignRoomCombinationToBooking, else first free pair from
   getAvailableRoomCombinations, none -> refuse; single: assignIndividualRoomToBooking, false -> left unassigned and said so,
   ASSUMPTION); re-price; void fee; recalculateBookingFinancials (false -> throw); commit.
9. Re-price per row with rh_guest_price_row (includes/guest-booking-quote.php:42), rate plans re-evaluated for today; child rule
   rh_child_rule_for + rh_child_limit_error; packages rh_packages_for_booking (missing -> rh_package_unavailable_message). One UPDATE:
   total_amount, vat_amount, tourism_levy_amount, total_with_vat, vat_rate, tourism_levy_percent, child_supplement_total, the 4 child
   snapshot columns, rate_plan_id/label/discount, package_total; then booking_packages price_amount/quantity/total_cost.
   New total vs expected > BALANCE_TOLERANCE -> 409 "Price changed, review again".
10. Fee void inline (voidBookingCharge opens its own transaction): UPDATE booking_charges SET voided=1, voided_at=NOW(),
    void_reason='Booking reactivated', voided_by=? WHERE booking_id=? AND voided=0 AND description LIKE '%'.rh_noshow_fee_tag(id).
    A later lapse posts no second fee (ASSUMPTION; shared code unchanged).
11. Dialog: old total (sum rh_booking_full_total), new total, old/new rate-plan labels, package changes, room outcome.
12. Permission `reactivate_booking` (getAllPermissions + manager list near admin/includes/permissions.php:93), `case 'reactivate'` in
    rhBookingActionPermission; booking-details.php:85-109 elseif for reactivate_preview / reactivate_apply, JSON as :1604.
13. History: logBookingStatusChange(expired -> pending, 'Reactivated by X; price old -> new'), logBookingEvent with price snapshot,
    logBookingAudit behind auditTableExists. Cancellation-policy stamp kept (ASSUMPTION). No email. Quotations stay expired.

## Tests
Extra: second lapse -> notice exactly once; no column -> no error and sweep still runs; joined-pair restore; guest booked again
-> refused; payment exists -> refused.
expired future booking + room free -> pending, new price = today's quote, amount_due right, does not expire on the
next load; room taken -> refused, nothing changed; past check-in -> refused; tagged group room -> fee voided;
no permission -> refused; two parallel attempts -> one wins.
