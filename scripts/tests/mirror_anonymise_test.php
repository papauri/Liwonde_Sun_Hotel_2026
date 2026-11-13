<?php
/**
 * Pure test (no database) for scripts/mirror_from_rosalyns.php:
 *   - .env parsing, same-database and path guards, the source SQL gate
 *   - table planning (DENY list, settings-merge, one-side-only, views, room media)
 *   - column planning (intersection, generated, Liwonde-only NOT NULL) and PII classification,
 *     including the columns that must be left alone
 *   - the deterministic anonymiser, scrub of JSON / history text, free-text placeholder
 *   - settings allowlist, mute keys, finance sequence bumps
 *   - a static check that the source connection is never written
 * Uses fake schema arrays only; never connects to any database.
 * Run: php scripts/tests/mirror_anonymise_test.php
 */

declare(strict_types=1);

define('MIRROR_LIB_ONLY', true);
require __DIR__ . '/../mirror_from_rosalyns.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label\n";
    }
}
function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return true;
    }
    return false;
}
function col(string $dt, array $extra = []): array
{
    return array_merge(['data_type' => $dt, 'column_type' => $dt, 'max_len' => null, 'nullable' => true, 'default' => null, 'extra' => ''], $extra);
}

echo "1. .env and guards\n";
$env = mirror_parse_env("\xEF\xBB\xBF# comment\nDB_HOST=localhost\nDB_NAME=\"liwonde_db\"\nDB_USER='u'\nDB_PASS=p=w\n\nBROKEN\n");
ok($env['DB_HOST'] === 'localhost' && $env['DB_NAME'] === 'liwonde_db' && $env['DB_USER'] === 'u' && $env['DB_PASS'] === 'p=w', 'env: BOM, comments, quotes, = in value');
ok(!isset($env['BROKEN']), 'env: line without = ignored');
$cfg = mirror_db_config($env, 'T');
ok($cfg['port'] === '3306', 'db config: default port');
ok(throws(function () { mirror_db_config(['DB_HOST' => 'h'], 'T'); }), 'db config: missing DB_NAME refused');
ok(mirror_same_database(['host' => 'localhost', 'port' => '3306', 'name' => 'A'], ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'a']), 'same db: localhost == 127.0.0.1, name case-insensitive');
ok(!mirror_same_database(['host' => 'h', 'port' => '3306', 'name' => 'a'], ['host' => 'h', 'port' => '3306', 'name' => 'b']), 'different db name is not the same');
ok(mirror_path_has_segment('C:\\x\\Liwonde_Sun_Hotel_2026\\.env', 'Liwonde_Sun_Hotel_2026'), 'path segment: Windows path');
ok(!mirror_path_has_segment('/x/Liwonde_Sun_Hotel_2026_old/.env', 'Liwonde_Sun_Hotel_2026'), 'path segment: look-alike folder refused');

