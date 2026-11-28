<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Reactivate an expired booking (owner 2026-11-29, migration 093, includes/booking-reactivate.php). TEST HOTEL ONLY:
 *   php scripts/tests/reactivate_booking_test.php
 * Most scenarios run inside scripts/tests/page_harness.php (one outer transaction that never commits, leak-checked):
 * this file is its own child there. Only the parallel / expiry-sweep scenario needs committed rows (two connections, a
 * fresh process for the sweep): those rows carry the reference prefix RACT + "reactivate test" and are deleted by id in a
 * finally block and a shutdown function. Every fixture has no guest email (nothing can be emailed); nothing is sent.
 *   pure:     stage key per lapse, clock SQL with / without the column, lapse time, permission defaults
 *   harness:  basic (pending, today's price, amount_due, same room, history, second try refused), room taken, past check-in,
 *             tentative hold, group fee voided, two-room group, payment, guest booked again, price changed, joined pair
 *             kept / pair taken, page: preview as admin, refused without the permission, button rendered
 *   committed: two parallel applies -> one wins ("Already reactivated"); the real sweep in a fresh process leaves a
 *             reactivated booking pending, and lapses it once reactivated_at is old too; leftover rows = 0
 * Extra modes (used by this file / the browser check): worker <id> <expected> <start>, sweep <id>, fixture, cleanup <ids>.
 */
$root = dirname(__DIR__, 2);
const RA_TAG = 'reactivate test';

/** Expired booking fixture made like the real thing: pending, a room assigned, then expired with the sweep's own steps. */
function ra_make(PDO $pdo, array $o): array
{
    $type = (int)$o['type'];
    $in = $o['in'];
    $out = $o['out'];
    $n = (int)(new DateTime($in))->diff(new DateTime($out))->days;
    $ref = 'RACT' . random_int(100000, 999999);
    $pdo->prepare("INSERT INTO bookings (booking_reference, room_id, primary_booking_id, guest_name, guest_email, guest_phone, guest_country,
            number_of_guests, adult_guests, child_guests, child_price_multiplier, check_in_date, check_out_date, number_of_nights, total_amount, child_supplement_total,
            tourism_levy_amount, tourism_levy_percent, vat_rate, vat_amount, total_with_vat, special_requests, status, payment_status, is_tentative,
            tentative_expires_at, occupancy_type, client_uuid, created_at)
        VALUES (?,?,?,'REACTIVATE TEST',?,'+265000000007','Malawi',2,2,0,50,?,?,?,?,0,0,0,16.5,?,?,?,?,'unpaid',?,?,'double',?,NOW() - INTERVAL 3 DAY)")
        ->execute([$ref, $type, $o['primary'] ?? null, $o['email'] ?? '', $in, $out, $n, 1000 * $n, 165 * $n, 1165 * $n, RA_TAG,
            $o['status'] ?? 'pending', !empty($o['tentative']) ? 1 : 0, !empty($o['tentative']) ? date('Y-m-d H:i:s', strtotime('-1 day')) : null, bin2hex(random_bytes(16))]);
    $id = (int)$pdo->lastInsertId();
    if (!empty($o['combo'])) {
        $r = assignRoomCombinationToBooking($id, (int)$o['combo']);
        if (empty($r['success'])) { throw new RuntimeException('fixture pair: ' . $r['message']); }
    } elseif (!empty($o['room'])) {
        if (assignIndividualRoomToBooking($id, (int)$o['room']) === false) { throw new RuntimeException('fixture room: ' . rh_room_assign_last_error()); }
    }
    if (($o['status'] ?? 'pending') === 'pending' && empty($o['keep'])) {
        $pdo->prepare("UPDATE bookings SET status = 'expired', expired_at = NOW() WHERE id = ?")->execute([$id]);
        rh_release_holds_for_booking_ids($pdo, [$id]);
        if (rh_group_noshow_charge_mode() !== '') {
            rh_group_noshow_fee_apply($pdo, $id, 'expired');
        }
    }
    recalculateBookingFinancials($id);
    return [$id, $ref];
}
function ra_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}
/** Free dates (far ahead) where every room of types 1 and 2 is free; a free Superior room. */
function ra_dates(PDO $pdo, int $nights = 2): array
{
    for ($i = 0; $i < 40; $i++) {
        $off = 420 + random_int(0, 300);
        $in = date('Y-m-d', strtotime("+{$off} day"));
        $out = date('Y-m-d', strtotime('+' . ($off + $nights) . ' day'));
        $v = checkRoomAvailability(1, $in, $out);
        $s = checkRoomAvailability(2, $in, $out);
        if (!empty($v['available']) && (int)($v['remaining_rooms'] ?? 0) >= 1 && !empty($s['available']) && (int)($s['remaining_rooms'] ?? 0) >= 6) {
            return [$in, $out];
        }
    }
    throw new RuntimeException('no free test dates');
}
function ra_free_room(PDO $pdo, string $in, string $out, array $skip = []): int
{
    foreach ($pdo->query('SELECT id FROM individual_rooms WHERE room_type_id = 2 AND is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        if (!in_array((int)$rid, $skip, true) && !empty(checkIndividualRoomAvailability((int)$rid, $in, $out)['available'])) {
            return (int)$rid;
        }
    }
    throw new RuntimeException('no free Superior room');
}
/** Today's quote for a fixture row, independently from the booking-reactivate plan: the guest booking helper itself. */
function ra_expected(PDO $pdo, array $b, ?array $combo = null): float
{
    $st = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
    $st->execute([(int)$b['room_id']]);
    $type = $st->fetch(PDO::FETCH_ASSOC);
    $n = (int)(new DateTime($b['check_in_date']))->diff(new DateTime($b['check_out_date']))->days;
    $p = rh_guest_price_row($pdo, $type, $type, $combo, (int)$b['room_id'], $b['check_in_date'], $b['check_out_date'], $n, (string)$b['occupancy_type'],
        (int)$b['child_guests'], rh_child_rule_for($pdo, (int)$b['room_id']), 0.0);
    return round((float)$p['total_with_vat'], 2);
}
function ra_admin(PDO $pdo): array
{
    return $pdo->query("SELECT id, username, full_name FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
function ra_try(callable $f): string
{
    try { $f(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}
function ra_cleanup(PDO $pdo, array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { return 0; }
    $in = implode(',', $ids);
    // Only rows of our own fixtures (ids we created), never anything else.
    foreach (['booking_timeline_logs', 'booking_audit_log', 'booking_rooms', 'booking_charges', 'booking_packages'] as $t) {
        try { $pdo->exec("DELETE FROM {$t} WHERE booking_id IN ({$in})"); } catch (Throwable $e) { /* table missing */ }
    }
    try { $pdo->exec("DELETE FROM quotations WHERE booking_type = 'room' AND booking_id IN ({$in})"); } catch (Throwable $e) {}
    try { $pdo->exec("DELETE FROM automated_email_log WHERE account_type = 'room' AND account_id IN ({$in})"); } catch (Throwable $e) {}
    return (int)$pdo->exec("DELETE FROM bookings WHERE id IN ({$in}) AND special_requests = " . $pdo->quote(RA_TAG));
}

// ===================================================================== harness child (rolled back)
if (isset($GLOBALS['__h_cfg'])) {
    require_once $root . '/admin/includes/permissions.php';
    require_once $root . '/admin/includes/audit-functions.php';
    require_once $root . '/includes/booking-reactivate.php';
    $sc = (string)($_POST['scenario'] ?? '');
    $out = ['scenario' => $sc];
    $admin = ra_admin($pdo);
    $GLOBALS['_SITE_SETTINGS']['group_noshow_charge'] = $sc === 'fee' ? 'first_night' : '0'; // in memory only
    [$in, $out2] = ra_dates($pdo);
    $out['dates'] = [$in, $out2];
    switch ($sc) {
        case 'basic':
            $room = ra_free_room($pdo, $in, $out2);
            [$id] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2, 'room' => $room]);
            $b0 = ra_row($pdo, $id);
            $out['setup'] = ['status' => $b0['status'], 'room' => $b0['individual_room_id'], 'due' => $b0['amount_due']];
            $q = rh_reactivate_quote($pdo, $id);
            $out['q'] = $q;
            $out['expected'] = ra_expected($pdo, $b0);
            $out['apply_err'] = ra_try(function () use ($pdo, $id, $q, $admin, &$out) { $out['apply'] = rh_reactivate_apply($pdo, $id, $q['new_total'] ?? null, $admin); });
            $b = ra_row($pdo, $id);
            $out['row'] = array_intersect_key($b, array_flip(['status', 'reactivated_at', 'expired_at', 'is_tentative', 'total_with_vat', 'amount_due', 'individual_room_id', 'created_at', 'payment_status']));
            $out['room_before'] = $room;
            $br = $pdo->prepare('SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ? AND individual_room_id = ? AND released_at IS NULL');
            $br->execute([$id, $room]);
            $out['booking_rooms_live'] = (int)$br->fetchColumn();
            $tl = $pdo->prepare("SELECT action, description FROM booking_timeline_logs WHERE booking_id = ? ORDER BY id DESC LIMIT 4");
            $tl->execute([$id]);
            $out['timeline'] = $tl->fetchAll(PDO::FETCH_ASSOC);
            $out['lapse'] = rh_pending_lapse_at($b, 48) - (int)strtotime((string)$b['reactivated_at']);
            // The sweep's own selection (same SQL as _expireStalePendingBookings) with created_at set back past the hold.
            $pdo->prepare('UPDATE bookings SET created_at = NOW() - INTERVAL 10 DAY WHERE id = ?')->execute([$id]);
            $sel = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.id = ? AND b.status = 'pending' AND " . rh_pending_clock_sql('b') . " < (NOW() - INTERVAL 48 HOUR)");
            $sel->execute([$id]);
            $out['sweep_picks_new'] = (int)$sel->fetchColumn();
            $sel2 = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.id = ? AND b.status = 'pending' AND " . rh_pending_clock_sql('b', false) . " < (NOW() - INTERVAL 48 HOUR)");
            $sel2->execute([$id]);
            $out['sweep_picks_old_rule'] = (int)$sel2->fetchColumn();
            $out['second'] = ra_try(function () use ($pdo, $id, $q, $admin) { rh_reactivate_apply($pdo, $id, $q['new_total'] ?? 0, $admin); });
            break;
        case 'taken':
            [$id] = ra_make($pdo, ['type' => 1, 'in' => $in, 'out' => $out2]);
            [$other] = ra_make($pdo, ['type' => 1, 'in' => $in, 'out' => $out2, 'keep' => true]); // pending, holds the only villa
            $b0 = ra_row($pdo, $id);
            $out['q'] = rh_reactivate_quote($pdo, $id);
            $out['apply'] = ra_try(function () use ($pdo, $id, $admin) { rh_reactivate_apply($pdo, $id, 1, $admin); });
            $b = ra_row($pdo, $id);
            $out['unchanged'] = $b['status'] === 'expired' && $b['reactivated_at'] === null && $b['total_with_vat'] === $b0['total_with_vat'] && $b['updated_at'] === $b0['updated_at'];
            break;
        case 'past':
            $past = date('Y-m-d', strtotime('-2 day'));
            [$id] = ra_make($pdo, ['type' => 2, 'in' => $past, 'out' => date('Y-m-d', strtotime('+1 day'))]);
            $out['q'] = rh_reactivate_quote($pdo, $id);
            $out['apply'] = ra_try(function () use ($pdo, $id, $admin) { rh_reactivate_apply($pdo, $id, 1, $admin); });
            $out['status'] = ra_row($pdo, $id)['status'];
            break;
        case 'tentative':
            [$id] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2, 'tentative' => true]);
            $out['q'] = rh_reactivate_quote($pdo, $id);
            break;
        case 'fee':
            [$rootId] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2, 'status' => 'confirmed']);
            [$id] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2, 'primary' => $rootId]);
            $f = $pdo->prepare('SELECT id, voided, line_total FROM booking_charges WHERE booking_id = ? AND description LIKE ?');
            $f->execute([$id, '%' . rh_noshow_fee_tag($id)]);
            $out['fee_before'] = $f->fetch(PDO::FETCH_ASSOC);
            $out['due_before'] = ra_row($pdo, $id)['amount_due'];
            $q = rh_reactivate_quote($pdo, $id);
            $out['q'] = $q;
            $out['apply_err'] = ra_try(function () use ($pdo, $id, $q, $admin) { rh_reactivate_apply($pdo, $id, $q['new_total'] ?? null, $admin); });
            $f2 = $pdo->prepare('SELECT voided, void_reason, voided_by FROM booking_charges WHERE booking_id = ? AND description LIKE ?');
            $f2->execute([$id, '%' . rh_noshow_fee_tag($id)]);
            $out['fee_after'] = $f2->fetch(PDO::FETCH_ASSOC);
            $b = ra_row($pdo, $id);
            $out['row'] = ['status' => $b['status'], 'amount_due' => $b['amount_due'], 'total_with_vat' => $b['total_with_vat']];
            $out['root_status'] = ra_row($pdo, $rootId)['status'];
            $out['admin_id'] = (int)$admin['id'];
            break;
        case 'group2':
            [$rootId] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2]);
            [$mem] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2, 'primary' => $rootId]);
            $pdo->prepare("UPDATE bookings SET occupancy_type = 'single', number_of_guests = 1, adult_guests = 1 WHERE id = ?")->execute([$mem]);
            $q = rh_reactivate_quote($pdo, $mem);
            $out['q'] = $q;
            $out['expected_sum'] = round(ra_expected($pdo, ra_row($pdo, $rootId)) + ra_expected($pdo, ra_row($pdo, $mem)), 2);
            $out['apply_err'] = ra_try(function () use ($pdo, $mem, $q, $admin) { rh_reactivate_apply($pdo, $mem, $q['new_total'] ?? null, $admin); });
            $out['statuses'] = [ra_row($pdo, $rootId)['status'], ra_row($pdo, $mem)['status']];
            break;
        case 'paid':
            [$id, $ref] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2]);
            $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, created_at) VALUES (?, 'room', ?, ?, CURDATE(), 100, 0, 0, 100, 'cash', 'partial_payment', 'completed', NOW())")
                ->execute(['RACP' . random_int(100000, 999999), $id, $ref]);
            recalculateBookingFinancials($id);
            $out['q'] = rh_reactivate_quote($pdo, $id);
            $out['apply'] = ra_try(function () use ($pdo, $id, $admin) { rh_reactivate_apply($pdo, $id, 1, $admin); });
            $out['status'] = ra_row($pdo, $id)['status'];
            break;
        case 'dup':
            [$id] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2, 'email' => 'ra-dup@example.invalid']);
            [$again, $againRef] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2, 'email' => 'ra-dup@example.invalid', 'keep' => true]);
            $out['again_ref'] = $againRef;
            $out['q'] = rh_reactivate_quote($pdo, $id);
            $out['apply'] = ra_try(function () use ($pdo, $id, $admin) { rh_reactivate_apply($pdo, $id, 1, $admin); });
            $out['status'] = ra_row($pdo, $id)['status'];
            break;
        case 'stale':
            [$id] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2]);
            $b0 = ra_row($pdo, $id);
            $q = rh_reactivate_quote($pdo, $id);
            $out['apply'] = ra_try(function () use ($pdo, $id, $q, $admin) { rh_reactivate_apply($pdo, $id, ($q['new_total'] ?? 0) + 50, $admin); });
            $b = ra_row($pdo, $id);
            $out['unchanged'] = $b['status'] === 'expired' && $b['total_with_vat'] === $b0['total_with_vat'] && $b['reactivated_at'] === null;
            $out['near_ok'] = ra_try(function () use ($pdo, $id, $q, $admin) { rh_reactivate_apply($pdo, $id, ($q['new_total'] ?? 0) + 0.004, $admin); });
            $out['status_after_near'] = ra_row($pdo, $id)['status'];
            break;
        case 'joined':
        case 'joined_taken':
            $combo = $pdo->query('SELECT rc.*, a.room_type_id AS room_a_type_id, b.room_type_id AS room_b_type_id FROM room_combinations rc
                JOIN individual_rooms a ON a.id = rc.room_a_id JOIN individual_rooms b ON b.id = rc.room_b_id WHERE rc.combined_room_type_id = 7 AND rc.is_active = 1 ORDER BY rc.id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            [$id] = ra_make($pdo, ['type' => 7, 'in' => $in, 'out' => $out2, 'combo' => (int)$combo['id']]);
            $out['released_combo'] = (int)$pdo->query('SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ' . $id . ' AND room_combination_id = ' . (int)$combo['id'] . ' AND released_at IS NOT NULL')->fetchColumn();
            if ($sc === 'joined_taken') {
                ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2, 'room' => (int)$combo['room_a_id'], 'keep' => true]);
            }
            $q = rh_reactivate_quote($pdo, $id);
            $out['q'] = $q;
            $out['expected'] = ra_expected($pdo, ra_row($pdo, $id), $combo);
            $out['apply_err'] = ra_try(function () use ($pdo, $id, $q, $admin) { rh_reactivate_apply($pdo, $id, $q['new_total'] ?? 1, $admin); });
            $b = ra_row($pdo, $id);
            $out['row'] = ['status' => $b['status'], 'room_combination_id' => $b['room_combination_id'], 'total_with_vat' => $b['total_with_vat']];
            $out['combo'] = (int)$combo['id'];
            $out['live_rooms'] = (int)$pdo->query('SELECT COUNT(*) FROM booking_rooms WHERE booking_id = ' . $id . ' AND released_at IS NULL')->fetchColumn();
            break;
        case 'no_perm':
            [$id] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out2]);
            $staff = $pdo->query("SELECT id, username, full_name FROM admin_users WHERE username = 'test.staff' AND is_active = 1")->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, is_granted, granted_by) VALUES (?, 'reactivate_booking', 0, NULL) ON DUPLICATE KEY UPDATE is_granted = 0")->execute([(int)$staff['id']]);
            $q = rh_reactivate_quote($pdo, $id);
            $out['apply'] = ra_try(function () use ($pdo, $id, $q, $staff) { rh_reactivate_apply($pdo, $id, $q['new_total'] ?? 0, $staff); });
            $out['status'] = ra_row($pdo, $id)['status'];
            break;
    }
    echo json_encode($out);
    return;
}

