<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * Value change log: admin/includes/value-change-log.php (compare, format, clip, separators, parse, sentence, record)
 * and how System Logs > Staff activity reads 'value_change' rows (admin/includes/staff-activity-lib.php).
 * Usage (from the repo root): php scripts/tests/value_change_log.php
 * Pure: no database. Rows go to a test sink instead of admin_activity_log.
 */
$root = dirname(__DIR__, 2);
chdir($root);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

require_once $root . '/admin/includes/value-change-log.php';
check(!isset($GLOBALS['pdo']), 'loading the helper connects to nothing');
require_once $root . '/admin/includes/staff-activity-lib.php';

$pg = 'menu-management.php';

// ---- No change -> null ----
check(rh_value_log_details($pg, 'Chicken curry', 5, 'Price', '8500', '8500', 'money') === null, 'same money: null');
check(rh_value_log_details($pg, 'Chicken curry', 5, 'Price', '8500', '8500.00', 'money') === null, 'money 8500 vs 8500.00: null');
check(rh_value_log_details($pg, 'Chicken curry', 5, 'Price', 8500, '8,500.00', 'money') === null, 'money int vs "8,500.00": null');
check(rh_value_log_details($pg, 'Rice', 9, 'Par level', '16.50', '16.5', 'number') === null, 'number 16.50 vs 16.5: null');
check(rh_value_log_details($pg, 'Rice', 9, 'Par level', '+010', '10.000', 'number') === null, 'number +010 vs 10.000: null');
check(rh_value_log_details($pg, 'Rice', 9, 'Name', ' Rice ', 'Rice', 'text') === null, 'text compared trimmed: null');
check(rh_value_log_details($pg, 'Rice', 9, 'Name', '', '', 'text') === null, 'blank to blank: null');
check(rh_value_log_details($pg, 'Rice', 9, 'Name', null, '', 'text') === null, 'null to blank: null');

// ---- Changes and formatting ----
$d = rh_value_log_details($pg, 'Chicken curry', 5, 'Price', '8500', 9000, 'money');
check($d === 'page=menu-management.php; id=5; item=Chicken curry; field=Price; kind=money; old=8500.00; new=9000.00', 'money details text (2 dp)', (string)$d);
check(rh_value_log_sentence((string)$d) === 'Changed Chicken curry Price from 8,500.00 to 9,000.00', 'money sentence with thousands separators', rh_value_log_sentence((string)$d));
$d = rh_value_log_details($pg, 'Chicken curry', 5, 'Price', '1234567.5', '0.004', 'money');
check(rh_value_log_sentence((string)$d) === 'Changed Chicken curry Price from 1,234,567.50 to 0.00', 'money rounds to 2 dp (no -0.00)', rh_value_log_sentence((string)$d));
check(rh_value_log_details($pg, 'X', 1, 'Price', '10.004', '10.001', 'money') === null, 'money equal to 2 dp: null');
check(rh_value_log_details($pg, 'X', 1, 'Price', '10.00', '10.01', 'money') !== null, 'money one tambala apart: a change');
$d = rh_value_log_details($pg, 'Rice', 9, 'Par level', '16.50', '17', 'number');
$p = rh_value_log_parse((string)$d);
check($p !== null && $p['old'] === '16.5' && $p['new'] === '17' && $p['kind'] === 'number', 'number stored normalised', (string)$d);
$d = rh_value_log_details($pg, 'Rice', 9, 'Cost', '', '2500', 'money');
check(rh_value_log_sentence((string)$d) === 'Changed Rice Cost from (blank) to 2,500.00', 'sentence: blank before', rh_value_log_sentence((string)$d));
$d = rh_value_log_details($pg, 'Rice', 9, 'Cost', '2500', 'n/a', 'money');
check(rh_value_log_sentence((string)$d) === 'Changed Rice Cost from 2,500.00 to n/a', 'money kind with a non-number stays text', (string)$d);
$d = rh_value_log_details($pg, 'Rice', 9, 'Note', 'abc', '', 'text');
check(rh_value_log_sentence((string)$d) === 'Changed Rice Note from abc to (blank)', 'sentence: blank after');