echo "2. Source SQL gate\n";
foreach (['SELECT 1', "  select * from `bookings` WHERE id > ? ORDER BY id LIMIT ?", 'SHOW TABLES', 'SET SESSION TRANSACTION READ ONLY',
    "SET SESSION time_zone = '+00:00'", 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ'] as $q) {
    ok(!throws(function () use ($q) { mirror_assert_source_sql($q); }), 'allowed: ' . trim($q));
}
foreach (['INSERT INTO x VALUES (1)', 'UPDATE bookings SET a = 1', 'DELETE FROM payments', 'DROP TABLE x', 'TRUNCATE x',
    'SET FOREIGN_KEY_CHECKS = 0', 'SET SESSION sql_mode = \'\'', 'SET GLOBAL read_only = 0', 'SELECT 1; DELETE FROM x',
    'SELECT * FROM bookings FOR UPDATE', 'SELECT * FROM x LOCK IN SHARE MODE', "SELECT * FROM x INTO OUTFILE '/tmp/a'",
    'START TRANSACTION', 'CALL p()', 'REPLACE INTO x VALUES (1)', 'ALTER TABLE x ADD c INT', 'LOAD DATA INFILE "a" INTO TABLE x'] as $q) {
    ok(throws(function () use ($q) { mirror_assert_source_sql($q); }), 'refused: ' . $q);
}
ok(mirror_qi('booking_rooms') === '`booking_rooms`' && throws(function () { mirror_qi('x`; DROP'); }), 'identifier quoting refuses odd names');

echo "3. Table planning\n";
$bt = ['type' => 'BASE TABLE', 'engine' => 'innodb'];
$names = ['admin_users', 'user_permissions', 'password_resets', 'session_logs', 'api_keys', 'api_usage_logs', 'ai_vault', 'ai_memory',
    'email_send_log', 'automated_email_log', 'guest_communication_log', 'email_settings', 'booking_email_templates', 'push_subscriptions',
    'station_messages', 'print_printers', 'print_jobs', 'idempotency_keys', 'site_pages', 'page_heroes', 'about_us', 'footer_links',
    'hotel_gallery', 'restaurant_gallery', 'managed_media_catalog', 'testimonials', 'reviews', 'review_responses', 'migration_log',
    'enabled_modules', 'import_jobs', 'site_visitors', 'cookie_consent_log', 'mra_submission_queue', 'licence_cache', 'offline_replay_log',
    'facebook_settings', 'policies', 'gym_content', 'guest_services', 'system_event_log', 'admin_activity_log', 'backup_log',
    'rooms', 'individual_rooms', 'room_combinations', 'individual_room_photos', 'rate_plans', 'blocked_dates', 'bookings', 'booking_rooms',
    'booking_charges', 'booking_timeline_logs', 'booking_date_adjustments', 'cancellation_log', 'payments', 'payment_audit_log',
    'credit_notes', 'credit_note_applications', 'finance_sequences', 'stock_orders', 'stock_order_items', 'stock_recipes',
    'menu_items', 'gym_members', 'gym_packages', 'conference_inquiries', 'events', 'event_inquiries', 'housekeeping_assignments',
    'quotations', 'contact_inquiries', 'stock_shift_closes', 'site_settings', 'mystery_table', 'v_report', 'src_only_tbl'];
$src = array_fill_keys($names, $bt);
$tgt = array_fill_keys($names, $bt);
unset($tgt['src_only_tbl']);
$tgt['tgt_only_tbl'] = $bt;
$src['v_report'] = ['type' => 'VIEW', 'engine' => ''];
$tgt['stock_recipes'] = ['type' => 'BASE TABLE', 'engine' => 'myisam'];
$plan = mirror_plan_tables($src, $tgt);
foreach (array_slice($names, 0, 43) as $t) {
    ok($plan[$t]['action'] === 'skip' && strpos($plan[$t]['reason'], 'DENY') === 0, "deny: $t");
}
foreach (['rooms', 'individual_rooms', 'room_combinations', 'individual_room_photos', 'rate_plans', 'blocked_dates', 'bookings', 'booking_rooms',
    'booking_charges', 'booking_timeline_logs', 'booking_date_adjustments', 'cancellation_log', 'payments', 'payment_audit_log', 'credit_notes',
    'credit_note_applications', 'finance_sequences', 'stock_orders', 'stock_order_items', 'menu_items', 'gym_members', 'gym_packages',
    'conference_inquiries', 'events', 'event_inquiries', 'housekeeping_assignments', 'quotations', 'contact_inquiries', 'stock_shift_closes'] as $t) {
    ok($plan[$t]['action'] === 'replace' && $plan[$t]['classified'], "replace: $t");
}
ok($plan['site_settings']['action'] === 'settings-merge', 'site_settings is settings-merge');
ok($plan['mystery_table']['action'] === 'replace' && !$plan['mystery_table']['classified'], 'unknown table replaced but flagged UNCLASSIFIED');
ok($plan['v_report']['action'] === 'skip' && $plan['v_report']['reason'] === 'view', 'views skipped');
ok($plan['src_only_tbl']['action'] === 'skip' && $plan['tgt_only_tbl']['action'] === 'skip', 'one-side-only tables skipped');
ok($plan['stock_recipes']['non_innodb'] === true && $plan['bookings']['non_innodb'] === false, 'non-InnoDB target flagged');

echo "4. Column planning\n";
$cp = mirror_plan_columns(
    ['id' => col('int'), 'a' => col('varchar'), 'only_src' => col('int'), 'gen' => col('decimal', ['extra' => 'stored generated']), 't' => col('varchar', ['column_type' => 'varchar(50)'])],
    ['id' => col('int', ['nullable' => false, 'extra' => 'auto_increment']), 'a' => col('varchar'), 'gen' => col('decimal', ['extra' => 'virtual generated']),
        't' => col('varchar', ['column_type' => 'varchar(100)']), 'only_tgt' => col('int', ['nullable' => false]), 'only_tgt_def' => col('int', ['nullable' => false, 'default' => '0'])]
);
ok($cp['common'] === ['id', 'a', 't'], 'common = intersection minus generated');
ok($cp['src_only'] === ['only_src'] && $cp['tgt_only'] === ['only_tgt', 'only_tgt_def'], 'one-side columns listed');
ok($cp['tgt_required_missing'] === ['only_tgt'], 'Liwonde-only NOT NULL without default flagged');
ok($cp['generated'] === ['gen'] && count($cp['type_diff']) === 1, 'generated skipped, type difference listed');
ok(!mirror_is_generated(['extra' => 'DEFAULT_GENERATED']) && !mirror_is_generated(['extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP']), 'created_at DEFAULT CURRENT_TIMESTAMP (MySQL 8 DEFAULT_GENERATED) is copied, not skipped');
ok(mirror_is_generated(['extra' => 'VIRTUAL GENERATED']) && mirror_is_generated(['extra' => 'STORED GENERATED']) && mirror_is_generated(['extra' => 'PERSISTENT GENERATED']), 'real generated columns are skipped');
ok(mirror_insert_sql('payments', ['id', 'amount'], 2) === 'INSERT INTO `payments` (`id`, `amount`) VALUES (?, ?), (?, ?)', 'insert SQL uses placeholders only');
ok(mirror_batch_rows(400) === 150 && mirror_batch_rows(5) === 200, 'batch size respects placeholder cap');

echo "5. Column classification\n";
$v = col('varchar');
$cases = [
    ['bookings', 'guest_name', $v, 'name'], ['bookings', 'guest_email', $v, 'email'], ['bookings', 'guest_phone', $v, 'phone'],
    ['bookings', 'room_guest_name', $v, 'name'], ['bookings', 'guest_address', $v, 'address'], ['bookings', 'id_number', $v, 'idnum'],
    ['bookings', 'passport_number', $v, 'idnum'], ['bookings', 'company_name', $v, 'company'], ['bookings', 'special_requests', col('text'), 'freetext'],
    ['bookings', 'admin_notes', col('text'), 'freetext'], ['gym_members', 'whatsapp_number', $v, 'phone'], ['gym_members', 'name', $v, 'name'],
    ['gym_members', 'first_name', $v, 'name_first'], ['gym_members', 'last_name', $v, 'name_last'], ['contact_inquiries', 'name', $v, 'name'],
    ['payments', 'notes', col('text'), 'freetext'], ['payments', 'description', col('text'), 'freetext'], ['payments', 'payer_name', $v, 'name'],
    ['cancellation_log', 'reason', col('text'), 'freetext'], ['receipt_events', 'recipient', $v, 'contact'],
    ['receipt_events', 'performed_by_name', $v, 'staff'], ['bookings', 'cancelled_by_name', $v, 'staff'], ['admin_view', 'admin_full_name', $v, 'staff'],
    ['booking_timeline_logs', 'old_value', col('text'), 'scrub'], ['booking_timeline_logs', 'details', col('json'), 'scrub'],
    ['booking_charges', 'description', $v, 'scrub'], ['credit_notes', 'reason_notes', col('text'), 'scrub'], ['gym_inquiries', 'notes', col('text'), 'scrub'],
    ['stock_shift_closes', 'notes', col('text'), 'scrub'], ['site_visitors', 'ip_address', $v, 'ip'], ['conference_inquiries', 'organization', $v, 'company'],
    ['conference_inquiries', 'contact_person', $v, 'name'],
    // must be left alone
    ['bookings', 'number_of_guests', col('int'), 'none'], ['rooms', 'max_guests', col('int'), 'none'], ['bookings', 'adult_guests', col('int'), 'none'],
    ['bookings', 'child_guests', col('tinyint'), 'none'], ['stock_orders', 'client_uuid', $v, 'keep'], ['gym_members', 'member_number', $v, 'keep'],
    ['gym_members', 'member_id', col('int'), 'none'], ['payments', 'receipt_emailed_at', col('datetime'), 'none'], ['email_x', 'email_kind', $v, 'keep'],
    ['bookings', 'id_type', $v, 'keep'], ['payments', 'mobile_money', col('decimal'), 'none'], ['bookings', 'booking_reference', $v, 'keep'],
    ['bookings', 'status', col('enum'), 'none'], ['bookings', 'total_amount', col('decimal'), 'none'], ['payments', 'vat_amount', col('decimal'), 'none'],
    ['payments', 'receipt_number', $v, 'keep'], ['rooms', 'name', $v, 'keep'], ['rooms', 'description', col('text'), 'keep'],
    ['menu_items', 'description', col('text'), 'keep'], ['bookings', 'check_in_date', col('date'), 'none'], ['bookings', 'cancelled_by', col('int'), 'none'],
    ['gym_packages', 'name', $v, 'keep'], ['payments', 'payment_method', $v, 'keep'], ['bookings', 'guest_country', $v, 'keep'],
    ['room_maintenance_log', 'status_to', $v, 'scrub'], ['stock_nonstock_receipts', 'description', $v, 'keep'], ['stock_purchase_orders', 'notes', col('text'), 'scrub'],
    ['stock_orders', 'notes', col('text'), 'freetext'], ['pos_deleted_orders', 'snapshot_json', col('longtext'), 'scrub'], ['payments', 'issued_to', $v, 'name'],
    ['gym_members', 'medical_conditions', col('text'), 'freetext'], ['contact_inquiries', 'subject', $v, 'scrub'], ['email_queue', 'to', $v, 'contact'],
    ['booking_status_log', 'change_reason', $v, 'freetext'], ['stock_ingredients', 'notes', col('text'), 'scrub'], ['stock_ingredients', 'name', $v, 'keep'],
    ['gym_packages', 'description', col('text'), 'keep'], ['gym_hours', 'note', $v, 'scrub'],
];
foreach ($cases as [$t, $c, $m, $want]) {
    $got = mirror_classify_column($t, $c, $m);
    ok($got === $want, "$t.$c => $want" . ($got === $want ? '' : " (got $got)"));
}

echo "6. Anonymiser\n";
$st = mirror_anon_init('salt-a');
$plan = ['guest_name' => 'name', 'guest_email' => 'email', 'guest_phone' => 'phone', 'special_requests' => 'freetext', 'company_name' => 'company',
    'id_number' => 'idnum', 'guest_address' => 'address'];
$r1 = ['id' => '1', 'guest_name' => 'John Realname', 'guest_email' => 'John.Real@Gmail.com', 'guest_phone' => '0999 123 456', 'total_amount' => '150000.00',
    'check_in_date' => '2026-11-01', 'status' => 'confirmed', 'room_id' => '7', 'vat_amount' => '24000.00', 'booking_reference' => 'RH2026000123',
    'special_requests' => 'Please call my wife Mary on 0888765432', 'company_name' => 'Acme Ltd', 'id_number' => 'MW123456', 'guest_address' => '12 Real Road, Blantyre'];
$a1 = mirror_anonymise_row($st, 'bookings', $r1, $plan, ['id'], ['guest_name' => 100]);
ok($a1['guest_name'] !== 'John Realname' && preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $a1['guest_name']) === 1, 'name replaced with a made-up name');
$fn = explode(' ', $a1['guest_name']);
ok(in_array($fn[0], mirror_first_names(), true) && in_array($fn[1], mirror_last_names(), true), 'name comes from the built-in lists');
ok(preg_match('/^guest\d{6,}@example\.invalid$/', $a1['guest_email']) === 1, 'email => guestN@example.invalid');
ok(preg_match('/^\+265 000 \d{6}$/', $a1['guest_phone']) === 1, 'phone => +265 000 NNNNNN');
ok(preg_match('/^Test Company \d{3}$/', $a1['company_name']) === 1, 'company => Test Company NNN');
ok(preg_match('/^TESTID \d+$/', $a1['id_number']) === 1 && preg_match('/^Test address \d+$/', $a1['guest_address']) === 1, 'id and address replaced');
ok($a1['special_requests'] === MIRROR_PLACEHOLDER, 'personal free text replaced by placeholder');
foreach (['id', 'total_amount', 'check_in_date', 'status', 'room_id', 'vat_amount', 'booking_reference'] as $k) {
    ok($a1[$k] === $r1[$k], "untouched: $k");
}
// Same email, differently spelled name, other table: same fake name and email.
$r2 = ['id' => '9', 'guest_name' => 'J. Realname', 'guest_email' => 'john.real@gmail.com ', 'guest_phone' => '+265999123456'];
$a2 = mirror_anonymise_row($st, 'conference_inquiries', $r2, ['guest_name' => 'name', 'guest_email' => 'email', 'guest_phone' => 'phone'], ['id']);
ok($a2['guest_name'] === $a1['guest_name'] && $a2['guest_email'] === $a1['guest_email'], 'same email => same fake identity across tables');
ok($a2['guest_phone'] === $a1['guest_phone'], 'same number written two ways => same fake phone');
// Determinism across runs, salt matters.
$stB = mirror_anon_init('salt-a');
$b1 = mirror_anonymise_row($stB, 'bookings', $r1, $plan, ['id']);
ok($b1['guest_name'] === $a1['guest_name'] && $b1['guest_email'] === $a1['guest_email'] && $b1['guest_phone'] === $a1['guest_phone'], 'repeat run gives the same fakes');
$stC = mirror_anon_init('salt-b');
$c1 = mirror_anonymise_row($stC, 'bookings', $r1, $plan, ['id']);
ok($c1['guest_email'] !== $a1['guest_email'] || $c1['guest_name'] !== $a1['guest_name'], 'a different salt gives different fakes');
// NULL / '' / system values.
$a3 = mirror_anonymise_row($st, 'bookings', ['id' => '3', 'guest_name' => 'Walk-in', 'guest_email' => '', 'guest_phone' => null], $plan, ['id']);
ok($a3['guest_name'] === 'Walk-in' && $a3['guest_email'] === '' && $a3['guest_phone'] === null, 'Walk-in, empty and NULL kept');
$a4 = mirror_anonymise_row($st, 'bookings', ['id' => '4', 'guest_name' => 'Mrs Grace Other', 'guest_email' => 'grace@x.mw'], $plan, ['id']);
ok(strpos($a4['guest_name'], 'Mrs ') === 0 && strpos($a4['guest_name'], 'Grace') === false, 'title kept, name replaced');
$a5 = mirror_anonymise_row($st, 'bookings', ['id' => '5', 'guest_name' => 'Someone Withaverylongname'], $plan, ['id'], ['guest_name' => 6]);
ok(mb_strlen($a5['guest_name']) <= 6, 'fake truncated to the column length');
ok(mirror_fake_email($st, 'test@gmail.com') !== 'test@gmail.com', 'a real-looking test@ address is still replaced');
// Different emails never share a fake email.
$seen = [];
$st2 = mirror_anon_init('x');
for ($i = 0; $i < 3000; $i++) {
    $seen[mirror_fake_email($st2, "p$i@example.com")] = true;
}
ok(count($seen) === 3000, 'email numbers never collide (3000 guests)');
// No-email rows keyed by table + primary key; same name reused.
$st3 = mirror_anon_init('k');
$n1 = mirror_anonymise_row($st3, 'booking_rooms', ['id' => '1', 'room_guest_name' => 'Peter Occupant'], ['room_guest_name' => 'name'], ['id']);
$n2 = mirror_anonymise_row($st3, 'bookings', ['id' => '77', 'room_guest_name' => 'peter  occupant'], ['room_guest_name' => 'name'], ['id']);
ok($n1['room_guest_name'] === $n2['room_guest_name'], 'same name without email => same fake');
// Staff and first/last split.
$s1 = mirror_anonymise_row($st3, 'payments', ['id' => '1', 'recorded_by_name' => 'Cashier Realperson', 'created_by' => 'system'], ['recorded_by_name' => 'staff', 'created_by' => 'staff'], ['id']);
ok($s1['recorded_by_name'] !== 'Cashier Realperson' && $s1['created_by'] === 'system', 'staff name replaced, "system" kept');
$fl = mirror_anonymise_row($st3, 'gym_members', ['id' => '2', 'first_name' => 'Agnes', 'last_name' => 'Realsurname', 'email' => 'agnes@x.mw'],
    ['first_name' => 'name_first', 'last_name' => 'name_last', 'email' => 'email'], ['id']);
