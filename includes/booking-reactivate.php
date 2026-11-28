<?php
/**
 * Reactivate an expired booking (FEATURE, owner 2026-11-29: "re-activating an expired automatic booking if the room and
 * dates are available" + "get re-priced at today's rate"). Spec: .claude/REACTIVATE_EXPIRED_PLAN.md (BUILD SPEC).
 *
 * An expired booking (and every other expired room of its group, all or nothing) goes back to 'pending' with the same
 * reference, guest and history, after a fresh availability check under the room-type locks, re-priced with the guest
 * booking's own pricing (rh_guest_price_row: today's rate plans, VAT, tourism levy, child rule, packages). Its hold clock
 * restarts (bookings.reactivated_at, migration 093). Without that column the feature is off ("Run migration 093 first.").
 *
 *   rh_reactivate_quote($pdo, $id)                              read only: the review (old vs new total, plans, rooms)
 *   rh_reactivate_apply($pdo, $id, $expectedNewTotal, $user)    the save; throws RuntimeException with a plain message
 *
 * No email is sent. Quotations the expiry marked expired stay as history. A group no-show / expiry charge is voided.
 */

require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/guest-allocation.php';
require_once __DIR__ . '/guest-booking-quote.php';
require_once __DIR__ . '/booking-timeline.php';