// ---- Bad input -> null ----
foreach (['Menu.php', 'menu management.php', 'menu.php; id=1', '../x.php', 'x.phtml', '', str_repeat('a', 81) . '.php'] as $bp) {
    check(rh_value_log_details($bp, 'X', 1, 'Price', '1', '2', 'money') === null, 'bad page -> null: ' . substr($bp, 0, 20));
}
check(rh_value_log_details($pg, 'X', 1, 'Price', '1', '2', 'cash') === null, 'unknown kind -> null');
check(rh_value_log_details($pg, 'X', -1, 'Price', '1', '2', 'money') === null, 'negative id -> null');

// ---- Separators, ; and = round-trip through parse ----
$item = 'Fish; chips = 2; field=odd; kind=money';
$field = 'Price; old=x = y';
$d = rh_value_log_details($pg, $item, 12, $field, 'a; b=c; old=d', 'e; new=f; g=h', 'text');
$p = rh_value_log_parse((string)$d);
check($p !== null && $p['id'] === 12 && $p['page'] === $pg && $p['kind'] === 'text', 'parse: page, id, kind', (string)$d);
check($p !== null && $p['item'] === 'Fish; chips = 2, field=odd; kind=money', 'parse: item keeps ; and =, only "; field=" made safe', (string)($p['item'] ?? ''));
check($p !== null && $p['field'] === $field, 'parse: field with ; and = round-trips exactly', (string)($p['field'] ?? ''));
check($p !== null && $p['old'] === 'a; b=c; old=d' && $p['new'] === 'e; new=f; g=h', 'parse: values with ; and = (and "; new=" inside new)', (string)$d);
$p = rh_value_log_parse((string)rh_value_log_details($pg, 'Soup', 3, 'Size; kind=text', 'Odd; new=old', 'Plain', 'text'));
check($p !== null && $p['field'] === 'Size, kind=text' && $p['old'] === 'Odd, new=old' && $p['new'] === 'Plain', 'parse: "; kind=" in field and "; new=" in old cannot split wrongly');
$p = rh_value_log_parse((string)rh_value_log_details($pg, 'Tea = hot; sweet', 4, 'Price', '1', '2', 'money'));
check($p !== null && $p['item'] === 'Tea = hot; sweet', 'parse: item with ; and = round-trips exactly', (string)($p['item'] ?? ''));
check(rh_value_log_parse('page=menu-management.php; action=save') === null, 'parse: a page_action text is not a value change');
check(rh_value_log_parse('key=vat_rate; label=VAT rate %; old=1; new=2') === null, 'parse: a setting_change text is not a value change');
check(rh_value_log_sentence('rubbish') === 'Changed a value', 'sentence: unreadable details');

// ---- Clip, one line, blank labels ----
$long = str_repeat('abcdefghij', 30);
$p = rh_value_log_parse((string)rh_value_log_details($pg, $long, 7, "Line\none", 'x', $long, 'text'));
check($p !== null && mb_strlen($p['new']) === 120 && substr($p['new'], -3) === '...' && mb_strlen($p['item']) === 120, 'values and item cut at 120 with ...');
check($p !== null && $p['field'] === 'Line one', 'newlines become spaces');
check(rh_value_log_details($pg, 'X', 1, 'Note', $long . 'A', $long . 'B', 'text') !== null, 'long texts that differ after 120 characters are still a change');
$p = rh_value_log_parse((string)rh_value_log_details($pg, '  ', 44, '', '1', '2', 'number'));
check($p !== null && $p['item'] === 'Item #44' && $p['field'] === 'Value', 'blank item / field get a plain fallback');