ok($fl['first_name'] !== 'Agnes' && $fl['last_name'] !== 'Realsurname' && strpos($fl['first_name'], ' ') === false, 'first/last replaced by one fake person');

$stM = mirror_anon_init('m');
mirror_anonymise_row($stM, 'gym_members', ['id' => '1', 'full_name' => 'Mary'], ['full_name' => 'name'], ['id']);
$sm = (string)mirror_scrub($stM, 'Rosemary called; Mary paid');
ok(strpos($sm, 'Rosemary called; ') === 0 && strpos($sm, 'Mary paid') === false, 'single-word name swapped only as a whole word');
// Owner 2026-11-13: a name must go whatever its case in the text (found: a group name kept in pay-later history text).
$stC = mirror_anon_init('c');
mirror_anonymise_row($stC, 'bookings', ['id' => '1', 'guest_name' => 'Zorbix', 'guest_email' => 'z@zorbix.mw'], ['guest_name' => 'name', 'guest_email' => 'email'], ['id']);
$sc = (string)mirror_scrub($stC, 'Pay-later on for ZORBIX; zorbix group; Zorbixon stays; Guest name kept');
ok(stripos(str_replace('Zorbixon', '', $sc), 'zorbix') === false && strpos($sc, 'Zorbixon stays') !== false, 'name swapped in UPPER and lower case, whole words only');
ok(strpos($sc, 'Guest name kept') !== false && strpos($sc, "\x01") === false && strpos($sc, "\x02") === false, 'stoplist word kept, no marker left behind');
$st2c = mirror_anon_init('c2');
mirror_anonymise_row($st2c, 'bookings', ['id' => '1', 'guest_name' => 'Thandiwe Phirilo'], ['guest_name' => 'name'], ['id']);
$s2c = (string)mirror_scrub($st2c, 'note from THANDIWE PHIRILO and phirilo family');
ok(stripos($s2c, 'thandiwe') === false && stripos($s2c, 'phirilo') === false, 'full name and surname swapped in any case');
ok(mirror_unique_collisions(['uniq_name' => ['full_name'], 'uniq_email' => ['email'], 'uniq_ref' => ['reference']], ['full_name' => 'name', 'email' => 'email', 'reference' => 'keep'])
    === ['uniq_name (full_name)'], 'UNIQUE index on a repeatable fake is blocking; email / keep are not');

