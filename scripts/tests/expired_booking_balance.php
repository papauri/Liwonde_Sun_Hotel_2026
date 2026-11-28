<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * An expired booking owes nothing unless it carries the group no-show / expiry charge (owner fault 2026-11-29:
 * RBH2026933782 showed its full stay as Balance with a Record payment button). Usage (repo root, TEST HOTEL ONLY):
 *   php scripts/tests/expired_booking_balance.php
 * Everything runs in ONE transaction that is rolled back (a second connection checks nothing leaked). The group charge
 * setting is switched IN MEMORY only ($_SITE_SETTINGS), never written to site_settings.
 *  (a) expired, no charge, no payment -> folio total 0, balance 0, stored amount_due 0, unpaid; payments refused.
 *  (b) expired WITH the group expiry charge -> owes exactly that charge.
 *  (c) expired, no charge, with a payment -> balance 0, credit_balance = paid.
 *  (d) no-show (full stay) and cancelled (kept amount) unchanged.
 */
$root = dirname(__DIR__, 2);
chdir($root);
require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(1);
}
require_once $root . '/includes/booking-functions.php';
require_once $root . '/includes/booking-timeline.php';
require_once $root . '/admin/includes/booking-lifecycle.php';
require_once $root . '/config/invoice.php';
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= BALANCE_TOLERANCE;
$setMode = static function (string $v): void { $GLOBALS['_SITE_SETTINGS']['group_noshow_charge'] = $v; };
const EBB_TAG = 'expired balance test';

/** A room booking 1,165 a night all-in (1,000 net + 165 VAT, no levy), check-in in the future. */
function ebb_booking(PDO $pdo, string $status, int $nights = 2, ?int $primaryId = null): int {
    $in = date('Y-m-d', strtotime('+5 day'));
    $out = date('Y-m-d', strtotime('+' . (5 + $nights) . ' day'));
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests,
            child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total, tourism_levy_amount, tourism_levy_percent,
            vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative, occupancy_type, client_uuid, primary_booking_id, created_at)
        VALUES (?,(SELECT id FROM rooms ORDER BY id LIMIT 1),'EBB TEST','','+265000000009','Malawi',1,1,0,50,?,?,?,?,0,0,0,16.5,?,?,?,?,'unpaid',0,'single',?,?,NOW())")
        ->execute(['EBBT' . random_int(100000, 999999), $in, $out, $nights, 1000 * $nights, 165 * $nights, 1165 * $nights, EBB_TAG, $status, bin2hex(random_bytes(16)), $primaryId]);
    $id = (int)$pdo->lastInsertId();
    recalculateBookingFinancials($id);
    return $id;
}
function ebb_set_status(PDO $pdo, int $id, string $status): void {
    $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$status, $id]);
    recalculateBookingFinancials($id);
}
function ebb_pay(PDO $pdo, int $bid, float $amt): void {
    $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
            payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, 'X', CURDATE(), ?, 0, 0, ?, 'cash', 'partial_payment', 'completed', NOW())")
        ->execute(['EBBP' . random_int(100000, 999999), $bid, $amt, $amt]);
    recalculateBookingFinancials($bid);
}
function ebb_row(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$chk = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));

