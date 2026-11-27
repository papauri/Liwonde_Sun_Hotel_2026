<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * New Booking dry run ("price check", plan .claude/CREATE_BOOKING_WIZARD_PLAN.md Phase B) over real HTTP: a price
 * check writes NOTHING. Liwonde (the test platform) only; refuses a DB listed in .claude/LIVE_TARGETS and any site
 * that is not Liwonde.
 *
 *   php scripts/tests/create_booking_dryrun_http.php --db
 *
 * Starts `php -S` with scripts/tests/dev-router.php, signs in as the first active admin (and, when one exists, an
 * active non-manager user for the blocked price override), then posts the New Booking form with response=wizard and
 * dry_run=1 for every case below: valid quotes, a duplicate, warnings and refusals at each step. Before and after it
 * counts every table (rows + highest id; checksums for the booking, payment, credit and room tables): the counts must
 * be identical. It only reads, opens pages and sends dry runs: $req() below refuses to post anything without
 * dry_run=1 + response=wizard, so this script can never save a booking. admin_presence (the "who's online" heartbeat
 * every page view writes) is not counted. Run it while nobody else is writing to the Liwonde DB (another session's
 * test or a user of the hosted test site): their rows would show as a change; the new rows are printed to tell.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (!in_array('--db', $argv, true)) { echo "create_booking_dryrun_http: needs --db (Liwonde only). Nothing run.\n"; exit(0); }
if (!function_exists('curl_init')) { echo "create_booking_dryrun_http: php curl extension missing. Nothing run.\n"; exit(1); }
if (stripos($root, 'Liwonde') === false) { fwrite(STDERR, "Refused: runs in the Liwonde repo only.\n"); exit(1); }

require $root . '/config/database.php';
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = (string)@file_get_contents($root . '/.claude/LIVE_TARGETS');
if ($db === '' || stripos($live, $db) !== false) { fwrite(STDERR, "Refused: $db is a live target.\n"); exit(1); }
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false) { fwrite(STDERR, "Refused: site is not Liwonde.\n"); exit(1); }

$pass = 0; $fail = 0;
function check(bool $c, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS $label\n"; } else { $fail++; echo "FAIL $label" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
function cbh_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
/** Rows + highest id of every table, plus checksums of the tables a save changes in place. */
function cbh_snapshot(PDO $pdo): array
{
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    $hasId = array_flip($pdo->query("SELECT table_name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'id'")->fetchAll(PDO::FETCH_COLUMN));
    $snap = [];
    foreach ($tables as $t) {
        if ($t === 'admin_presence') { continue; }
        $q = '`' . str_replace('`', '``', (string)$t) . '`';
        $snap[$t] = array_map('strval', $pdo->query('SELECT COUNT(*), ' . (isset($hasId[$t]) ? 'COALESCE(MAX(id), 0)' : "''") . " FROM $q")->fetch(PDO::FETCH_NUM));
    }
    foreach (['bookings', 'payments', 'credit_notes', 'credit_note_applications', 'rooms', 'individual_rooms', 'booking_rooms', 'room_combinations'] as $t) {
        if (isset($snap[$t])) { $snap[$t][] = (string)$pdo->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM)[1]; }
    }
    return $snap;
}