// ---- record(): sink, who, same, failed ----
$rows = [];
$sink = function (array $row) use (&$rows) { $rows[] = $row; };
$changes = [
    ['page' => $pg, 'item' => 'Chicken curry', 'id' => 5, 'field' => 'Price', 'old' => '8500', 'new' => '9000', 'kind' => 'money'],
    ['page' => $pg, 'item' => 'Beef stew', 'id' => 6, 'field' => 'Price', 'old' => '7000', 'new' => '7000.00', 'kind' => 'money'],
    ['page' => $pg, 'item' => 'Rice', 'id' => '9', 'field' => 'Name', 'old' => 'Rice', 'new' => 'White rice', 'kind' => 'text'],
];
$c = rh_value_log_record(null, $changes, ['id' => 42, 'username' => 'mercy', 'ip' => '10.0.0.9'], $sink);
check($c === ['written' => 2, 'same' => 1, 'failed' => 0], 'record: 2 written, 1 same', json_encode($c));
check(count($rows) === 2, 'record: sink got 2 rows');
$r = $rows[0] ?? [];
check(($r[0] ?? null) === 42 && ($r[1] ?? '') === 'mercy' && ($r[2] ?? '') === 'value_change' && ($r[4] ?? '') === '10.0.0.9', 'row: user id, username, action value_change, ip from $who');
check(($r[3] ?? '') === 'page=menu-management.php; id=5; item=Chicken curry; field=Price; kind=money; old=8500.00; new=9000.00', 'row: details', (string)($r[3] ?? ''));
check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($r[5] ?? '')) === 1, 'row: created_at is a PHP time');
check(strpos((string)($rows[1][3] ?? ''), 'id=9; item=Rice; field=Name; kind=text; old=Rice; new=White rice') !== false, 'row: id given as a digit string');

$rows = [];
$_SESSION = ['admin_user_id' => 7, 'admin_username' => 'ops'];
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
rh_value_log_record(null, [$changes[0]], [], $sink);
check(($rows[0][0] ?? null) === 7 && ($rows[0][1] ?? '') === 'ops' && ($rows[0][4] ?? '') === '192.0.2.1', 'who defaults to the session and REMOTE_ADDR');
unset($_SESSION, $_SERVER['REMOTE_ADDR']);
$rows = [];
rh_value_log_record(null, [$changes[0]], [], $sink);
check(($rows[0][0] ?? null) === 0 && ($rows[0][1] ?? 'x') === '', 'no session: user 0, blank username');

$rows = [];
$c = rh_value_log_record(null, [
    ['page' => 'Bad Page.php', 'item' => 'X', 'id' => 1, 'field' => 'Price', 'old' => '1', 'new' => '2', 'kind' => 'money'],
    ['page' => $pg, 'item' => 'X', 'id' => 1, 'field' => 'Price', 'old' => '1', 'new' => '2', 'kind' => 'cash'],
    ['page' => $pg, 'item' => 'X', 'id' => 'abc', 'field' => 'Price', 'old' => '1', 'new' => '2', 'kind' => 'money'],
    'not an array',
    $changes[0],
], ['id' => 1, 'username' => 'a'], $sink);
check($c === ['written' => 1, 'same' => 0, 'failed' => 4] && count($rows) === 1, 'bad page / kind / id / shape count as failed; the good one is written', json_encode($c));

$threw = false;
try {
    $c = rh_value_log_record(null, [$changes[0], $changes[2]], ['id' => 1, 'username' => 'a'], function () { throw new RuntimeException('boom'); });
} catch (Throwable $e) {
    $threw = true;
}
check(!$threw && $c === ['written' => 0, 'same' => 0, 'failed' => 2], 'a sink that throws counts failed and never throws', json_encode($c));
$c = rh_value_log_record(null, [$changes[0]], ['id' => 1, 'username' => 'a']);
check($c === ['written' => 0, 'same' => 0, 'failed' => 1], 'no database and no sink: failed, never throws', json_encode($c));
check(rh_value_log_record(null, [], [], $sink) === ['written' => 0, 'same' => 0, 'failed' => 0], 'no changes: all zero');
$src = (string)file_get_contents($root . '/admin/includes/value-change-log.php');
check(preg_match('/->(beginTransaction|commit|rollBack)\s*\(/', $src) === 0, 'the helper never begins, commits or rolls back a transaction');

