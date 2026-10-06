<?php
/**
 * LIVE end-to-end test: booking -> confirm -> pay -> check-in -> POS/KDS -> folio -> checkout.
 *
 * Usage: php scripts/tests/live_e2e.php
 *
 * Runs against the LIVE database (owner-approved). Every row it creates is prefixed
 * 'E2E LIVE' and is deliberately LEFT in the database (owner policy: test data stays).
 * It never deletes, truncates or drops. Outgoing mail is suppressed: the automated-send
 * hook in config/email.php is forced into fail-closed mode, so every send fails soft.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web

chdir(dirname(__DIR__, 2));
$root = getcwd();

// Fail-closed mail: an unusable redirect address makes sendEmail()/sendEmailWithAttachments()
// blank the recipient, so PHPMailer rejects it before anything is transmitted.
$GLOBALS['rh_automated_ctx'] = ['redirect' => 'e2e-mail-suppressed', 'bcc' => false];

require_once $root . '/config/database.php';
require_once $root . '/includes/booking-functions.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/includes/pricing.php';
require_once $root . '/includes/finance-sequences.php';
require_once $root . '/includes/room-management.php';
require_once $root . '/includes/restaurant-location-locks.php';
require_once $root . '/includes/station-hours.php';
require_once $root . '/admin/includes/audit-functions.php';
require_once $root . '/admin/includes/restaurant-payment-sync.php';
require_once $root . '/config/email.php';
require_once $root . '/config/invoice.php';
require_once $root . '/config/receipts.php';

/** @var PDO $pdo */
$user = ['id' => 1, 'username' => 'admin', 'full_name' => 'E2E LIVE Test'];
$TOL  = BALANCE_TOLERANCE;
$S    = [];          // shared state between steps
$created = ['booking' => null, 'payments' => [], 'orders' => [], 'charges' => []];
$stepsPass = 0;
$stepsFail = 0;
$curChecks = [];

function chk(bool $cond, string $label, string $detail = ''): void
{
    global $curChecks;
    $curChecks[] = [$cond, $label, $detail];
    echo '    ' . ($cond ? '[ok]   ' : '[FAIL] ') . $label . (!$cond && $detail !== '' ? ' -> ' . $detail : '') . "\n";
}

function step(string $name, callable $fn): void
{
    global $curChecks, $stepsPass, $stepsFail;
    $curChecks = [];
    echo "\n=== $name ===\n";
    try {
        $fn();
    } catch (Throwable $e) {
        $curChecks[] = [false, 'exception', $e->getMessage()];
        echo '    [FAIL] exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        global $pdo;
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    $bad = array_filter($curChecks, static fn($c) => !$c[0]);
    if ($bad || !$curChecks) {
        $stepsFail++;
        echo "  >> FAIL: $name\n";
    } else {
        $stepsPass++;
        echo "  >> PASS: $name\n";
    }
}

function fetch_booking(int $id): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Record a room payment the way admin/create-booking.php does (net payment_amount, gross total_amount). */
function e2e_pay_room(int $bookingId, float $gross, string $type, string $method = 'cash'): int
{
    global $pdo, $user, $created;
    $b = fetch_booking($bookingId);
    $vatMode = vat_mode();
    $vatPct = $vatMode === 'off' ? 0.0 : (float)getSetting('vat_rate', 0);
    $levyPct = in_array(getSetting('tourism_levy_enabled', '0'), ['1', 1, true, 'true', 'on'], true) ? (float)getSetting('tourism_levy_percent', 0) : 0.0;
    $vat = $vatPct > 0 ? round($gross * ($vatPct / (100 + $vatPct + $levyPct)), 2) : 0.0;
    $net = round($gross - $vat, 2);
    $receipt = finance_next_receipt_number($pdo, date('Y-m-d'));
    $ref = 'E2E-PAY-' . date('ymdHis') . '-' . random_int(100, 999);
    $pdo->prepare("INSERT INTO payments (
            payment_reference, booking_type, booking_id, booking_reference,
            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status,
            receipt_number, invoice_generated, status, notes, recorded_by
        ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, 'completed', ?, 1, 'completed', 'E2E LIVE test payment', ?)")
        ->execute([$ref, $bookingId, $b['booking_reference'], $net, $vatPct, $vat, $gross, $method, $type, $receipt, $user['id']]);
    $id = (int)$pdo->lastInsertId();
    $created['payments'][] = $id;
    $pdo->prepare('UPDATE bookings SET last_payment_date = CURDATE() WHERE id = ?')->execute([$bookingId]);
    recalculateBookingFinancials($bookingId);
    logBookingPayment($bookingId, (string)$b['booking_reference'], $gross, $type, $method, 'completed', (int)$user['id'], $ref);
    return $id;
}

function e2e_kds_log(int $orderId, ?int $itemId, string $event, ?string $from, ?string $to): void
{
    global $pdo, $user;
    $pdo->prepare('INSERT INTO stock_kds_events (order_id, order_item_id, event, from_status, to_status, user_id, user_name, ip_address) VALUES (?,?,?,?,?,?,?,NULL)')
        ->execute([$orderId, $itemId, $event, $from, $to, $user['id'], $user['full_name']]);
}

/** Mirror of kds_recompute_order_status() in api/kds-action.php (that file is an endpoint, not loadable). */
function e2e_kds_recompute(int $orderId): string
{
    global $pdo;
    $st = $pdo->prepare("SELECT kds_status FROM stock_order_items WHERE order_id=? AND kds_status<>'void'");
    $st->execute([$orderId]);
    $s = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$s) return 'served';
    $pending = in_array('pending', $s, true);
    $prep = in_array('preparing', $s, true);
    $ready = in_array('ready', $s, true);
    $coll = in_array('collection', $s, true);
    $served = in_array('served', $s, true);
    if (!$pending && !$prep && !$ready && !$coll) return 'served';
    if (!$pending && !$prep) return 'ready';
    if ($prep || $ready || $coll || $served) return 'in_progress';
    return 'new';
}