require_once $root . '/config/database.php';
if (stripos((string)DB_NAME, 'rosalyn') !== false) { // Rosalyn's is LIVE: DB tests run at the test hotel only
    fwrite(STDERR, "Refused: run this test from the Liwonde (test hotel) folder only.\n");
    exit(2);
}
require_once $root . '/admin/includes/permissions.php';
require_once $root . '/includes/booking-reactivate.php';
$mode = (string)($argv[1] ?? '');

// ===================================================================== helper processes
if ($mode === 'worker') { // one parallel apply on committed rows
    $start = (float)($argv[4] ?? 0);
    while (microtime(true) < $start) { usleep(2000); }
    $err = ra_try(function () use ($pdo, $argv) { rh_reactivate_apply($pdo, (int)$argv[2], $argv[3], ra_admin($pdo)); });
    echo json_encode(['err' => $err]), "\n";
    exit(0);
}
if ($mode === 'sweep') { // config/database.php above ran the real _expireStalePendingBookings in this fresh process
    echo json_encode(['status' => ra_row($pdo, (int)$argv[2])['status'] ?? null]), "\n";
    exit(0);
}
if ($mode === 'fixture') { // committed expired booking for the browser check (remove with: cleanup <id>)
    [$in, $out] = ra_dates($pdo);
    [$id, $ref] = ra_make($pdo, ['type' => 2, 'in' => $in, 'out' => $out, 'room' => ra_free_room($pdo, $in, $out)]);
    echo json_encode(['id' => $id, 'ref' => $ref]), "\n";
    exit(0);
}
if ($mode === 'cleanup') {
    echo 'deleted bookings: ' . ra_cleanup($pdo, explode(',', (string)($argv[2] ?? ''))) . "\n";
    echo 'leftover test rows: ' . (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE special_requests = " . $pdo->quote(RA_TAG))->fetchColumn() . "\n";
    exit(0);
}

// ===================================================================== main
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; } else { $fail++; echo "[FAIL] $name" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
$near = static fn($a, $b): bool => abs((float)$a - (float)$b) <= BALANCE_TOLERANCE;
$has = static fn($hay, string $needle): bool => stripos((string)$hay, $needle) !== false;
$harness = $root . '/scripts/tests/page_harness.php';
function ra_run(string $page, array $cfg): array {
    global $harness, $root;
    $tmp = tempnam(sys_get_temp_dir(), 'ra');
    file_put_contents($tmp, base64_encode(json_encode($cfg)));
    $cwd = getcwd();
    chdir($root);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($page) . ' ' . escapeshellarg('@' . $tmp) . ' 2>&1');
    chdir($cwd);
    @unlink($tmp);
    $res = ['error' => 'no result', 'raw' => substr((string)$out, -1500), 'rolled_back' => false];
    if (preg_match('/@@RESULT@@(.*)$/m', (string)$out, $m)) { $r = json_decode($m[1], true); if (is_array($r)) { $res = $r; } }
    if (empty($res['rolled_back']) || !empty($res['leak']) || !empty($res['marker_lost'])) {
        echo "HARNESS PROBLEM: " . json_encode(array_intersect_key($res, ['rolled_back' => 1, 'leak' => 1, 'marker_lost' => 1, 'raw' => 1, 'error' => 1])) . "\n";
    }
    return $res;
}
function ra_child(string $sc): array {
    $r = ra_run('scripts/tests/reactivate_booking_test.php', ['post' => ['scenario' => $sc]]);
    if (!isset($r['json'])) { echo "child $sc output: " . substr((string)($r['raw'] ?? json_encode($r)), -1500) . "\n"; }
    return $r['json'] ?? [];
}

