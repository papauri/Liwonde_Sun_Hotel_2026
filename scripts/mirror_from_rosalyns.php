<?php
/**
 * Mirror Rosalyn's (LIVE, read-only source) into Liwonde (the owner's dedicated test platform).
 *
 * Owner 2026-11-12: copy Rosalyn's rooms, rates, bookings and all accounting into Liwonde with
 * made-up personal details and the same financials. Liwonde's bookings and accounting data are
 * replaced (no backup, owner's decision). Liwonde's automated email / WhatsApp / Facebook sending
 * is switched off afterwards. Run on request only: no cron, no timers.
 *
 * Usage (from the Liwonde project root):
 *   php scripts/mirror_from_rosalyns.php                  dry run (default): prints the plan, writes nothing
 *   php scripts/mirror_from_rosalyns.php --dry-run        same
 *   php scripts/mirror_from_rosalyns.php --run --i-understand-liwonde-data-is-replaced
 *   add --allow-non-transactional only if the dry run lists a non-InnoDB table and you accept
 *   that a failure part-way would leave that table half copied.
 *
 * Credentials: source from ../Rosalyns-hotel-2026/.env, target from ./.env. They are never printed.
 * config/database.php is deliberately NOT loaded (it can migrate and write); raw PDO only.
 *
 * Source safety: the source session is SET SESSION TRANSACTION READ ONLY (verified), runs in one
 * consistent read snapshot, and every statement sent to it goes through mirror_src_query(), which
 * refuses anything that is not SELECT / SHOW / SET SESSION. Nothing in this file writes to it.
 *
 * Pure helpers (anonymiser, table / column / settings / sequence planning) are covered by
 * scripts/tests/mirror_anonymise_test.php, which never connects to a database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script may only be run from the command line.\n";
    exit(1);
}

define('MIRROR_SETTINGS_TABLE', 'site_settings');
define('MIRROR_PLACEHOLDER', 'Test note (replaced)');

// ---------------------------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------------------------

function mirror_out(string $line = ''): void
{
    echo $line, PHP_EOL;
}

// ---------------------------------------------------------------------------------------------
// .env and safety guards (pure)
// ---------------------------------------------------------------------------------------------

/** Same rules as config/database.php: KEY=value, # comments, optional matching quotes, UTF-8 BOM stripped. */
function mirror_parse_env(string $text): array
{
    $out = [];
    $text = (string)preg_replace("/^\xEF\xBB\xBF/", '', $text);
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '') {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** DB settings from a parsed .env. Throws without echoing any value. */
function mirror_db_config(array $env, string $label): array
{
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $k) {
        if (!isset($env[$k]) || trim((string)$env[$k]) === '') {
            throw new RuntimeException($label . ' .env is missing ' . $k . '.');
        }
    }
    return [
        'host' => trim((string)$env['DB_HOST']),
        'port' => trim((string)($env['DB_PORT'] ?? '')) !== '' ? trim((string)$env['DB_PORT']) : '3306',
        'name' => trim((string)$env['DB_NAME']),
        'user' => (string)$env['DB_USER'],
        'pass' => (string)($env['DB_PASS'] ?? ''),
    ];
}

/** True when both configs point at the same host + port + database name. */
function mirror_same_database(array $a, array $b): bool
{
    $h = static function (string $host): string {
        $host = strtolower(trim($host));
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) ? 'localhost' : $host;
    };
    return $h((string)$a['host']) === $h((string)$b['host'])
        && (int)$a['port'] === (int)$b['port']
        && strtolower(trim((string)$a['name'])) === strtolower(trim((string)$b['name']));
}

/** True when one folder in $path is exactly $segment (case-insensitive, either slash style). */
function mirror_path_has_segment(string $path, string $segment): bool
{
    foreach (preg_split('#[\\\\/]+#', $path) as $part) {
        if (strcasecmp((string)$part, $segment) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * The only gate to the source. Allows SELECT, SHOW and the read-only SET SESSION statements;
 * refuses multiple statements, locking reads and SELECT ... INTO.
 */
function mirror_assert_source_sql(string $sql): void
{
    $s = ltrim($sql);
    if (!preg_match('/^(SELECT|SHOW|SET\s+SESSION)\b/i', $s)) {
        throw new RuntimeException("Refused: only SELECT, SHOW or SET SESSION may be sent to Rosalyn's database.");
    }
    if (strpos($s, ';') !== false) {
        throw new RuntimeException("Refused: one statement at a time to Rosalyn's database.");
    }
    if (preg_match('/\bFOR\s+UPDATE\b|\bFOR\s+SHARE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\bINTO\s+(OUTFILE|DUMPFILE|@)/i', $s)) {
        throw new RuntimeException("Refused: no locking reads or SELECT ... INTO on Rosalyn's database.");
    }
    if (preg_match('/^SET\s+SESSION\b/i', $s)
        && !preg_match("/^SET\s+SESSION\s+(TRANSACTION\s+READ\s+ONLY|TRANSACTION\s+ISOLATION\s+LEVEL\s+REPEATABLE\s+READ|time_zone\s*=\s*'\+00:00')\s*$/i", $s)) {
        throw new RuntimeException("Refused: only read-only SET SESSION statements may be sent to Rosalyn's database.");
    }
}

/** Backtick-quote a schema identifier taken from information_schema; anything unusual is refused. */
function mirror_qi(string $id): string
{
    if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $id)) {
        throw new RuntimeException('Unexpected identifier name refused.');
    }
    return '`' . $id . '`';
}

// ---------------------------------------------------------------------------------------------
// Database access
// ---------------------------------------------------------------------------------------------

function mirror_bind_all(PDOStatement $st, array $params): void
{
    $i = 1;
    foreach ($params as $p) {
        if ($p === null) {
            $st->bindValue($i++, null, PDO::PARAM_NULL);
        } elseif (is_int($p)) {
            $st->bindValue($i++, $p, PDO::PARAM_INT);
        } else {
            $st->bindValue($i++, (string)$p, PDO::PARAM_STR);
        }
    }
}

/** Every statement for the source goes through here (see mirror_assert_source_sql). */
function mirror_src_query(PDO $srcPdo, string $sql, array $params = []): PDOStatement
{
    mirror_assert_source_sql($sql);
    if (!$params) {
        $st = $srcPdo->query($sql);
        if (!$st instanceof PDOStatement) {
            throw new RuntimeException('Source query failed.');
        }
        return $st;
    }
    $st = $srcPdo->prepare($sql);
    mirror_bind_all($st, $params);
    $st->execute();
    return $st;
}

function mirror_tgt_query(PDO $tgt, string $sql, array $params = []): PDOStatement
{
    $st = $tgt->prepare($sql);
    mirror_bind_all($st, $params);
    $st->execute();
    return $st;
}

/**
 * Raw PDO connection. The source uses emulated prepares and string fetches so every value comes
 * back exactly as the server formats it (no float re-rounding) and goes into the target unchanged.
 */
function mirror_connect(array $cfg, string $label, bool $isSource): PDO
{
    $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => $isSource,
        PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::ATTR_TIMEOUT => 20,
    ];
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $opts[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
    }
    try {
        return new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);
    } catch (Throwable $e) {
        // The driver message can contain the user name and host: never print it.
        throw new RuntimeException('Could not connect to the ' . $label . ' database (details withheld so no credentials are printed).');
    }
}