// ---- Test data (read only)
$admin = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$staff = $pdo->query("SELECT id, username FROM admin_users WHERE is_active = 1 AND role NOT IN ('admin', 'manager') ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$types = $pdo->query("SELECT id, name, total_rooms, children_allowed, max_guests, triple_occupancy_enabled FROM rooms WHERE is_active = 1 ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC);
$joinedType = (int)$pdo->query("SELECT combined_room_type_id FROM room_combinations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$plain = array_values(array_filter($types, static fn($t) => (int)$t['id'] !== $joinedType));
$tripleType = 0; $childType = 0;
foreach ($plain as $t) {
    if (!$tripleType && (int)$t['triple_occupancy_enabled'] === 1 && (int)$t['max_guests'] >= 3) { $tripleType = (int)$t['id']; }
    if (!$childType && (int)$t['children_allowed'] === 1 && (int)$t['max_guests'] >= 2) { $childType = (int)$t['id']; }
}
$typeA = (int)($plain[0]['id'] ?? 0);
$typeB = (int)($plain[1]['id'] ?? 0);
$plan = $pdo->query("SELECT name, start_date, end_date, room_type_ids FROM rate_plans WHERE is_active = 1 AND rule_type = 'seasonal' AND end_date >= DATE_ADD(CURDATE(), INTERVAL 3 DAY) ORDER BY start_date LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$notes = $pdo->query("SELECT id, credit_note_number, balance, guest_email FROM credit_notes WHERE status IN ('active', 'partially_applied') AND balance > 0.01 AND (expires_at IS NULL OR expires_at >= CURDATE()) AND guest_email LIKE '%@%' ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$noteOwn = $notes[0] ?? null;
$noteOther = null;
foreach ($notes as $n) { if ($noteOwn && strcasecmp($n['guest_email'], $noteOwn['guest_email']) !== 0) { $noteOther = $n; break; } }
$dupBooking = $pdo->query("SELECT id, booking_reference, client_uuid FROM bookings WHERE client_uuid IS NOT NULL AND client_uuid <> '' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check($admin && $typeA && $typeB && $joinedType && $tripleType && $childType, 'test data: an admin, two plain room types, a joined, a triple and a child-friendly type',
    json_encode([$typeA, $typeB, $joinedType, $tripleType, $childType]));

// ---- Server + sign in
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$base = "http://127.0.0.1:$port";
$null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, $root . '/scripts/tests/dev-router.php'],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root);
$jars = ['admin' => tempnam(sys_get_temp_dir(), 'cbdr'), 'staff' => tempnam(sys_get_temp_dir(), 'cbdr')];

