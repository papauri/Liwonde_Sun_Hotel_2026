<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
/**
 * Guest booking page (booking.php + js/booking.js): the total the guest SEES before submit vs what the save STORES.
 * Plan .claude/CREATE_BOOKING_WIZARD_PLAN.md phase G0. Comparison on the Liwonde TEST hotel: every save is rolled back;
 * only the page visits' own site_visitors / session_logs rows stay (as for any visit).
 *
 *   php scripts/tests/guest_booking_total_compare.php            every case
 *   php scripts/tests/guest_booking_total_compare.php --only=single,joined
 *
 * Shown: scripts/tests/guest_booking_total_compare.mjs opens booking.php in its own headless Chrome (own php -S port,
 *   own CDP port, temp profile), lets the page price the stay itself and reads #summaryTotal plus the form fields it
 *   would post. Every POST to booking.php is failed in the browser (never submitted).
 * Stored: the same fields are posted to the real booking.php through scripts/tests/page_harness.php (one transaction,
 *   rolled back; page commits are savepoints) via scripts/tests/mixed_booking_post_page.php (seeds the public booking
 *   CSRF token, clears the per-IP limiter file). Each case has a fresh client_uuid and its own @example.invalid email,
 *   so no existing-booking redirect or duplicate check fires. The rows the save wrote are read before the rollback.
 * Emails go to scripts/tests/fake-smtp.php on 127.0.0.1 (harness fake_smtp), WhatsApp is switched off inside the run;
 *   nothing leaves this machine. Bookings / booking_packages / room_packages are counted before and after.
 * Cases the hotel's own data cannot show (no active package, no child charge on any room type) are seeded INSIDE the
 *   rolled-back save; the browser gets the same package added to the page's own check-availability answer, and the
 *   seeded child charge is quoted by check-availability.php run through the harness under the same seed (that reply
 *   is exactly what the page shows when no package is ticked). Those rows are labelled.
 * Refuses unless this checkout is Liwonde, its database is not in .claude/LIVE_TARGETS and it is the test platform.
 */
$root = dirname(__DIR__, 2);
chdir($root);
if (stripos($root, 'Liwonde') === false) { exit("REFUSED: not the Liwonde checkout\n"); }
require $root . '/config/database.php';
require_once $root . '/includes/pricing.php';
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = array_filter(array_map('trim', @file($root . '/.claude/LIVE_TARGETS') ?: []), function ($l) { return $l !== '' && $l[0] !== '#'; });
if ($dbName === '' || in_array($dbName, $live, true)) { exit("REFUSED: database is a LIVE target\n"); }
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false || trim((string)getSetting('site_is_test_platform', '0')) !== '1') {
    exit("REFUSED: not the Liwonde test platform\n");
}
$only = null;
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--only=(.+)$/', $a, $m)) { $only = array_map('trim', explode(',', $m[1])); } }
const GBTC_TOL = 0.01; // BALANCE_TOLERANCE

