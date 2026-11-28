<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Bookings list tabs Current / Online / Expired (owner 2026-11-29). READ-ONLY.
 *   Current = pending, tentative, confirmed, checked-in (not cancelled / expired / checked-out / no-show; deleted never listed)
 *   Online  = rh_bk_source_sql() = 'online' (same rule as ?source=online)
 *   Expired = status 'expired' (the status path, like Cancelled / No-show)
 * Pure checks first (no database); then, at Liwonde only (path guard FIRST, Rosalyn's is live), the badge SUM (the
 * page's stats query shape) must equal the list COUNT (rh_bk_list_scope, also the CSV export's WHERE) and an independent
 * hand-written count, and Online must equal ?source=online.
 * Usage (repo root): php scripts/tests/bookings_tabs_valid_online.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }
function eq($got, $want, string $l): void { check($got === $want, $l, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); }

date_default_timezone_set('Africa/Blantyre');
require_once $root . '/admin/includes/bookings-list-filters.php';

// ---- pure: maps, clauses, order, view kind, calendar note
eq(rh_bk_tab_map()['current'] ?? '', 'current', '?filter=current opens the Current tab');
eq(rh_bk_tab_map()['online'] ?? '', 'online', '?filter=online opens the Online tab');
eq(isset(rh_bk_tab_map()['valid']), false, 'no leftover ?filter=valid');
eq(rh_bk_filters_from_get(['filter' => 'current'])['tab'], 'current', 'filter=current -> tab current');
eq(rh_bk_filters_from_get(['filter' => 'online'])['tab'], 'online', 'filter=online -> tab online');
eq(rh_bk_filters_from_get(['filter_status' => 'expired'])['tab'], 'expired', 'filter_status=expired -> tab expired');
eq(rh_bk_filters_from_get(['status' => 'expired'])['tab'], 'expired', '?status=expired alias -> tab expired');
eq(rh_bk_filters_from_get(['filter' => 'nonsense'])['filter'], '', 'unknown ?filter= ignored');
eq(rh_bk_tab_clauses('current')['clauses'], ["b.status NOT IN ('cancelled', 'expired', 'checked-out', 'no-show')"], 'Current clause');
eq(rh_bk_tab_clauses('online')['clauses'], [rh_bk_source_sql() . " = 'online'"], 'Online clause = ?source=online rule');
eq(rh_bk_tab_clauses('expired'), ['clauses' => [], 'order' => null], 'Expired adds nothing (filter_status does it)');
foreach (['current', 'online'] as $t) {
    check(strpos(implode(' ', rh_bk_tab_clauses($t)['clauses']), '?') === false, "$t clause has no placeholder (badge SUM can reuse it)");
    eq(rh_bk_default_order($t, ''), rh_bk_default_order('', ''), "$t: same order as All");
    eq(rh_bk_view_kind($t, ''), 'upcoming', "$t: upcoming view");
}
eq(rh_bk_default_order('expired', 'expired'), 'b.created_at DESC', 'Expired: newest booked first');
eq(rh_bk_view_kind('expired', 'expired'), 'history', 'Expired: history view');
eq(rh_bk_view_kind('', 'expired'), 'history', 'status=expired: history view');
$cal = static function (array $get): array { $f = rh_bk_filters_from_get($get); return rh_bk_calendar_params($f, (string)$f['tab']); };
eq($cal(['filter' => 'current'])['list_only'], ['status'], 'calendar note: Current names status');
eq($cal(['filter' => 'online'])['list_only'], ['source'], 'calendar note: Online names source');
eq($cal(['filter_status' => 'expired'])['list_only'], ['status'], 'calendar note: Expired names status');
$chips = rh_bk_chips(rh_bk_filters_from_get(['filter_status' => 'expired']));
eq($chips[0]['label'] ?? '', 'Status: Expired', 'Expired chip');
require_once $root . '/admin/includes/bookings-saved-filters.php';
eq(rh_bk_saved_query('filter=current&size=25'), 'filter=current&size=25', 'saved filter keeps Current');
eq(rh_bk_saved_query('filter=online'), 'filter=online', 'saved filter keeps Online');
eq(rh_bk_saved_query('filter_status=expired'), 'filter_status=expired', 'saved filter keeps Expired');

