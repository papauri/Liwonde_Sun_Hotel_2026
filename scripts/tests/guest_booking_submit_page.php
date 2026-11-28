<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Guest booking page, phase G-C (Book posted in the background): booking.php with response=wizard (no dry_run) and
 * quote_total. Plan .claude/CREATE_BOOKING_WIZARD_PLAN.md. Liwonde TEST hotel only.
 *
 *   php scripts/tests/guest_booking_submit_page.php
 *
 * Every POST runs the real booking.php through scripts/tests/page_harness.php (one transaction, rolled back; the page's
 * commits are savepoints) via scripts/tests/mixed_booking_post_page.php (keep_csrf: this script puts the session token
 * in itself; the per-IP limiter files are cleared). Emails go to scripts/tests/fake-smtp.php on 127.0.0.1, WhatsApp is
 * switched off inside the run: nothing leaves this machine. Bookings counts are compared before and after.
 * Cases: wizard single + mixed save = the same plain POST (rows, amounts, emails, session); quote_total 1.00 off, within
 * 0.005, missing; duplicate client_uuid; a field error; a wrong token; the plain POST unchanged.
 * Refuses unless this checkout is Liwonde, its database is not in .claude/LIVE_TARGETS and it is the test platform.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (stripos($root, 'Liwonde') === false) { exit("REFUSED: not the Liwonde checkout\n"); }
require $root . '/config/database.php';
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = array_filter(array_map('trim', @file($root . '/.claude/LIVE_TARGETS') ?: []), function ($l) { return $l !== '' && $l[0] !== '#'; });
if ($dbName === '' || in_array($dbName, $live, true)) { exit("REFUSED: database is a LIVE target\n"); }
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false || trim((string)getSetting('site_is_test_platform', '0')) !== '1') {
    exit("REFUSED: not the Liwonde test platform\n");
}
$TOL = defined('BALANCE_TOLERANCE') ? (float)BALANCE_TOLERANCE : 0.01;

$countBookings = function () use (&$pdo): int {
    try { return (int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(); }
    catch (PDOException $e) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        return (int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    }
};
$before = $countBookings();

// ── Room types and free dates ──────────────────────────────────────────────────
$rooms = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1) AS n_combos
    FROM rooms r WHERE r.is_active = 1 ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC);
$pick = function (callable $f) use ($rooms) { foreach ($rooms as $r) { if ($f($r)) { return $r; } } return null; };
$noTier = $pick(fn($r) => !$r['n_combos'] && (int)$r['double_occupancy_enabled'] && (int)$r['max_guests'] >= 2);
$kids = $pick(fn($r) => !$r['n_combos'] && (int)$r['children_allowed'] && (int)$r['single_occupancy_enabled'] && (int)$r['max_guests'] >= 2 && (int)$r['rooms_available'] >= 2 && (!$noTier || (int)$r['id'] !== (int)$noTier['id']));
if (!$noTier || !$kids) { exit("cannot find the room types the cases need\n"); }
$free = function (int $roomId, string $from, int $nights): ?array {
    $d = new DateTime($from);
    for ($i = 0; $i < 60; $i++, $d->modify('+1 day')) {
        $ci = $d->format('Y-m-d');
        $co = (clone $d)->modify("+{$nights} day")->format('Y-m-d');
        $a = checkRoomAvailability($roomId, $ci, $co);
        if (!empty($a['available'])) { return [$ci, $co]; }
    }
    return null;
};
$far = (new DateTime('today'))->modify('+75 day')->format('Y-m-d');
$K = (int)$kids['id'];
$dSingle = $free($K, $far, 2);
$dMixed = null;
for ($probe = new DateTime($far), $i = 0; $i < 60 && !$dMixed; $i++, $probe->modify('+1 day')) {
    $a = $free((int)$noTier['id'], $probe->format('Y-m-d'), 2);
    if ($a && $a[0] === $probe->format('Y-m-d') && $free($K, $a[0], 2) === $a) { $dMixed = $a; }
}
if (!$dSingle || !$dMixed) { exit("no free dates found\n"); }