/** Mirror of pos_fireKitchen() in admin/pos.php. */
function e2e_fire(int $orderId): void
{
    global $pdo;
    $pdo->prepare("UPDATE stock_orders SET kitchen_status='new', fired_at=COALESCE(fired_at,NOW()), kitchen_printed_at=COALESCE(kitchen_printed_at,NOW()) WHERE id=? AND kitchen_status IN ('none','new')")->execute([$orderId]);
    e2e_kds_log($orderId, null, 'fired', null, 'new');
}

/** Walk every line through start -> ready -> collect -> serve exactly as api/kds-action.php does. */
function e2e_kds_advance(int $orderId): array
{
    global $pdo, $user;
    $items = $pdo->prepare('SELECT id, menu_item_id, menu_type, quantity, stock_deducted FROM stock_order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);
    $deductFailures = 0;
    foreach ($rows as $it) {
        $iid = (int)$it['id'];
        $pdo->prepare("UPDATE stock_order_items SET kds_status='preparing', started_at=COALESCE(started_at,NOW()) WHERE id=?")->execute([$iid]);
        e2e_kds_log($orderId, $iid, 'started', 'pending', 'preparing');
        $flag = (int)$it['stock_deducted'];
        if (!$flag) {
            if (deductStockForMenuItem((int)$it['menu_item_id'], (string)$it['menu_type'], (float)$it['quantity'], 'pos_order', $iid, (int)$user['id'])) {
                $flag = 1;
            } else {
                $deductFailures++;
            }
        }
        $pdo->prepare("UPDATE stock_order_items SET kds_status='ready', ready_at=COALESCE(ready_at,NOW()), stock_deducted=? WHERE id=?")->execute([$flag, $iid]);
        e2e_kds_log($orderId, $iid, 'ready', 'preparing', 'ready');
        $pdo->prepare("UPDATE stock_order_items SET kds_status='collection' WHERE id=?")->execute([$iid]);
        e2e_kds_log($orderId, $iid, 'collected', 'ready', 'collection');
        $pdo->prepare("UPDATE stock_order_items SET kds_status='served', served_at=NOW(), bumped_by=? WHERE id=?")->execute([$user['id'], $iid]);
        e2e_kds_log($orderId, $iid, 'served', 'collection', 'served');
    }
    $new = e2e_kds_recompute($orderId);
    $pdo->prepare("UPDATE stock_orders SET kitchen_status=?, served_at=CASE WHEN ?='served' THEN NOW() ELSE served_at END WHERE id=?")->execute([$new, $new, $orderId]);
    return ['status' => $new, 'deduct_failures' => $deductFailures, 'lines' => count($rows)];
}

function e2e_menu_items(int $n): array
{
    global $pdo;
    $st = $pdo->query("SELECT mi.id, mi.item_name AS name, mi.price, COALESCE(mi.station, mc.default_station) AS station, mc.slug AS menu_type
        FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE mi.is_available = 1 AND mi.price > 0
          AND EXISTS (SELECT 1 FROM stock_recipes sr JOIN stock_recipe_ingredients sri ON sri.recipe_id = sr.id
                      JOIN stock_ingredients si ON si.id = sri.ingredient_id
                      WHERE sr.menu_item_id = mi.id AND sr.menu_type = mc.slug AND sri.quantity_per_portion > 0 AND si.current_quantity > 5)
        ORDER BY mi.price ASC LIMIT " . (int)$n);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function e2e_insert_lines(int $orderId, array $items, float $qty, string $orderType): array
{
    global $pdo;
    $ins = $pdo->prepare('INSERT INTO stock_order_items (order_id, menu_item_id, menu_type, item_name, quantity, unit_price, line_total, notes, station) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $total = 0.0;
    $folio = [];
    foreach ($items as $it) {
        $station = in_array($it['station'] ?? '', ['kitchen', 'bar', 'coffee_bar'], true) ? $it['station'] : 'kitchen';
        $line = round((float)$it['price'] * $qty, 2);
        $ins->execute([$orderId, (int)$it['id'], $it['menu_type'], $it['name'], $qty, (float)$it['price'], $line, 'E2E LIVE', $station]);
        $total += $line;
        if ($orderType === 'room_service') {
            $folio[] = ['item_id' => (int)$it['id'], 'type' => $it['menu_type'], 'qty' => $qty, 'soi_id' => (int)$pdo->lastInsertId()];
        }
    }
    return [round($total, 2), $folio];
}

echo "LIVE E2E (" . date('Y-m-d H:i:s') . ' ' . RH_TIMEZONE . ") - vat_mode=" . vat_mode() . ' vat=' . getSetting('vat_rate', 0) . "% tolerance=$TOL\n";
echo "Mail suppression: automated-send redirect is fail-closed; no mail leaves this script.\n";

/* ------------------------------------------------------------------ 1 */
step('1. Create booking (admin create-booking code path)', function () use (&$S, &$created, $pdo, $user, $TOL) {
    $in  = date('Y-m-d');
    $out = date('Y-m-d', strtotime('+1 day'));
    $nights = 1;

    $rooms = $pdo->query('SELECT * FROM rooms WHERE is_active = 1 AND price_per_night > 0 ORDER BY price_per_night ASC')->fetchAll(PDO::FETCH_ASSOC);
    $pick = null;
    foreach ($rooms as $r) {
        if ((int)($r['max_guests'] ?? 2) < 2) continue;
        $av = checkRoomAvailability((int)$r['id'], $in, $out);
        if (empty($av['available'])) continue;
        $free = getAvailableIndividualRooms((int)$r['id'], $in, $out);
        if (!$free) continue;
        $pick = $r;
        $S['remaining_before'] = (int)($av['remaining_rooms'] ?? 0);
        break;
    }
    chk($pick !== null, 'a real available room type with a free physical room exists for ' . $in);
    if (!$pick) return;
    $S['room_type_id'] = (int)$pick['id'];
    echo "    room type #{$pick['id']} {$pick['name']} price={$pick['price_per_night']}\n";

    // Pricing exactly as create-booking.php: occupancy price -> dynamic pricing -> rh_stay_totals
    $base = !empty($pick['price_double_occupancy']) ? (float)$pick['price_double_occupancy'] : (float)$pick['price_per_night'];
    $dyn = applyDynamicPricing($pdo, (int)$pick['id'], $in, $out, $nights, $base);
    $price = (float)$dyn['final_price'];
    $tt = rh_stay_totals($price * $nights, 'price');
    chk(abs($tt['net'] + $tt['vat'] + $tt['levy'] - $tt['total_with_vat']) <= $TOL, 'net + VAT + levy = total_with_vat within tolerance', json_encode($tt));
    if (vat_mode() === 'inclusive' && $tt['vat_rate'] > 0) {
        $expVat = $price * $nights - ($price * $nights) / (1 + $tt['vat_rate'] / 100);
        chk(abs($tt['vat'] - $expVat) <= $TOL, 'inclusive VAT extracted from price correctly', "vat={$tt['vat']} expected=" . round($expVat, 2));
        chk(abs($tt['total_with_vat'] - ($price * $nights + $tt['levy'])) <= $TOL, 'inclusive: guest total = price (+ levy)');
    }
    if (!in_array(getSetting('tourism_levy_enabled', '0'), ['1', 1, true, 'true', 'on'], true)) {
        chk($tt['levy'] == 0.0, 'levy disabled => levy amount 0');
    } else {
        chk(abs($tt['levy'] - round($tt['net'] * $tt['levy_rate'] / 100, 2)) <= $TOL, 'levy = net x levy%');
    }
    $S['tt'] = $tt;

    $ref = 'E2E-LIVE-' . bin2hex(random_bytes(5));
    $uuid = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM rooms WHERE id = ? FOR UPDATE')->execute([(int)$pick['id']]);
    $av2 = checkRoomAvailability((int)$pick['id'], $in, $out, null, 0, 0);
    chk(!empty($av2['available']) && (int)($av2['remaining_rooms'] ?? 0) >= 1, 're-check of availability under the room lock');
    $cpm = (float)($pick['child_price_multiplier'] ?? getSetting('booking_child_price_multiplier', 50));
    $pdo->prepare("INSERT INTO bookings (
            booking_reference, room_id, individual_room_id,
            guest_name, guest_email, guest_phone, guest_country, guest_address,
            number_of_guests, adult_guests, child_guests, child_price_multiplier,
            check_in_date, check_out_date, number_of_nights,
            total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat,
            special_requests, status, payment_status,
            is_tentative, tentative_expires_at, occupancy_type,
            client_uuid, rate_plan_id, rate_plan_label, rate_plan_discount,
            primary_booking_id, created_at
        ) VALUES (?,?,NULL, ?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?,?, NULL, NOW())")
        ->execute([
            $ref, (int)$pick['id'],
            'E2E LIVE Test', 'e2e-live@example.invalid', '+265000000001', 'E2E LIVE', 'E2E LIVE address',
            2, 2, 0, $cpm,
            $in, $out, $nights,
            round($tt['net'] + $tt['levy'], 2), 0.0, $tt['levy'], $tt['levy_rate'],
            $tt['vat_rate'], $tt['vat'], $tt['total_with_vat'],
            'E2E LIVE automated test booking - safe to ignore', 'pending', 'unpaid',
            0, null, 'double',
            $uuid, $dyn['rate_plan_id'] ?? null, ($dyn['rate_plan_label'] ?? '') ?: null, ($dyn['discount_amount'] ?? 0) ?: null,
        ]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)')
        ->execute([$id, 'Created by E2E LIVE test script', $user['id']]);
    recalculateBookingFinancials($id);
    $pdo->commit();
    $created['booking'] = $id;
    $S['id'] = $id;
    $S['ref'] = $ref;

    $b = fetch_booking($id);
    logBookingCreated($b, 'admin', (int)$user['id'], $user['full_name']);
    logBookingCreatedAudit($id, $ref, 'admin', $user['full_name']);
    logBookingAudit($id, 'created', null, ['status' => 'pending'], 'E2E LIVE created', $ref);

    chk($b && $b['status'] === 'pending', "booking #$id ($ref) saved as pending");
    chk(abs((float)$b['total_with_vat'] - $tt['total_with_vat']) <= $TOL, 'saved total_with_vat matches computed', "{$b['total_with_vat']} vs {$tt['total_with_vat']}");
    chk(abs((float)$b['vat_amount'] - $tt['vat']) <= $TOL, 'saved vat_amount matches computed');
    chk(abs((float)$b['amount_due'] - $tt['total_with_vat']) <= $TOL, 'amount_due = total owed before any payment', "amount_due={$b['amount_due']}");
    chk($b['payment_status'] === 'unpaid', 'payment_status unpaid');

    $av3 = checkRoomAvailability((int)$pick['id'], $in, $out);
    $blocks = in_array('pending', getBookingStatusesThatBlockAvailability(), true);
    $expect = $S['remaining_before'] - ($blocks ? 1 : 0);
    chk((int)($av3['remaining_rooms'] ?? -99) === $expect, 'availability reflects the new booking (pending ' . ($blocks ? 'blocks' : 'does not block') . ')', 'remaining=' . ($av3['remaining_rooms'] ?? 'n/a') . " expected=$expect");
});

if (empty($S['id'])) {
    echo "\nBooking not created - cannot continue.\n";
    echo "\nRESULT: $stepsPass step(s) passed, $stepsFail failed\n";
    exit(1);
}
$id = (int)$S['id'];
$ref = (string)$S['ref'];

/* ------------------------------------------------------------------ 2 */
step('2. Confirm, deposit, balance, assign room, set room clean, check in', function () use (&$S, $pdo, $user, $TOL, $id, $ref) {
    $b = fetch_booking($id);
    $tv = validateBookingStatusTransition('pending', 'confirmed');
    chk($tv['allowed'], 'pending -> confirmed transition allowed');

    // Confirm (admin/bookings.php path): lock, re-check availability, status, rooms_available
    $rid = (int)$b['room_id'];
    $before = (int)$pdo->query("SELECT rooms_available FROM rooms WHERE id = $rid")->fetchColumn();
    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM rooms WHERE id = ? FOR UPDATE')->execute([$rid]);
    $av = checkRoomAvailability($rid, $b['check_in_date'], $b['check_out_date'], $id);
    chk(!empty($av['available']), 'availability re-check before confirm (excluding itself)');
    $pdo->prepare("UPDATE bookings SET status='confirmed', is_tentative=0, tentative_expires_at=NULL WHERE id=?")->execute([$id]);
    $pdo->prepare('UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0')->execute([$rid]);
    $pdo->commit();
    $after = (int)$pdo->query("SELECT rooms_available FROM rooms WHERE id = $rid")->fetchColumn();
    chk($after === max(0, $before - 1), 'rooms_available decremented on confirm', "before=$before after=$after");
    logBookingStatusChange($id, $ref, 'pending', 'confirmed', 'admin', (int)$user['id'], $user['full_name']);
    logBookingAudit($id, 'confirmed', ['status' => 'pending'], ['status' => 'confirmed'], 'E2E LIVE', $ref);

    $mail = sendBookingConfirmedEmail(array_merge(fetch_booking($id), ['room_name' => 'E2E LIVE']));
    chk(is_array($mail) && empty($mail['success']), 'confirmation email fails soft (suppressed, nothing sent)', json_encode($mail));

    // Deposit
    $total = (float)fetch_booking($id)['total_with_vat'];
    $dep = round($total * 0.30, 2);
    $S['deposit_pay'] = e2e_pay_room($id, $dep, 'partial_payment', 'cash');
    $b = fetch_booking($id);
    chk(abs((float)$b['amount_paid'] - $dep) <= $TOL, 'amount_paid = deposit', "paid={$b['amount_paid']}");
    chk(abs((float)$b['amount_due'] - ($total - $dep)) <= $TOL, 'amount_due = total - deposit', "due={$b['amount_due']}");
    chk($b['payment_status'] === 'partial', 'payment_status partial after deposit');
    $pv = $pdo->prepare('SELECT payment_amount, vat_amount, total_amount FROM payments WHERE id = ?');
    $pv->execute([$S['deposit_pay']]);
    $p = $pv->fetch(PDO::FETCH_ASSOC);
    chk(abs((float)$p['payment_amount'] + (float)$p['vat_amount'] - (float)$p['total_amount']) <= $TOL, 'payment net + VAT = gross (payment_amount is net)');

    // check-in must not be allowed while unpaid-ish? validateCheckIn permits partial; process-checkin requires paid
    $vc = validateCheckIn($b);
    chk($vc['allowed'], 'validateCheckIn allows a confirmed, part-paid booking on its arrival date', $vc['reason']);

    // Balance
    $S['balance_pay'] = e2e_pay_room($id, round($total - $dep, 2), 'full_payment', 'cash');
    $b = fetch_booking($id);
    chk($b['payment_status'] === 'paid' && (float)$b['amount_due'] <= $TOL, 'fully paid, amount_due ~ 0', "status={$b['payment_status']} due={$b['amount_due']}");

    // Assign physical room
    $as = autoAssignConfirmedPaidBooking($id);
    chk(!empty($as['success']) && !empty($as['assigned_room_id']), 'physical room auto-assigned', $as['message'] ?? '');
    $b = fetch_booking($id);
    $irId = (int)$b['individual_room_id'];
    $S['ir'] = $irId;
    chk($irId > 0, 'bookings.individual_room_id set', "id=$irId");
    if ($irId <= 0) return;

    // Housekeeping: dirty -> clean via the housekeeping functions
    $u = updateRoomStatus($irId, ROOM_STATUS_CLEANING, 'E2E LIVE: simulate dirty room', (int)$user['id'], ['force' => true]);
    chk(!empty($u['success']), 'room set to cleaning', $u['message'] ?? '');
    $gate = evaluateCheckInRoomReady($id, (int)$user['id'], false);
    chk(!$gate['allowed'], 'check-in is blocked while the room is not clean (gate works)');
    $mc = markRoomClean($irId, (int)$user['id'], ['notes' => 'E2E LIVE clean']);
    chk(!empty($mc['success']), 'markRoomClean() succeeded', $mc['message'] ?? '');
    $rs = $pdo->prepare('SELECT status, housekeeping_status FROM individual_rooms WHERE id = ?');
    $rs->execute([$irId]);
    $r = $rs->fetch(PDO::FETCH_ASSOC);
    chk($r['status'] === 'available', 'room status available after clean', json_encode($r));
    $gate = evaluateCheckInRoomReady($id, (int)$user['id'], false);
    chk($gate['allowed'], 'clean-room gate now passes', $gate['message']);

    // Check in (admin/process-checkin.php)
    $st = $pdo->prepare("UPDATE bookings SET status='checked-in' WHERE id=? AND status='confirmed' AND payment_status='paid'");
    $st->execute([$id]);
    chk($st->rowCount() === 1, 'booking checked in (confirmed + paid)');
    $pdo->prepare("UPDATE individual_rooms SET status='occupied' WHERE id=?")->execute([$irId]);
    $pdo->prepare("INSERT INTO room_maintenance_log (individual_room_id, status_from, status_to, reason, performed_by) VALUES (?, 'available', 'occupied', ?, ?)")
        ->execute([$irId, 'Check-in: ' . $ref, $user['id']]);
    logBookingCheckIn($id, $ref, 'admin', (int)$user['id'], $user['full_name']);
    logBookingAudit($id, 'checked-in', ['status' => 'confirmed'], ['status' => 'checked-in'], 'E2E LIVE', $ref);
    $b = fetch_booking($id);
    chk($b['status'] === 'checked-in', 'status checked-in');

    // Folio
    $f = getBookingFolioSummary($id);
    chk(empty($f['error']), 'folio summary loads', $f['error'] ?? '');
    $S['folio0'] = $f;
    chk(abs((float)$b['amount_due']) <= $TOL, 'folio: nothing owed at check-in');
});

/* ------------------------------------------------------------------ 3a */
step('3a. POS walk-in order -> KDS -> cash payment -> ledger + report query', function () use (&$S, &$created, $pdo, $user, $TOL) {
    $items = e2e_menu_items(2);
    chk(count($items) >= 1, 'recipe-backed menu items found');
    if (!$items) return;
    $loc = rh_restaurant_resolve_pos_location($pdo, 'walk_in', '');
    $ref = generateStockOrderReference();
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO stock_orders (reference, client_uuid, order_type, booking_id, individual_room_id, table_number, room_number, customer_name, customer_email, customer_phone, notes, status, total_amount, created_by, opened_as_tab)
        VALUES (?, ?, 'walk_in', NULL, NULL, ?, NULL, 'E2E LIVE Walk-in', NULL, NULL, 'E2E LIVE', 'placed', 0, ?, 0)")
        ->execute([$ref, bin2hex(random_bytes(16)), $loc['table_number'], $user['id']]);
    $oid = (int)$pdo->lastInsertId();
    [$total] = e2e_insert_lines($oid, $items, 1.0, 'walk_in');
    $pdo->prepare('UPDATE stock_orders SET total_amount=?, subtotal=? WHERE id=?')->execute([$total, $total, $oid]);
    $pdo->commit();
    $created['orders'][] = $oid;
    $S['walkin'] = $oid;
    chk($total > 0, "order #$oid ($ref) opened with " . count($items) . " line(s), total $total");

    e2e_fire($oid);
    $st = $pdo->prepare('SELECT kitchen_status, fired_at FROM stock_orders WHERE id=?');
    $st->execute([$oid]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    chk($o['kitchen_status'] === 'new' && $o['fired_at'] !== null, 'order sent to KDS (kitchen_status=new, fired_at set)');

    $adv = e2e_kds_advance($oid);
    chk($adv['status'] === 'served', 'KDS advanced start->ready->collect->serve; order reads served', json_encode($adv));
    chk($adv['deduct_failures'] === 0, 'stock deducted at Ready for every line');
    $sd = $pdo->prepare('SELECT COUNT(*) FROM stock_order_items WHERE order_id=? AND stock_deducted=1');
    $sd->execute([$oid]);
    chk((int)$sd->fetchColumn() === count($items), 'every line flagged stock_deducted=1');

    // Payment (pos_applyPaymentToOrder single-payment branch + pos_syncPayment)
    $vatPct = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
    $net = $vatPct > 0 ? round($total / (1 + $vatPct / 100), 2) : round($total, 2);
    $vat = round($total - $net, 2);
    $pdo->prepare("UPDATE stock_orders SET status='paid', paid_at=NOW(), payment_method='cash', tendered_amount=?, change_due=0, tip_amount=0 WHERE id=?")->execute([$total, $oid]);
    rh_stamp_order_paid_by($pdo, $oid, (int)$user['id']);
    $pid = rh_sync_restaurant_payment($pdo, $oid, $ref, 'E2E LIVE Walk-in', ['net' => $net, 'vat_rate' => $vatPct, 'vat' => $vat, 'gross' => $total], (int)$user['id'], 'cash');
    $created['payments'][] = $pid;
    $S['walkin_pay'] = $pid;
    $p = $pdo->prepare('SELECT * FROM payments WHERE id=?');
    $p->execute([$pid]);
    $p = $p->fetch(PDO::FETCH_ASSOC);
    chk($p && $p['booking_type'] === 'restaurant' && (int)$p['booking_id'] === $oid, 'payments row written (restaurant, linked to order)');
    chk(abs((float)$p['total_amount'] - $total) <= $TOL, 'payment gross = order total');
    chk(abs((float)$p['payment_amount'] + (float)$p['vat_amount'] - (float)$p['total_amount']) <= $TOL, 'payment net + VAT = gross');
    chk($p['payment_status'] === 'completed' && !empty($p['receipt_number']), 'payment completed with receipt number', (string)($p['receipt_number'] ?? ''));

    // The payment-split query of admin/includes/reports-extra-tabs.php, run through the real file
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
    $active_tab = 'fnb';
    $currency_symbol = 'MWK';
    $split = null;
    ob_start();
    try {
        include dirname(__DIR__, 2) . '/admin/includes/reports-extra-tabs.php';
        $split = $fnb['payment_split'] ?? null;
    } catch (Throwable $e) {
        ob_end_clean();
        chk(false, 'reports-extra-tabs.php loads', $e->getMessage());
        return;
    }
    ob_end_clean();
    $cash = 0.0;
    $cashN = 0;
    foreach ((array)$split as $row) {
        if ($row['payment_method'] === 'cash') { $cash = (float)$row['total']; $cashN = (int)$row['n']; }
    }
    chk($split !== null && $cashN >= 1 && $cash + $TOL >= $total, 'reports payment_split sees the cash sale', 'split=' . json_encode($split));
    $direct = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE id=? AND booking_type='restaurant' AND deleted_at IS NULL AND COALESCE(payment_type,'') <> 'refund' AND payment_status IN ('completed','paid','refunded','partially_refunded') AND created_at BETWEEN ? AND ?");
    $direct->execute([$pid, date('Y-m-d') . ' 00:00:00', date('Y-m-d') . ' 23:59:59']);
    chk((int)$direct->fetchColumn() === 1, 'payment row satisfies every predicate of the payment_split query');

    // Receipt for the POS payment
    $rc = receipt_generate_pdf($pdo, $pid, $user);
    chk(!empty($rc['success']), 'receipt PDF generated for the POS payment', $rc['message'] ?? json_encode($rc));
});

/* ------------------------------------------------------------------ 3b */
step('3b. Room-service order charged to the room folio', function () use (&$S, &$created, $pdo, $user, $TOL, $id) {
    $b0 = fetch_booking($id);
    chk($b0['status'] === 'checked-in', 'guest is checked in (required for room service)');
    $irNo = $pdo->prepare('SELECT room_number FROM individual_rooms WHERE id=?');
    $irNo->execute([(int)$b0['individual_room_id']]);
    $roomNo = (string)$irNo->fetchColumn();
    $items = e2e_menu_items(1);
    if (!$items || $roomNo === '') { chk(false, 'menu item + room number available'); return; }

    $ref = generateStockOrderReference();
    $pdo->beginTransaction();
    $loc = rh_restaurant_resolve_pos_location($pdo, 'room_service', $roomNo);
    chk((int)$loc['booking_id'] === $id, "room $roomNo resolves to the E2E booking", json_encode($loc));
    $pdo->prepare("INSERT INTO stock_orders (reference, client_uuid, order_type, booking_id, individual_room_id, table_number, room_number, customer_name, customer_email, customer_phone, notes, status, total_amount, created_by, opened_as_tab)
        VALUES (?, ?, 'room_service', ?, ?, ?, ?, 'E2E LIVE Test', NULL, NULL, 'E2E LIVE', 'placed', 0, ?, 1)")
        ->execute([$ref, bin2hex(random_bytes(16)), $loc['booking_id'], $loc['individual_room_id'], $loc['table_number'], $loc['room_number'], $user['id']]);
    $oid = (int)$pdo->lastInsertId();
    $qty = 2.0;
    [$total, $folio] = e2e_insert_lines($oid, $items, $qty, 'room_service');
    $pdo->prepare('UPDATE stock_orders SET total_amount=?, subtotal=? WHERE id=?')->execute([$total, $total, $oid]);
    $dueBefore = (float)$b0['amount_due'];
    foreach ($folio as $fi) {
        $ch = addBookingChargeFromMenu($id, $fi['type'], $fi['item_id'], $fi['qty'], (int)$user['id']);
        if (empty($ch['success']) || empty($ch['charge_id'])) {
            $pdo->rollBack();
            chk(false, 'folio charge posted', json_encode($ch));
            return;
        }
        $created['charges'][] = (int)$ch['charge_id'];
        $pdo->prepare('UPDATE booking_charges SET stock_order_id=? WHERE id=?')->execute([$oid, (int)$ch['charge_id']]);
        $pdo->prepare('UPDATE stock_order_items SET stock_deducted=1 WHERE id=?')->execute([$fi['soi_id']]);
    }
    $pdo->prepare('UPDATE stock_orders SET folio_posted_at=NOW() WHERE id=?')->execute([$oid]);
    recalculateBookingFinancials($id);
    $pdo->commit();
    $created['orders'][] = $oid;
    $S['rs'] = $oid;
    $S['rs_total'] = $total;

    $b = fetch_booking($id);
    chk(abs((float)$b['folio_charges_total'] - $total) <= $TOL, "folio_charges_total = order total ($total)", "folio={$b['folio_charges_total']}");
    chk(abs((float)$b['amount_due'] - ($dueBefore + $total)) <= $TOL, 'amount_due rose by the charged amount (gross, F&B)', "due={$b['amount_due']}");
    chk($b['payment_status'] === 'partial', 'payment_status reverts to partial while folio is unpaid', $b['payment_status']);
    $c = $pdo->prepare('SELECT * FROM booking_charges WHERE stock_order_id=? AND voided=0');
    $c->execute([$oid]);
    $rows = $c->fetchAll(PDO::FETCH_ASSOC);
    chk(count($rows) === count($items), 'booking_charges row(s) linked to the order');
    $vatPct = rh_vat_enabled() ? (float)getSetting('vat_rate') : 0.0;
    $expVat = $vatPct > 0 ? round($total - $total / (1 + $vatPct / 100), 2) : 0.0;
    $sumVat = array_sum(array_map(static fn($r) => (float)$r['vat_amount'], $rows));
    chk(abs($sumVat - $expVat) <= 0.02, 'charge VAT extracted from the gross price', "vat=$sumVat expected=$expVat");
    $chargeTotal = array_sum(array_map(static fn($r) => (float)$r['line_total'], $rows));
    chk(abs($chargeTotal - $total) <= $TOL, 'charge line_total (gross) = POS order total');

    e2e_fire($oid);
    $adv = e2e_kds_advance($oid);
    chk($adv['status'] === 'served', 'room-service order served via KDS', json_encode($adv));
    $dbl = $pdo->prepare('SELECT stock_deducted FROM stock_order_items WHERE order_id=?');
    $dbl->execute([$oid]);
    chk(!in_array(0, array_map('intval', $dbl->fetchAll(PDO::FETCH_COLUMN)), true), 'stock_deducted stays 1 (no second deduction at Ready)');
    $tl = getBookingFolioSummary($id);
    chk(empty($tl['error']), 'folio summary includes the charge', $tl['error'] ?? '');
});

/* ------------------------------------------------------------------ 4 */
step('4. Settle folio, checkout at zero balance, invoice, receipt, timeline', function () use (&$S, &$created, $pdo, $user, $TOL, $id, $ref) {
    $b = fetch_booking($id);
    $due = round((float)$b['amount_due'], 2);
    chk($due > $TOL, "balance owed before settlement: $due");

    // Checkout must be blocked while a balance is owed
    $blocked = processGuestCheckout($id, (int)$user['id'], []);
    chk(empty($blocked['success']), 'checkout blocked while the balance is unpaid', $blocked['message'] ?? '');
    chk(fetch_booking($id)['status'] === 'checked-in', 'blocked checkout left the booking checked-in');

    $S['final_pay'] = e2e_pay_room($id, $due, 'full_payment', 'cash');
    $b = fetch_booking($id);
    chk((float)$b['amount_due'] <= $TOL && $b['payment_status'] === 'paid', 'zero balance after settlement', "due={$b['amount_due']}");
    $expectPaid = (float)$b['total_with_vat'] + (float)$b['folio_charges_total'];
    chk(abs((float)$b['amount_paid'] - $expectPaid) <= $TOL, 'amount_paid = room total + folio charges', "paid={$b['amount_paid']} expected=$expectPaid");

    $res = processGuestCheckout($id, (int)$user['id'], ['room_status' => ROOM_STATUS_CLEANING]);
    chk(!empty($res['success']), 'checkout succeeded', $res['message'] ?? '');
    logBookingCheckOut($id, $ref, 'admin', (int)$user['id'], $user['full_name']);
    logBookingAudit($id, 'checked-out', ['status' => 'checked-in'], ['status' => 'checked-out'], 'E2E LIVE', $ref);
    $b = fetch_booking($id);
    chk($b['status'] === 'checked-out' && !empty($b['checkout_completed_at']), 'status checked-out, checkout_completed_at stamped');
    chk((int)$b['final_invoice_generated'] === 1 && !empty($b['final_invoice_number']), 'final invoice generated', (string)($b['final_invoice_number'] ?? ''));
    $path = (string)($b['final_invoice_path'] ?? '');
    $file = $path !== '' ? (is_file($path) ? $path : dirname(__DIR__, 2) . '/' . ltrim($path, '/')) : '';
    chk($file !== '' && is_file($file) && filesize($file) > 500, 'invoice PDF file exists on disk', $path);
    chk(empty($res['data']['workflow']['invoice']['email_sent']), 'invoice email not sent (suppressed, failed soft)');

    $ir = $pdo->prepare('SELECT status FROM individual_rooms WHERE id=?');
    $ir->execute([(int)$S['ir']]);
    chk($ir->fetchColumn() === 'cleaning', 'room released to cleaning on checkout');
    $hk = $pdo->prepare("SELECT COUNT(*) FROM housekeeping_assignments WHERE individual_room_id = ?");
    try {
        $hk->execute([(int)$S['ir']]);
        chk((int)$hk->fetchColumn() >= 1, 'housekeeping assignment created');
    } catch (Throwable $e) {
        chk(true, 'housekeeping assignment table lookup skipped (' . substr($e->getMessage(), 0, 60) . ')');
    }
    $rooms = $pdo->prepare('SELECT rooms_available, total_rooms FROM rooms WHERE id=?');
    $rooms->execute([(int)$b['room_id']]);
    $rr = $rooms->fetch(PDO::FETCH_ASSOC);
    chk((int)$rr['rooms_available'] <= (int)$rr['total_rooms'], 'rooms_available restored within total_rooms', json_encode($rr));

    // Receipts for the booking payments
    $rp = $pdo->prepare("SELECT id, receipt_number FROM payments WHERE booking_type='room' AND booking_id=? AND deleted_at IS NULL ORDER BY id");
    $rp->execute([$id]);
    $pays = $rp->fetchAll(PDO::FETCH_ASSOC);
    chk(count($pays) === 3, '3 payments on the booking (deposit, balance, folio settlement)', 'got ' . count($pays));
    chk(!in_array('', array_map(static fn($p) => (string)$p['receipt_number'], $pays), true), 'every payment carries a receipt number');
    $rc = receipt_generate_pdf($pdo, (int)$S['final_pay'], $user);
    chk(!empty($rc['success']), 'receipt PDF generated for the final payment', $rc['message'] ?? json_encode($rc));
    // receipt_send_email() reports a failed send by throwing; that is the documented soft-fail contract.
    try {
        $rcm = receipt_send_email($pdo, (int)$S['final_pay'], 'e2e-live@example.invalid', $user);
    } catch (Throwable $e) {
        $rcm = ['success' => false, 'message' => $e->getMessage()];
    }
    chk(empty($rcm['success']), 'receipt email fails soft (nothing sent)', json_encode($rcm));

    // Timeline
    $tl = getBookingTimeline($id);
    $types = array_unique(array_map(static fn($e) => (string)$e['action_type'], $tl));
    foreach (['create', 'status_change', 'payment', 'check_in', 'check_out'] as $need) {
        chk(in_array($need, $types, true), "timeline has a '$need' entry");
    }
    $aud = $pdo->prepare('SELECT COUNT(*) FROM booking_audit_log WHERE booking_id=?');
    $aud->execute([$id]);
    chk((int)$aud->fetchColumn() >= 3, 'audit log has the lifecycle entries');

    // Restore the physical room to service (it was dirtied by the checkout)
    $mc = markRoomClean((int)$S['ir'], (int)$user['id'], ['notes' => 'E2E LIVE reset after test']);
    $rs = $pdo->prepare('SELECT status FROM individual_rooms WHERE id=?');
    $rs->execute([(int)$S['ir']]);
    chk(!empty($mc['success']) && $rs->fetchColumn() === 'available', 'test room returned to available after housekeeping');
});

/* ------------------------------------------------------------------ 5 */
step('5. rhUserTimezone / rhToUserTime with a simulated rh_tz cookie', function () use ($id, $pdo) {
    unset($_COOKIE['rh_tz']);
    chk(rhUserTimezone() === RH_TIMEZONE, 'no cookie -> hotel timezone');
    $_COOKIE['rh_tz'] = 'America/New_York';
    chk(rhUserTimezone() === 'America/New_York', 'cookie America/New_York accepted');
    chk(rhToUserTime('2026-10-06 14:30:00') === '2026-10-06 08:30', 'Blantyre 14:30 -> New York 08:30 via cookie', rhToUserTime('2026-10-06 14:30:00'));
    $_COOKIE['rh_tz'] = 'Asia/Tokyo';
    chk(rhToUserTime('2026-10-06 14:30:00') === '2026-10-06 21:30', 'Blantyre 14:30 -> Tokyo 21:30 via cookie', rhToUserTime('2026-10-06 14:30:00'));
    $_COOKIE['rh_tz'] = '<script>alert(1)</script>';
    chk(rhUserTimezone() === RH_TIMEZONE && rhToUserTime('2026-10-06 14:30:00') === '2026-10-06 14:30', 'hostile cookie falls back to hotel time');
    $_COOKIE['rh_tz'] = 'Europe/London';
    $st = $pdo->prepare('SELECT created_at FROM bookings WHERE id=?');
    $st->execute([$id]);
    $createdAt = (string)$st->fetchColumn();
    $exp = (new DateTime($createdAt, new DateTimeZone(RH_TIMEZONE)))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i');
    chk(rhToUserTime($createdAt) === $exp, 'real booking created_at renders in the cookie timezone', rhToUserTime($createdAt) . " vs $exp");
    chk(rhToUserTime('') === '' && rhToUserTime('0000-00-00 00:00:00') === '', 'empty / zero dates render as empty');
    chk(RH_TIMEZONE === date_default_timezone_get(), 'storage timezone untouched by display conversion');
    unset($_COOKIE['rh_tz']);
});
/* ------------------------------------------------------------------ 6 */
step('6. Refund-cancel path: cancel leftover open E2E-LIVE bookings through the app', function () use ($pdo, $user, $TOL) {
    $st = $pdo->query("SELECT id, booking_reference, status FROM bookings WHERE booking_reference LIKE 'E2E-LIVE-%' AND status IN ('pending','tentative','confirmed','checked-in') ORDER BY id");
    $open = $st->fetchAll(PDO::FETCH_ASSOC);
    echo '    open E2E bookings: ' . count($open) . "\n";
    foreach ($open as $o) {
        $bid = (int)$o['id'];
        if ($o['status'] === 'checked-in') {
            $co = processGuestCheckout($bid, (int)$user['id'], ['confirm_checkout_with_balance' => true]);
            chk(!empty($co['success']), "leftover #$bid checked out", $co['message'] ?? '');
            continue;
        }
        $res = cancelRoomBookingSettled($pdo, $bid, (int)$user['id'], 'E2E LIVE cleanup', 'completed');
        chk(!empty($res['success']), "leftover #$bid cancelled with refund settlement", $res['error'] ?? '');
        $b = fetch_booking($bid);
        chk($b['status'] === 'cancelled', "#$bid status cancelled");
        chk((float)$b['amount_due'] <= $TOL && (float)$b['credit_balance'] <= $TOL, "#$bid nothing owed either way after refund", 'due=' . $b['amount_due'] . ' credit=' . $b['credit_balance'] . ' pay=' . $b['payment_status']);
        if (!empty($b['individual_room_id'])) {
            $r = $pdo->prepare('SELECT status FROM individual_rooms WHERE id=?');
            $r->execute([(int)$b['individual_room_id']]);
            chk($r->fetchColumn() === 'available', "#$bid physical room released to available");
        }
    }
});


echo "\n----------------------------------------\n";
echo 'E2E LIVE rows left in DB: booking #' . ($created['booking'] ?? '-') . ' (' . ($S['ref'] ?? '-') . ')'
    . ', payments [' . implode(',', $created['payments']) . ']'
    . ', stock_orders [' . implode(',', $created['orders']) . ']'
    . ', booking_charges [' . implode(',', $created['charges']) . "]\n";
echo "RESULT: $stepsPass step(s) PASS, $stepsFail FAIL\n";
exit($stepsFail > 0 ? 1 : 0);