echo "== pure ==\n";
ok('stage: never reactivated = expired (old key kept)', rh_pending_expired_notice_stage(['reactivated_at' => null]) === 'expired' && rh_pending_expired_notice_stage([]) === 'expired');
$s1 = rh_pending_expired_notice_stage(['reactivated_at' => '2026-11-29 10:00:00']);
$s2 = rh_pending_expired_notice_stage(['reactivated_at' => '2026-12-03 09:30:00']);
ok('stage: a lapse after reactivation gets its own key (notice once per lapse)', $s1 !== 'expired' && $s1 !== $s2 && strpos($s1, 'expired:r') === 0, "$s1 / $s2");
ok('stage: <= 40 chars with the test: prefix and still matches the email-log prefix mapping', strlen(rh_pending_expired_notice_stage(['reactivated_at' => '2099-12-31 23:59:59'], 'test:')) <= 40);
ok('clock SQL without the column = created_at only (no reactivated_at in the SQL)', rh_pending_clock_sql('b', false) === 'b.created_at');
ok('clock SQL with the column = GREATEST(created_at, reactivated_at)', rh_pending_clock_sql('x', true) === 'GREATEST(x.created_at, COALESCE(x.reactivated_at, x.created_at))');
$e = ra_try(function () use ($pdo) { $pdo->query('SELECT COUNT(*) FROM bookings b WHERE ' . rh_pending_clock_sql('b', false) . ' < NOW()')->fetchColumn(); });
ok('clock SQL without the column runs', $e === '', $e);
ok('lapse: missing key = created_at + hours', rh_pending_lapse_at(['created_at' => '2026-11-01 10:00:00'], 48) === strtotime('2026-11-03 10:00:00'));
ok('lapse: later reactivated_at restarts the clock', rh_pending_lapse_at(['created_at' => '2026-11-01 10:00:00', 'reactivated_at' => '2026-11-20 08:00:00'], 48) === strtotime('2026-11-22 08:00:00'));
ok('lapse: hours 0 = never', rh_pending_lapse_at(['created_at' => '2026-11-01 10:00:00'], 0) === 0);
ok('column present on Liwonde (migration 093 ran)', rh_reactivated_at_present($pdo));
$K = 'reactivate_booking';
ok('permission defined', isset(getAllPermissions()[$K]));
ok('manager + administrator have it by default', in_array($K, getDefaultPermissionsForRole('manager'), true) && in_array($K, getDefaultPermissionsForRole('admin'), true));
$others = array_diff(array_keys(getAllRoles()), ['admin', 'manager']);
ok('no other role has it by default', !array_filter($others, static fn($r) => in_array($K, getDefaultPermissionsForRole($r), true)));
ok('both actions need it', rhBookingActionPermission('reactivate') === $K && rhBookingActionPermission('reactivate_preview') === $K && rhBookingActionPermission('reactivate_apply') === $K);