// ---- database part: Liwonde only, read-only. Path check FIRST: at Rosalyn's (live) the database is never opened.
if (stripos($root, 'Liwonde') !== false) { require $root . '/config/database.php'; }
if (stripos($root, 'Liwonde') === false || stripos((string)getSetting('site_name', ''), 'liwonde') === false) {
    echo "SKIP database checks: run at Liwonde (the test platform) only; Rosalyn's is live.\n";
} else {
    // The page's stats query shape: SUM(<tab pieces>) over the shared scope + not deleted.
    $badge = static function (array $get) use ($pdo): array {
        $f = rh_bk_filters_from_get($get);
        $sc = rh_bk_scope($f, true);
        $where = implode(' AND ', array_merge($sc['clauses'], ['b.deleted_at IS NULL']));
        $sum = static function (string $tab): string { return 'SUM(' . implode(' AND ', rh_bk_tab_clauses($tab)['clauses']) . ')'; };
        $st = $pdo->prepare("SELECT COUNT(*) AS total, " . $sum('current') . " AS cur, " . $sum('online') . " AS onl, SUM(b.status = 'expired') AS exp
            FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id WHERE $where");
        $st->execute($sc['params']);
        return array_map('intval', $st->fetch(PDO::FETCH_ASSOC));
    };
    // The list's COUNT: rh_bk_list_scope (the list, its pager and the CSV export use this WHERE).
    $listCount = static function (array $get) use ($pdo): int {
        $f = rh_bk_filters_from_get($get);
        $ls = rh_bk_list_scope($f, true);
        $st = $pdo->prepare("SELECT COUNT(*) FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id WHERE " . implode(' AND ', $ls['clauses']));
        $st->execute($ls['params']);
        return (int)$st->fetchColumn();
    };
    $one = static function (string $sql) use ($pdo): int { return (int)$pdo->query($sql)->fetchColumn(); };

    $b = $badge([]);
    $total = $one("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL");
    $curHand = $one("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status IN ('pending','tentative','confirmed','checked-in')");
    $expHand = $one("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status = 'expired'");
    $other = $one("SELECT COUNT(*) FROM bookings WHERE deleted_at IS NULL AND status NOT IN ('pending','tentative','confirmed','checked-in','checked-out','no-show','cancelled','expired')");
    echo "counts: all $total, current {$b['cur']}, online {$b['onl']}, expired {$b['exp']}\n";
    eq($b['total'], $total, 'All badge = every not-deleted booking');
    eq($b['cur'], $listCount(['filter' => 'current']), 'Current: badge = list count');
    eq($b['cur'], $curHand, 'Current: = pending + tentative + confirmed + checked-in (hand-written count)');
    eq($other, 0, 'no booking carries a status outside the known set (so Current cannot hide one)');
    eq($b['exp'], $listCount(['filter_status' => 'expired']), 'Expired: badge = list count');
    eq($b['exp'], $expHand, 'Expired: = status expired, not deleted (hand-written count)');
    eq($b['onl'], $listCount(['filter' => 'online']), 'Online: badge = list count');
    eq($b['onl'], $listCount(['source' => 'online']), 'Online tab count = ?source=online count');
    check($b['cur'] > 0 && $b['exp'] > 0, 'Liwonde has Current and Expired rows to prove against');

    // Current excludes the other tabs' statuses, deleted rows never listed.
    foreach (['cancelled', 'expired', 'checked-out', 'no-show'] as $s) {
        eq($listCount(['filter' => 'current', 'filter_status' => $s]), 0, "Current + status $s = none");
    }
    eq($listCount(['filter' => 'current']) + $listCount(['filter_status' => 'checked-out']) + $listCount(['filter_status' => 'no-show'])
        + $listCount(['filter_status' => 'cancelled']) + $listCount(['filter_status' => 'expired']), $total, 'Current + Checked out + No-show + Cancelled + Expired = All');
    $leak = $one("SELECT COUNT(*) FROM bookings b WHERE b.deleted_at IS NOT NULL AND b.status NOT IN ('cancelled', 'expired', 'checked-out', 'no-show')");
    echo "deleted rows with a current-type status: $leak (never listed: every list adds deleted_at IS NULL)\n";
    $lsCur = rh_bk_list_scope(rh_bk_filters_from_get(['filter' => 'current']), true);
    check(in_array('b.deleted_at IS NULL', $lsCur['clauses'], true), 'Current list always carries deleted_at IS NULL');

    // Online = guest-made: independent check through the PHP rule (rh_created_by_map) for every booking.
    require_once $root . '/includes/booking-created-by.php';
    $ids = array_map('intval', $pdo->query("SELECT id FROM bookings WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN));
    $php = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        foreach (rh_created_by_map($pdo, $chunk) as $pk) { $php += ($pk['kind'] === 'online') ? 1 : 0; }
    }
    eq($b['onl'], $php, 'Online badge = bookings whose creator rule (PHP rh_created_by_pick) says online');

    // Query time of the page's stats query with and without the Online SUM.
    $time = static function (bool $withOnline) use ($pdo): float {
        $sc = rh_bk_scope(rh_bk_filters_from_get([]), true);
        $sum = static function (string $tab): string { return 'SUM(' . implode(' AND ', rh_bk_tab_clauses($tab)['clauses']) . ')'; };
        $sql = "SELECT COUNT(*), " . $sum('current') . ", SUM(b.status = 'expired')" . ($withOnline ? ', ' . $sum('online') : '')
            . " FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id LEFT JOIN individual_rooms ir ON b.individual_room_id = ir.id WHERE b.deleted_at IS NULL";
        $best = 1e9;
        for ($i = 0; $i < 3; $i++) { $t = microtime(true); $pdo->query($sql)->fetchAll(); $best = min($best, (microtime(true) - $t) * 1000); }
        return $best;
    };
    printf("stats query ms (best of 3): without Online %.0f, with Online %.0f\n", $time(false), $time(true));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);