echo "7. Scrub and free text\n";
$stS = mirror_anon_init('s');
$base = mirror_anonymise_row($stS, 'bookings', ['id' => '1', 'guest_name' => 'John Realname', 'guest_email' => 'john@real.mw', 'guest_phone' => '0999123456'],
    ['guest_name' => 'name', 'guest_email' => 'email', 'guest_phone' => 'phone'], ['id']);
$json = json_encode(['old' => 'John Realname', 'new' => 'John Realname Jr', 'email' => 'john@real.mw', 'amount' => 150000.5, 'date' => '2026-11-01 10:00:00',
    'ref' => 'RCP2026000123', 'ts' => 1762950000]);
$out = mirror_scrub($stS, $json);
$dec = json_decode((string)$out, true);
ok(is_array($dec), 'scrubbed JSON is still valid JSON');
ok($dec['old'] === $base['guest_name'] && $dec['email'] === $base['guest_email'], 'JSON: name and email swapped for the same fakes');
ok($dec['amount'] === 150000.5 && $dec['date'] === '2026-11-01 10:00:00' && $dec['ref'] === 'RCP2026000123' && $dec['ts'] === 1762950000, 'JSON: amounts, dates, refs, timestamps untouched');
$txt = mirror_scrub($stS, 'Guest name changed from John Realname to Realname family; call +265 888 765 432 or other@mail.com on 01-11-2026');
ok(strpos($txt, 'Realname') === false && strpos($txt, '765 432') === false && strpos($txt, 'other@mail.com') === false, 'history text: names, surname, phone, email swapped');
ok(strpos($txt, '01-11-2026') !== false, 'history text: date untouched');
ok(mirror_scrub($stS, 'Payment 150000.00 MWK on 2026-11-01, ref INV-2026-000045') === 'Payment 150000.00 MWK on 2026-11-01, ref INV-2026-000045', 'system text without personal data unchanged');
ok(mirror_freetext($stS, 'Payment recorded via cash') === 'Payment recorded via cash', 'system wording kept');
ok(mirror_freetext($stS, 'Guest is allergic to nuts, husband arrives late') === MIRROR_PLACEHOLDER, 'personal wording replaced');
ok(mirror_freetext($stS, 'Mr Realname wants a cot') === MIRROR_PLACEHOLDER, 'unsure => replaced');
$mk = mirror_freetext($stS, 'paid by Mr Banda for the gym [member:GM-0003] OVERRIDE see LSH-INV-2026-000012');
ok(strpos($mk, MIRROR_PLACEHOLDER) === 0 && strpos($mk, '[member:GM-0003]') !== false && strpos($mk, 'OVERRIDE') !== false && strpos($mk, 'LSH-INV-2026-000012') !== false,
    'markers that code matches on survive the placeholder');