echo "== basic (rolled back) ==\n";
$c = ra_child('basic');
ok('setup: expired, room released, owes nothing', ($c['setup']['status'] ?? '') === 'expired' && empty($c['setup']['room']) && $near($c['setup']['due'] ?? -1, 0), json_encode($c['setup'] ?? []));
ok('quote ok: old total 2,330, new total = today\'s quote (rh_guest_price_row)', !empty($c['q']['ok']) && $near($c['q']['old_total'] ?? 0, 2330)
    && $near($c['q']['new_total'] ?? 0, $c['expected'] ?? -1) && ($c['expected'] ?? 0) > 2330, json_encode([$c['q'] ?? [], $c['expected'] ?? null]));
ok('apply ok', ($c['apply_err'] ?? 'x') === '', (string)($c['apply_err'] ?? ''));
$r = $c['row'] ?? [];
ok('pending, reactivated_at set, expired_at cleared, not tentative', ($r['status'] ?? '') === 'pending' && !empty($r['reactivated_at']) && empty($r['expired_at']) && (int)($r['is_tentative'] ?? 1) === 0, json_encode($r));
ok('total_with_vat = today\'s quote; amount_due = the same (nothing paid), unpaid', $near($r['total_with_vat'] ?? 0, $c['expected'] ?? -1) && $near($r['amount_due'] ?? 0, $c['expected'] ?? -1)
    && ($r['payment_status'] ?? '') === 'unpaid', json_encode($r));