/** Read-only session + one consistent snapshot on the source. Aborts unless READ ONLY is confirmed. */
function mirror_source_begin(PDO $srcPdo): void
{
    mirror_src_query($srcPdo, "SET SESSION time_zone = '+00:00'");
    mirror_src_query($srcPdo, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    mirror_src_query($srcPdo, 'SET SESSION TRANSACTION READ ONLY');
    $ro = null;
    foreach (['SELECT @@SESSION.transaction_read_only', 'SELECT @@SESSION.tx_read_only'] as $q) {
        try {
            $ro = (string)mirror_src_query($srcPdo, $q)->fetchColumn();
            break;
        } catch (Throwable $e) {
            $ro = null;
        }
    }
    if ($ro !== '1') {
        throw new RuntimeException("Rosalyn's session could not be confirmed READ ONLY. Nothing was done.");
    }
    $srcPdo->beginTransaction();
}

/** information_schema snapshot: tables, columns, primary keys, triggers. $q = fn(sql, params): PDOStatement. */
function mirror_read_schema(callable $q): array
{
    $tables = [];
    foreach ($q('SELECT TABLE_NAME AS t, TABLE_TYPE AS ty, ENGINE AS en FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', [])->fetchAll() as $r) {
        $tables[(string)$r['t']] = ['type' => strtoupper((string)$r['ty']), 'engine' => strtolower((string)($r['en'] ?? ''))];
    }
    $cols = [];
    $sql = 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS dt, COLUMN_TYPE AS ct, CHARACTER_MAXIMUM_LENGTH AS ml,'
        . ' IS_NULLABLE AS nl, COLUMN_DEFAULT AS df, EXTRA AS ex FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION';
    foreach ($q($sql, [])->fetchAll() as $r) {
        $cols[(string)$r['t']][(string)$r['c']] = [
            'data_type' => strtolower((string)$r['dt']),
            'column_type' => strtolower((string)$r['ct']),
            'max_len' => $r['ml'] === null ? null : (int)$r['ml'],
            'nullable' => strtoupper((string)$r['nl']) === 'YES',
            'default' => $r['df'],
            'extra' => strtolower((string)($r['ex'] ?? '')),
        ];
    }
    $pk = [];
    $sql = "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.STATISTICS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'PRIMARY' ORDER BY TABLE_NAME, SEQ_IN_INDEX";
    foreach ($q($sql, [])->fetchAll() as $r) {
        $pk[(string)$r['t']][] = (string)$r['c'];
    }
    $unique = [];
    $sql = "SELECT TABLE_NAME AS t, INDEX_NAME AS i, COLUMN_NAME AS c FROM information_schema.STATISTICS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX";
    foreach ($q($sql, [])->fetchAll() as $r) {
        $unique[(string)$r['t']][(string)$r['i']][] = (string)$r['c'];
    }
    $triggers = [];
    foreach ($q('SELECT TRIGGER_NAME AS n, EVENT_OBJECT_TABLE AS t FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()', [])->fetchAll() as $r) {
        $triggers[] = (string)$r['t'] . '.' . (string)$r['n'];
    }
    return ['tables' => $tables, 'columns' => $cols, 'pk' => $pk, 'unique' => $unique, 'triggers' => $triggers];
}

// ---------------------------------------------------------------------------------------------
// Table planning (pure)
// ---------------------------------------------------------------------------------------------

/** DENY list: never touched on Liwonde. Order matters only for the reason shown. */
function mirror_deny_rules(): array
{
    return [
        ['/^(admin_|users?$|user_|roles?$|role_|permissions?$|.*_permissions?$|staff_accounts?$)/', 'admin users, roles, permissions'],
        ['/(^sessions?$|^user_sessions|^admin_sessions|session_logs?|login|password|passwd|two_factor|(^|_)otp(_|$))/', 'sessions, login logs, password resets'],
        ['/(api_key|apikey|api_usage)/', 'API keys and usage'],
        ['/licen[cs]e/', 'licence'],
        ['/^ai_/', 'AI assistant (keys, settings, memory, vault)'],
        ['/(email_send_log|automated_email_log|guest_communication_log|reminder_log|whatsapp|(^|_)sms(_|$)|email_queue|mail_queue|_queue$|^queue|outbox|notification|email_settings|email_templates?$|email_log|mail_log)/', 'email / WhatsApp send logs, queues, settings, templates'],
        ['/backup/', 'backups log'],
        ['/(push|device|station|pairing|^print_|printer|offline_replay)/', 'push subscriptions, devices, station pairing, printers'],
        ['/(token|secret|credential|oauth|idempotency|(^|_)keys?$|vault|encrypt|_enc$|facebook|social)/', 'tokens and secrets'],
        ['/^(site_pages|pages?$|page_|about|section_headers?|footer|polic(y|ies)|testimonials?|facilities|guest_services|gym_content|gym_facilities|gym_features|hero|banner|slider|logo|brand|theme|seo|cms|content|faq|navigation|nav_)/', 'site identity content (pages, hero, about, footer, policies)'],
        ['/review/', 'guest reviews (public site content)'],
        ['/^(migration|schema_|locks?$|cache|enabled_modules|module|import_|system_event_log|error_log|site_visitors|visitor|cookie_consent|analytics|page_views|mra_)|_cache$/', 'system state, analytics, imports'],
        ['/_settings$/', 'settings table other than site_settings (Liwonde keeps its own)'],
    ];
}

/** Room and menu pictures follow their rooms / menu items (copied); every other media table is identity. */
function mirror_is_media_table(string $t): bool
{
    return (bool)preg_match('/(gallery|media|photo|image|video)/', $t);
}

function mirror_is_followed_media(string $t): bool
{
    return (bool)preg_match('/^(individual_rooms?|rooms?|menu|menu_items?|food_menu|drink_menu)_/', $t);
}

function mirror_deny_reason(string $table): ?string
{
    $t = strtolower($table);
    if (mirror_is_media_table($t)) {
        return mirror_is_followed_media($t) ? null : 'media / gallery (Liwonde identity)';
    }
    foreach (mirror_deny_rules() as $rule) {
        if (preg_match($rule[0], $t)) {
            return $rule[1];
        }
    }
    return null;
}

/** Tables we expect to replace; anything replaced that is not recognised is flagged UNCLASSIFIED. */
function mirror_is_known_data_table(string $table): bool
{
    return (bool)preg_match('/^(rooms?$|room_|individual_room|bookings?$|booking_|payments?$|payment_|invoices?|receipts?|receipt_|credit_note|refund|accounts?$|account_|folio|finance_|pos_|stock_|menu|food_menu|drink_menu|recipe|shift|eod|end_of_day|z_report|housekeeping|maintenance|gym_|conference|events?$|event_|cancellation|blocked_dates|rate_plan|rates?$|rate_|packages?$|quotation|tentative|contact_inquir|restaurant_|demand_log|guests?$|guest_|customers?$|deals?$|supplier|purchase|expense|cash|till|tabs?$|orders?$|order_|kds_|availability)/', strtolower($table));
}

/**
 * One row per table on either side: action replace / settings-merge / skip, with the reason.
 * $src / $tgt: name => ['type' => 'BASE TABLE'|'VIEW', 'engine' => 'innodb'].
 */
function mirror_plan_tables(array $src, array $tgt): array
{
    $all = array_unique(array_merge(array_keys($src), array_keys($tgt)));
    sort($all, SORT_STRING);
    $plan = [];
    foreach ($all as $t) {
        $inS = isset($src[$t]);
        $inT = isset($tgt[$t]);
        $row = ['action' => 'skip', 'reason' => '', 'classified' => true, 'non_innodb' => false];
        if (!$inS) {
            $row['reason'] = 'Liwonde only (left untouched)';
        } elseif (!$inT) {
            $row['reason'] = "Rosalyn's only (Liwonde lacks the table: run Liwonde migrations, then re-run)";
        } elseif (($src[$t]['type'] ?? '') !== 'BASE TABLE' || ($tgt[$t]['type'] ?? '') !== 'BASE TABLE') {
            $row['reason'] = 'view';
        } elseif ($t === MIRROR_SETTINGS_TABLE) {
            $row['action'] = 'settings-merge';
            $row['reason'] = 'money and booking-rule keys only';
        } elseif (($deny = mirror_deny_reason($t)) !== null) {
            $row['reason'] = 'DENY: ' . $deny;
        } else {
            $row['action'] = 'replace';
            $row['classified'] = mirror_is_known_data_table($t);
            $row['reason'] = $row['classified'] ? 'data' : 'UNCLASSIFIED: review';
            $row['non_innodb'] = strtolower((string)($tgt[$t]['engine'] ?? '')) !== 'innodb';
        }
        $plan[$t] = $row;
    }
    return $plan;
}

// ---------------------------------------------------------------------------------------------
// Column planning (pure)
// ---------------------------------------------------------------------------------------------

function mirror_is_generated(array $meta): bool
{
    // Only real generated columns (VIRTUAL / STORED / PERSISTENT GENERATED). MySQL 8 also marks every column with an
    // expression default (created_at DEFAULT CURRENT_TIMESTAMP) as DEFAULT_GENERATED: those MUST be copied, or every
    // copied row would get today's date.
    return (bool)preg_match('/\b(virtual|stored|persistent)\b/i', (string)($meta['extra'] ?? ''));
}

/** Columns copied = present on both sides and generated on neither. */
function mirror_plan_columns(array $srcCols, array $tgtCols): array
{
    $out = ['common' => [], 'src_only' => [], 'tgt_only' => [], 'tgt_required_missing' => [], 'type_diff' => [], 'generated' => []];
    foreach ($srcCols as $c => $m) {
        if (!isset($tgtCols[$c])) {
            $out['src_only'][] = $c;
            continue;
        }
        if (mirror_is_generated($m) || mirror_is_generated($tgtCols[$c])) {
            $out['generated'][] = $c;
            continue;
        }
        $out['common'][] = $c;
        if (($m['column_type'] ?? '') !== ($tgtCols[$c]['column_type'] ?? '')) {
            $out['type_diff'][] = $c . ' (' . ($m['column_type'] ?? '?') . ' -> ' . ($tgtCols[$c]['column_type'] ?? '?') . ')';
        }
    }
    foreach ($tgtCols as $c => $m) {
        if (isset($srcCols[$c])) {
            continue;
        }
        $out['tgt_only'][] = $c;
        if (empty($m['nullable']) && ($m['default'] ?? null) === null
            && strpos((string)($m['extra'] ?? ''), 'auto_increment') === false && !mirror_is_generated($m)) {
            $out['tgt_required_missing'][] = $c;
        }
    }
    return $out;
}

function mirror_is_text_type(string $dt): bool
{
    return in_array(strtolower($dt), ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'], true);
}

/** Free-text columns some code matches on (LIKE / strpos): only names, emails and phones are swapped. */
function mirror_logic_columns(): array
{
    return ['gym_inquiries.notes', 'credit_notes.reason_notes', 'stock_shift_closes.notes'];
}

/**
 * Category for one column. Non-text types are never touched ('none').
 * Identity: name, name_first, name_last, staff, company, email, phone, contact, address, idnum, ip.
 * Text: freetext (placeholder unless system wording), scrub (identity map replace only), keep.
 */
function mirror_classify_column(string $table, string $col, array $meta): string
{
    if (!mirror_is_text_type((string)($meta['data_type'] ?? ''))) {
        return 'none';
    }
    $t = strtolower($table);
    $c = strtolower($col);

    if (preg_match('/(^|_)ip(_address)?$/', $c)) {
        return 'ip';
    }
    if (preg_match('/(^|_)(email|emails|e_mail|email_address|email_addresses)$/', $c)) {
        return 'email';
    }
    if (preg_match('/(^|_)(phone|phones|telephone|tel|mobile|cell|cellphone|whatsapp|msisdn|fax)(_number|_no|_num)?$/', $c)
        || preg_match('/(^|_)contact_(number|no)$/', $c)) {
        return 'phone';
    }
    if (preg_match('/(^|_)(passport|passport_number|passport_no|national_id|national_id_number|id_number|id_no|id_num|id_document|id_doc|id_card|id_card_number|identity_number|nrc|nrc_number|drivers_licen[cs]e|driving_licen[cs]e|licen[cs]e_number|vehicle_reg|vehicle_registration|vehicle_number|number_plate|plate_number|tpin|tin|tin_number|tax_id|vat_number|vat_reg_number|vat_registration_number)$/', $c)) {
        return 'idnum';
    }
    if (preg_match('/(^|_)(address|addresses|address_line_?[12]|street|street_address|postal_address|physical_address|home_address|billing_address|residential_address|city|town|postcode|postal_code|zip|zip_code|po_box)$/', $c)) {
        return 'address';
    }
    if (preg_match('/(^|_)(company|company_name|organisation|organization|organisation_name|organization_name|business_name|employer|firm|agency|agency_name|corporate_name|institution|institution_name)$/', $c)) {
        return 'company';
    }
    if ($c === 'paid_by') {
        return 'name';
    }
    if (preg_match('/(^|_)(staff|admin|user|cashier|waiter|waitress|server|bartender|operator|clerk|employee|actor|approver|receiver|recorder|housekeeper|technician|attendant|instructor|trainer|coach)(_full)?_name$/', $c)
        || preg_match('/^(username|user_name|staff_name|admin_name|admin_full_name|actor|assigned_to|handled_by|served_by|instructor|trainer)$/', $c)
        || preg_match('/_by_name$/', $c)
        || preg_match('/_by$/', $c)) {
        return 'staff';
    }
    if (preg_match('/^(first_name|firstname|given_name|forename)$|_first_name$/', $c)) {
        return 'name_first';
    }
    if (preg_match('/^(last_name|lastname|surname|family_name)$|_(last_name|surname)$/', $c)) {
        return 'name_last';
    }
    if (preg_match('/(^|_)(guest|customer|client|contact|member|attendee|organi[sz]er|booker|payer|billing|recipient|visitor|delegate|patron|travell?er|holder|occupant|room_guest|emergency_contact|next_of_kin|guardian|parent|spouse|partner|bill_to|billed_to|received_from|account_holder|card_holder|cardholder|tenant|participant)_?(full_)?names?$/', $c)
        || preg_match('/^(full_name|fullname|guest|customer|client|attendee|organiser|organizer|booker|payer|received_from|bill_to|billed_to|attention|attn|contact_person|names|issued_to|paid_to|refund_to|refunded_to|invoice_to|sold_to|account_name|additional_guests|guest_list|occupants|companions)$/', $c)) {
        return 'name';
    }
    if ($c === 'name'
        && preg_match('/(guest|customer|client|member|inquir|enquir|contact|attendee|visitor|delegate|booker|patron|subscriber|participant|enrol)/', $t)
        && !preg_match('/(_packages?|_types?|_plans?|_classes|_tiers?|_levels?|_categor(y|ies)|settings|templates?)$/', $t)) {
        return 'name';
    }
    if (preg_match('/(^|_)(recipient|recipients|sent_to|send_to|to_address|contact|contact_info|contact_details|contact_value|reply_to|emergency_contact)$/', $c)
        || preg_match('/^(to|cc|bcc)$/', $c)) {
        return 'contact';
    }
    // Numbering rows hold sequence names and scopes only (no people); they must copy unchanged.
    if ($t === 'finance_sequences') {
        return 'keep';
    }
    if (in_array($t . '.' . $c, mirror_logic_columns(), true)) {
        return 'scrub';
    }
    if (preg_match('/(^|_)(id|uuid|guid|hash|token|key|code|ref|reference|number|no|num|status|state|type|kind|method|mode|currency|channel|source|prefix|sku|barcode|slug|role|level|category|unit|colou?r|icon|lang|locale|country|nationality|gender|title|salutation|action|event|field|field_name|column_name|entity|table_name|model|version|format|provider|gateway|path|url|file|filename|image|photo|mime|extension|user_agent|day|days|time|date)$/', $c)) {
        return 'keep';
    }
    // Stock tables are not about people, except the POS orders (stock_orders / items / splits) that carry customers.
    $stockOnly = (bool)preg_match('/^stock_/', $t) && !preg_match('/^stock_order(s|_items|_splits)$/', $t);
    $people = $stockOnly ? '/(?!)/' : '/(booking|payment|invoice|receipt|credit|refund|folio|account|cancel|quotation|deposit|finance|inquir|enquir|guest|customer|client|member|conference|event|gym|tentative|contact|order|tab|room_service|reservation|enrol)/';
    $finance = $stockOnly ? '/(?!)/' : '/(booking|payment|invoice|receipt|credit|refund|folio|account|cancel|quotation|deposit|finance|inquir|enquir|reservation|tentative)/';
    $lines = '/(charges?|items?|lines?)$/';
    $typed = '/(^|_)(special_requests?|requests?|notes?|note_text|remarks?|comments?|message|messages|feedback|dietary|dietary_requirements|dietary_notes|allergies|preferences|instructions|special_instructions|additional_info|additional_information|extra_info|other_info|other_requests|requirements|purpose|reason|cancellation_reason|cancel_reason|reason_notes|discount_reason|override_reason|void_reason|refund_reason|adjustment_reason|delete_reason|deletion_reason|internal_notes|admin_notes|guest_notes|staff_notes|checkin_note|checkout_note|tentative_notes|refund_notes|change_reason|action_reason|event_note|medical_conditions|medical_notes|health_notes|health_conditions|medical|health)$/';
    if (preg_match('/(audit|timeline|history|_log$|_logs$|_events$|activity|_changes?$|_trail$)/', $t)) {
        // History tables: typed notes / reasons follow the free-text rule; old->new values, JSON and
        // generated descriptions keep their wording with every known name / email / phone swapped.
        return (preg_match($typed, $c) && preg_match($people, $t)) ? 'freetext' : 'scrub';
    }
    if (strtolower((string)$meta['data_type']) === 'json') {
        return 'scrub';
    }
    // Definition tables (packages, types, plans, classes, categories, hours, room combinations) describe
    // the product, not a person: wording kept, typed notes only get the identity swap.
    if (preg_match('/(_packages?|_types?|_plans?|_classes|_tiers?|_levels?|_categor(y|ies)|_hours|_templates?|^rate_plans|^room_combinations)$/', $t)) {
        return preg_match($typed, $c) ? 'scrub' : 'keep';
    }
    if (preg_match('/(^|_)(special_requests?|requests?|notes?|note_text|remarks?|comments?|message|messages|feedback|dietary|dietary_requirements|dietary_notes|allergies|preferences|instructions|special_instructions|additional_info|additional_information|extra_info|other_info|other_requests|requirements|purpose|cancellation_reason|cancel_reason|reason_notes|discount_reason|override_reason|void_reason|refund_reason|adjustment_reason|delete_reason|deletion_reason|internal_notes|admin_notes|guest_notes|staff_notes|checkin_note|checkout_note|tentative_notes|refund_notes|change_reason|action_reason|event_note|medical_conditions|medical_notes|health_notes|health_conditions|medical|health)$/', $c)) {
        return preg_match($people, $t) ? 'freetext' : 'scrub';
    }
    if (preg_match('/(^|_)(reason|description|details|detail|summary|memo|narration|narrative|particulars)$/', $c)) {
        if (preg_match($finance, $t) && !preg_match($lines, $t)) {
            return 'freetext';
        }
        return preg_match($people, $t) ? 'scrub' : 'keep';
    }
    // If unsure: any other text in a people / money table keeps its wording with known identities swapped.
    // Definition tables (packages, types, plans, classes, categories, hours) and plain labels stay as they are.
    if (preg_match('/(_packages?|_types?|_plans?|_classes|_tiers?|_levels?|_categor(y|ies)|_hours|_templates?)$/', $t)
        || in_array($c, ['name', 'label', 'display_name', 'short_name'], true)) {
        return 'keep';
    }
    return preg_match($people, $t) ? 'scrub' : 'keep';
}

/** col => category for every text column (including 'keep', so the dry run can list them). */
function mirror_plan_pii(string $table, array $cols, array $meta): array
{
    $out = [];
    foreach ($cols as $c) {
        $cat = mirror_classify_column($table, $c, $meta[$c] ?? []);
        if ($cat !== 'none') {
            $out[$c] = $cat;
        }
    }
    return $out;
}

function mirror_identity_categories(): array
{
    return ['name', 'name_first', 'name_last', 'staff', 'company', 'email', 'phone', 'contact', 'address', 'idnum'];
}

/**
 * UNIQUE indexes on Liwonde that include a column whose fakes can repeat (names, staff, ip, text):
 * two guests could get the same fake and the insert would fail. Email, phone, company, address
 * and ID fakes never repeat, so those are fine.
 */
function mirror_unique_collisions(array $uniqueIdx, array $pii): array
{
    $risky = ['name', 'name_first', 'name_last', 'staff', 'ip', 'freetext', 'scrub', 'contact'];
    $out = [];
    foreach ($uniqueIdx as $idx => $cols) {
        foreach ($cols as $c) {
            if (in_array($pii[$c] ?? '', $risky, true)) {
                $out[] = $idx . ' (' . implode(', ', $cols) . ')';
                break;
            }
        }
    }
    return $out;
}

/** Numbering settings shown side by side (both hotels keep their own). */
function mirror_numbering_keys(): array
{
    return ['document_number_prefix', 'invoice_prefix', 'invoice_start_number', 'receipt_prefix', 'receipt_start_number',
        'credit_note_prefix', 'credit_note_start_number', 'refund_prefix', 'refund_start_number', 'booking_reference_prefix'];
}

/** Changing categories only (drops keep). */
function mirror_active_plan(array $pii): array
{
    return array_filter($pii, static function ($cat) {
        return $cat !== 'keep' && $cat !== 'none';
    });
}

/** Identity pre-pass order: bookings first, then tables holding emails with names, then phones, audit/logs last. */
function mirror_prepass_order(array $plans): array
{
    $score = [];
    foreach ($plans as $t => $pii) {
        $cats = array_values($pii);
        $s = 50;
        if ($t === 'bookings') {
            $s = 0;
        } elseif (in_array('email', $cats, true) && (in_array('name', $cats, true) || in_array('name_first', $cats, true))) {
            $s = 10;
        } elseif (in_array('email', $cats, true) || in_array('phone', $cats, true)) {
            $s = 20;
        } elseif (preg_match('/(audit|timeline|history|_log$|_logs$|_events$)/', $t)) {
            $s = 90;
        }
        $score[$t] = sprintf('%02d|%s', $s, $t);
    }
    asort($score, SORT_STRING);
    return array_keys($score);
}

// ---------------------------------------------------------------------------------------------
// Settings (pure)
// ---------------------------------------------------------------------------------------------

/** Money and booking-rule keys copied from Rosalyn's (built from getSetting() keys in the code). */
function mirror_settings_allowlist(): array
{
    return [
        'vat_enabled', 'vat_rate', 'vat_pricing_mode',
        'tourism_levy_enabled', 'tourism_levy_percent',
        'currency_code', 'currency_symbol',
        'restaurant_tax_pct', 'restaurant_service_charge_pct',
        'child_guest_price_multiplier', 'booking_child_price_multiplier',
        'credit_note_expiry_months', 'refund_method_default', 'guest_self_cancel_refund', 'nonroom_cancel_mode',
        'cancellation_notice_days', 'cancellation_refund_mode', 'no_show_refund_policy',
        'check_in_time', 'check_out_time',
        'max_advance_booking_days', 'booking_time_buffer_minutes',
        'tentative_bookings_enabled', 'tentative_duration_hours', 'tentative_reminder_hours',
        'pending_expiry_hours', 'unpaid_confirmed_release_hours', 'unpaid_confirmed_reminder_hours',
        'pending_duration_hours', 'tentative_enabled', 'tentative_hold_hours', 'tentative_grace_period_hours',
        'tentative_max_extensions', 'tentative_deposit_required', 'tentative_deposit_percent', 'tentative_block_availability',
        'stock_variance_min_cost', 'stock_variance_block_pct', 'stock_variance_alert_pct',
    ];
}

/** Liwonde keeps every identity / channel / numbering key even if a pattern below would match. */
function mirror_settings_identity_key(string $key): bool
{
    return (bool)preg_match('/(^site_|^hotel_|email|phone|whatsapp|smtp|facebook|twitter|instagram|linkedin|social|logo|brand|address|_prefix$|_start_number$|^bank_|licen[cs]e|_key$|token|secret|password|^api_|^ai_|vat_number|timezone|tagline|hero|footer|seo|google|maps|module|_system_enabled$|business_preset|maintenance|document_|invoice_|receipt_|quotation_|_template|policy$|terms)/', strtolower($key))
        && !in_array(strtolower($key), ['no_show_refund_policy'], true);
}

function mirror_settings_key_allowed(string $key): bool
{
    $k = strtolower(trim($key));
    if ($k === '' || mirror_settings_identity_key($k)) {
        return false;
    }
    if (in_array($k, mirror_settings_allowlist(), true)) {
        return true;
    }
    return (bool)preg_match('/^(deposit_|min_stay|max_stay|min_nights|max_nights|cancellation_(notice|refund|fee|charge|window|hours|days)|no_show_(fee|charge)|late_checkout_(fee|charge)|early_checkin_(fee|charge))/', $k);
}

/** Keys set to '0' on Liwonde after the copy (upsert: missing scheduler keys default to ON). */
function mirror_mute_keys(array $targetKeys): array
{
    $keys = [
        'automated_email_master',
        'automated_email_job_overdue', 'automated_email_job_quotation', 'automated_email_job_tentative',
        'automated_email_job_tentative_expired', 'automated_email_job_pending_expired',
        'automated_email_job_unpaid_reminder', 'automated_email_job_unpaid_release',
        'automated_job_daily_reports', 'automated_job_station_reports', 'automated_job_stock_expiry',
        'automated_job_recurring_maintenance',
        'booking_prearrival_reminder_enabled', 'booking_poststay_review_enabled', 'gym_reminder_enabled',
        'whatsapp_enabled', 'whatsapp_notifications_enabled', 'whatsapp_guest_notifications', 'whatsapp_hotel_notifications',
        'whatsapp_notify_on_booking', 'whatsapp_notify_on_confirmation', 'whatsapp_notify_on_cancellation',
        'whatsapp_notify_on_checkin', 'whatsapp_notify_on_checkout',
        'restaurant_whatsapp_enabled',
        'facebook_posting_enabled', 'facebook_rooms_enabled', 'facebook_menu_enabled', 'facebook_gym_enabled',
        'facebook_events_enabled', 'facebook_conference_enabled',
    ];
    foreach ($targetKeys as $k) {
        $k = (string)$k;
        if (preg_match('/_log_enabled$/', $k)) {
            continue;
        }
        if (preg_match('/^(whatsapp_[a-z_]*(enabled|notifications)|whatsapp_notify_on_[a-z_]+|facebook_[a-z_]*_enabled|automated_email_job_[a-z_]+)$/', $k)) {
            $keys[] = $k;
        }
    }
    $keys = array_values(array_unique($keys));
    sort($keys, SORT_STRING);
    return $keys;
}

/** Copy the source's sql_mode to the target session and keep explicit id 0 rows as 0. */
function mirror_target_sql_mode(string $srcMode): string
{
    $parts = array_filter(array_map('trim', explode(',', $srcMode)), 'strlen');
    if (!in_array('NO_AUTO_VALUE_ON_ZERO', $parts, true)) {
        $parts[] = 'NO_AUTO_VALUE_ON_ZERO';
    }
    return implode(',', $parts);
}

// ---------------------------------------------------------------------------------------------
// Finance sequences (pure)
// ---------------------------------------------------------------------------------------------

/** 'receipt:RCP' -> 'RCP'; 'invoice:room_INV' -> 'INV'; 'invoice:conference_CONF-INV' -> 'CONF-INV'. */
function mirror_sequence_prefix_from_name(string $name): ?string
{
    $p = strpos($name, ':');
    if ($p === false) {
        return null;
    }
    $kind = substr($name, 0, $p);
    $frag = substr($name, $p + 1);
    if ($kind === 'invoice') {
        $u = strpos($frag, '_');
        return $u === false ? $frag : substr($frag, $u + 1);
    }
    return $frag;
}

/**
 * Sequence rows whose next_number must move past the copied document numbers.
 * $docPrefix = the source's document_number_prefix ('' when unset).
 */
function mirror_sequence_bumps(array $seqRows, array $docNumbers, string $docPrefix = ''): array
{
    $out = [];
    $hp = $docPrefix !== '' ? '(?:' . preg_quote($docPrefix, '/') . '-)?' : '';
    foreach ($seqRows as $r) {
        $prefix = mirror_sequence_prefix_from_name((string)($r['sequence_name'] ?? ''));
        $scope = (string)($r['sequence_scope'] ?? '');
        if ($prefix === null || $prefix === '' || !preg_match('/^\d{4}$/', $scope)) {
            continue;
        }
        $re = '/^' . $hp . preg_quote($prefix, '/') . '-?' . preg_quote($scope, '/') . '-?(\d{1,9})$/';
        $max = 0;
        foreach ($docNumbers as $d) {
            if (preg_match($re, (string)$d, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        $cur = (int)($r['next_number'] ?? 1);
        if ($max > 0 && $max + 1 > $cur) {
            $out[] = ['sequence_name' => (string)$r['sequence_name'], 'sequence_scope' => $scope, 'from' => $cur, 'to' => $max + 1];
        }
    }
    return $out;
}

/** table => columns holding document numbers that finance_sequences hands out. */
function mirror_document_columns(): array
{
    return [
        'payments' => ['receipt_number', 'invoice_number', 'payment_reference'],
        'bookings' => ['final_invoice_number'],
        'credit_notes' => ['credit_note_number'],
    ];
}

// ---------------------------------------------------------------------------------------------
// Anonymiser (pure, deterministic)
// ---------------------------------------------------------------------------------------------

function mirror_first_names(): array
{
    return ['Chikondi', 'Tadala', 'Thoko', 'Kondwani', 'Mphatso', 'Chisomo', 'Limbani', 'Tiyamike', 'Dalitso', 'Yamikani',
        'Madalitso', 'Pemphero', 'Takondwa', 'Tamanda', 'Chimwemwe', 'Wezi', 'Kumbukani', 'Mwayi', 'Chifundo', 'Tawonga',
        'Zikomo', 'Luso', 'Mayeso', 'Khumbo', 'Thandiwe', 'Atupele', 'Lusungu', 'Chawezi', 'Temwa', 'Kettie',
        'Alinafe', 'Mtendere', 'Ulemu', 'Fatsani', 'Taonga', 'Wongani', 'Kayembe', 'Chiyembekezo', 'Mercy', 'Blessings'];
}

function mirror_last_names(): array
{
    return ['Banda', 'Phiri', 'Mwale', 'Kalonga', 'Nyirenda', 'Gondwe', 'Kumwenda', 'Mhango', 'Mkandawire', 'Kamanga',
        'Msiska', 'Mlotha', 'Mbewe', 'Zulu', 'Tembo', 'Lungu', 'Moyo', 'Kachale', 'Chisale', 'Mussa',
        'Jere', 'Ngwira', 'Chavula', 'Chimwaza', 'Nkhoma', 'Mvula', 'Chikopa', 'Kalua', 'Mtambo', 'Kanyenda',
        'Munthali', 'Kaphuka', 'Mlenga', 'Soko', 'Chipeta', 'Kapito', 'Makwinja', 'Ndovi', 'Sakala', 'Chibwe'];
}

/** Values in name-like columns that are not a person (kept as they are). */
function mirror_is_system_value(string $v): bool
{
    $v = trim($v);
    if ($v === '') {
        return true;
    }
    return (bool)preg_match('/^(walk[- ]?in( guest| customer)?|guests?|n\/?a|none|nil|null|unknown|anonymous|-+|\.+|staff|house( account| use)?|hotel|in[- ]?house|system|admin|administrator|api|scheduler|auto(mated)?|cron|online|website|web|pos|kds|bar|restaurant|kitchen|reception|front ?desk|cash( customer| sale)?|card|mobile money|airtel money|tnm mpamba|bank( transfer)?|complimentary|comp|owner|management|manager|test\b.*|demo\b.*|sample\b.*)$/i', $v);
}

function mirror_anon_init(string $salt): array
{
    return [
        'salt' => $salt,
        'name' => [], 'name_key' => [], 'staff' => [], 'company' => [], 'addr' => [], 'idnum' => [],
        'email' => [], 'phone' => [], 'num' => [],
        'replace' => [], 'tokens' => [], 'pairs' => null,
    ];
}

function mirror_hash_int(array $st, string $kind, string $key): int
{
    return (int)hexdec(substr(hash_hmac('sha256', $kind . '|' . $key, (string)$st['salt']), 0, 12));
}

/** Hash-derived number, bumped on collision so two different originals never share a number. */
function mirror_numbered(array &$st, string $kind, string $key, int $min, int $mod): int
{
    if (isset($st['num'][$kind]['k'][$key])) {
        return $st['num'][$kind]['k'][$key];
    }
    $n = $min + (mirror_hash_int($st, $kind, $key) % $mod);
    while (isset($st['num'][$kind]['n'][$n]) && $st['num'][$kind]['n'][$n] !== $key) {
        $n++;
    }
    $st['num'][$kind]['k'][$key] = $n;
    $st['num'][$kind]['n'][$n] = $key;
    return $n;
}

function mirror_norm_text(string $v): string
{
    $v = trim((string)preg_replace('/\s+/u', ' ', $v));
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
}

/** Last 9 digits: the same number written 0999…, +265 999…, 265999… gets one key. */
function mirror_phone_key(string $v): string
{
    $d = (string)preg_replace('/\D/', '', $v);
    return strlen($d) > 9 ? substr($d, -9) : $d;
}

function mirror_register(array &$st, string $orig, string $fake): void
{
    $orig = trim($orig);
    if ($orig === '' || $orig === $fake || strlen($orig) < 4 || isset($st['replace'][$orig])) {
        return;
    }
    $st['replace'][$orig] = $fake;
    $st['pairs'] = null;
}

function mirror_token_stoplist(): array
{
    return ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof', 'Rev', 'Hon', 'Sir', 'Madam', 'Room', 'Rooms', 'Guest', 'Guests',
        'Hotel', 'The', 'And', 'Test', 'Walk', 'House', 'Company', 'Ltd', 'Limited', 'Group', 'Family', 'Team', 'Staff',
        'Booking', 'Payment', 'Refund', 'Invoice', 'Receipt', 'Cash', 'Card', 'Bank', 'Mobile', 'Money', 'Deluxe',
        'Standard', 'Executive', 'Suite', 'Single', 'Double', 'Twin', 'Family', 'Junior', 'Senior', 'Malawi'];
}

/** Pair the first and last word of an original name with the fake name's, for surname-only mentions in text. */
function mirror_register_tokens(array &$st, string $orig, string $fake): void
{
    $o = preg_split('/\s+/u', trim($orig)) ?: [];
    $f = preg_split('/\s+/u', trim($fake)) ?: [];
    if (!$o || !$f) {
        return;
    }
    $pairs = [[$o[0], $f[0]]];
    if (count($o) > 1 && count($f) > 1) {
        $pairs[] = [$o[count($o) - 1], $f[count($f) - 1]];
    }
    $stop = mirror_token_stoplist();
    foreach ($pairs as [$a, $b]) {
        if (!preg_match('/^\p{Lu}[\p{L}\'’-]{2,}$/u', $a) || in_array($a, $stop, true) || $a === $b || isset($st['tokens'][$a])) {
            continue;
        }
        $st['tokens'][$a] = $b;
        $st['pairs'] = null;
    }
}

function mirror_gen_name(array $st, string $key): string
{
    $first = mirror_first_names();
    $last = mirror_last_names();
    $h = mirror_hash_int($st, 'name', $key);
    return $first[$h % count($first)] . ' ' . $last[intdiv($h, count($first)) % count($last)];
}

function mirror_title_split(string $v): array
{
    if (preg_match('/^((?:(?:mr|mrs|ms|miss|dr|prof|rev|hon|sir|madam|mx)\.?\s+(?:&|and)\s+)?(?:mr|mrs|ms|miss|dr|prof|rev|hon|sir|madam|mx)\.?\s+)/i', $v, $m)) {
        return [$m[1], trim(substr($v, strlen($m[1])))];
    }
    return ['', trim($v)];
}

/**
 * Fake person name. $identityKey = 'e:<email>' / 'p:<phone>' / 't:<table>:<pk>'.
 * Same email or phone -> same fake; a name already seen -> the same fake; titles are kept.
 * $part: 'full', 'first' or 'last'.
 */
function mirror_fake_name(array &$st, string $orig, string $identityKey, string $part = 'full'): string
{
    if (mirror_is_system_value($orig)) {
        return $orig;
    }
    // Several people in one field ("A Banda, B Phiri") -> each one separately.
    if ($part === 'full' && preg_match('/[,;\n]/', $orig)) {
        $bits = preg_split('/([,;\n]\s*)/', $orig, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($bits as $i => $b) {
            $out .= ($i % 2 === 1 || trim($b) === '') ? $b : mirror_fake_name($st, $b, $i === 0 ? $identityKey : '', 'full');
        }
        return $out;
    }
    [$title, $bare] = mirror_title_split($orig);
    if ($bare === '') {
        return $orig;
    }
    $norm = mirror_norm_text($bare);
    if ($part !== 'full') {
        $key = $identityKey !== '' ? $identityKey : 'n:' . $norm;
        $full = $st['name_key'][$key] ?? mirror_gen_name($st, $key);
        $st['name_key'][$key] = $full;
        $w = explode(' ', $full);
        $fake = $part === 'first' ? $w[0] : $w[count($w) - 1];
        mirror_register_tokens($st, $bare, $fake);
        return $title . $fake;
    }
    $fake = null;
    if ($identityKey !== '' && isset($st['name_key'][$identityKey])) {
        $fake = $st['name_key'][$identityKey];
    } elseif (isset($st['name'][$norm])) {
        $fake = $st['name'][$norm];
    }
    if ($fake === null) {
        $fake = mirror_gen_name($st, $identityKey !== '' ? $identityKey : 'n:' . $norm);
    }
    if (!isset($st['name'][$norm])) {
        $st['name'][$norm] = $fake;
    }
    if ($identityKey !== '' && !isset($st['name_key'][$identityKey])) {
        $st['name_key'][$identityKey] = $fake;
    }
    if (strpos($bare, ' ') !== false) {
        mirror_register($st, $bare, $fake);
    }
    mirror_register_tokens($st, $bare, $fake);
    return $title . $fake;
}

function mirror_fake_staff(array &$st, string $orig): string
{
    if (mirror_is_system_value($orig) || ctype_digit(trim($orig))) {
        return $orig;
    }
    [$title, $bare] = mirror_title_split($orig);
    $norm = mirror_norm_text($bare);
    if (!isset($st['staff'][$norm])) {
        $st['staff'][$norm] = mirror_gen_name($st, 'staff:' . $norm);
    }
    $fake = $st['staff'][$norm];
    if (strpos($bare, ' ') !== false) {
        mirror_register($st, $bare, $fake);
    }
    mirror_register_tokens($st, $bare, $fake);
    return $title . $fake;
}

function mirror_fake_email(array &$st, string $orig): string
{
    $v = trim($orig);
    if ($v === '' || preg_match('/@example\.invalid$/i', $v) || (strpos($v, '@') === false && mirror_is_system_value($v))) {
        return $orig;
    }
    if (preg_match('/[,;\s]/', $v) && substr_count($v, '@') > 1) {
        return (string)preg_replace_callback('/[^,;\s]+/', static function ($m) use (&$st) {
            return mirror_fake_email($st, $m[0]);
        }, $v);
    }
    $key = strtolower($v);
    if (!isset($st['email'][$key])) {
        $st['email'][$key] = 'guest' . mirror_numbered($st, 'email', $key, 100000, 900000) . '@example.invalid';
    }
    $fake = $st['email'][$key];
    mirror_register($st, $v, $fake);
    if ($key !== $v) {
        mirror_register($st, $key, $fake);
    }
    return $fake;
}

function mirror_fake_phone(array &$st, string $orig): string
{
    $v = trim($orig);
    if (strpos($v, '+265 000 ') === 0) {
        return $orig;
    }
    if (preg_match('#[,;/]#', $v)) {
        return (string)preg_replace_callback('#[^,;/]+#', static function ($m) use (&$st) {
            $p = $m[0];
            $lead = (string)preg_replace('/^(\s*).*$/s', '$1', $p);
            return trim($p) === '' ? $p : $lead . mirror_fake_phone($st, trim($p));
        }, $v);
    }
    $key = mirror_phone_key($v);
    if (strlen($key) < 5) {
        return $orig;
    }
    if (!isset($st['phone'][$key])) {
        $n = mirror_numbered($st, 'phone', $key, 0, 1000000);
        $st['phone'][$key] = '+265 000 ' . str_pad((string)$n, 6, '0', STR_PAD_LEFT);
    }
    $fake = $st['phone'][$key];
    $digits = (string)preg_replace('/\D/', '', $v);
    foreach ([$v, $digits, '0' . $key, '+265' . $key, '265' . $key, '+265 ' . $key] as $variant) {
        if (strlen($variant) >= 7) {
            mirror_register($st, $variant, $fake);
        }
    }
    return $fake;
}

function mirror_fake_company(array &$st, string $orig): string
{
    if (mirror_is_system_value($orig)) {
        return $orig;
    }
    $norm = mirror_norm_text($orig);
    if (!isset($st['company'][$norm])) {
        $st['company'][$norm] = 'Test Company ' . str_pad((string)mirror_numbered($st, 'company', $norm, 1, 999), 3, '0', STR_PAD_LEFT);
    }
    mirror_register($st, trim($orig), $st['company'][$norm]);
    return $st['company'][$norm];
}

function mirror_fake_address(array &$st, string $orig): string
{
    if (mirror_is_system_value($orig)) {
        return $orig;
    }
    $norm = mirror_norm_text($orig);
    if (!isset($st['addr'][$norm])) {
        $st['addr'][$norm] = 'Test address ' . mirror_numbered($st, 'addr', $norm, 1, 99999);
    }
    mirror_register($st, trim($orig), $st['addr'][$norm]);
    return $st['addr'][$norm];
}

function mirror_fake_idnum(array &$st, string $orig): string
{
    if (mirror_is_system_value($orig)) {
        return $orig;
    }
    $norm = mirror_norm_text($orig);
    if (!isset($st['idnum'][$norm])) {
        $st['idnum'][$norm] = 'TESTID ' . mirror_numbered($st, 'idnum', $norm, 1, 99999);
    }
    mirror_register($st, trim($orig), $st['idnum'][$norm]);
    return $st['idnum'][$norm];
}

function mirror_fake_ip(array $st, string $orig): string
{
    return '192.0.2.' . (1 + mirror_hash_int($st, 'ip', trim($orig)) % 254);
}

/** recipient / contact columns: email, phone or name, decided by the value. */
function mirror_fake_contact(array &$st, string $orig, string $identityKey): string
{
    if (strpos($orig, '@') !== false) {
        return mirror_fake_email($st, $orig);
    }
    if (strlen((string)preg_replace('/\D/', '', $orig)) >= 7) {
        return mirror_fake_phone($st, $orig);
    }
    return mirror_fake_name($st, $orig, $identityKey);
}

/** Original -> marker pairs (longest first via strtr), including the JSON-escaped spellings. */
function mirror_replace_pairs(array &$st): array
{
    if (is_array($st['pairs'])) {
        return $st['pairs'];
    }
    $pairs = [];
    $fakes = [];
    $i = 0;
    foreach ($st['replace'] as $orig => $fake) {
        $marker = "\x01" . $i . "\x02";
        $fakes[$marker] = $fake;
        $pairs[(string)$orig] = $marker;
        foreach ([json_encode((string)$orig), json_encode((string)$orig, JSON_UNESCAPED_UNICODE)] as $j) {
            if (is_string($j)) {
                $j = substr($j, 1, -1);
                if ($j !== (string)$orig && $j !== '' && !isset($pairs[$j])) {
                    $pairs[$j] = $marker;
                }
            }
        }
        $i++;
    }
    $st['pairs'] = ['map' => $pairs, 'fakes' => $fakes];
    return $st['pairs'];
}

/**
 * Swap every known original (names, emails, phones, companies, addresses, ids) for its fake,
 * then surname/first-name mentions, then any other email address or phone number.
 * Amounts, dates, statuses and document numbers are left as they are.
 */
function mirror_scrub(array &$st, $text)
{
    if ($text === null) {
        return null;
    }
    $s = (string)$text;
    if (trim($s) === '') {
        return $s;
    }
    $p = mirror_replace_pairs($st);
    if ($p['map']) {
        $s = strtr($s, $p['map']);
    }
    if ($st['tokens']) {
        $tokens = $st['tokens'];
        $r = preg_replace_callback('/(?<![\p{L}\p{N}_@.])\p{Lu}[\p{L}\'’-]{2,}(?![\p{L}\p{N}_])/u', static function ($m) use ($tokens) {
            return $tokens[$m[0]] ?? $m[0];
        }, $s);
        if (is_string($r)) {
            $s = $r;
        }
    }
    $r = preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', static function ($m) use (&$st) {
        return mirror_fake_email($st, $m[0]);
    }, $s);
    $s = is_string($r) ? $r : $s;
    foreach (['/(?<![\w.\/:+\-])\+\d{2,3}(?:[ \-]?\d){6,11}(?![\w.\/:\-])/', '/(?<![\w.\/:+\-])0[1-9](?: ?\d){7,8}(?![\w.\/:\-])/'] as $re) {
        $r = preg_replace_callback($re, static function ($m) use (&$st) {
            return mirror_fake_phone($st, $m[0]);
        }, $s);
        $s = is_string($r) ? $r : $s;
    }
    if ($p['fakes']) {
        $s = strtr($s, $p['fakes']);
    }
    return $s;
}

/** Clearly system-generated wording with no personal detail left (after scrub). If unsure: false. */
function mirror_is_system_wording(string $s): bool
{
    $s = trim($s);
    if ($s === '') {
        return true;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    if ($len > 160) {
        return false;
    }
    if (preg_match('/\b(mr|mrs|ms|miss|dr|madam|sir)\b\.?\s+\p{Lu}/iu', $s)) {
        return false;
    }
    if (preg_match('/(?<!\d)0[1-9](?: ?\d){7,8}(?!\d)/', $s)) {
        return false;
    }
    return (bool)preg_match('/^(?:\[[^\]]{1,40}\]\s*)?(auto(matic|matically)?|system|payment|paid|refund(ed)?|booking|status|room|invoice|receipt|credit note|deposit|balance|checked[- ]?(in|out)|check[- ]?(in|out)|cancel+(ed|ation)?|no[- ]?show|created|updated|changed|moved|extended|shortened|split|merged|converted|void(ed)?|reversed|overpayment|tourism levy|vat|rate|price|discount|charge|added|removed|assigned|released|expired|confirmed|tentative|pending|imported|migrated|transfer(red)?|settle(d|ment)?|posted|closed|opened|override|correction|adjust(ed|ment)?|stock|order|pos|kds|restaurant|gym|conference|event|quotation|walk[- ]?in)\b/i', $s);
}

/** Markers code matches on (member tags, OVERRIDE, document numbers) survive the placeholder. */
function mirror_preserve_tokens(string $s): array
{
    $keep = [];
    $patterns = [
        '/\[[A-Za-z_]+:[^\]\r\n]{1,60}\]/',
        '/\bon payment [A-Z0-9][A-Z0-9\-]{3,}/',
        '/\b(OVERRIDE|VOIDED|VOID|REFUNDED|REFUND|COMPLIMENTARY|COMP|FOC|NO-SHOW|NOSHOW)\b/',
        '/\b(?:[A-Z0-9]{1,6}-)?[A-Z]{2,6}-?\d{4}-?\d{4,8}\b/',
    ];
    foreach ($patterns as $re) {
        if (preg_match_all($re, $s, $m)) {
            foreach ($m[0] as $tok) {
                $keep[$tok] = true;
            }
        }
    }
    return array_keys($keep);
}

function mirror_freetext(array &$st, $text)
{
    if ($text === null) {
        return null;
    }
    $s = (string)$text;
    if (trim($s) === '') {
        return $s;
    }
    $clean = (string)mirror_scrub($st, $s);
    if (mirror_is_system_wording($clean)) {
        return $clean;
    }
    $keep = mirror_preserve_tokens($clean);
    return MIRROR_PLACEHOLDER . ($keep ? ' ' . implode(' ', $keep) : '');
}

/** 'guest_name' -> '', 'contact_email' -> 'contact', 'room_guest_name' -> 'room_guest'. */
function mirror_col_base(string $col): string
{
    $c = strtolower($col);
    $c = (string)preg_replace('/_?(full_)?names?$|_?(email|emails|e_mail)(_address(es)?)?$|_?(phone|telephone|tel|mobile|cell|cellphone|whatsapp|msisdn)(_number|_no|_num)?$|_person$/', '', $c);
    if (in_array($c, ['first', 'last', 'given', 'family', 'sur', 'middle', 'fore', 'guest', 'customer', 'client', 'member', 'primary_guest', 'lead_guest'], true)) {
        return '';
    }
    return $c;
}

/** Who a name belongs to: the row's matching email, else matching phone, else table + primary key. */
function mirror_identity_key(array $row, array $plan, string $nameCol, string $rowKey): string
{
    $base = mirror_col_base($nameCol);
    foreach (['email' => 'e:', 'phone' => 'p:'] as $cat => $tag) {
        foreach ($plan as $c => $k) {
            if ($k !== $cat || mirror_col_base($c) !== $base) {
                continue;
            }
            $v = trim((string)($row[$c] ?? ''));
            if ($v === '' || mirror_is_system_value($v)) {
                continue;
            }
            if ($cat === 'email') {
                $first = (string)preg_split('/[,;\s]+/', $v)[0];
                if (strpos($first, '@') !== false && !preg_match('/@example\.invalid$/i', $first)) {
                    return $tag . strtolower($first);
                }
            } else {
                $pk = mirror_phone_key($v);
                if (strlen($pk) >= 5) {
                    return $tag . $pk;
                }
            }
        }
    }
    return $rowKey;
}

/**
 * Anonymise one row. Only columns in $plan change; amounts, dates, statuses, references, ids and
 * every non-text column are returned untouched. NULL and '' stay NULL and ''.
 */
function mirror_anonymise_row(array &$st, string $table, array $row, array $plan, array $pkCols, array $maxLen = []): array
{
    $pk = [];
    foreach ($pkCols as $c) {
        $pk[] = (string)($row[$c] ?? '');
    }
    $rowKey = 't:' . $table . ':' . ($pkCols ? implode(',', $pk) : md5((string)json_encode($row)));
    foreach ($plan as $col => $cat) {
        if (!array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '') {
            continue;
        }
        $v = (string)$row[$col];
        switch ($cat) {
            case 'name':
                $new = mirror_fake_name($st, $v, mirror_identity_key($row, $plan, $col, $rowKey));
                break;
            case 'name_first':
                $new = mirror_fake_name($st, $v, mirror_identity_key($row, $plan, $col, $rowKey), 'first');
                break;
            case 'name_last':
                $new = mirror_fake_name($st, $v, mirror_identity_key($row, $plan, $col, $rowKey), 'last');
                break;
            case 'staff':
                $new = mirror_fake_staff($st, $v);
                break;
            case 'company':
                $new = mirror_fake_company($st, $v);
                break;
            case 'email':
                $new = mirror_fake_email($st, $v);
                break;
            case 'phone':
                $new = mirror_fake_phone($st, $v);
                break;
            case 'contact':
                $new = mirror_fake_contact($st, $v, $rowKey);
                break;
            case 'address':
                $new = mirror_fake_address($st, $v);
                break;
            case 'idnum':
                $new = mirror_fake_idnum($st, $v);
                break;
            case 'ip':
                $new = mirror_fake_ip($st, $v);
                break;
            case 'freetext':
                $new = (string)mirror_freetext($st, $v);
                break;
            case 'scrub':
                $new = (string)mirror_scrub($st, $v);
                break;
            default:
                continue 2;
        }
        $max = (int)($maxLen[$col] ?? 0);
        if ($max > 0) {
            $len = function_exists('mb_strlen') ? mb_strlen($new, 'UTF-8') : strlen($new);
            if ($len > $max) {
                $new = function_exists('mb_substr') ? mb_substr($new, 0, $max, 'UTF-8') : substr($new, 0, $max);
            }
        }
        $row[$col] = $new;
    }
    return $row;
}

/** Multi-row INSERT with placeholders only. */
function mirror_insert_sql(string $table, array $cols, int $rows): string
{
    $one = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    return 'INSERT INTO ' . mirror_qi($table) . ' (' . implode(', ', array_map('mirror_qi', $cols)) . ') VALUES '
        . implode(', ', array_fill(0, max(1, $rows), $one));
}

function mirror_batch_rows(int $colCount): int
{
    return max(1, min(200, intdiv(60000, max(1, $colCount))));
}

// ---------------------------------------------------------------------------------------------
// Runner pieces that touch the databases
// ---------------------------------------------------------------------------------------------

function mirror_is_int_type(string $dt): bool
{
    return in_array(strtolower($dt), ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true);
}

/** Read a source table in primary-key chunks (keyset for a single integer key). */
function mirror_iter_rows(callable $srcQ, string $table, array $cols, array $pkCols, bool $intPk, int $chunk = 1000): Generator
{
    $sel = implode(', ', array_map('mirror_qi', $cols));
    $t = mirror_qi($table);
    $pkOk = $pkCols && !array_diff($pkCols, $cols);
    if ($pkOk && count($pkCols) === 1 && $intPk) {
        $pk = mirror_qi($pkCols[0]);
        $last = null;
        while (true) {
            if ($last === null) {
                $rows = $srcQ("SELECT $sel FROM $t ORDER BY $pk LIMIT ?", [$chunk])->fetchAll();
            } else {
                $rows = $srcQ("SELECT $sel FROM $t WHERE $pk > ? ORDER BY $pk LIMIT ?", [$last, $chunk])->fetchAll();
            }
            foreach ($rows as $r) {
                yield $r;
            }
            if (count($rows) < $chunk) {
                return;
            }
            $last = (string)$rows[count($rows) - 1][$pkCols[0]];
        }
    }
    if ($pkOk) {
        $order = implode(', ', array_map('mirror_qi', $pkCols));
        $offset = 0;
        while (true) {
            $rows = $srcQ("SELECT $sel FROM $t ORDER BY $order LIMIT ? OFFSET ?", [$chunk, $offset])->fetchAll();
            foreach ($rows as $r) {
                yield $r;
            }
            if (count($rows) < $chunk) {
                return;
            }
            $offset += $chunk;
        }
    }
    foreach ($srcQ("SELECT $sel FROM $t", [])->fetchAll() as $r) {
        yield $r;
    }
}

function mirror_count(callable $q, string $table): int
{
    return (int)$q('SELECT COUNT(*) FROM ' . mirror_qi($table), [])->fetchColumn();
}

function mirror_read_settings(callable $q): array
{
    $out = [];
    foreach ($q('SELECT setting_key, setting_value FROM ' . mirror_qi(MIRROR_SETTINGS_TABLE), [])->fetchAll() as $r) {
        $out[(string)$r['setting_key']] = $r['setting_value'];
    }
    return $out;
}

function mirror_collect_docs(callable $q, array $schemaCols): array
{
    $docs = [];
    foreach (mirror_document_columns() as $t => $cols) {
        foreach ($cols as $c) {
            if (!isset($schemaCols[$t][$c])) {
                continue;
            }
            $sql = 'SELECT DISTINCT ' . mirror_qi($c) . ' AS d FROM ' . mirror_qi($t) . ' WHERE ' . mirror_qi($c) . " IS NOT NULL AND " . mirror_qi($c) . " <> ''";
            foreach ($q($sql, [])->fetchAll() as $r) {
                $docs[] = (string)$r['d'];
            }
        }
    }
    return $docs;
}

function mirror_read_sequences(callable $q, array $schemaTables): array
{
    if (!isset($schemaTables['finance_sequences'])) {
        return [];
    }
    return $q('SELECT sequence_name, sequence_scope, next_number FROM ' . mirror_qi('finance_sequences'), [])->fetchAll();
}

function mirror_upsert_setting(PDO $tgt, string $key, string $value, bool $exists): void
{
    $t = mirror_qi(MIRROR_SETTINGS_TABLE);
    if ($exists) {
        mirror_tgt_query($tgt, "UPDATE $t SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
    } else {
        mirror_tgt_query($tgt, "INSERT INTO $t (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
    }
}

/** Copy one table: anonymised rows into Liwonde in batched prepared INSERTs. Returns rows inserted. */
function mirror_copy_table(callable $srcQ, PDO $tgt, string $table, array $cols, array $pkCols, bool $intPk, array $plan, array $maxLen, array &$st): int
{
    $batchRows = mirror_batch_rows(count($cols));
    $buf = [];
    $bytes = 0;
    $done = 0;
    $flush = static function () use (&$buf, &$bytes, &$done, $tgt, $table, $cols) {
        if (!$buf) {
            return;
        }
        $params = [];
        foreach ($buf as $r) {
            foreach ($cols as $c) {
                $params[] = $r[$c] ?? null;
            }
        }
        mirror_tgt_query($tgt, mirror_insert_sql($table, $cols, count($buf)), $params);
        $done += count($buf);
        $buf = [];
        $bytes = 0;
    };
    foreach (mirror_iter_rows($srcQ, $table, $cols, $pkCols, $intPk) as $row) {
        $row = $plan ? mirror_anonymise_row($st, $table, $row, $plan, $pkCols, $maxLen) : $row;
        $buf[] = $row;
        foreach ($row as $v) {
            $bytes += $v === null ? 4 : strlen((string)$v);
        }
        if (count($buf) >= $batchRows || $bytes > 4000000) {
            $flush();
        }
    }
    $flush();
    return $done;
}

// ---------------------------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------------------------

function mirror_usage(): void
{
    mirror_out('Mirror Rosalyn\'s (read only) into Liwonde (test platform) with made-up personal details.');
    mirror_out('  php scripts/mirror_from_rosalyns.php                      dry run (default): plan only, writes nothing');
    mirror_out('  php scripts/mirror_from_rosalyns.php --run --i-understand-liwonde-data-is-replaced');
    mirror_out('  optional with --run: --allow-non-transactional (only if the dry run lists a non-InnoDB table)');
}

function mirror_main(array $argv): int
{
    $args = array_slice($argv, 1);
    $known = ['--run', '--dry-run', '--i-understand-liwonde-data-is-replaced', '--allow-non-transactional', '--help', '-h'];
    foreach ($args as $a) {
        if (!in_array($a, $known, true)) {
            fwrite(STDERR, "Unknown option: $a\n");
            mirror_usage();
            return 2;
        }
    }
    if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
        mirror_usage();
        return 0;
    }
    $run = in_array('--run', $args, true);
    if ($run && in_array('--dry-run', $args, true)) {
        fwrite(STDERR, "Choose --run or --dry-run, not both.\n");
        return 2;
    }
    if ($run && !in_array('--i-understand-liwonde-data-is-replaced', $args, true)) {
        fwrite(STDERR, "--run also needs --i-understand-liwonde-data-is-replaced (Liwonde's data is replaced, no backup).\n");
        return 2;
    }
    $allowNonTx = in_array('--allow-non-transactional', $args, true);

    // --- guards on paths and credentials -----------------------------------------------------
    $liwondeRoot = dirname(__DIR__);
    $targetEnv = realpath($liwondeRoot . DIRECTORY_SEPARATOR . '.env');
    $sourceEnv = realpath(dirname($liwondeRoot) . DIRECTORY_SEPARATOR . 'Rosalyns-hotel-2026' . DIRECTORY_SEPARATOR . '.env');
    if ($targetEnv === false || !is_readable($targetEnv)) {
        fwrite(STDERR, "Liwonde .env not found next to this project.\n");
        return 1;
    }
    if ($sourceEnv === false || !is_readable($sourceEnv)) {
        fwrite(STDERR, "Rosalyn's .env not found at ../Rosalyns-hotel-2026/.env.\n");
        return 1;
    }
    if (!mirror_path_has_segment($targetEnv, 'Liwonde_Sun_Hotel_2026')) {
        fwrite(STDERR, "Refused: the target .env is not inside Liwonde_Sun_Hotel_2026.\n");
        return 1;
    }
    if (!mirror_path_has_segment($sourceEnv, 'Rosalyns-hotel-2026') || mirror_path_has_segment($sourceEnv, 'Liwonde_Sun_Hotel_2026') || $sourceEnv === $targetEnv) {
        fwrite(STDERR, "Refused: the source .env is not Rosalyn's.\n");
        return 1;
    }
    $srcEnvArr = mirror_parse_env((string)file_get_contents($sourceEnv));
    $tgtEnvArr = mirror_parse_env((string)file_get_contents($targetEnv));
    $srcCfg = mirror_db_config($srcEnvArr, "Rosalyn's");
    $tgtCfg = mirror_db_config($tgtEnvArr, 'Liwonde');
    if (mirror_same_database($srcCfg, $tgtCfg)) {
        fwrite(STDERR, "Refused: source and target are the same database.\n");
        return 1;
    }
    $saltRaw = (string)($tgtEnvArr['APP_ENCRYPTION_SALT'] ?? '');
    $salt = hash('sha256', 'mirror-from-rosalyns|' . $saltRaw);
    unset($srcEnvArr, $tgtEnvArr, $saltRaw);

    mirror_out($run ? "MODE: RUN - Liwonde's data will be replaced" : 'MODE: DRY RUN - nothing is written anywhere');
    mirror_out("Source: Rosalyn's database '" . $srcCfg['name'] . "' (read only). Target: Liwonde database '" . $tgtCfg['name'] . "'.");

    $srcPdo = mirror_connect($srcCfg, "Rosalyn's", true);
    $tgt = mirror_connect($tgtCfg, 'Liwonde', false);
    $srcCfg['pass'] = $tgtCfg['pass'] = '';

    // Second identity check from the servers themselves.
    $srcId = mirror_src_query($srcPdo, 'SELECT @@hostname AS h, @@port AS p, DATABASE() AS d')->fetch();
    $tgtId = mirror_tgt_query($tgt, 'SELECT @@hostname AS h, @@port AS p, DATABASE() AS d')->fetch();
    if ($srcId && $tgtId && (string)$srcId['h'] === (string)$tgtId['h'] && (string)$srcId['p'] === (string)$tgtId['p'] && strcasecmp((string)$srcId['d'], (string)$tgtId['d']) === 0) {
        fwrite(STDERR, "Refused: both connections reached the same database.\n");
        return 1;
    }

    mirror_source_begin($srcPdo);
    mirror_out("Source session: READ ONLY confirmed, one consistent snapshot.");
    $srcMode = (string)mirror_src_query($srcPdo, 'SELECT @@SESSION.sql_mode')->fetchColumn();
    $srcQ = static function (string $sql, array $p = []) use ($srcPdo) {
        return mirror_src_query($srcPdo, $sql, $p);
    };
    $tgtQ = static function (string $sql, array $p = []) use ($tgt) {
        return mirror_tgt_query($tgt, $sql, $p);
    };
    $tgt->exec("SET SESSION time_zone = '+00:00'");

    $S = mirror_read_schema($srcQ);
    $T = mirror_read_schema($tgtQ);
    $plan = mirror_plan_tables($S['tables'], $T['tables']);

    // --- counts and column plans ----------------------------------------------------------------
    $counts = [];
    $colPlans = [];
    $piiPlans = [];
    $blocking = [];
    foreach ($plan as $t => $p) {
        $sc = isset($S['tables'][$t]) && $S['tables'][$t]['type'] === 'BASE TABLE' ? mirror_count($srcQ, $t) : null;
        $tc = isset($T['tables'][$t]) && $T['tables'][$t]['type'] === 'BASE TABLE' ? mirror_count($tgtQ, $t) : null;
        $counts[$t] = [$sc, $tc];
        if ($p['action'] !== 'replace') {
            continue;
        }
        $cp = mirror_plan_columns($S['columns'][$t] ?? [], $T['columns'][$t] ?? []);
        $colPlans[$t] = $cp;
        $piiPlans[$t] = mirror_plan_pii($t, $cp['common'], $T['columns'][$t] ?? []);
        if ($cp['tgt_required_missing'] && (int)$sc > 0) {
            $blocking[] = "$t: Liwonde-only NOT NULL columns without a default: " . implode(', ', $cp['tgt_required_missing']);
        }
        if (!$cp['common']) {
            $blocking[] = "$t: no columns in common";
        }
        foreach (mirror_unique_collisions($T['unique'][$t] ?? [], $piiPlans[$t]) as $u) {
            $blocking[] = "$t: UNIQUE index on a column whose made-up values can repeat: $u";
        }
        if ($p['non_innodb'] && !$allowNonTx) {
            $blocking[] = "$t: not InnoDB on Liwonde (a failure part-way cannot be rolled back; add --allow-non-transactional to accept)";
        }
    }

    mirror_out('');
    mirror_out('=== TABLES (action | Rosalyn\'s rows | Liwonde rows | reason) ===');
    foreach ($plan as $t => $p) {
        [$sc, $tc] = $counts[$t];
        $flags = [];
        if ($p['action'] === 'replace' && (int)$sc === 0 && (int)$tc > 0) {
            $flags[] = 'WARNING: Rosalyn\'s has 0 rows, Liwonde\'s ' . $tc . ' will be deleted';
        }
        if ($p['non_innodb']) {
            $flags[] = 'NOT InnoDB';
        }
        mirror_out(sprintf('%-15s %-40s %8s %8s  %s%s', $p['action'], $t, $sc === null ? '-' : (string)$sc, $tc === null ? '-' : (string)$tc,
            $p['reason'], $flags ? '  [' . implode('; ', $flags) . ']' : ''));
    }
    $unclassified = array_keys(array_filter($plan, static function ($p) {
        return $p['action'] === 'replace' && !$p['classified'];
    }));
    mirror_out('UNCLASSIFIED (replaced, please review): ' . ($unclassified ? implode(', ', $unclassified) : 'none'));
    if ($T['triggers']) {
        mirror_out('Liwonde triggers (fire on insert/delete; review): ' . implode(', ', $T['triggers']));
    }

    mirror_out('');
    mirror_out('=== COLUMNS (replaced tables) ===');
    foreach ($colPlans as $t => $cp) {
        $lines = [];
        if ($cp['src_only']) {
            $lines[] = "Rosalyn's only (not copied): " . implode(', ', $cp['src_only']);
        }
        if ($cp['tgt_only']) {
            $lines[] = 'Liwonde only (left to default): ' . implode(', ', $cp['tgt_only']);
        }
        if ($cp['tgt_required_missing']) {
            $lines[] = 'BLOCKING Liwonde-only NOT NULL without default: ' . implode(', ', $cp['tgt_required_missing']);
        }
        if ($cp['type_diff']) {
            $lines[] = 'type differs: ' . implode('; ', $cp['type_diff']);
        }
        if ($cp['generated']) {
            $lines[] = 'generated (skipped): ' . implode(', ', $cp['generated']);
        }
        if ($lines) {
            mirror_out($t . ': ' . implode(' | ', $lines));
        }
    }

    mirror_out('');
    mirror_out('=== PERSONAL DATA (per replaced table: anonymised columns, then text columns left alone) ===');
    $logic = mirror_logic_columns();
    foreach ($piiPlans as $t => $pii) {
        if (!$pii) {
            continue;
        }
        $anon = [];
        $kept = [];
        foreach ($pii as $c => $cat) {
            if ($cat === 'keep') {
                $kept[] = $c;
            } elseif (in_array($t . '.' . $c, $logic, true)) {
                $anon[] = $c . '=scrub (kept: logic-bearing; only names/emails/phones swapped)';
            } else {
                $anon[] = $c . '=' . $cat;
            }
        }
        mirror_out($t . ':');
        mirror_out('  anonymise: ' . ($anon ? implode(', ', $anon) : '-'));
        mirror_out('  left alone: ' . ($kept ? implode(', ', $kept) : '-'));
    }
    mirror_out('Categories: name/name_first/name_last/staff = made-up Malawian-style names; company = "Test Company 012";');
    mirror_out('  email = guestN@example.invalid; phone = +265 000 NNNNNN; address = "Test address N"; idnum = "TESTID N";');
    mirror_out('  ip = 192.0.2.N; contact = by value; freetext = "' . MIRROR_PLACEHOLDER . '" unless system wording;');
    mirror_out('  scrub = text kept, every known name/email/phone inside swapped for the same fake.');

    // --- settings ------------------------------------------------------------------------------
    $srcSettings = isset($plan[MIRROR_SETTINGS_TABLE]) && $plan[MIRROR_SETTINGS_TABLE]['action'] === 'settings-merge' ? mirror_read_settings($srcQ) : [];
    $tgtSettings = isset($T['tables'][MIRROR_SETTINGS_TABLE]) ? mirror_read_settings($tgtQ) : [];
    $copyKeys = [];
    $notCopied = [];
    foreach (array_keys($srcSettings) as $k) {
        if (mirror_settings_key_allowed((string)$k)) {
            $copyKeys[] = (string)$k;
        } else {
            $notCopied[] = (string)$k;
        }
    }
    sort($copyKeys, SORT_STRING);
    sort($notCopied, SORT_STRING);
    $muteKeys = mirror_mute_keys(array_keys($tgtSettings));
    mirror_out('');
    mirror_out('=== SETTINGS (site_settings: money and booking-rule keys copied, Liwonde identity kept) ===');
    foreach ($copyKeys as $k) {
        $old = array_key_exists($k, $tgtSettings) ? (string)$tgtSettings[$k] : '(missing)';
        $new = (string)$srcSettings[$k];
        mirror_out(sprintf('  copy %-36s Liwonde %s -> %s%s', $k, $old, $new, $old === $new ? ' (same)' : ''));
    }
    mirror_out('  allowlist keys absent at Rosalyn\'s: ' . implode(', ', array_diff(mirror_settings_allowlist(), $copyKeys)));
    mirror_out('  Rosalyn\'s keys NOT copied (names only): ' . ($notCopied ? implode(', ', $notCopied) : '-'));
    mirror_out('  Document numbering (each hotel keeps its own; Rosalyn\'s | Liwonde):');
    $numberingDiffers = false;
    foreach (mirror_numbering_keys() as $k) {
        $a = array_key_exists($k, $srcSettings) ? (string)$srcSettings[$k] : '(unset)';
        $b = array_key_exists($k, $tgtSettings) ? (string)$tgtSettings[$k] : '(unset)';
        if ($a !== $b && $k !== 'booking_reference_prefix') {
            $numberingDiffers = true;
        }
        mirror_out(sprintf('    %-28s %s | %s', $k, $a, $b));
    }
    if ($numberingDiffers) {
        mirror_out('  WARNING: document prefixes / start numbers differ, so Liwonde\'s own new numbers run on its own series');
        mirror_out('  (no clash with the copied numbers, but they will not continue straight after them).');
    }

    mirror_out('');
    mirror_out('=== MUTE MESSAGING on Liwonde (set to 0, inserted when missing because missing scheduler keys default to on) ===');
    foreach ($muteKeys as $k) {
        mirror_out(sprintf('  %-40s now %s -> 0', $k, array_key_exists($k, $tgtSettings) ? (string)$tgtSettings[$k] : '(missing = on)'));
    }
    mirror_out('  Applied and committed BEFORE the copy (so the scheduler stops first), then re-applied inside the copy.');
    mirror_out('  automated_email_master = 0 stops every scheduler job except the nightly backup.');
    mirror_out('  Guest emails/phones become @example.invalid / +265 000 numbers, so manual sends cannot reach a real guest.');
    mirror_out('  LATER: there is no global "all email off" switch (email_development_mode only works on localhost);');
    mirror_out('  staff notification addresses in Liwonde\'s own settings still receive manual-action emails.');

    // --- identity pre-pass (builds the source->fake map before anything is written) -------------
    $st = mirror_anon_init($salt);
    $idCats = mirror_identity_categories();
    foreach (mirror_prepass_order($piiPlans) as $t) {
        $idPlan = array_filter($piiPlans[$t], static function ($cat) use ($idCats) {
            return in_array($cat, $idCats, true);
        });
        if (!$idPlan || (int)$counts[$t][0] === 0) {
            continue;
        }
        $pkCols = $S['pk'][$t] ?? [];
        $selCols = array_values(array_unique(array_merge(array_intersect($pkCols, $colPlans[$t]['common']), array_keys($idPlan))));
        $intPk = count($pkCols) === 1 && mirror_is_int_type((string)($S['columns'][$t][$pkCols[0]]['data_type'] ?? ''));
        foreach (mirror_iter_rows($srcQ, $t, $selCols, $pkCols, $intPk) as $row) {
            mirror_anonymise_row($st, $t, $row, $idPlan, $pkCols);
        }
    }
    mirror_out('');
    mirror_out(sprintf('=== IDENTITY MAP === %d emails, %d phones, %d guest names, %d staff names, %d companies (no real values printed)',
        count($st['email']), count($st['phone']), count($st['name']), count($st['staff']), count($st['company'])));

    // --- sequences preview ----------------------------------------------------------------------
    $srcDocPrefix = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($srcSettings['document_number_prefix'] ?? '')));
    $srcDocPrefix = substr($srcDocPrefix, 0, 6);
    $seqCopied = isset($plan['finance_sequences']) && $plan['finance_sequences']['action'] === 'replace';
    mirror_out('');
    mirror_out('=== FINANCE SEQUENCES === ' . ($seqCopied ? "copied from Rosalyn's, then each next_number moved past the highest copied document number" : 'finance_sequences not replaced'));
    if (!$run) {
        $bumps = mirror_sequence_bumps(mirror_read_sequences($srcQ, $S['tables']), mirror_collect_docs($srcQ, $S['columns']), $srcDocPrefix);
        foreach ($bumps as $b) {
            mirror_out(sprintf('  would move %s %s: %d -> %d', $b['sequence_name'], $b['sequence_scope'], $b['from'], $b['to']));
        }
        if (!$bumps) {
            mirror_out("  none needed (Rosalyn's sequences are already past its document numbers)");
        }
    }

    if ($blocking) {
        mirror_out('');
        mirror_out('=== BLOCKING (a run would refuse) ===');
        foreach ($blocking as $b) {
            mirror_out('  ' . $b);
        }
    }

    $after = [
        'AFTER A RUN: clear Liwonde\'s caches at once (VAT, levy, vat_pricing_mode, currency and check-in/out times are',
        '  file-cached for up to an hour, and cached pages still show the old rooms): on the Liwonde server run',
        '  "php scripts/scheduled-cache-clear.php --force" or Admin > Cache Management > clear all. This script deletes no files.',
        '  AI tables (ai_review_cache, ai_review_brief, ai_tool_cache) are left alone (DENY); they expire by day / expiry time.',
        '  Ask the AI lead to clear today\'s rows if the Assistant shows pre-copy figures.',
    ];

    if (!$run) {
        mirror_out('');
        foreach ($after as $l) {
            mirror_out($l);
        }
        mirror_out('DRY RUN complete. Nothing was written.');
        $srcPdo->rollBack();
        return 0;
    }

    if ($blocking) {
        fwrite(STDERR, "Refused: fix the BLOCKING items above first. Nothing was written.\n");
        $srcPdo->rollBack();
        return 1;
    }

    // --- RUN: one transaction on Liwonde ---------------------------------------------------------
    $replace = array_keys(array_filter($plan, static function ($p) {
        return $p['action'] === 'replace';
    }));
    // Mute first, in its own small commit: the in-app scheduler re-reads automated_email_master from the
    // database before each run, so it stops touching Liwonde bookings before the long copy starts.
    if (isset($T['tables'][MIRROR_SETTINGS_TABLE])) {
        $tgt->beginTransaction();
        try {
            foreach ($muteKeys as $k) {
                mirror_upsert_setting($tgt, $k, '0', array_key_exists($k, $tgtSettings));
                $tgtSettings[$k] = '0';
            }
            $tgt->commit();
        } catch (Throwable $e) {
            if ($tgt->inTransaction()) {
                $tgt->rollBack();
            }
            $srcPdo->rollBack();
            fwrite(STDERR, 'FAILED while muting Liwonde messaging (nothing copied): ' . $e->getMessage() . "\n");
            return 1;
        }
        mirror_out('Liwonde messaging muted (committed before the copy).');
    }
    $tgt->exec('SET SESSION sql_mode = ' . $tgt->quote(mirror_target_sql_mode($srcMode)));
    $tgt->exec('SET FOREIGN_KEY_CHECKS = 0');
    $summary = [];
    $seqChanges = [];
    $started = microtime(true);
    try {
        $tgt->beginTransaction();
        foreach ($replace as $t) {
            $cp = $colPlans[$t];
            $pkCols = $S['pk'][$t] ?? [];
            $intPk = count($pkCols) === 1 && mirror_is_int_type((string)($S['columns'][$t][$pkCols[0]]['data_type'] ?? ''));
            $maxLen = [];
            foreach ($cp['common'] as $c) {
                $maxLen[$c] = (int)($T['columns'][$t][$c]['max_len'] ?? 0);
            }
            // Full-table delete of this Liwonde table, as the owner instructed (replace, no backup).
            $tgt->exec('DELETE FROM ' . mirror_qi($t) . ' WHERE 1 = 1');
            $n = (int)$counts[$t][0] > 0
                ? mirror_copy_table($srcQ, $tgt, $t, $cp['common'], $pkCols, $intPk, mirror_active_plan($piiPlans[$t]), $maxLen, $st)
                : 0;
            $summary[$t] = $n;
        }
        if (isset($T['tables'][MIRROR_SETTINGS_TABLE])) {
            foreach ($copyKeys as $k) {
                mirror_upsert_setting($tgt, $k, (string)$srcSettings[$k], array_key_exists($k, $tgtSettings));
                $tgtSettings[$k] = (string)$srcSettings[$k];
            }
            foreach ($muteKeys as $k) {
                mirror_upsert_setting($tgt, $k, '0', array_key_exists($k, $tgtSettings));
                $tgtSettings[$k] = '0';
            }
        }
        if ($seqCopied) {
            $seqChanges = mirror_sequence_bumps(mirror_read_sequences($tgtQ, $T['tables']), mirror_collect_docs($tgtQ, $T['columns']), $srcDocPrefix);
            foreach ($seqChanges as $b) {
                mirror_tgt_query($tgt, 'UPDATE ' . mirror_qi('finance_sequences') . ' SET next_number = ? WHERE sequence_name = ? AND sequence_scope = ? AND next_number < ?',
                    [$b['to'], $b['sequence_name'], $b['sequence_scope'], $b['to']]);
            }
        }
        $tgt->commit();
    } catch (Throwable $e) {
        if ($tgt->inTransaction()) {
            $tgt->rollBack();
        }
        $tgt->exec('SET FOREIGN_KEY_CHECKS = 1');
        $srcPdo->rollBack();
        fwrite(STDERR, 'FAILED, Liwonde rolled back (InnoDB tables unchanged): ' . get_class($e) . ': ' . $e->getMessage() . "\n");
        return 1;
    }
    $tgt->exec('SET FOREIGN_KEY_CHECKS = 1');

    // --- verify by re-query --------------------------------------------------------------------
    mirror_out('');
    mirror_out(sprintf('=== RUN SUMMARY (%.1fs) ===', microtime(true) - $started));
    $bad = 0;
    foreach ($summary as $t => $n) {
        $now = mirror_count($tgtQ, $t);
        $want = (int)$counts[$t][0];
        $ok = $now === $want && $n === $want;
        if (!$ok) {
            $bad++;
        }
        mirror_out(sprintf('  %-40s Rosalyn\'s %8d  inserted %8d  Liwonde now %8d  %s', $t, $want, $n, $now, $ok ? 'ok' : 'MISMATCH'));
    }
    $check = mirror_read_settings($tgtQ);
    $muteOk = true;
    foreach ($muteKeys as $k) {
        if ((string)($check[$k] ?? '') !== '0') {
            $muteOk = false;
            mirror_out('  MUTE NOT APPLIED: ' . $k);
        }
    }
    $copyOk = true;
    foreach ($copyKeys as $k) {
        if ((string)($check[$k] ?? '') !== (string)$srcSettings[$k]) {
            $copyOk = false;
            mirror_out('  SETTING NOT COPIED: ' . $k);
        }
    }
    foreach ($seqChanges as $b) {
        mirror_out(sprintf('  sequence %s %s moved %d -> %d', $b['sequence_name'], $b['sequence_scope'], $b['from'], $b['to']));
    }
    mirror_out(sprintf('  settings copied: %d (%s); messaging keys muted: %d (%s)', count($copyKeys), $copyOk ? 'verified' : 'CHECK', count($muteKeys), $muteOk ? 'verified' : 'CHECK'));
    mirror_out(sprintf('  identities: %d emails, %d phones, %d guest names, %d staff names, %d companies',
        count($st['email']), count($st['phone']), count($st['name']), count($st['staff']), count($st['company'])));
    foreach ($after as $l) {
        mirror_out($l);
    }
    $srcPdo->rollBack(); // ends the read-only snapshot; nothing was ever written to the source
    mirror_out($bad === 0 && $muteOk && $copyOk ? 'RUN complete.' : 'RUN finished with mismatches listed above.');
    return ($bad === 0 && $muteOk && $copyOk) ? 0 : 1;
}

if (!defined('MIRROR_LIB_ONLY') && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    try {
        exit(mirror_main($_SERVER['argv'] ?? []));
    } catch (Throwable $e) {
        fwrite(STDERR, 'Stopped: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
}