ok(mirror_freetext($stS, null) === null && mirror_freetext($stS, '') === '', 'NULL and empty free text kept');

echo "8. Settings and mute\n";
foreach (['vat_rate', 'vat_enabled', 'vat_pricing_mode', 'tourism_levy_percent', 'currency_code', 'check_in_time', 'cancellation_refund_mode',
    'no_show_refund_policy', 'deposit_percent', 'tentative_duration_hours'] as $k) {
    ok(mirror_settings_key_allowed($k), "copy setting: $k");
}
foreach (['site_name', 'site_url', 'email_main', 'phone_main', 'address_line1', 'site_logo', 'smtp_host', 'whatsapp_number', 'booking_reference_prefix',
    'invoice_prefix', 'receipt_start_number', 'document_number_prefix', 'vat_number', 'bank_account_number', 'site_timezone', 'cancellation_policy',
    'facebook_page_access_token', 'gym_system_enabled', 'licence_key', 'automated_email_master'] as $k) {
    ok(!mirror_settings_key_allowed($k), "keep Liwonde's: $k");
}
$mute = mirror_mute_keys(['whatsapp_custom_enabled', 'whatsapp_log_enabled', 'site_name', 'automated_email_job_new_thing']);
foreach (['automated_email_master', 'whatsapp_enabled', 'facebook_posting_enabled', 'booking_prearrival_reminder_enabled', 'whatsapp_custom_enabled', 'automated_email_job_new_thing'] as $k) {
    ok(in_array($k, $mute, true), "mute: $k");
}
ok(!in_array('whatsapp_log_enabled', $mute, true) && !in_array('site_name', $mute, true) && !in_array('automated_backup_enabled', $mute, true), 'mute leaves logging, identity and backup alone');
ok(mirror_target_sql_mode('STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION') === 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,NO_AUTO_VALUE_ON_ZERO', 'target sql_mode = source + NO_AUTO_VALUE_ON_ZERO');

echo "9. Finance sequences\n";
$seq = [
    ['sequence_name' => 'receipt:RCP', 'sequence_scope' => '2026', 'next_number' => '5'],
    ['sequence_name' => 'invoice:room_INV', 'sequence_scope' => '2026', 'next_number' => '100'],
    ['sequence_name' => 'invoice:conference_CONF-INV', 'sequence_scope' => '2026', 'next_number' => '1'],
    ['sequence_name' => 'credit_note:CN', 'sequence_scope' => '2025', 'next_number' => '3'],
];
$docs = ['RCP2026000009', 'RCP2025000044', 'INV-2026-000050', 'CONF-INV-2026-000007', 'CN-2025-000002', 'REF-2026-000001'];
$b = mirror_sequence_bumps($seq, $docs, '');
$by = [];
foreach ($b as $x) {
    $by[$x['sequence_name'] . '|' . $x['sequence_scope']] = $x['to'];
}
ok(($by['receipt:RCP|2026'] ?? null) === 10, 'undashed receipt: next after the highest copied');
ok(!isset($by['invoice:room_INV|2026']), 'sequence already ahead is not moved back');
ok(($by['invoice:conference_CONF-INV|2026'] ?? null) === 8, 'conference invoice series moved on its own');
ok(!isset($by['credit_note:CN|2025']), 'credit note already ahead untouched');
$b2 = mirror_sequence_bumps([['sequence_name' => 'invoice:room_INV', 'sequence_scope' => '2026', 'next_number' => '1']], ['RH-INV-2026-000031', 'RH-CONF-INV-2026-000090'], 'RH');
ok(count($b2) === 1 && $b2[0]['to'] === 32, 'hotel prefix handled; conference numbers do not move the room series');
ok(mirror_sequence_prefix_from_name('refund:REF') === 'REF' && mirror_sequence_prefix_from_name('nonsense') === null, 'prefix from sequence name');

echo "10. Source is never written (static check)\n";
$code = (string)file_get_contents(__DIR__ . '/../mirror_from_rosalyns.php');
preg_match_all('/\$srcPdo->(\w+)\(/', $code, $m);
$methods = array_values(array_unique($m[1]));
sort($methods);
ok(array_diff($methods, ['beginTransaction', 'prepare', 'query', 'rollBack']) === [], 'source handle only uses query/prepare/beginTransaction/rollBack: ' . implode(',', $methods));
preg_match('/function mirror_src_query\(.*?\n\}/s', $code, $fn);
ok(isset($fn[0]) && strpos($fn[0], 'mirror_assert_source_sql($sql);') !== false, 'every source statement passes the SQL gate');
$outside = preg_replace('/function mirror_src_query\(.*?\n\}/s', '', $code);
ok(preg_match('/\$srcPdo->(query|prepare)\(/', (string)$outside) === 0, 'no source query/prepare outside the gated wrapper');
ok(strpos($code, "require_once") === false && strpos($code, 'config/database.php\';') === false, 'config/database.php is not loaded');
ok(preg_match('/\bTRUNCATE\b/', preg_replace('/^\s*(\*|\/\/).*$/m', '', $code)) === 0, 'no TRUNCATE');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