ok('same room given back (bookings + booking_rooms)', (int)($r['individual_room_id'] ?? 0) === (int)($c['room_before'] ?? -1) && (int)($c['booking_rooms_live'] ?? 0) === 1, json_encode([$r['individual_room_id'] ?? null, $c['room_before'] ?? null]));
$tlActs = array_column($c['timeline'] ?? [], 'action');
$tlText = json_encode($c['timeline'] ?? []);
ok('history: status change + "Booking reactivated" with who and old -> new price', in_array('Booking reactivated', $tlActs, true) && $has($tlText, 'Reactivated by') && $has($tlText, '2,330.00 ->'), $tlText);
ok('hold restarts: lapses 48 h after the reactivation', (int)($c['lapse'] ?? 0) === 48 * 3600, (string)($c['lapse'] ?? ''));
ok('sweep selection with created_at 10 days back: NOT picked (clock = reactivated_at)', (int)($c['sweep_picks_new'] ?? 1) === 0 && (int)($c['sweep_picks_old_rule'] ?? 0) === 1, json_encode([$c['sweep_picks_new'] ?? null, $c['sweep_picks_old_rule'] ?? null]));
ok('second try: "Already reactivated"', ($c['second'] ?? '') === 'Already reactivated', (string)($c['second'] ?? ''));