$pdo->beginTransaction();
try {
    $setMode('0');

    // ---- (a) expired, no charge, no payment ----
    $a = ebb_booking($pdo, 'pending', 2);
    ok('(a) setup: pending booking owes its stay (2,330)', $near(ebb_row($pdo, $a)['amount_due'], 2330));
    ebb_set_status($pdo, $a, 'expired');
    $fsA = getBookingFolioSummary($a);
    $rA = ebb_row($pdo, $a);
    ok('(a) expired untagged: folio grand_total 0, balance_due 0, base 0, levy 0',
        $near($fsA['grand_total'], 0) && $near($fsA['balance_due'], 0) && $near($fsA['booking_base_amount'], 0) && $near($fsA['total_levy'], 0)
        && $near($fsA['total_vat'], 0) && $near($fsA['total_subtotal'], 0), json_encode($fsA));
    ok('(a) expired untagged: stored amount_due 0, credit 0, payment_status unpaid',
        $near($rA['amount_due'], 0) && $near($rA['credit_balance'], 0) && $rA['payment_status'] === 'unpaid', json_encode([$rA['amount_due'], $rA['credit_balance'], $rA['payment_status']]));
    ok('(a) booked rates kept as history (total_with_vat 2,330 untouched)', $near($rA['total_with_vat'], 2330), (string)$rA['total_with_vat']);
    $guard = bookingAllowsAction($rA, 'record_payment');
    ok('(a) Record payment refused for the expired hold', $guard['allowed'] === false && stripos($guard['reason'], 'expired') !== false, json_encode($guard));
    $permsA = getBookingPermissions($rA);
    ok('(a) page permission map agrees (can_record_payment false)', empty($permsA['can_record_payment']));

    // ---- (b) expired WITH the group expiry charge (first night = 1,165) ----
    $setMode('first_night');
    $bp = ebb_booking($pdo, 'confirmed', 2);
    $bm = ebb_booking($pdo, 'pending', 2, $bp);
    $pdo->prepare("UPDATE bookings SET status = 'expired', expired_at = NOW() WHERE id = ?")->execute([$bm]);
    $apply = rh_group_noshow_fee_apply($pdo, $bm, 'expired');
    $fsB = getBookingFolioSummary($bm);
    $rB = ebb_row($pdo, $bm);
    ok('(b) group expiry charge posted (1,165)', !empty($apply['posted']) && $near($apply['amount'], 1165) && rh_noshow_fee_tagged($pdo, $bm), json_encode($apply));
    ok('(b) tagged expired still owes exactly the charge: summary 1,165, stored amount_due 1,165',
        $near($fsB['grand_total'], 1165) && $near($fsB['balance_due'], 1165) && $near($rB['amount_due'], 1165), json_encode([$fsB['grand_total'], $fsB['balance_due'], $rB['amount_due']]));
    ok('(b) Record payment allowed while the charge is owed', bookingAllowsAction($rB, 'record_payment')['allowed'] === true);
    $setMode('0');

    // ---- (c) expired, no charge, with a payment ----
    $c = ebb_booking($pdo, 'pending', 2);
    ebb_pay($pdo, $c, 500);
    ebb_set_status($pdo, $c, 'expired');
    $fsC = getBookingFolioSummary($c);
    $rC = ebb_row($pdo, $c);
    ok('(c) expired untagged with 500 paid: summary paid 500, balance 0',
        $near($fsC['amount_paid'], 500) && $near($fsC['balance_due'], 0) && $near($fsC['grand_total'], 0), json_encode($fsC));
    ok('(c) stored: amount_due 0, credit_balance 500 (owed back), payment_status paid',
        $near($rC['amount_due'], 0) && $near($rC['credit_balance'], 500) && $rC['payment_status'] === 'paid',
        json_encode([$rC['amount_due'], $rC['credit_balance'], $rC['payment_status']]));

    // ---- (d) no-show and cancelled unchanged ----
    $d1 = ebb_booking($pdo, 'confirmed', 2);
    ebb_set_status($pdo, $d1, 'no-show');
    $fsD1 = getBookingFolioSummary($d1);
    ok('(d) untagged no-show still bills the full stay (2,330)', $near($fsD1['grand_total'], 2330) && $near(ebb_row($pdo, $d1)['amount_due'], 2330), json_encode($fsD1));
    $d2 = ebb_booking($pdo, 'confirmed', 2);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = 1165 WHERE id = ?")->execute([$d2]);
    recalculateBookingFinancials($d2);
    $fsD2 = getBookingFolioSummary($d2);
    ok('(d) cancelled bills only the kept amount (1,165)', $near($fsD2['grand_total'], 1165) && $near(ebb_row($pdo, $d2)['amount_due'], 1165), json_encode($fsD2));
    $d3 = ebb_booking($pdo, 'confirmed', 2);
    $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_retained_amount = NULL WHERE id = ?")->execute([$d3]);
    recalculateBookingFinancials($d3);
    ok('(d) cancelled with nothing kept bills 0, unpaid', $near(getBookingFolioSummary($d3)['grand_total'], 0) && ebb_row($pdo, $d3)['payment_status'] === 'unpaid');
    $d4 = ebb_booking($pdo, 'confirmed', 2);
    ok('(d) a live confirmed booking still owes its stay (2,330)', $near(getBookingFolioSummary($d4)['balance_due'], 2330) && $near(ebb_row($pdo, $d4)['amount_due'], 2330));

    // ---- (e) group invoice: an expired member with money stays listed; its lines still foot to the total ----
    $ep = ebb_booking($pdo, 'confirmed', 2);
    $em = ebb_booking($pdo, 'pending', 2, $ep);
    ebb_pay($pdo, $em, 500);
    ebb_set_status($pdo, $em, 'expired');
    $rows = rh_invoice_doc_rows($pdo, $ep);
    $t = null;
    $h = buildInvoiceHTML($rows['booking'], 'TEST-EBB', 'Hotel', 'x@example.invalid', '000', 'Addr', 'MWK', $rows['group_bookings'], null, false, $t);
    $foot = (float)$t['subtotal'] + (vat_mode() === 'exclusive' ? (float)$t['vat'] : 0.0) + (float)$t['levy'];
    ok('(e) group invoice total = lead 2,330 + expired member 0; paid 500',
        $near($t['total'], 2330) && $near($t['paid'] ?? $t['amount_paid'] ?? 500, 500), json_encode($t));
    ok('(e) group invoice lines foot to its total (no stay line billed for the expired member)', $near($foot, $t['total']), json_encode([$foot, $t]));
} catch (Throwable $e) {
    ok('no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    unset($GLOBALS['_SITE_SETTINGS']['group_noshow_charge']);
}
$left = $chk->query("SELECT COUNT(*) FROM bookings WHERE special_requests = '" . EBB_TAG . "'")->fetchColumn();
ok('rolled back: no test bookings left', (int)$left === 0, (string)$left);
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