$counts = function () use ($pdo): array {
    $o = [];
    foreach (['bookings', 'booking_packages', 'room_packages'] as $t) { $o[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
    return $o;
};
// Other connections of this DB user (a harness left open would show here); the site's own traffic can also appear.
$otherConns = function () use ($pdo): ?int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER = SUBSTRING_INDEX(CURRENT_USER(), '@', 1) AND ID <> CONNECTION_ID()")->fetchColumn(); }
    catch (Throwable $e) { return null; }
};
$connsBefore = $otherConns();
$before = $counts();
echo "vat_pricing_mode=" . getSetting('vat_pricing_mode', 'off') . " vat_rate=" . getSetting('vat_rate', '0') . " levy=" . getSetting('tourism_levy_enabled', '0') . '/' . getSetting('tourism_levy_percent', '0') . "%\n";
echo 'before: ' . json_encode($before) . "\n";

// ── Pick real active room types, plan and dates ───────────────────────────────
$rooms = $pdo->query("SELECT r.*, (SELECT COUNT(*) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1) AS n_combos
    FROM rooms r WHERE r.is_active = 1 ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC);
$pick = function (callable $f) use ($rooms) { foreach ($rooms as $r) { if ($f($r)) { return $r; } } return null; };
$noTier = $pick(fn($r) => !$r['n_combos'] && (int)$r['double_occupancy_enabled'] && ($r['price_double_occupancy'] === null || (float)$r['price_double_occupancy'] <= 0) && (int)$r['max_guests'] >= 2);
$kids = $pick(fn($r) => !$r['n_combos'] && (int)$r['children_allowed'] && (int)$r['single_occupancy_enabled'] && (int)$r['max_guests'] >= 2 && (int)$r['rooms_available'] >= 2);
$joined = $pick(fn($r) => (int)$r['n_combos'] > 0);
$plan = $pdo->query("SELECT * FROM rate_plans WHERE is_active = 1 AND end_date >= CURDATE() + INTERVAL 2 DAY ORDER BY start_date LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$noTier || !$kids || !$joined) { exit("cannot find the room types the cases need\n"); }

$free = function (int $roomId, string $from, int $nights, int $rooms = 1, bool $joinedType = false, ?string $until = null): ?array {
    $d = new DateTime($from);
    for ($i = 0; $i < 60; $i++, $d->modify('+1 day')) {
        $ci = $d->format('Y-m-d');
        $co = (clone $d)->modify("+{$nights} day")->format('Y-m-d');
        if ($until !== null && $co > $until) { return null; }
        $a = checkRoomAvailability($roomId, $ci, $co);
        if (empty($a['available'])) { continue; }
        if (isset($a['remaining_rooms']) && (int)$a['remaining_rooms'] < $rooms) { continue; }
        if ($joinedType && count(getAvailableRoomCombinations($roomId, $ci, $co)) < $rooms) { continue; }
        return [$ci, $co];
    }
    return null;
};
$far = (new DateTime('today'))->modify('+70 day')->format('Y-m-d');
$dNoTier = $free((int)$noTier['id'], $far, 2);
$dKids = $free((int)$kids['id'], $far, 2, 2);
$dJoined = $free((int)$joined['id'], $far, 2, 1, true);
$dPlan = null;
if ($plan) {
    $start = max($plan['start_date'], (new DateTime('tomorrow'))->format('Y-m-d'));
    $until = (new DateTime($plan['end_date']))->modify('+1 day')->format('Y-m-d');
    $dPlan = $free((int)$kids['id'], $start, 2, 1, false, $until);
}
if (!$dNoTier || !$dKids || !$dJoined) { exit("no free dates found for a case\n"); }

$K = (int)$kids['id'];
$pkgInject = function (string $type, float $amount): array {
    return ['id' => 990000 + crc32($type) % 9999, 'name' => 'ZZ G0 package ' . $type, 'price_type' => $type, 'price_amount' => $amount];
};
$cases = [
    ['key' => 'double_no_tier', 'label' => "double, no tier price ({$noTier['name']})", 'room_id' => (int)$noTier['id'], 'dates' => $dNoTier, 'guests' => 2, 'children' => 0],
    ['key' => 'single', 'label' => "single ({$kids['name']})", 'room_id' => $K, 'dates' => $dKids, 'guests' => 1, 'children' => 0],
    ['key' => 'child', 'label' => "child, hotel's own rule ({$kids['name']})", 'room_id' => $K, 'dates' => $dKids, 'guests' => 2, 'children' => 1],
    ['key' => 'child_seeded', 'label' => 'child, seeded 50% charge [server quote]', 'room_id' => $K, 'dates' => $dKids, 'guests' => 2, 'children' => 1,
        'seed' => ["UPDATE rooms SET child_charge_mode = 'percent', child_price_multiplier = 50, child_charge_amount = NULL, free_children = 0 WHERE id = {$K}"], 'server_quote' => true],
    ['key' => 'rate_plan', 'label' => 'rate plan ' . ($plan ? '"' . $plan['name'] . '"' : '(none active)'), 'room_id' => $K, 'dates' => $dPlan, 'guests' => 2, 'children' => 0],
    ['key' => 'two_rooms', 'label' => "two rooms, 3 guests ({$kids['name']})", 'room_id' => $K, 'dates' => $dKids, 'guests' => 3, 'children' => 0],
    ['key' => 'joined', 'label' => "joined room ({$joined['name']})", 'room_id' => (int)$joined['id'], 'dates' => $dJoined, 'guests' => min(4, (int)$joined['max_guests']), 'children' => 0],
    ['key' => 'tentative', 'label' => 'tentative', 'room_id' => $K, 'dates' => $dKids, 'guests' => 2, 'children' => 0, 'booking_type' => 'tentative'],
];
foreach (['per_night' => 30000, 'per_stay' => 45000, 'per_person_per_night' => 12500] as $type => $amt) {
    $cases[] = ['key' => 'package_' . $type, 'label' => "package $type [seeded]", 'room_id' => $K, 'dates' => $dKids, 'guests' => 2, 'children' => 0,
        'package' => $pkgInject($type, $amt)];
}
if ($only) { $cases = array_values(array_filter($cases, fn($c) => in_array($c['key'], $only, true))); }

// ── Shown: the browser helper ────────────────────────────────────────────────
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gbtc_' . bin2hex(random_bytes(4));
@mkdir($tmp);
register_shutdown_function(function () use ($tmp) { foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); } @rmdir($tmp); });
$browserCases = [];
foreach ($cases as $c) {
    if (!empty($c['server_quote']) || empty($c['dates'])) { continue; }
    $browserCases[] = ['key' => $c['key'], 'room_id' => $c['room_id'], 'check_in' => $c['dates'][0], 'check_out' => $c['dates'][1], 'guests' => $c['guests'],
        'children' => $c['children'], 'booking_type' => $c['booking_type'] ?? 'standard', 'inject_package' => $c['package'] ?? null];
}
file_put_contents("$tmp/cases.json", json_encode($browserCases));
echo "browser: " . count($browserCases) . " case(s)...\n";
// Runs a child with a hard time limit; on timeout only that child's own process tree is stopped.
$runLimited = function (array $cmd, int $seconds, ?string $echoTo = null, ?array $env = null): array {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) { return ['out' => '', 'timed_out' => false]; }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $t0 = microtime(true); $timedOut = false;
    while (true) {
        $chunk = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        if ($chunk !== '') { $out .= $chunk; if ($echoTo === 'stdout') { echo $chunk; } }
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
// Two free ports for the helper's own php -S and Chrome (the helper also refuses if either answers before it starts).
$freePort = function (array $not = []): int {
    for ($try = 0; $try < 40; $try++) {
        $cand = random_int(20000, 45000);
        if (in_array($cand, $not, true)) { continue; }
        $probe = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
        if ($probe) { fclose($probe); return $cand; }
    }
    return 0;
};
$httpPort = $freePort();
$cdpPort = $freePort([$httpPort]);
if (!$httpPort || !$cdpPort) { exit("no free ports for the browser helper: nothing run\n"); }
$nodeEnv = array_merge(getenv(), ['GBTC_HTTP_PORT' => (string)$httpPort, 'GBTC_CDP_PORT' => (string)$cdpPort]);
$nodeRun = $runLimited(['node', $root . '/scripts/tests/guest_booking_total_compare.mjs', "$tmp/cases.json", "$tmp/shown.json"], 300, 'stdout', $nodeEnv);
if ($nodeRun['timed_out']) { echo "browser helper hit the 300 s limit and was stopped: unmeasured cases are marked\n"; }
$shownAll = json_decode((string)@file_get_contents("$tmp/shown.json"), true) ?: ['results' => []];
$shown = [];
foreach ($shownAll['results'] as $r) { $shown[$r['key']] = $r; }

// ── Stored: the real save, rolled back ────────────────────────────────────────
$port = 0;
for ($try = 0; $try < 20 && !$port; $try++) {
    $cand = random_int(20000, 45000);
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $cand, $e1, $e2);
    if ($probe) { fclose($probe); $port = $cand; }
}
$spool = "$tmp/smtp.jsonl";
$smtp = $port ? proc_open([PHP_BINARY, $root . '/scripts/tests/fake-smtp.php', (string)$port, $spool], [0 => ['pipe', 'r'], 1 => ['file', "$tmp/smtp.out", 'a'], 2 => ['file', "$tmp/smtp.err", 'a']], $pipes) : null;
// Stopped on every exit path (including a fatal error in the loop below).
register_shutdown_function(function () use (&$smtp) { if (is_resource($smtp)) { @proc_terminate($smtp); @proc_close($smtp); } });
for ($i = 0; $i < 50 && !is_file($spool . '.ready'); $i++) { usleep(100000); }
if (!is_resource($smtp) || !is_file($spool . '.ready')) { exit("capture SMTP server did not start: nothing run\n"); }
$mailCount = function () use ($spool): int { return is_file($spool) ? count(array_filter(file($spool, FILE_IGNORE_NEW_LINES) ?: [], 'strlen')) : 0; };
$resetLimit = function (): void {
    $key = 'iprl_booking_form_' . substr(hash('sha256', '127.0.0.1|booking_form'), 0, 24);
    if (function_exists('deleteCache')) { @deleteCache($key); }
    $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $key . '.json';
    if (is_file($f)) { @unlink($f); }
};