// ---- Staff activity reads the rows ----
check((rh_sa_types()['value_changes'] ?? '') === 'Price & value changes', 'Staff activity type "Price & value changes"');
$tk = array_keys(rh_sa_types());
check(array_search('value_changes', $tk, true) === array_search('setting_changes', $tk, true) + 1, 'type sits right after Settings changes');
check(rh_sa_action_type('value_change') === 'value_changes', 'value_change rows get their own type');
check(rh_sa_action_type('setting_change') === 'setting_changes' && rh_sa_action_type('settings_saved') === 'settings', 'setting_change and settings unchanged');
foreach (rh_sa_action_prefixes() as $t => $re) {
    check(preg_match('/^\^\([^()]+\)$/', $re) === 1, 'prefix shape ^(...) for ' . $t);
}
[$oSql, $oArgs] = rh_sa_activity_type_sql('other');
check(strpos((string)($oArgs[0] ?? ''), '|value_change|') !== false && strpos((string)($oArgs[0] ?? ''), '^(setting_change|') === 0, '"Other" excludes value_change and setting_change rows', (string)($oArgs[0] ?? ''));
[$tSql, $tArgs] = rh_sa_activity_type_sql('value_changes');
check(strpos($tSql, 'REGEXP') !== false && ($tArgs[0] ?? '') === '^(value_change)', 'type filter Price & value changes');
$f = rh_sa_parse_filters(['type' => 'value_changes'], '2026-11-29');
check($f['type'] === 'value_changes', 'the filter accepts value_changes');
$staff = [42 => ['name' => 'Mercy', 'username' => 'mercy', 'role' => 'manager']];
$known = array_keys(rh_sa_pages());
$knownPage = in_array('menu-management.php', $known, true) ? 'menu-management.php' : (string)($known[0] ?? '');
$desc = rh_sa_describe('activity', ['_ts' => '2026-11-29 10:00:00', '_id' => 1, '_uid' => 42, 'action' => 'value_change',
    'details' => 'page=' . $knownPage . '; id=5; item=Chicken curry; field=Price; kind=money; old=8500.00; new=9000.00'], $staff);
check($desc['type'] === 'value_changes' && $desc['what'] === 'Changed Chicken curry Price from 8,500.00 to 9,000.00' && empty($desc['screen_save']), 'timeline sentence for a value change', (string)$desc['what']);
check($knownPage !== '' && $desc['where'] === rh_sa_page_title($knownPage), 'timeline: where = the page title for a known page', (string)$desc['where']);
$desc = rh_sa_describe('activity', ['_ts' => '2026-11-29 10:00:00', '_id' => 2, '_uid' => 42, 'action' => 'value_change',
    'details' => 'page=zz-unknown-screen.php; id=1; item=X; field=Cost; kind=number; old=1; new=2'], $staff);
check($desc['where'] === 'zz-unknown-screen.php', 'timeline: where = the file name for an unknown page', (string)$desc['where']);
$desc = rh_sa_describe('activity', ['_ts' => '2026-11-29 10:00:00', '_id' => 3, '_uid' => 42, 'action' => 'value_change', 'details' => 'garbled'], $staff);
check($desc['type'] === 'value_changes' && $desc['what'] === 'Changed a value' && $desc['where'] === 'A screen', 'timeline: unreadable details');
$desc = rh_sa_describe('activity', ['_ts' => '2026-11-14 10:00:00', '_id' => 4, '_uid' => 42, 'action' => 'setting_change',
    'details' => 'key=tourism_levy_percent; label=Tourism levy %; old=1; new=1.5'], $staff);
check($desc['type'] === 'setting_changes' && $desc['where'] === 'Hotel Settings', 'setting_change timeline row unchanged');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