echo "== refusals (rolled back) ==\n";
$c = ra_child('taken');
ok('room taken: quote refused naming the room', empty($c['q']['ok']) && $has($c['q']['error'] ?? '', 'VIP Beach Front Villa'), json_encode($c['q'] ?? []));
ok('room taken: apply refused, nothing changed', $c['apply'] !== '' && !empty($c['unchanged']), json_encode([$c['apply'] ?? null, $c['unchanged'] ?? null]));
$c = ra_child('past');
ok('past check-in: refused with the reason, still expired', empty($c['q']['ok']) && $has($c['q']['error'] ?? '', 'has passed') && $has($c['apply'] ?? '', 'has passed') && ($c['status'] ?? '') === 'expired', json_encode($c));
$c = ra_child('tentative');
ok('expired tentative hold: refused', empty($c['q']['ok']) && $has($c['q']['error'] ?? '', 'tentative hold'), json_encode($c['q'] ?? []));
$c = ra_child('paid');
ok('payment recorded: refused, still expired', empty($c['q']['ok']) && $has($c['q']['error'] ?? '', 'payment') && $has($c['apply'] ?? '', 'payment') && ($c['status'] ?? '') === 'expired', json_encode($c));
$c = ra_child('dup');
ok('guest booked again (same email, type, dates): refused naming that booking', empty($c['q']['ok']) && $has($c['q']['error'] ?? '', (string)($c['again_ref'] ?? '#')) && ($c['status'] ?? '') === 'expired', json_encode($c));
$c = ra_child('stale');
ok('price changed between review and save: "Price changed, review again", nothing changed', ($c['apply'] ?? '') === 'Price changed, review again.' && !empty($c['unchanged']), json_encode($c));
ok('a difference inside BALANCE_TOLERANCE is the same price (saved)', ($c['near_ok'] ?? 'x') === '' && ($c['status_after_near'] ?? '') === 'pending', json_encode($c));
$c = ra_child('no_perm');
ok('no permission: refused in the library too, still expired', $has($c['apply'] ?? '', 'permission') && ($c['status'] ?? '') === 'expired', json_encode($c));