$harness = function (string $page, array $cfg) use ($root, $tmp, $runLimited): array {
    $cfgFile = "$tmp/cfg.b64";
    file_put_contents($cfgFile, base64_encode(json_encode($cfg)));
    // 90 s per save. A killed harness never commits: its connection closes and MySQL rolls the open transaction back.
    $run = $runLimited([PHP_BINARY, $root . '/scripts/tests/page_harness.php', $page, '@' . $cfgFile], 90);
    $out = $run['out'];
    $res = ['raw' => substr($out, -600), 'timed_out' => $run['timed_out']];
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $m) && is_array($j = json_decode($m[1], true))) { $res = $j + $res; }
    if (strpos($out, 'HARNESS LEAK') !== false) { $res['leak'] = true; }
    return $res;
};
$noWa = "INSERT INTO site_settings (setting_key, setting_value) VALUES ('whatsapp_enabled','0'),('whatsapp_notifications_enabled','0') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";

$rowsOut = [];
$harnessProblems = [];
foreach ($cases as $c) {
    $line = ['case' => $c['label'], 'shown' => null, 'stored' => null, 'match' => 'n/a', 'note' => ''];
    if (empty($c['dates'])) { $line['note'] = 'no dates (no active rate plan with free nights)'; $rowsOut[] = $line; continue; }
    [$ci, $co] = $c['dates'];
    $line['dates'] = "$ci..$co";
    $setup = array_merge([$noWa], $c['seed'] ?? []);
    $vars = [];
    if (!empty($c['package'])) {
        $p = $c['package'];
        $setup[] = "INSERT INTO room_packages (name, slug, short_description, icon, price_type, price_amount, applies_to, room_type_ids, is_featured, is_active, sort_order)
            VALUES (" . $pdo->quote($p['name']) . ", " . $pdo->quote('zz-g0-' . $p['price_type'] . '-' . bin2hex(random_bytes(3))) . ", '', 'fas fa-gift', " . $pdo->quote($p['price_type']) . ", " . (float)$p['price_amount'] . ", 'all', NULL, 0, 1, 0)";
        $setup[] = "SET @gbtc_pk = LAST_INSERT_ID()";
        $vars['pk'] = 'SELECT @gbtc_pk';
    }

    // The POST: the page's own fields (browser), or, for a server-quote case, the same party typed in.
    $post = [];
    if (!empty($c['server_quote'])) {
        $q = $harness('check-availability.php', ['anon' => true, 'method' => 'GET', 'setup' => $setup,
            'get' => ['room_id' => (string)$c['room_id'], 'check_in' => $ci, 'check_out' => $co, 'child_guests' => (string)$c['children'],
                'adult_guests' => (string)($c['guests'] - $c['children']), 'number_of_guests' => (string)$c['guests'], 'rooms_needed' => '1']]);
        $sp = $q['json']['split_pricing'] ?? null;
        $line['shown'] = is_array($sp) && !empty($sp['valid']) ? (float)$sp['total_with_vat'] : null;
        $line['shown_detail'] = is_array($sp) ? ['child_supplement_total' => $sp['child_supplement_total'] ?? null, 'rate' => $sp['room_rate_total_per_night'] ?? null] : ($q['raw'] ?? '');
        if (!empty($q['leak']) || !empty($q['marker_lost']) || empty($q['rolled_back'])) { $harnessProblems[] = $c['key'] . ' (quote)'; }
        $post = ['room_id' => (string)$c['room_id'], 'check_in_date' => $ci, 'check_out_date' => $co, 'number_of_guests' => (string)$c['guests'],
            'child_guests' => (string)$c['children'], 'booking_type' => 'standard', 'guest_country' => '', 'guest_address' => '', 'special_requests' => ''];
    } else {
        $s = $shown[$c['key']] ?? null;
        if (!$s) { $line['note'] = 'could not measure: the browser gave no result (run limit)'; $rowsOut[] = $line; continue; }
        $line['shown'] = $s['shown'];
        $line['shown_text'] = $s['shown_text'];
        $line['shown_detail'] = ['rate' => $s['rate_text'] ?? null, 'occupancy' => $s['occupancy_text'] ?? null, 'child' => $s['child_text'] ?? null,
            'package' => $s['package_text'] ?? null, 'plan' => $s['rate_plan_text'] ?? null, 'split_total' => $s['split_pricing']['total_with_vat'] ?? null];
        if (!empty($s['errors'])) { $line['note'] .= 'browser: ' . implode(' | ', $s['errors']) . ' '; }
        foreach (($s['fields'] ?? []) as [$k, $v]) {
            if (substr($k, -2) === '[]') { $post[substr($k, 0, -2)][] = $v; } else { $post[$k] = $v; }
        }
        if (!empty($c['package'])) {
            if (!in_array((string)$c['package']['id'], array_map('strval', (array)($post['package_ids'] ?? [])), true)) {
                $line['note'] .= 'the page never ticked the package: case not compared'; $rowsOut[] = $line; continue;
            }
            $post['package_ids'] =array_map(fn($v) => (int)$v === (int)$c['package']['id'] ? '{pk}' : $v, (array)($post['package_ids'] ?? []));
        }
    }
    $email = 'zz.g0.' . $c['key'] . '.' . bin2hex(random_bytes(3)) . '@example.invalid';
    $uuid = sprintf('%s-%s-4%s-a%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
    $post = array_merge($post, ['csrf_token' => 'gbtc' . bin2hex(random_bytes(16)), 'client_uuid' => $uuid, 'guest_name' => 'Zz Gzero Compare',
        'guest_email' => $email, 'guest_phone' => '+265999000111']);
    $resetLimit();
    $mailsBefore = $mailCount();
    $qe = $pdo->quote($email);
    $r = $harness('scripts/tests/mixed_booking_post_page.php', ['post' => $post, 'setup' => $setup, 'vars' => $vars, 'fake_smtp' => $port,
        'dump_html' => "$tmp/page.html", 'session_keys' => ['booking_success'],
        'verify' => [
            "SELECT id, booking_reference, room_id, number_of_guests, adult_guests, child_guests, occupancy_type, status, is_tentative, total_amount, total_with_vat, amount_due,
                vat_amount, tourism_levy_amount, child_supplement_total, package_total, rate_plan_id, rate_plan_discount FROM bookings WHERE guest_email = $qe ORDER BY id",
            "SELECT bp.package_name, bp.price_type, bp.price_amount, bp.quantity, bp.total_cost FROM booking_packages bp JOIN bookings b ON b.id = bp.booking_id WHERE b.guest_email = $qe",
        ]]);
    $line['emails_captured'] = $mailCount() - $mailsBefore;
    if (!empty($r['timed_out'])) { $line['note'] .= 'could not measure: the save did not answer within 90 s (harness stopped, nothing committed)'; $harnessProblems[] = $c['key'] . ' (timeout)'; $rowsOut[] = $line; continue; }
    if (!empty($r['leak']) || !empty($r['marker_lost']) || empty($r['rolled_back'])) { $harnessProblems[] = $c['key']; }
    $bk = is_array($r['verify'][0] ?? null) ? $r['verify'][0] : [];
    $bp = is_array($r['verify'][1] ?? null) ? $r['verify'][1] : [];
    if (!$bk) {
        $html = (string)@file_get_contents("$tmp/page.html");
        $msg = '';
        if (preg_match('#<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)</div>#s', $html, $mm)) { $msg = trim(preg_replace('/\s+/', ' ', strip_tags($mm[1]))); }
        $line['note'] .= 'REFUSED by the save: ' . ($msg !== '' ? $msg : substr(strip_tags($r['raw'] ?? ''), -300));
        $rowsOut[] = $line;
        continue;
    }
    $sum = fn(string $col) => round(array_sum(array_map(fn($x) => (float)$x[$col], $bk)), 2);
    $line['stored'] = $sum('total_with_vat');
    $line['stored_detail'] = ['rows' => count($bk), 'total_amount' => $sum('total_amount'), 'amount_due' => $sum('amount_due'), 'vat' => $sum('vat_amount'),
        'levy' => $sum('tourism_levy_amount'), 'child' => $sum('child_supplement_total'), 'package_total' => $sum('package_total'),
        'booking_packages' => round(array_sum(array_map(fn($x) => (float)$x['total_cost'], $bp)), 2), 'status' => implode('/', array_unique(array_column($bk, 'status'))),
        'occupancy' => implode('/', array_column($bk, 'occupancy_type')), 'rate_plan' => implode('/', array_map(fn($x) => (string)$x['rate_plan_id'], $bk)),
        'confirmation_total' => $r['session']['booking_success']['total'] ?? null];
    if (abs($line['stored'] - $line['stored_detail']['amount_due']) > GBTC_TOL) { $line['note'] .= 'amount_due differs from total_with_vat. '; }
    if ($line['shown'] !== null) { $line['match'] = abs($line['shown'] - $line['stored']) <= GBTC_TOL ? 'yes' : 'NO'; }
    $rowsOut[] = $line;
}
if (is_resource($smtp)) { proc_terminate($smtp); proc_close($smtp); }
$resetLimit();

// ── Report ────────────────────────────────────────────────────────────────────
$fmt = fn($v) => $v === null ? '—' : number_format((float)$v, 2);
echo "\n" . str_pad('case', 52) . str_pad('shown', 16) . str_pad('stored', 16) . "match\n";
foreach ($rowsOut as $l) {
    echo str_pad(substr($l['case'], 0, 51), 52) . str_pad($fmt($l['shown']), 16) . str_pad($fmt($l['stored']), 16) . $l['match'] . (isset($l['dates']) ? "   {$l['dates']}" : '') . "\n";
    if (!empty($l['shown_detail'])) { echo "    shown:  " . json_encode($l['shown_detail']) . "\n"; }
    if (!empty($l['stored_detail'])) { echo "    stored: " . json_encode($l['stored_detail']) . "\n"; }
    if (isset($l['emails_captured'])) { echo "    emails captured locally: {$l['emails_captured']}\n"; }
    if ($l['note'] !== '') { echo "    note: {$l['note']}\n"; }
}
$after = $counts();
echo "\nafter:  " . json_encode($after) . "\n";
echo 'rows left: ' . ($after === $before ? 'none (counts unchanged)' : 'COUNTS CHANGED') . "\n";
$connsAfter = $otherConns();
echo 'other DB connections of this user: before ' . ($connsBefore ?? '?') . ', after ' . ($connsAfter ?? '?')
    . (($connsBefore !== null && $connsAfter !== null && $connsAfter > $connsBefore) ? ' (MORE: check for a harness left open)' : ' (no harness connection left open)') . "\n";
echo 'emails: ' . $mailCount() . ' captured by the local capture server, none sent out; browser POSTs blocked: ' . (int)($shownAll['posts_blocked'] ?? 0) . "\n";
if ($harnessProblems) { echo 'HARNESS PROBLEM (leak / not rolled back): ' . implode(', ', $harnessProblems) . "\n"; }
if (!empty($shownAll['php_errors'])) { echo "php -S errors: " . implode(' | ', array_slice($shownAll['php_errors'], 0, 5)) . "\n"; }
$bad = count(array_filter($rowsOut, fn($l) => $l['match'] === 'NO'));
echo "\nmismatches: $bad\n";
exit(($bad || $harnessProblems || $after !== $before) ? 1 : 0);