// ── Temp folder, fake SMTP, harness ───────────────────────────────────────────
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gbsp_' . bin2hex(random_bytes(4));
@mkdir($tmp);
register_shutdown_function(function () use ($tmp) { foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); } @rmdir($tmp); });
$runLimited = function (array $cmd, int $seconds): array {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['out' => '', 'timed_out' => false]; }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $t0 = microtime(true); $timedOut = false;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) { break; }
        if (microtime(true) - $t0 > $seconds) {
            $timedOut = true;
            if (PHP_OS_FAMILY === 'Windows') { exec('taskkill /F /T /PID ' . (int)$st['pid'] . ' 2>NUL'); } else { proc_terminate($p, 9); }
            break;
        }
        usleep(100000);
    }
    $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return ['out' => $out, 'timed_out' => $timedOut];
};
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 45000);
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
    if ($probe) { fclose($probe); $port = $cand; }
}
$spool = "$tmp/smtp.jsonl";
$smtp = $port ? proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', "$tmp/smtp.out", 'a'], 2 => ['file', "$tmp/smtp.err", 'a']], $pipes) : null;
register_shutdown_function(function () use (&$smtp) { if (is_resource($smtp)) { @proc_terminate($smtp); @proc_close($smtp); } });
for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
if (!is_resource($smtp) || !is_file($spool . '.ready')) { exit("capture SMTP server did not start: nothing run\n"); }
$mails = function () use ($spool): array {
    return is_file($spool) ? array_values(array_map(fn($l) => json_decode($l, true) ?: [], array_filter(file($spool, FILE_IGNORE_NEW_LINES) ?: [], 'strlen'))) : [];
};
$resetLimit = function (): void {
    foreach (['booking_form', 'booking_quote'] as $action) {
        foreach (['127.0.0.1', '::1'] as $ip) {
            $key = 'iprl_' . $action . '_' . substr(hash('sha256', $ip . '|' . $action), 0, 24);
            if (function_exists('deleteCache')) { @deleteCache($key); }
            $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $key . '.json';
            if (is_file($f)) { @unlink($f); }
        }
    }
};
$noWa = "INSERT INTO site_settings (setting_key, setting_value) VALUES ('whatsapp_enabled','0'),('whatsapp_notifications_enabled','0') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
$sessKeys = ['_csrf_pub_booking', 'booking_attempts', 'booking_success', '_confirm_refs', 'booking_form_uuid'];
$uuid = fn() => sprintf('%s-%s-4%s-a%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
$harnessProblems = [];

// One booking.php POST. $sessToken = the token in the session before the page runs (null = none).
$post = function (array $p, ?string $sessToken, array $setup, string $label) use ($root, $tmp, $runLimited, $port, $mails, $resetLimit, $noWa, $sessKeys, $pdo, &$harnessProblems): array {
    $resetLimit();
    $mb = count($mails());
    $qe = $pdo->quote((string)($p['guest_email'] ?? ''));
    $cfg = ['post' => $p, 'setup' => array_merge([$noWa], $setup), 'fake_smtp' => $port, 'keep_csrf' => true,
        'session' => $sessToken === null ? [] : ['_csrf_pub_booking' => $sessToken], 'session_keys' => $sessKeys,
        'verify' => ["SELECT * FROM bookings WHERE guest_email = $qe ORDER BY id",
            "SELECT bp.* FROM booking_packages bp JOIN bookings b ON b.id = bp.booking_id WHERE b.guest_email = $qe ORDER BY bp.id"]];
    file_put_contents("$tmp/cfg.b64", base64_encode(json_encode($cfg)));
    $run = $runLimited([PHP_BINARY, $root . '/scripts/tests/page_harness.php', 'scripts/tests/mixed_booking_post_page.php', '@' . "$tmp/cfg.b64"], 90);
    $r = ['raw' => substr($run['out'], -800), 'timed_out' => $run['timed_out']];
    if (preg_match('/@@RESULT@@(.*)$/m', $run['out'], $m) && is_array($j = json_decode($m[1], true))) { $r = $j + $r; }
    if (!empty($r['timed_out']) || strpos($run['out'], 'HARNESS LEAK') !== false || !empty($r['leak']) || !empty($r['marker_lost']) || empty($r['rolled_back'])) {
        $harnessProblems[] = $label;
    }
    $r['mails'] = array_slice($mails(), $mb);
    $r['rows'] = is_array($r['verify'][0] ?? null) ? $r['verify'][0] : [];
    $r['pkgs'] = is_array($r['verify'][1] ?? null) ? $r['verify'][1] : [];
    return $r;
};
$sumTotal = fn(array $rows) => round(array_sum(array_map(fn($x) => (float)$x['total_with_vat'], $rows)), 2);
// The rows without what differs by nature between two saves (ids, references, booking id, timestamps).
$normRows = function (array $rows): array {
    return array_map(function ($row) {
        foreach (array_keys($row) as $k) {
            if (in_array($k, ['id', 'booking_reference', 'client_uuid', 'primary_booking_id', 'booking_id'], true) || substr($k, -3) === '_at') { unset($row[$k]); }
        }
        return $row;
    }, $rows);
};
// The emails with the save's own references replaced (each save draws its own reference) and clock times blanked.
$normMails = function (array $mails, array $rows): array {
    $refs = array_column($rows, 'booking_reference');
    usort($refs, fn($a, $b) => strlen($b) - strlen($a));
    $ids = array_map(fn($id) => 'id=' . $id, array_column($rows, 'id')); // admin links carry the row id
    return array_map(function ($m) use ($refs, $ids) {
        $f = fn($s) => preg_replace('/\b\d{1,2}:\d{2}(:\d{2})?\b/', 'HH:MM', str_replace($ids, 'id=ID', str_replace($refs, 'REF', (string)$s)));
        return ['to' => $m['to'] ?? [], 'subject' => $f($m['subject'] ?? ''), 'text' => $f($m['text'] ?? ''), 'attachments' => $m['attachments'] ?? []];
    }, $mails);
};
$diffKeys = function (array $a, array $b): array {
    $out = [];
    foreach ($a as $i => $row) {
        foreach ($row as $k => $v) { if (!array_key_exists($k, $b[$i] ?? []) || (string)$b[$i][$k] !== (string)$v) { $out[] = "row$i.$k: " . json_encode($v) . ' vs ' . json_encode($b[$i][$k] ?? null); } }
    }
    if (count($a) !== count($b)) { $out[] = 'row count ' . count($a) . ' vs ' . count($b); }
    return $out;
};

// True when this session may open the booking's confirmation page (pub_confirm_remember ran for it).
$confirmed = function ($refs, string $ref): bool {
    foreach ((array)$refs as $e) { if (is_array($e) && ($e['k'] ?? '') === 'booking' && (string)($e['r'] ?? '') === $ref) { return true; } }
    return false;
};
$results = [];
$check = function (string $label, bool $ok, string $detail = '') use (&$results) { $results[] = [$label, $ok, $detail]; };

// The quote the guest would have seen on Review: the page's own price check at step 5 (writes nothing).
$quoteFor = function (array $p, array $setup) use ($post): ?float {
    // (the price check needs no quote_total and never consumes the token)
    $tok = 'gbsp' . bin2hex(random_bytes(16));
    $r = $post($p + ['csrf_token' => $tok, 'response' => 'wizard', 'dry_run' => '1', 'dry_run_upto' => '5'], $tok, $setup, 'quote');
    return isset($r['json']['ok'], $r['json']['quote']['totals']['total_with_vat']) && $r['json']['ok'] ? (float)$r['json']['quote']['totals']['total_with_vat'] : null;
};

// ── Wizard save = plain save (single room type, then two room types) ──────────────
$bases = [
    'single' => [['room_id' => (string)$K, 'check_in_date' => $dSingle[0], 'check_out_date' => $dSingle[1], 'number_of_guests' => '2',
        'child_guests' => '0', 'booking_type' => 'standard', 'guest_country' => 'Malawi', 'guest_address' => '', 'special_requests' => 'zz G-C test'], []],
    'mixed' => [['room_id' => (string)$noTier['id'], 'check_in_date' => $dMixed[0], 'check_out_date' => $dMixed[1], 'number_of_guests' => '3',
        'child_guests' => '1', 'booking_type' => 'standard', 'guest_country' => '', 'guest_address' => '', 'special_requests' => '',
        'extra_room_types' => [(string)$K]], ["UPDATE rooms SET children_allowed = 0 WHERE id = " . (int)$noTier['id']]],
];
$quotes = [];
foreach ($bases as $key => [$b, $setup]) {
    $email = 'zz.gc.' . $key . '.' . bin2hex(random_bytes(3)) . '@example.invalid';
    $p = $b + ['guest_name' => 'Zz Gc Submit', 'guest_email' => $email, 'guest_phone' => '+265999000111'];
    $q = $quoteFor($p + ['client_uuid' => $uuid()], $setup);
    $quotes[$key] = $q;
    if ($q === null) { $check("$key: Review quote", false, 'the price check gave no quote'); continue; }

    $tokA = 'gbsp' . bin2hex(random_bytes(16));
    $plain = $post($p + ['csrf_token' => $tokA, 'client_uuid' => $uuid()], $tokA, $setup, "$key plain");
    $tokB = 'gbsp' . bin2hex(random_bytes(16));
    $wiz = $post($p + ['csrf_token' => $tokB, 'client_uuid' => $uuid(), 'response' => 'wizard', 'quote_total' => number_format($q, 2, '.', '')], $tokB, $setup, "$key wizard");

    $plainOk = !isset($plain['json']) && count($plain['rows']) >= 1 && !empty($plain['session']['booking_success']) && count($plain['mails']) === 2
        && $plain['session']['_csrf_pub_booking'] === null && $plain['session']['booking_form_uuid'] === null;
    $check("$key: plain POST saves as before (no JSON, booking_success, 2 emails, token used up)", $plainOk,
        'rows ' . count($plain['rows']) . ', emails ' . count($plain['mails']) . ', json ' . (isset($plain['json']) ? 'YES' : 'no'));

    $lead = (string)($wiz['rows'][0]['booking_reference'] ?? '');
    $j = $wiz['json'] ?? null;
    $wizOk = is_array($j) && ($j['ok'] ?? null) === true && ($j['redirect'] ?? '') === 'booking-confirmation.php?ref=' . urlencode($lead) && $lead !== ''
        && is_string($j['csrf_token'] ?? null) && $j['csrf_token'] !== '';
    $check("$key: wizard POST answers ok + redirect to its booking", $wizOk, json_encode(is_array($j) ? array_intersect_key($j, array_flip(['ok', 'step', 'message', 'redirect'])) : substr(strip_tags((string)$wiz['raw']), -300)));

    $rowDiff = $diffKeys($normRows($plain['rows']), $normRows($wiz['rows']));
    $pkgDiff = $diffKeys($normRows($plain['pkgs']), $normRows($wiz['pkgs']));
    $check("$key: stored rows identical to the plain POST", !$rowDiff && !$pkgDiff && count($wiz['rows']) === count($plain['rows']),
        count($wiz['rows']) . ' row(s), total ' . $sumTotal($wiz['rows']) . ' vs ' . $sumTotal($plain['rows']) . ($rowDiff || $pkgDiff ? ' | ' . implode('; ', array_slice(array_merge($rowDiff, $pkgDiff), 0, 6)) : ''));
    $check("$key: stored total = the Review quote", abs($sumTotal($wiz['rows']) - $q) <= $TOL, $sumTotal($wiz['rows']) . ' vs quote ' . $q);

    $mp = $normMails($plain['mails'], $plain['rows']);
    $mw = $normMails($wiz['mails'], $wiz['rows']);
    $mailDiff = '';
    foreach ($mw as $mi => $m) {
        foreach (['to', 'subject', 'text', 'attachments'] as $mk) {
            if (($mp[$mi][$mk] ?? null) == $m[$mk]) { continue; }
            $x = is_string($m[$mk]) ? $m[$mk] : json_encode($m[$mk]);
            $y = is_string($mp[$mi][$mk] ?? null) ? $mp[$mi][$mk] : json_encode($mp[$mi][$mk] ?? null);
            for ($at = 0; $at < min(strlen($x), strlen($y)) && $x[$at] === $y[$at]; $at++);
            $mailDiff .= " | mail$mi.$mk at $at: wizard '" . substr($x, max(0, $at - 40), 100) . "' plain '" . substr($y, max(0, $at - 40), 100) . "'";
        }
    }
    $check("$key: emails identical to the plain POST (recipients, subjects, text)", count($mw) === 2 && $mp == $mw,
        count($mw) . ' email(s): ' . implode(' | ', array_map(fn($m) => $m['subject'], $mw)) . $mailDiff);

    $bsP = $plain['session']['booking_success'] ?? [];
    $bsW = $wiz['session']['booking_success'] ?? [];
    unset($bsP['reference'], $bsP['all_references'], $bsW['reference'], $bsW['all_references']);
    $sessOk = $bsW && $bsW == $bsP && ($wiz['session']['booking_success']['reference'] ?? null) === $lead
        && $confirmed($wiz['session']['_confirm_refs'] ?? null, $lead) && $wiz['session']['booking_form_uuid'] === null;
    $check("$key: session as the plain POST (booking_success, confirm ref, booking id burnt)", $sessOk,
        'confirm_refs ' . json_encode($wiz['session']['_confirm_refs'] ?? null));
}

// ── Refusals and the duplicate ────────────────────────────────────────────────
$b = $bases['single'][0];
$q = $quotes['single'];
$fresh = function () use ($b, $uuid): array {
    return $b + ['guest_name' => 'Zz Gc Submit', 'guest_email' => 'zz.gc.ref.' . bin2hex(random_bytes(3)) . '@example.invalid',
        'guest_phone' => '+265999000111', 'client_uuid' => $uuid(), 'response' => 'wizard'];
};
$wizPost = function (array $p, ?string $sessToken = null, array $setup = []) use ($post): array {
    $tok = 'gbsp' . bin2hex(random_bytes(16));
    $p += ['csrf_token' => $tok];
    return $post($p, $sessToken ?? $p['csrf_token'], $setup, 'refusal');
};
$nothing = fn(array $r) => !$r['rows'] && !$r['mails'] && empty($r['session']['booking_success']);
$brief = fn(array $r) => is_array($r['json'] ?? null) ? json_encode(array_intersect_key($r['json'], array_flip(['ok', 'step', 'field', 'message', 'duplicate', 'redirect', 'price_changed'])))
    . (isset($r['json']['quote']['totals']['total_with_vat']) ? ' quote ' . $r['json']['quote']['totals']['total_with_vat'] : '') : 'no JSON: ' . substr(strip_tags((string)$r['raw']), -300);

if ($q !== null) {
    $r = $wizPost($fresh() + ['quote_total' => number_format($q + 1, 2, '.', '')]);
    $j = $r['json'] ?? [];
    $check('quote_total 1.00 off: step 5, new quote, nothing written', ($j['ok'] ?? null) === false && ($j['step'] ?? null) === 5
        && abs((float)($j['quote']['totals']['total_with_vat'] ?? -1) - $q) <= $TOL && $nothing($r) && is_string($j['csrf_token'] ?? null) && $j['csrf_token'] !== '', $brief($r));

    $r = $wizPost($fresh() + ['quote_total' => number_format($q + 0.004, 3, '.', '')]);
    $check('quote_total within 0.005: saved, stored total = quote', ($r['json']['ok'] ?? null) === true && count($r['rows']) >= 1
        && abs($sumTotal($r['rows']) - $q) <= $TOL && count($r['mails']) === 2, $brief($r) . ' stored ' . $sumTotal($r['rows']));

    $r = $wizPost($fresh());
    $check('quote_total missing: step 5, nothing written', ($r['json']['ok'] ?? null) === false && ($r['json']['step'] ?? null) === 5 && $nothing($r), $brief($r));

    $r = $wizPost($fresh() + ['quote_total' => 'abc']);
    $check('quote_total not a number: step 5, nothing written', ($r['json']['ok'] ?? null) === false && ($r['json']['step'] ?? null) === 5 && $nothing($r), $brief($r));

    // Duplicate booking id: a booking with this client_uuid exists (seeded inside the rolled-back run).
    $p = $fresh() + ['quote_total' => number_format($q, 2, '.', '')];
    $seedRef = 'ZZGC' . strtoupper(bin2hex(random_bytes(3)));
    $n = (int)(new DateTime($b['check_in_date']))->diff(new DateTime($b['check_out_date']))->days;
    $seed = "INSERT INTO bookings (booking_reference, room_id, guest_name, guest_email, guest_phone, check_in_date, check_out_date, number_of_nights, number_of_guests, total_amount, status, client_uuid)
        VALUES ('$seedRef', $K, 'Zz Gc Seed', 'zz.gc.seed@example.invalid', '+265999000111', " . $pdo->quote($b['check_in_date']) . ", " . $pdo->quote($b['check_out_date']) . ", $n, 1, 1000, 'pending', " . $pdo->quote($p['client_uuid']) . ")";
    $r = $wizPost($p, null, [$seed]);
    $refs = (array)($r['session']['_confirm_refs'] ?? []);
    $remembered = $confirmed($refs, $seedRef);
    $check('duplicate client_uuid: ok + redirect, confirm ref remembered, nothing new', ($r['json']['ok'] ?? null) === true && !empty($r['json']['duplicate'])
        && ($r['json']['redirect'] ?? '') === 'booking-confirmation.php?ref=' . $seedRef && $remembered && $nothing($r), $brief($r) . ' confirm_refs ' . json_encode($refs));

    // A field error: its step and field, a fresh token (the posted one is used up).
    $p = $fresh() + ['quote_total' => number_format($q, 2, '.', ''), 'csrf_token' => 'gbsp' . bin2hex(random_bytes(16))];
    $p['guest_email'] = 'not-an-email';
    $r = $wizPost($p);
    $j = $r['json'] ?? [];
    $check('field error: step 4 guest_email, fresh token = session token, nothing written', ($j['ok'] ?? null) === false && ($j['step'] ?? null) === 4
        && ($j['field'] ?? null) === 'guest_email' && is_string($j['csrf_token'] ?? null) && $j['csrf_token'] !== '' && $j['csrf_token'] !== $p['csrf_token']
        && $j['csrf_token'] === ($r['session']['_csrf_pub_booking'] ?? null) && !$r['rows'] && !$r['mails'], $brief($r));

    // Wrong token.
    $p = $fresh() + ['quote_total' => number_format($q, 2, '.', '')];
    $r = $wizPost($p + ['csrf_token' => 'gbspwrong' . bin2hex(random_bytes(8))], 'gbsp' . bin2hex(random_bytes(16)));
    $check('wrong token: step 0, nothing written', ($r['json']['ok'] ?? null) === false && ($r['json']['step'] ?? null) === 0 && $nothing($r), $brief($r));

    // Two room types, quote 1.00 off: the same gate before rh_mixed_create() writes anything.
    if ($quotes['mixed'] !== null) {
        [$mb, $mSetup] = $bases['mixed'];
        $p = $mb + ['guest_name' => 'Zz Gc Submit', 'guest_email' => 'zz.gc.mxref.' . bin2hex(random_bytes(3)) . '@example.invalid', 'guest_phone' => '+265999000111',
            'client_uuid' => $uuid(), 'response' => 'wizard', 'quote_total' => number_format($quotes['mixed'] - 1, 2, '.', '')];
        $r = $wizPost($p, null, $mSetup);
        $j = $r['json'] ?? [];
        $check('two room types, quote_total 1.00 off: step 5, new quote, nothing written', ($j['ok'] ?? null) === false && ($j['step'] ?? null) === 5
            && abs((float)($j['quote']['totals']['total_with_vat'] ?? -1) - $quotes['mixed']) <= $TOL && $nothing($r), $brief($r));
    }

    // Plain POST carrying a stray quote_total (no response=wizard): still the normal save, the gate does not apply.
    $p = $fresh();
    unset($p['response']);
    $r = $wizPost($p + ['quote_total' => '1.00']);
    $check('plain POST with a wrong quote_total: normal save (no JSON, booking_success, 2 emails)', !isset($r['json']) && count($r['rows']) >= 1
        && !empty($r['session']['booking_success']) && count($r['mails']) === 2, 'rows ' . count($r['rows']) . ', emails ' . count($r['mails']));
}
if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
$resetLimit();

// ── Report ────────────────────────────────────────────────────────────────────
echo "single: {$kids['name']} {$dSingle[0]}..{$dSingle[1]} quote " . json_encode($quotes['single'] ?? null) . "\n";
echo "mixed:  {$noTier['name']} (adults only, seeded) + {$kids['name']} {$dMixed[0]}..{$dMixed[1]} quote " . json_encode($quotes['mixed'] ?? null) . "\n\n";
$fail = 0;
foreach ($results as [$label, $ok, $detail]) {
    $fail += $ok ? 0 : 1;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "\n     $detail" : '') . "\n";
}
$after = $countBookings();
echo "\nbookings before $before, after $after: " . ($after === $before ? 'unchanged' : 'CHANGED') . "\n";
if ($harnessProblems) { echo 'HARNESS PROBLEM: ' . implode(', ', $harnessProblems) . "\n"; $fail++; }
if ($after !== $before) { $fail++; }
echo 'emails captured locally: ' . count($mails()) . " (none sent out)\n";
$failedChecks = count(array_filter($results, fn($x) => !$x[1]));
echo 'RESULT: ' . (count($results) - $failedChecks) . ' passed, ' . $failedChecks . ' failed' . ($fail > $failedChecks ? ' (+ harness/count problem)' : '') . "\n";
exit($fail ? 1 : 0);