echo "== group, fee, joined (rolled back) ==\n";
$c = ra_child('fee');
ok('setup: tagged group expiry charge posted (owes 1,165)', isset($c['fee_before']['voided']) && (int)$c['fee_before']['voided'] === 0 && $near($c['due_before'] ?? 0, 1165), json_encode([$c['fee_before'] ?? null, $c['due_before'] ?? null]));
ok('fee: reactivated', ($c['apply_err'] ?? 'x') === '' && ($c['row']['status'] ?? '') === 'pending', (string)($c['apply_err'] ?? ''));
ok('fee: the charge is voided (reason, who)', (int)($c['fee_after']['voided'] ?? 0) === 1 && ($c['fee_after']['void_reason'] ?? '') === 'Booking reactivated'
    && (int)($c['fee_after']['voided_by'] ?? 0) === (int)($c['admin_id'] ?? -1), json_encode($c['fee_after'] ?? []));
ok('fee: owes the new stay only (amount_due = total_with_vat)', $near($c['row']['amount_due'] ?? 0, $c['row']['total_with_vat'] ?? -1) && $near($c['row']['total_with_vat'] ?? 0, $c['q']['new_total'] ?? -1), json_encode($c['row'] ?? []));
ok('fee: the confirmed lead room is untouched', ($c['root_status'] ?? '') === 'confirmed');
$c = ra_child('group2');
ok('group of two expired rooms: both in the review, total = sum of today\'s quotes', count($c['q']['rows'] ?? []) === 2 && $near($c['q']['new_total'] ?? 0, $c['expected_sum'] ?? -1), json_encode([$c['q'] ?? [], $c['expected_sum'] ?? null]));
ok('group of two: both pending (from the member room)', ($c['apply_err'] ?? 'x') === '' && ($c['statuses'] ?? []) === ['pending', 'pending'], json_encode($c));
$c = ra_child('joined');
ok('joined: setup released the pair', (int)($c['released_combo'] ?? 0) === 2, json_encode($c));
ok('joined: same pair given back, priced from the pair', ($c['apply_err'] ?? 'x') === '' && ($c['row']['status'] ?? '') === 'pending' && (int)($c['row']['room_combination_id'] ?? 0) === (int)($c['combo'] ?? -1)
    && (int)($c['live_rooms'] ?? 0) === 2 && $near($c['row']['total_with_vat'] ?? 0, $c['expected'] ?? -1) && $has($c['q']['rows'][0]['room'] ?? '', 'Same joined rooms'), json_encode($c));
$c = ra_child('joined_taken');
ok('joined: a room of the only pair taken -> refused, still expired', empty($c['q']['ok']) && ($c['apply_err'] ?? '') !== '' && ($c['row']['status'] ?? '') === 'expired', json_encode([$c['q'] ?? [], $c['apply_err'] ?? null]));

echo "== booking-details.php (rolled back) ==\n";
$pref = 'RACT' . random_int(100000, 999999);
[$pin, $pout] = ra_dates($pdo);
$setup = ["INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, guest_country, number_of_guests, adult_guests, child_guests, child_price_multiplier,
        check_in_date, check_out_date, number_of_nights, total_amount, tourism_levy_amount, tourism_levy_percent, vat_rate, vat_amount, total_with_vat, amount_due, special_requests,
        status, expired_at, payment_status, is_tentative, occupancy_type, client_uuid, created_at)
    VALUES ('$pref', 2, 'REACTIVATE TEST', '', '+265000000007', 'Malawi', 2, 2, 0, 50, '$pin', '$pout', 2, 2000, 0, 0, 16.5, 330, 2330, 0, '" . RA_TAG . "',
        'expired', NOW(), 'unpaid', 0, 'double', '" . bin2hex(random_bytes(16)) . "', NOW() - INTERVAL 3 DAY)"];
$vars = ['bid' => "SELECT id FROM bookings WHERE booking_reference = '$pref'"];
$p = ra_run('admin/booking-details.php', ['ajax' => true, 'post' => ['action' => 'reactivate_preview'], 'get' => ['id' => '{bid}'], 'setup' => $setup, 'vars' => $vars]);
ok('page: preview as admin answers the review JSON', !empty($p['json']['success']) && $near($p['json']['quote']['old_total'] ?? 0, 2330) && ($p['json']['quote']['new_total'] ?? 0) > 0, json_encode($p['json'] ?? $p['raw'] ?? []));
$staff = (int)$pdo->query("SELECT id FROM admin_users WHERE username = 'test.staff' AND is_active = 1")->fetchColumn();
$grant = static fn(int $g): string => "INSERT INTO user_permissions (user_id, permission_key, is_granted, granted_by) VALUES ($staff, 'reactivate_booking', $g, NULL) ON DUPLICATE KEY UPDATE is_granted = $g";
$p = ra_run('admin/booking-details.php', ['ajax' => true, 'as_username' => 'test.staff', 'post' => ['action' => 'reactivate_apply', 'expected_total' => '1'], 'get' => ['id' => '{bid}'],
    'setup' => array_merge($setup, [$grant(0)]), 'vars' => $vars, 'verify' => ["SELECT status FROM bookings WHERE booking_reference = '$pref'"]]);