$req = function (string $who, string $path, ?array $post = null) use ($base, $jars): array {
    $ch = curl_init($base . $path);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$who], CURLOPT_COOKIEFILE => $jars[$who], CURLOPT_FOLLOWLOCATION => $post === null, CURLOPT_TIMEOUT => 120];
    if ($post !== null) {
        // Interlock: this script can only ever send a dry run (a post without both switches would be a real save).
        if (($post['dry_run'] ?? '') !== '1' || ($post['response'] ?? '') !== 'wizard') { throw new RuntimeException('refused: not a dry run'); }
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opt);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
};
$csrfOf = static function (string $html): string { return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : ''; };
$cases = [];
try {
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) { usleep(200000); $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); $up = true; } }
    check($up, 'dev server started');
    [$code, $html] = $req('admin', '/__devlogin?to=create-booking.php');
    $csrf = $csrfOf($html);
    check($code === 200 && $csrf !== '', 'signed in as an admin; New Booking shows a CSRF token', "HTTP $code");
    $req('admin', '/admin/create-booking.php'); // a second view: anything a page view starts (the web scheduler) has finished
    $staffCsrf = '';
    if ($staff) {
        [$sc, $sh] = $req('staff', '/__devlogin?to=create-booking.php&as=' . rawurlencode($staff['username']));
        $staffCsrf = $sc === 200 ? $csrfOf($sh) : '';
    }

    $before = cbh_snapshot($pdo);
    echo 'tables counted: ' . count($before) . "\n";

    $day = static fn(int $d) => date('Y-m-d', strtotime(sprintf('%+d day', $d)));
    $form = function (array $lines, int $guests, array $dates, array $extra = []) use (&$csrf): array {
        $f = ['create_booking' => '1', 'csrf_token' => $csrf, 'response' => 'wizard', 'dry_run' => '1', 'client_uuid' => cbh_uuid(),
            'room_line_room_id' => [], 'room_line_qty' => [], 'room_line_occupancy' => [], 'room_line_override' => [], 'room_line_guest_name' => [],
            'guest_name' => 'Price Check Test', 'guest_email' => 'price.check@example.invalid', 'guest_phone' => '+265 999 000 111', 'guest_country' => 'Malawi',
            'number_of_guests' => (string)$guests, 'child_guests' => '0', 'check_in_date' => $dates[0], 'check_out_date' => $dates[1],
            'booking_status' => 'confirmed', 'payment_method' => 'cash', 'amount_collected' => '', 'special_requests' => '', 'admin_notes' => ''];
        foreach ($lines as [$rid, $qty, $occ, $ovr]) {
            $f['room_line_room_id'][] = (string)$rid; $f['room_line_qty'][] = (string)$qty; $f['room_line_occupancy'][] = $occ;
            $f['room_line_override'][] = (string)$ovr; $f['room_line_guest_name'][] = '';
        }
        return array_replace($f, $extra);
    };
    $dry = function (array $post, string $who = 'admin') use ($req): array {
        [$code, $body] = $req($who, '/admin/create-booking.php', $post);
        $j = json_decode($body, true);
        return is_array($j) ? $j + ['_http' => $code] : ['_http' => $code, '_raw' => substr(trim(strip_tags($body)), 0, 300)];
    };

    // Dates every plain case can use: the first window (2 nights) where one room of each type is free.
    $win = null;
    foreach ([120, 150, 180, 210, 240, 300] as $d) {
        $w = [$day($d), $day($d + 2)];
        $r = $dry($form([[$typeA, 1, 'double', ''], [$typeB, 1, 'double', ''], [$joinedType, 1, 'double', '']], 3, $w));
        if (!empty($r['ok'])) { $win = $w; break; }
    }
    check($win !== null, 'a 2-night window with each room type free was found', $win ? implode(' to ', $win) : 'none');
    $win = $win ?? [$day(120), $day(122)];

    /** Quote figures reconcile: net + VAT + levy = total per row; rows sum to the line, lines to the totals; owed after cash and credit. */
    $reconciles = static function (array $q): string {
        $t = 0.0; $v = 0.0; $l = 0.0; $n = 0.0; $cs = 0.0;
        foreach ($q['lines'] as $ln) {
            $lt = 0.0;
            foreach ($ln['rows'] as $row) {
                if (abs($row['net'] + $row['vat_amount'] + $row['levy_amount'] - $row['total_with_vat']) > 0.005) { return 'row net+vat+levy != total ' . json_encode($row); }
                $lt += $row['total_with_vat']; $v += $row['vat_amount']; $l += $row['levy_amount']; $n += $row['net']; $cs += $row['child_supplement'];
            }
            if (abs($lt - $ln['line_total_with_vat']) > 0.005) { return 'rows != line total'; }
            $t += $ln['line_total_with_vat'];
        }
        $tt = $q['totals'];
        foreach (['total_with_vat' => $t, 'vat' => $v, 'levy' => $l, 'net' => $n, 'child_supplement' => $cs] as $k => $sum) {
            if (abs($tt[$k] - $sum) > 0.005) { return "totals.$k " . $tt[$k] . " != sum $sum"; }
        }
        if (abs($tt['net'] + $tt['vat'] + $tt['levy'] - $tt['total_with_vat']) > 0.005) { return 'totals net+vat+levy != total'; }
        $owed = round(max(0.0, $tt['total_with_vat'] - $q['payment']['amount'] - $q['credit']['will_apply']), 2);
        if (abs($owed - $q['still_owed']) > 0.005) { return "still_owed {$q['still_owed']} != $owed"; }
        return '';
    };
    $quoteCase = function (string $label, array $post, ?callable $extra = null, string $who = 'admin') use ($dry, $reconciles, &$cases): ?array {
        $r = $dry($post, $who);
        $ok = !empty($r['ok']) && isset($r['quote']['totals']);
        check($ok, "$label: quote returned", json_encode($r));
        if (!$ok) { return null; }
        $why = $reconciles($r['quote']);
        check($why === '', "$label: figures reconcile (net + VAT + levy = total, rows = lines = totals, still owed)", $why);
        if ($extra) { $extra($r); }
        $q = $r['quote'];
        $cases[] = sprintf('%-24s total %12.2f vat %11.2f levy %9.2f net %12.2f child %8.2f credit %10.2f owed %12.2f pay %-8s final %-8s %s', $label,
            $q['totals']['total_with_vat'], $q['totals']['vat'], $q['totals']['levy'], $q['totals']['net'], $q['totals']['child_supplement'],
            $q['credit']['will_apply'], $q['still_owed'], $q['payment']['status'], $q['payment']['final_status'], $r['warnings'] ? 'warn step ' . $r['warnings'][0]['step'] : '');
        return $r;
    };
    $refused = function (string $label, array $post, int $step, string $field, ?string $msg, string $who = 'admin') use ($dry, &$cases): void {
        $r = $dry($post, $who);
        check(($r['ok'] ?? null) === false && ($r['step'] ?? null) === $step && ($r['field'] ?? null) === $field && ($msg === null || ($r['message'] ?? '') === $msg),
            "$label: refused at step $step ($field)" . ($msg !== null ? ": $msg" : ''), json_encode($r));
        $cases[] = sprintf('%-24s refused step %s field %s: %s', $label, $r['step'] ?? '?', $r['field'] ?? '?', $r['message'] ?? json_encode($r));
    };

    // ── Valid quotes ──
    $quoteCase('double, no tier price', $form([[$typeA, 1, 'double', '']], 2, $win));
    $quoteCase('triple', $form([[$tripleType, 1, 'triple', '']], 3, $win));
    $quoteCase('child', $form([[$childType, 1, 'double', '']], 2, $win, ['child_guests' => '1']));
    $quoteCase('joined room', $form([[$joinedType, 1, 'double', '']], 2, $win));
    $quoteCase('manager override', $form([[$typeA, 1, 'double', '500000']], 2, $win), static function ($r) {
        $row = $r['quote']['lines'][0]['rows'][0];
        check(abs($row['total_with_vat'] - 500000) < 0.005 && $row['price_source'] === 'override', 'manager override: the typed price is the room stay total (price source override)', json_encode($row));
    });
    $quoteCase('two room types', $form([[$typeA, 1, 'double', ''], [$typeB, 1, 'double', '']], 3, $win), static function ($r) {
        check(count($r['quote']['lines']) === 2, 'two room types: two lines');
    });
    if ($plan) {
        $ids = array_map('intval', (array)json_decode((string)$plan['room_type_ids'], true));
        $pt = in_array($typeB, $ids, true) ? $typeB : (int)($ids[0] ?? $typeB);
        $pIn = max((string)$plan['start_date'], $day(1));
        $quoteCase('rate plan', $form([[$pt, 1, 'double', '']], 2, [$pIn, date('Y-m-d', strtotime($pIn . ' +2 day'))]), static function ($r) use ($plan) {
            check($r['quote']['lines'][0]['rows'][0]['rate_plan_label'] !== '', 'rate plan: the row carries the plan label (' . $plan['name'] . ')', json_encode($r['quote']['lines'][0]['rows'][0]));
        });
    } else {
        check(false, 'rate plan: no active seasonal rate plan in the future on this DB');
    }
    $tent = ['booking_status' => 'tentative'];
    if ($noteOwn) {
        $tent += ['guest_email' => $noteOwn['guest_email'], 'apply_credit_note_ids' => [(string)$noteOwn['id']]];
    }
    $quoteCase('tentative', $form([[$typeB, 1, 'double', '']], 2, $win, $tent), static function ($r) {
        check($r['quote']['credit']['will_apply'] == 0 && $r['quote']['credit']['notes'] === [] && $r['quote']['email_key'] === 'tentative_booking_created',
            'tentative: no credit applied even with a note selected; the tentative email', json_encode($r['quote']['credit']) . ' ' . $r['quote']['email_key']);
    });
    if ($noteOwn) {
        $cr = ['guest_email' => strtoupper($noteOwn['guest_email']), 'apply_credit_note_ids' => [(string)$noteOwn['id']]];
        $full = $quoteCase('credit, no cash', $form([[$typeB, 1, 'double', '']], 2, $win, $cr), static function ($r) use ($noteOwn) {
            $q = $r['quote'];
            check(abs($q['credit']['will_apply'] - min((float)$noteOwn['balance'], $q['totals']['total_with_vat'])) < 0.005 && $q['credit']['notes'][0]['number'] === $noteOwn['credit_note_number']
                && $q['credit']['notes'][0]['override'] === false && $q['payment']['status'] === 'unpaid',
                'credit, no cash: will_apply = min(note balance, total); the guest\'s own note (email case ignored); cash status unpaid', json_encode($q['credit']) . ' ' . $q['payment']['status']);
        });
        if ($full) {
            $tot = $full['quote']['totals']['total_with_vat'];
            $quoteCase('credit + part cash', $form([[$typeB, 1, 'double', '']], 2, $win, $cr + ['payment_received' => '1', 'amount_collected' => number_format($tot - 3000, 2, '.', '')]), static function ($r) {
                $q = $r['quote'];
                check(abs($q['credit']['will_apply'] - 3000) < 0.005 && abs($q['still_owed']) < 0.005 && $q['payment']['status'] === 'partial' && $q['payment']['final_status'] === 'paid',
                    'credit + part cash: credit covers only what cash leaves (3000.00), nothing owed, cash status partial, final paid', json_encode($q['payment']) . ' ' . json_encode($q['credit']));
            });
            $quoteCase('cash covers all', $form([[$typeB, 1, 'double', '']], 2, $win, $cr + ['payment_received' => '1', 'amount_collected' => number_format($tot, 2, '.', '')]), static function ($r) {
                check($r['quote']['credit']['will_apply'] == 0 && $r['quote']['payment']['status'] === 'paid', 'cash covers all: no credit applied', json_encode($r['quote']['credit']));
            });
            $quoteCase('overpay, no tick', $form([[$typeB, 1, 'double', '']], 2, $win, ['payment_received' => '1', 'amount_collected' => number_format($tot + 1000, 2, '.', '')]), static function ($r) {
                check(($r['warnings'][0]['step'] ?? 0) === 4 && abs($r['quote']['payment']['over_by'] - 1000) < 0.005,
                    'overpay without the tick: a step-4 warning with the quote (a save refuses), over_by 1000.00', json_encode($r['warnings']) . ' ' . json_encode($r['quote']['payment']));
            });
        }
    } else {
        check(false, 'credit cases: no usable credit note on this DB');
    }
    if ($noteOther && $noteOwn) {
        $ov = ['guest_email' => $noteOwn['guest_email'], 'apply_credit_note_ids' => [(string)$noteOther['id']], 'apply_credit_override' => '1'];
        $quoteCase('other account, no reason', $form([[$typeB, 1, 'double', '']], 2, $win, $ov), static function ($r) {
            check($r['quote']['credit']['notes'] === [] && $r['quote']['credit']['will_apply'] == 0, 'another account\'s note without a reason: not applied', json_encode($r['quote']['credit']));
        });
        $quoteCase('other account + reason', $form([[$typeB, 1, 'double', '']], 2, $win, $ov + ['apply_credit_override_reason' => 'Paid by the family account']), static function ($r) use ($noteOther) {
            $c = $r['quote']['credit'];
            check(($c['notes'][0]['override'] ?? null) === true && abs($c['will_apply'] - min((float)$noteOther['balance'], $r['quote']['totals']['total_with_vat'])) < 0.005,
                'another account\'s note with tick + reason: applied and flagged as an override', json_encode($c));
        });
    }
    $quoteCase('email wording refused', $form([[$typeA, 1, 'double', '']], 2, $win, ['send_email' => '1', 'email_wording_key' => 'booking_cancelled', 'email_wording_html' => '<p>Hello</p>']), static function ($r) {
        check(($r['warnings'][0]['step'] ?? 0) === 5 && strpos($r['warnings'][0]['message'], 'belongs to a different email') !== false,
            'refused email wording: a step-5 warning with the quote (a save refuses)', json_encode($r['warnings']));
    });
    if ($dupBooking) {
        $r = $dry($form([[$typeA, 1, 'double', '']], 2, $win, ['client_uuid' => (string)$dupBooking['client_uuid']]));
        check(($r['ok'] ?? null) === true && ($r['duplicate'] ?? null) === true && ($r['redirect'] ?? '') === 'booking-details.php?id=' . (int)$dupBooking['id'] && !isset($r['quote']),
            'a form that already saved a booking: {ok, duplicate, redirect}, no quote', json_encode($r));
        $cases[] = sprintf('%-24s %s', 'duplicate uuid', json_encode($r));
    }

    // ── Refusals, each at its step ──
    $refused('past check-in', $form([[$typeA, 1, 'double', '']], 2, [$day(-1), $day(1)]), 1, 'checkInDate', null);
    $refused('check-out before in', $form([[$typeA, 1, 'double', '']], 2, [$win[1], $win[0]]), 1, 'checkOutDate', 'Check-out must be after check-in.');
    $refused('no room left', $form([[$typeA, 20, 'double', '']], 40, $win), 2, 'roomLinesContainer', null);
    $refused('missing email', $form([[$typeA, 1, 'double', '']], 2, $win, ['guest_email' => '']), 3, 'guestEmail', 'Valid email is required.');
    $refused('errors at 3 steps', $form([], 1, $win, ['guest_email' => '', 'child_guests' => '1']), 1, 'childGuests', 'At least 1 adult is required.');
    $refused('bad security token', $form([[$typeA, 1, 'double', '']], 2, $win, ['csrf_token' => 'not-the-token']), 0, '', 'Security token invalid — refresh the page.');
    if ($staff && $staffCsrf !== '') {
        $refused('override, no right', $form([[$typeA, 1, 'double', '450000']], 2, $win, ['csrf_token' => $staffCsrf]), 2, 'roomLinesContainer',
            'Your role does not have permission to override room pricing.', 'staff');
    } else {
        echo 'NOTE blocked price override over HTTP not run: ' . ($staff ? $staff['username'] . ' cannot open New Booking' : 'no non-manager user') . " (covered by the static test)\n";
    }

    $after = cbh_snapshot($pdo);
    $changed = [];
    foreach ($before as $t => $v) { if (($after[$t] ?? null) !== $v) { $changed[$t] = [$v, $after[$t] ?? null]; } }
    foreach ($after as $t => $v) { if (!isset($before[$t])) { $changed[$t] = [null, $v]; } }
    check($changed === [], 'NO WRITES: every table has the same rows, highest id and checksums after ' . (count($cases) + 1) . ' dry runs', json_encode($changed));
    foreach ($changed as $t => [$b]) {
        if (is_array($b) && $b[1] !== '') {
            $st = $pdo->prepare('SELECT * FROM `' . str_replace('`', '``', $t) . '` WHERE id > ? ORDER BY id LIMIT 5');
            $st->execute([(int)$b[1]]);
            echo "  new rows in $t: " . json_encode($st->fetchAll(PDO::FETCH_ASSOC)) . "\n";
        }
    }
    echo "\n" . implode("\n", $cases) . "\n";
} finally {
    if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
    foreach ($jars as $j) { @unlink($j); }
}
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