if (!function_exists('rh_reactivate_row_error')) {
    /**
     * Why this one bookings row cannot be reactivated, or '' when it can (status, deleted, tentative hold, check-in date,
     * money). Read only.
     */
    function rh_reactivate_row_error(PDO $pdo, array $b): string
    {
        $ref = (string)($b['booking_reference'] ?? ('#' . (int)($b['id'] ?? 0)));
        if ((string)($b['status'] ?? '') !== 'expired') {
            return "Only an expired booking can be reactivated ({$ref} is " . (string)($b['status'] ?? 'unknown') . ').';
        }
        if (!empty($b['deleted_at'])) {
            return "{$ref} has been deleted. Restore it first.";
        }
        // ASSUMPTION (spec 2026-11-29): an expired tentative hold is not reactivated.
        if (!empty($b['tentative_expires_at'])) {
            return "{$ref} was a tentative hold. Expired holds are not reactivated: make a new booking.";
        }
        $ci = substr((string)($b['check_in_date'] ?? ''), 0, 10);
        if ($ci === '' || $ci < date('Y-m-d')) {
            return "{$ref}: the check-in date (" . ($ci !== '' ? date('j M Y', strtotime($ci)) : 'none') . ') has passed. Make a new booking instead.';
        }
        // ASSUMPTION (spec 2026-11-29): any money on the booking = refused (never turned into a credit here).
        $st = $pdo->prepare('SELECT ' . rh_booking_has_payment_sql('b') . ' FROM bookings b WHERE b.id = ?');
        $st->execute([(int)$b['id']]);
        if ((int)$st->fetchColumn() === 1) {
            return "{$ref} has a payment recorded. It cannot be reactivated here: make a new booking and move the money.";
        }
        return '';
    }

    /** The expired, not deleted rows of the booking's group (root = primary_booking_id ?: id), by id. Plain read. */
    function rh_reactivate_group_rows(PDO $pdo, array $target): array
    {
        $root = (int)($target['primary_booking_id'] ?? 0) ?: (int)$target['id'];
        $st = $pdo->prepare("SELECT * FROM bookings WHERE (id = ? OR primary_booking_id = ?) AND status = 'expired' AND deleted_at IS NULL ORDER BY id");
        $st->execute([$root, $root]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The rooms the booking held when it was released: the latest released booking_rooms rows (primary first). */
    function rh_reactivate_released_rooms(PDO $pdo, int $bookingId): array
    {
        if (!bookingAvailabilityTableExists('booking_rooms')) {
            return [];
        }
        $st = $pdo->prepare("SELECT br.individual_room_id, br.room_combination_id, br.is_primary, ir.room_number
            FROM booking_rooms br LEFT JOIN individual_rooms ir ON ir.id = br.individual_room_id
            WHERE br.booking_id = ? AND br.released_at IS NOT NULL
              AND br.released_at = (SELECT MAX(x.released_at) FROM booking_rooms x WHERE x.booking_id = ? AND x.released_at IS NOT NULL)
            ORDER BY br.is_primary DESC, br.individual_room_id");
        $st->execute([$bookingId, $bookingId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The whole plan for $rows (the expired rows of the group, $target among them): checks, room outcome and the new
     * price of every row. Throws RuntimeException with a plain message. Writes nothing. Run under the room-type locks
     * by rh_reactivate_apply(), without them by rh_reactivate_quote().
     */
    function rh_reactivate_plan(PDO $pdo, array $target, array $rows): array
    {
        if (!rh_reactivated_at_present($pdo)) {
            throw new RuntimeException('Run migration 093 first.');
        }
        $ids = array_map(static function ($r) { return (int)$r['id']; }, $rows);
        if (!in_array((int)$target['id'], $ids, true)) {
            throw new RuntimeException(rh_reactivate_row_error($pdo, $target) ?: 'This booking cannot be reactivated.');
        }
        foreach ($rows as $r) {
            $err = rh_reactivate_row_error($pdo, $r);
            if ($err !== '') {
                throw new RuntimeException($err . (count($rows) > 1 ? ' The rooms of a group are reactivated together or not at all.' : ''));
            }
        }
        // The guest booked again meanwhile (same email, room type and dates, still active: the booking.php rule).
        $in = implode(',', array_fill(0, count($ids), '?'));
        $dup = $pdo->prepare("SELECT booking_reference FROM bookings WHERE guest_email = ? AND room_id = ? AND check_in_date = ? AND check_out_date = ?
            AND status IN ('pending', 'tentative', 'confirmed', 'checked-in') AND deleted_at IS NULL AND id NOT IN ($in) ORDER BY id LIMIT 1");
        foreach ($rows as $r) {
            $email = trim((string)($r['guest_email'] ?? ''));
            if ($email === '') {
                continue; // ASSUMPTION (2026-11-29, reactivate): no email = nothing to match on
            }
            $dup->execute(array_merge([$email, (int)$r['room_id'], (string)$r['check_in_date'], (string)$r['check_out_date']], $ids));
            $other = $dup->fetchColumn();
            if ($other !== false) {
                throw new RuntimeException('The guest has booked again: ' . $other . ' has the same room type and dates. Use that booking; nothing was changed.');
            }
        }

        $typeSt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
        $types = [];
        $groups = [];
        foreach ($rows as $r) {
            $t = (int)$r['room_id'];
            if (!isset($types[$t])) {
                $typeSt->execute([$t]);
                $types[$t] = $typeSt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$types[$t]) {
                    throw new RuntimeException('The room type of ' . $r['booking_reference'] . ' no longer exists.');
                }
            }
            $groups[$t . '|' . $r['check_in_date'] . '|' . $r['check_out_date']][] = $r;
        }

        $plan = ['rows' => [], 'old_total' => 0.0, 'new_total' => 0.0, 'ids' => $ids];
        $claimedRooms = [];
        foreach ($groups as $g) {
            $first = $g[0];
            $typeId = (int)$first['room_id'];
            $type = $types[$typeId];
            $typeName = (string)$type['name'];
            $ci = (string)$first['check_in_date'];
            $co = (string)$first['check_out_date'];
            $children = 0;
            $childRooms = 0;
            foreach ($g as $r) {
                $children += (int)($r['child_guests'] ?? 0);
                $childRooms += (int)($r['child_guests'] ?? 0) > 0 ? 1 : 0;
            }
            // Same stock check as a new booking: inventory, blocked dates, joined rooms, every night.
            $av = checkRoomAvailability($typeId, $ci, $co, null, $children, $childRooms);
            $invErr = ga_inventory_error($av, count($g), $typeName, $ci, $co);
            if ($invErr !== '') {
                $why = trim((string)($av['blocked_message'] ?? ''));
                throw new RuntimeException('Cannot reactivate: ' . $typeName . ' is not free for ' . $ci . ' to ' . $co . ' (' . rtrim($invErr, '. ')
                    . ($why !== '' ? '; ' . $why : '') . '). Nothing was changed.');
            }
            $joined = roomTypeHasActiveCombinations($typeId);
            $combos = $joined ? getAvailableRoomCombinations($typeId, $ci, $co) : [];
            $chosenCombos = [];
            foreach ($g as $r) {
                $rid = (int)$r['id'];
                $released = rh_reactivate_released_rooms($pdo, $rid);
                $room = ['kind' => 'none', 'combination' => null, 'individual_room_id' => 0, 'text' => 'No room was assigned before: assign one as usual.'];
                if ($joined) {
                    $oldCombo = 0;
                    foreach ($released as $rel) {
                        if ((int)$rel['room_combination_id'] > 0) {
                            $oldCombo = (int)$rel['room_combination_id'];
                            break;
                        }
                    }
                    $pick = null;
                    $free = static function (array $c) use (&$claimedRooms): bool {
                        return !in_array((int)$c['room_a_id'], $claimedRooms, true) && !in_array((int)$c['room_b_id'], $claimedRooms, true);
                    };
                    foreach ($combos as $c) {
                        if ((int)$c['id'] === $oldCombo && $free($c)) {
                            $pick = $c;
                            break;
                        }
                    }
                    $same = $pick !== null;
                    if ($pick === null) {
                        foreach ($combos as $c) {
                            if ($free($c)) {
                                $pick = $c;
                                break;
                            }
                        }
                    }
                    if ($pick === null) {
                        throw new RuntimeException('Cannot reactivate: no joined room pair of ' . $typeName . ' is free for ' . $ci . ' to ' . $co . '. Nothing was changed.');
                    }
                    $claimedRooms[] = (int)$pick['room_a_id'];
                    $claimedRooms[] = (int)$pick['room_b_id'];
                    $chosenCombos[] = $pick;
                    $pairName = trim((string)($pick['room_a_number'] ?? '') . ' + ' . (string)($pick['room_b_number'] ?? ''), ' +');
                    $room = ['kind' => 'pair', 'combination' => $pick, 'individual_room_id' => 0,
                        'text' => ($same ? 'Same joined rooms ' : ($oldCombo > 0 ? 'Its joined rooms are taken: rooms ' : 'Joined rooms ')) . $pairName . ($same ? ' kept' : ' given instead')];
                } else {
                    $oldRoom = 0;
                    $oldNo = '';
                    foreach ($released as $rel) {
                        if ((int)$rel['individual_room_id'] > 0) {
                            $oldRoom = (int)$rel['individual_room_id'];
                            $oldNo = (string)($rel['room_number'] ?? '');
                            break;
                        }
                    }
                    if ($oldRoom > 0) {
                        $ia = in_array($oldRoom, $claimedRooms, true) ? ['available' => false] : checkIndividualRoomAvailability($oldRoom, $ci, $co, $rid);
                        if (!empty($ia['available'])) {
                            $claimedRooms[] = $oldRoom;
                            $room = ['kind' => 'room', 'combination' => null, 'individual_room_id' => $oldRoom, 'text' => 'Same room ' . $oldNo . ' kept'];
                        } else {
                            // ASSUMPTION (spec 2026-11-29): its old room is taken = reactivated without a room, said so.
                            $room = ['kind' => 'none', 'combination' => null, 'individual_room_id' => 0,
                                'text' => 'Room ' . $oldNo . ' is taken now: left without a room, assign one as usual'];
                        }
                    }
                }

                // Price at today's rate: the guest booking's own helper (rate plans re-evaluated today, VAT, levy).
                $nights = max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400));
                $kids = (int)($r['child_guests'] ?? 0);
                $childRule = rh_child_rule_for($pdo, $typeId);
                $policy = ga_resolve_policy($type);
                $childErr = rh_child_limit_error($childRule, $kids, !empty($policy['children_allowed']), $typeName);
                if ($childErr !== '') {
                    throw new RuntimeException('Cannot reactivate ' . $r['booking_reference'] . ': ' . $childErr);
                }
                $occ = trim((string)($r['occupancy_type'] ?? ''));
                if ($occ === '') {
                    $gp = ga_plan($type, max(1, (int)$r['number_of_guests']), $kids);
                    $occ = (string)($gp['allocation'][0]['occupancy_type'] ?? 'double');
                }
                // Packages: only on the row holding booking_packages rows, each re-priced at today's price.
                $pkOld = $pdo->prepare('SELECT * FROM booking_packages WHERE booking_id = ? ORDER BY id');
                $pkOld->execute([$rid]);
                $oldPkgs = $pkOld->fetchAll(PDO::FETCH_ASSOC);
                $newPkgs = [];
                $missing = [];
                $pkgTotal = 0.0;
                foreach ($oldPkgs as $op) {
                    // ASSUMPTION (2026-11-29, reactivate): a per-person package keeps the head count it was sold for.
                    $persons = (string)$op['price_type'] === 'per_person_per_night'
                        ? max(1, intdiv(rh_pkg_row_units($op), max(1, (int)($r['number_of_nights'] ?? $nights))))
                        : max(1, (int)($r['adult_guests'] ?? 1));
                    $pk = rh_packages_for_booking($pdo, $typeId, [(int)$op['package_id']], $nights, $persons);
                    if ($pk['missing']) {
                        $missing = array_merge($missing, $pk['missing']);
                        continue;
                    }
                    $nr = $pk['rows'][0];
                    $nr['old_total_cost'] = (float)$op['total_cost'];
                    $nr['booking_package_id'] = (int)$op['id'];
                    $newPkgs[] = $nr;
                    $pkgTotal += (float)$nr['total_cost'];
                }
                if ($missing) {
                    throw new RuntimeException('Cannot reactivate ' . $r['booking_reference'] . ': ' . rh_package_unavailable_message($missing));
                }
                $pkgTotal = round($pkgTotal, 2);
                $priced = rh_guest_price_row($pdo, $type, $type, $room['combination'], $typeId, $ci, $co, $nights, $occ, $kids, $childRule, $pkgTotal);
                $oldTotal = rh_booking_full_total($r);
                $plan['rows'][] = [
                    'id' => $rid, 'ref' => (string)$r['booking_reference'], 'room_type' => $typeName, 'room_type_id' => $typeId,
                    'check_in' => $ci, 'check_out' => $co, 'nights' => $nights, 'occupancy' => $occ,
                    'old' => $r, 'old_total' => $oldTotal, 'new_total' => (float)$priced['total_with_vat'],
                    'old_plan' => trim((string)($r['rate_plan_label'] ?? '')), 'new_plan' => trim((string)($priced['dynamic']['rate_plan_label'] ?? '')),
                    'priced' => $priced, 'child_snapshot' => rh_child_rule_snapshot($childRule),
                    'old_packages' => $oldPkgs, 'new_packages' => $newPkgs, 'room' => $room,
                ];
                $plan['old_total'] += $oldTotal;
                $plan['new_total'] += (float)$priced['total_with_vat'];
            }
            if ($joined && $chosenCombos) {
                $stockErr = rh_joined_members_stock_error($chosenCombos, $ci, $co);
                if ($stockErr !== '') {
                    throw new RuntimeException('Cannot reactivate: ' . $stockErr . ' Nothing was changed.');
                }
            }
        }
        $plan['old_total'] = round($plan['old_total'], 2);
        $plan['new_total'] = round($plan['new_total'], 2);
        return $plan;
    }

    /** The review, safe for the browser (no row dumps). */
    function rh_reactivate_public(array $plan): array
    {
        $rows = [];
        foreach ($plan['rows'] as $p) {
            $pk = [];
            foreach ($p['new_packages'] as $n) {
                $pk[] = ['name' => (string)$n['package_name'], 'old' => round((float)$n['old_total_cost'], 2), 'new' => round((float)$n['total_cost'], 2)];
            }
            $rows[] = ['ref' => $p['ref'], 'room_type' => $p['room_type'], 'check_in' => $p['check_in'], 'check_out' => $p['check_out'], 'nights' => $p['nights'],
                'old_total' => round($p['old_total'], 2), 'new_total' => round($p['new_total'], 2),
                'old_plan' => $p['old_plan'], 'new_plan' => $p['new_plan'], 'packages' => $pk, 'room' => $p['room']['text']];
        }
        return ['rows' => $rows, 'old_total' => $plan['old_total'], 'new_total' => $plan['new_total']];
    }

    /** Read-only review of reactivating booking $id: ['ok' => bool, 'error' => string] + rh_reactivate_public(). */
    function rh_reactivate_quote(PDO $pdo, int $id): array
    {
        try {
            if (!rh_reactivated_at_present($pdo)) {
                throw new RuntimeException('Run migration 093 first.');
            }
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
            $st->execute([$id]);
            $target = $st->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                throw new RuntimeException('Booking not found.');
            }
            $plan = rh_reactivate_plan($pdo, $target, rh_reactivate_group_rows($pdo, $target));
            return ['ok' => true, 'error' => ''] + rh_reactivate_public($plan);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[reactivate] quote failed for #' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not check this booking. Reload the page and try again.'];
        }
    }

    /**
     * Reactivate booking $id (and the other expired rooms of its group). $expectedNewTotal = the new total the staff
     * member reviewed; a different price now is refused. $user = ['id', 'full_name' / 'username'].
     * Throws RuntimeException with a plain message; nothing is changed then.
     * @return array rh_reactivate_public() + ['room_notes' => string[]]
     */
    function rh_reactivate_apply(PDO $pdo, int $id, $expectedNewTotal, array $user): array
    {
        $uid = (int)($user['id'] ?? 0);
        $uname = trim((string)($user['full_name'] ?? '')) ?: (string)($user['username'] ?? 'Staff');
        if (!function_exists('hasPermission')) {
            require_once __DIR__ . '/../admin/includes/permissions.php';
        }
        if ($uid <= 0 || !hasPermission($uid, 'reactivate_booking')) {
            throw new RuntimeException('You do not have permission to reactivate bookings.');
        }
        if (!rh_reactivated_at_present($pdo)) {
            throw new RuntimeException('Run migration 093 first.');
        }
        if ($expectedNewTotal === null || $expectedNewTotal === '' || !is_numeric($expectedNewTotal)) {
            throw new RuntimeException('Review the new price first.');
        }
        $expected = round((float)$expectedNewTotal, 2);

        // Plain reads BEFORE the transaction (rh_related_room_type_ids rule): the group's ids and room types.
        $st = $pdo->prepare('SELECT id, primary_booking_id FROM bookings WHERE id = ?');
        $st->execute([$id]);
        $pre = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pre) {
            throw new RuntimeException('Booking not found.');
        }
        $root = (int)($pre['primary_booking_id'] ?? 0) ?: $id;
        $st = $pdo->prepare('SELECT id, room_id FROM bookings WHERE id = ? OR primary_booking_id = ? ORDER BY id');
        $st->execute([$root, $root]);
        $preRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $lockIds = array_map('intval', array_column($preRows, 'id'));
        if (!in_array($id, $lockIds, true)) {
            $lockIds[] = $id;
        }
        sort($lockIds, SORT_NUMERIC);
        $lockTypes = rh_related_room_type_ids($pdo, array_map('intval', array_column($preRows, 'room_id')));

        $pdo->beginTransaction();
        try {
            // Lock order: booking rows (ascending id), then room types (ascending id) - the booking-creation order.
            $lock = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
            $locked = [];
            foreach ($lockIds as $lid) {
                $lock->execute([$lid]);
                $row = $lock->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $locked[$lid] = $row;
                }
            }
            rh_lock_room_types($pdo, $lockTypes);
            $target = $locked[$id] ?? null;
            if (!$target) {
                throw new RuntimeException('Booking not found.');
            }
            if ((string)$target['status'] !== 'expired') {
                throw new RuntimeException('Already reactivated');
            }
            $rows = [];
            foreach ($locked as $row) {
                if ((string)$row['status'] === 'expired' && empty($row['deleted_at'])) {
                    $rows[] = $row;
                }
            }
            foreach ($rows as $row) {
                if (!in_array((int)$row['room_id'], $lockTypes, true)) {
                    throw new RuntimeException('The booking changed while it was being reactivated. Reload the page and try again.');
                }
            }
            $plan = rh_reactivate_plan($pdo, $target, $rows);
            if (abs($plan['new_total'] - $expected) > BALANCE_TOLERANCE) {
                throw new RuntimeException('Price changed, review again.');
            }

            // 1. Status back to pending; the hold clock restarts.
            $upd = $pdo->prepare("UPDATE bookings SET status = 'pending', is_tentative = 0, expired_at = NULL, reactivated_at = NOW(), updated_at = NOW()
                WHERE id = ? AND status = 'expired' AND deleted_at IS NULL");
            foreach ($plan['rows'] as $p) {
                $upd->execute([$p['id']]);
                if ($upd->rowCount() !== 1) {
                    throw new RuntimeException('Already reactivated');
                }
            }
            // 2. Rooms back (joined pair must be given, else nothing changes; a single room may be left unassigned).
            $notes = [];
            foreach ($plan['rows'] as $p) {
                $room = $p['room'];
                if ($room['kind'] === 'pair') {
                    $res = assignRoomCombinationToBooking($p['id'], (int)$room['combination']['id'], $uid ?: null);
                    if (empty($res['success'])) {
                        throw new RuntimeException('Cannot reactivate ' . $p['ref'] . ': ' . ((string)($res['message'] ?? '') ?: 'its joined rooms could not be given.') . ' Nothing was changed.');
                    }
                    $notes[] = $p['ref'] . ': ' . $room['text'] . '.';
                } elseif ($room['kind'] === 'room') {
                    if (assignIndividualRoomToBooking($p['id'], (int)$room['individual_room_id'], false, null, $uid ?: null) === false) {
                        $assignErr = function_exists('rh_room_assign_last_error') ? (string)rh_room_assign_last_error() : '';
                        // A database error / lock conflict inside the assign (it swallows the PDOException) may have
                        // aborted our transaction: never carry on, roll everything back. Only a business refusal falls through.
                        if (!$pdo->inTransaction() || $assignErr === 'Database error while assigning the room'
                            || (function_exists('rh_lock_conflict_message') && $assignErr === rh_lock_conflict_message())) {
                            throw new RuntimeException('Cannot reactivate ' . $p['ref'] . ': ' . ($assignErr !== '' ? $assignErr : 'the room could not be given') . '. Nothing was changed.');
                        }
                        $notes[] = $p['ref'] . ': left without a room (' . ($assignErr !== '' ? $assignErr : 'room not free') . '), assign one as usual.';
                    } else {
                        $notes[] = $p['ref'] . ': ' . $room['text'] . '.';
                    }
                } else {
                    $notes[] = $p['ref'] . ': ' . $room['text'] . '.';
                }
            }
            // 3. Today's price.
            $price = $pdo->prepare('UPDATE bookings SET total_amount = ?, vat_amount = ?, tourism_levy_amount = ?, total_with_vat = ?, vat_rate = ?,
                tourism_levy_percent = ?, child_supplement_total = ?, child_price_multiplier = ?, child_charge_mode = ?, child_charge_amount = ?,
                free_children = ?, rate_plan_id = ?, rate_plan_label = ?, rate_plan_discount = ?, package_total = ? WHERE id = ?');
            $pkUpd = $pdo->prepare('UPDATE booking_packages SET price_amount = ?, quantity = ?, total_cost = ? WHERE id = ? AND booking_id = ?');
            foreach ($plan['rows'] as $p) {
                $pr = $p['priced'];
                $cs = $p['child_snapshot'];
                $price->execute([$pr['total_amount'], $pr['vat'], $pr['levy'], $pr['total_with_vat'], $pr['vat_rate'], $pr['levy_rate'],
                    $pr['child_supplement'], $cs['child_price_multiplier'], $cs['child_charge_mode'], $cs['child_charge_amount'], $cs['free_children'],
                    $pr['dynamic']['rate_plan_id'] ?? null, ($pr['dynamic']['rate_plan_label'] ?? '') ?: null, ($pr['dynamic']['discount_amount'] ?? 0) ?: null,
                    $pr['package_total'], $p['id']]);
                foreach ($p['new_packages'] as $n) {
                    $pkUpd->execute([$n['price_amount'], max(1, (int)$n['quantity']), $n['total_cost'], $n['booking_package_id'], $p['id']]);
                }
            }
            // 4. The group no-show / expiry charge goes (inline: voidBookingCharge opens its own transaction).
            $void = $pdo->prepare("UPDATE booking_charges SET voided = 1, voided_at = NOW(), void_reason = 'Booking reactivated', voided_by = ?
                WHERE booking_id = ? AND voided = 0 AND description LIKE ?");
            $voided = [];
            foreach ($plan['rows'] as $p) {
                $void->execute([$uid ?: null, $p['id'], '%' . rh_noshow_fee_tag($p['id'])]);
                $voided[$p['id']] = $void->rowCount();
            }
            // 5. The stored balance follows.
            foreach ($plan['rows'] as $p) {
                if (!recalculateBookingFinancials($p['id'])) {
                    throw new RuntimeException('Could not recalculate the booking balance. Nothing was changed.');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('rh_is_lock_conflict') && rh_is_lock_conflict($e)) {
                throw new RuntimeException(rh_lock_conflict_message());
            }
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            error_log('[reactivate] apply failed for #' . $id . ': ' . $e->getMessage());
            throw new RuntimeException('Nothing was changed: the booking could not be reactivated. Reload the page and try again.');
        }

        // History (after the commit: a logging failure never undoes the reactivation). No email is sent.
        $m = static function (float $v): string { return number_format($v, 2); };
        foreach ($plan['rows'] as $p) {
            $why = 'Reactivated by ' . $uname . '; price ' . $m($p['old_total']) . ' -> ' . $m($p['new_total']);
            try {
                logBookingStatusChange($p['id'], $p['ref'], 'expired', 'pending', 'admin', $uid, $uname, $why);
                $pr = $p['priced'];
                logBookingEvent($p['id'], $p['ref'], 'Booking reactivated', 'financial',
                    $why . ' (re-priced at today\'s rate' . ($p['old_plan'] !== $p['new_plan'] ? ', rate plan ' . ($p['old_plan'] ?: 'none') . ' -> ' . ($p['new_plan'] ?: 'none') : '')
                    . (!empty($voided[$p['id']]) ? '; expiry charge voided' : '') . '). ' . $p['room']['text'] . '.',
                    $m($p['old_total']), $m($p['new_total']), 'admin', $uid, $uname,
                    ['price_snapshot' => ['source' => 'reactivate', 'rate' => round((float)$pr['rate'], 2), 'list_rate' => round((float)$pr['base_rate'], 2),
                        'nights' => $p['nights'], 'occupancy' => $p['occupancy'], 'room_type_id' => $p['room_type_id'], 'room_type' => $p['room_type'],
                        'rate_plan_id' => $pr['dynamic']['rate_plan_id'] ?? null, 'rate_plan_label' => $p['new_plan'] ?: null,
                        'child_supplement' => $pr['child_supplement'], 'vat_rate' => $pr['vat_rate'], 'vat_amount' => $pr['vat'],
                        'levy_rate' => $pr['levy_rate'], 'levy_amount' => $pr['levy'], 'package_total' => $pr['package_total'],
                        'total_with_vat' => $pr['total_with_vat'], 'old_total_with_vat' => $p['old_total'],
                        'joined_rooms_id' => $p['room']['kind'] === 'pair' ? (int)$p['room']['combination']['id'] : null]]);
                if (function_exists('logBookingAudit') && function_exists('auditTableExists') && auditTableExists($pdo, 'booking_audit_log')) {
                    logBookingAudit($p['id'], 'reactivated',
                        ['status' => 'expired', 'total_with_vat' => $m($p['old_total']), 'rate_plan_label' => $p['old_plan']],
                        ['status' => 'pending', 'total_with_vat' => $m($p['new_total']), 'rate_plan_label' => $p['new_plan']], $why, $p['ref']);
                }
            } catch (Throwable $e) {
                error_log('[reactivate] history log failed for ' . $p['ref'] . ': ' . $e->getMessage());
            }
        }
        return rh_reactivate_public($plan) + ['room_notes' => $notes];
    }
}