ok('page: save refused without reactivate_booking (receptionist), still expired', empty($p['json']['success']) && $has($p['json']['message'] ?? '', 'permission')
    && ($p['verify'][0][0]['status'] ?? '') === 'expired', json_encode([$p['json'] ?? $p['raw'] ?? [], $p['verify'] ?? []]));
$p = ra_run('admin/booking-details.php', ['ajax' => true, 'as_username' => 'test.staff', 'post' => ['action' => 'reactivate_preview'], 'get' => ['id' => '{bid}'],
    'setup' => array_merge($setup, [$grant(1)]), 'vars' => $vars]);
ok('page: granted to that person -> preview allowed', !empty($p['json']['success']), json_encode($p['json'] ?? $p['raw'] ?? []));
$p = ra_run('admin/booking-details.php', ['method' => 'GET', 'get' => ['id' => '{bid}'], 'setup' => $setup, 'vars' => $vars, 'grep' => ['id="bdReactivateBtn"', 'id="reactivateModal"']]);
ok('page: button + dialog rendered for an eligible expired booking', !empty($p['found']['id="bdReactivateBtn"']) && !empty($p['found']['id="reactivateModal"']), json_encode($p['found'] ?? $p['raw'] ?? []));
$p = ra_run('admin/booking-details.php', ['method' => 'GET', 'get' => ['id' => '{bid}'], 'setup' => array_merge($setup, ["UPDATE bookings SET check_in_date = CURDATE() - INTERVAL 3 DAY, check_out_date = CURDATE() - INTERVAL 1 DAY WHERE booking_reference = '$pref'"]),
    'vars' => $vars, 'grep' => ['id="bdReactivateBtn"']]);
ok('page: no button when the check-in date has passed', isset($p['found']) && empty($p['found']['id="bdReactivateBtn"']), json_encode($p['found'] ?? $p['raw'] ?? []));

echo "== committed rows: parallel + the real sweep ==\n";
$made = [];
register_shutdown_function(static function () use (&$made, $pdo) { if ($made) { ra_cleanup($pdo, $made); } });
try {
    [$pin, $pout] = ra_dates($pdo);
    [$id] = ra_make($pdo, ['type' => 2, 'in' => $pin, 'out' => $pout, 'room' => ra_free_room($pdo, $pin, $pout)]);
    $made[] = $id;
    $q = rh_reactivate_quote($pdo, $id);
    ok('committed fixture: review ok', !empty($q['ok']), json_encode($q));
    $start = microtime(true) + 1.5;
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $procs[] = proc_open([PHP_BINARY, __FILE__, 'worker', (string)$id, (string)$q['new_total'], (string)$start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], $root);
    }
    $res = [];
    foreach ($procs as $i => $pr) {
        $o = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
        proc_close($pr);
        $j = json_decode(trim((string)preg_replace('/^.*?(\{"err".*\})\s*$/s', '$1', $o)), true);
        $res[] = is_array($j) ? $j['err'] : 'bad output: ' . substr($o, -300);
    }
    sort($res);
    ok('two parallel applies: one wins, the other gets "Already reactivated"', $res === ['', 'Already reactivated'], json_encode($res));
    $b = ra_row($pdo, $id);
    ok('one reactivation only: pending, one "Booking reactivated" line', $b['status'] === 'pending'
        && (int)$pdo->query("SELECT COUNT(*) FROM booking_timeline_logs WHERE booking_id = $id AND action = 'Booking reactivated'")->fetchColumn() === 1);
    $pdo->prepare('UPDATE bookings SET created_at = NOW() - INTERVAL 10 DAY WHERE id = ?')->execute([$id]);
    $sweep = static function () use ($id, $root): string {
        $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' sweep ' . (int)$id . ' 2>&1');
        $j = json_decode(trim((string)preg_replace('/^.*?(\{"status".*\})\s*$/s', '$1', (string)$o)), true);
        return is_array($j) ? (string)$j['status'] : 'bad output: ' . substr((string)$o, -300);
    };
    ok('real sweep (fresh process), booked 10 days ago but reactivated now: still pending', $sweep() === 'pending');
    $pdo->prepare('UPDATE bookings SET reactivated_at = NOW() - INTERVAL 5 DAY WHERE id = ?')->execute([$id]);
    ok('real sweep once the reactivation is 5 days old too: lapses again (expired)', $sweep() === 'expired');
    $b = ra_row($pdo, $id);
    ok('second lapse: the notice key differs from the first lapse (one notice per lapse)', rh_pending_expired_notice_stage($b) !== 'expired' && strpos(rh_pending_expired_notice_stage($b), 'expired:r') === 0, rh_pending_expired_notice_stage($b));
} finally {
    $del = ra_cleanup($pdo, $made);
    $made = [];
    $left = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE special_requests = " . $pdo->quote(RA_TAG))->fetchColumn();
    ok('committed fixtures removed (by id); leftover test rows = 0', $left === 0, "deleted $del, left $left");
}

echo "\nreactivate_booking_test: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
