<?php
/**
 * Value change log (owner 2026-11-26, SHEET_VIEW_PLAN.md rail 3): the ONE shared old -> new log for item values that
 * a screen saves (menu prices, stock cost per unit, budgets and the like), so no screen builds its own.
 *
 * One admin_activity_log row (action 'value_change') per value that really changed: which screen, who, when, which
 * item (id + plain name), which field (plain label), old -> new. Shown in System Logs > Staff activity
 * ("Price & value changes") and in the Admin activity list. Details:
 *   "page=<file>; id=<int>; item=<label>; field=<label>; kind=<money|number|text>; old=<v>; new=<v>"
 * Money is stored to 2 decimals ("8500.00") and shown with thousands separators in the sentence ("8,500.00").
 * Money is compared at 2 decimals too, so a value stored with more decimals (stock_ingredients.cost_per_unit is
 * DECIMAL(12,4)) must use kind 'number', else a change below 0.01 (0.0035 -> 0.0042) reads as no change.
 *
 * Only for item VALUES (prices, costs, amounts, names). Never pass a password, token, key or any other secret here:
 * nothing is masked (secrets belong to includes/settings-change-log.php, which hides them).
 *
 * Transactions: rh_value_log_record() runs a plain prepared INSERT on the caller's $pdo and never begins, commits or
 * rolls back a transaction of its own. Call it inside the caller's save transaction, after the writes and before the
 * commit: the log rows then commit or roll back together with the save. A returned 'failed' > 0 means a value was
 * saved without its log row; a caller inside a transaction may decide to roll back. In MySQL a deadlock or lock-wait
 * error on the INSERT has already rolled back the caller's whole transaction, so a caller in a transaction must check
 * the counts before it commits.
 *
 * Loading this file connects to nothing and logs nothing. Never throws. Pure helpers are tested by
 * scripts/tests/value_change_log.php (no database: a test sink receives the rows).
 */

// Shared one-line value clipping (rh_settings_log_clip). Loading it connects to nothing and logs nothing.
require_once __DIR__ . '/../../includes/settings-change-log.php';

if (!function_exists('rh_value_log_details')) {

    /** The admin_activity_log action of these rows. */
    function rh_value_log_action(): string
    {
        return 'value_change';
    }

    /** The kinds of value: how two values are compared and shown. */
    function rh_value_log_kinds(): array
    {
        return ['money' => true, 'number' => true, 'text' => true];
    }

    /** A screen file name as stored: 'menu-management.php'. */
    function rh_value_log_valid_page(string $page): bool
    {
        return preg_match('/^[a-z0-9_-]{1,80}\.php$/', $page) === 1;
    }

    /**
     * A plain number as a string ('8500', '-3.25', '8,500.00', '.5'), or null when it is not one. Thousands commas are
     * accepted only in the 1,234,567 shape.
     */
    function rh_value_log_number_string($v): ?string
    {
        if (is_bool($v) || !is_scalar($v)) {
            return null;
        }
        $s = trim((string)$v);
        if (preg_match('/^[+-]?\d{1,3}(,\d{3})+(\.\d*)?$/', $s)) {
            $s = str_replace(',', '', $s);
        }
        return preg_match('/^[+-]?(\d{1,15}(\.\d{0,10})?|\.\d{1,10})$/', $s) === 1 ? $s : null;
    }

    /** "+08500.50" -> "8500.5", "16.00" -> "16", "-0" -> "0": the same number always reads the same. */
    function rh_value_log_number_norm(string $s): string
    {
        $neg = $s !== '' && $s[0] === '-';
        $s = ltrim($s, '+-');
        if (strpos($s, '.') !== false) {
            [$int, $dec] = explode('.', $s, 2);
            $dec = rtrim($dec, '0');
        } else {
            $int = $s;
            $dec = '';
        }
        $int = ltrim($int, '0');
        if ($int === '') {
            $int = '0';
        }
        $out = $int . ($dec !== '' ? '.' . $dec : '');
        return ($neg && $out !== '0') ? '-' . $out : $out;
    }

    /** Money to 2 decimals, no thousands separators: "8500" -> "8500.00". */
    function rh_value_log_money_norm(string $s): string
    {
        $out = number_format(round((float)$s, 2), 2, '.', '');
        return $out === '-0.00' ? '0.00' : $out;
    }

    /** One value as compared and stored, before clipping: trimmed text, or the normalised number / money. */
    function rh_value_log_value($v, string $kind): string
    {
        $text = is_bool($v) ? ($v ? '1' : '0') : (is_scalar($v) ? trim((string)$v) : '');
        if ($kind === 'money' || $kind === 'number') {
            $num = rh_value_log_number_string($text);
            if ($num !== null) {
                return $kind === 'money' ? rh_value_log_money_norm($num) : rh_value_log_number_norm($num);
            }
        }
        return $text; // blank, or not a number: compared as trimmed text
    }

    /**
     * The details text for one change, or null when nothing should be recorded: no real change (trimmed text equal;
     * money / numbers equal as numbers, "8500" = "8500.00"), a bad page name, an unknown kind or a negative id.
     * In item, field and old the next separator ("; field=", "; kind=", "; new=") becomes ", field=" and so on, so the
     * read-back split is exact; any other ';' and '=' are kept as they are.
     */
    function rh_value_log_details(string $page, string $item, int $itemId, string $field, $old, $new, string $kind): ?string
    {
        if (!rh_value_log_valid_page($page) || !isset(rh_value_log_kinds()[$kind]) || $itemId < 0) {
            return null;
        }
        $o = rh_value_log_value($old, $kind);
        $n = rh_value_log_value($new, $kind);
        if ($o === $n) {
            return null; // compared in full, before clipping
        }
        $item = rh_settings_log_clip($item, 120);
        if ($item === '') {
            $item = 'Item #' . $itemId;
        }
        $field = rh_settings_log_clip($field, 120);
        if ($field === '') {
            $field = 'Value';
        }
        $item = str_replace('; field=', ', field=', $item);
        $field = str_replace('; kind=', ', kind=', $field);
        $ov = str_replace('; new=', ', new=', rh_settings_log_clip($o, 120));
        $nv = rh_settings_log_clip($n, 120);
        return 'page=' . $page . '; id=' . $itemId . '; item=' . $item . '; field=' . $field . '; kind=' . $kind
            . '; old=' . $ov . '; new=' . $nv;
    }

    /** Read a details text back: [page, id, item, field, kind, old, new] or null when it is not a value change. */
    function rh_value_log_parse(string $details): ?array
    {
        if (!preg_match('/^page=([a-z0-9_-]{1,80}\.php); id=(\d{1,19}); item=(.*?); field=(.*?); kind=(money|number|text); old=(.*?); new=(.*)$/s', $details, $m)) {
            return null;
        }
        return ['page' => $m[1], 'id' => (int)$m[2], 'item' => $m[3], 'field' => $m[4], 'kind' => $m[5], 'old' => $m[6], 'new' => $m[7]];
    }

    /** The sentence for Staff activity: "Changed Chicken curry Price from 8,500.00 to 9,000.00"; blank reads "(blank)". */
    function rh_value_log_sentence(string $details): string
    {
        $p = rh_value_log_parse($details);
        if ($p === null) {
            return 'Changed a value';
        }
        $kind = $p['kind'];
        $w = static function (string $v) use ($kind): string {
            if ($v === '') {
                return '(blank)';
            }
            if ($kind === 'money' && preg_match('/^-?\d{1,15}\.\d{2}$/', $v)) {
                return number_format((float)$v, 2, '.', ',');
            }
            return $v;
        };
        return 'Changed ' . $p['item'] . ' ' . $p['field'] . ' from ' . $w($p['old']) . ' to ' . $w($p['new']);
    }

    /* ── database (caller's $pdo, never throws, never opens a transaction) ── */

    /**
     * Record value changes. Each change: ['page', 'item', 'id', 'field', 'old', 'new', 'kind'].
     * $who: id, username, ip (else the session's admin_user_id / admin_username and REMOTE_ADDR).
     * $sink: tests only, receives each row [user_id, username, action, details, ip, created_at] instead of the database.
     * Returns ['written' => n, 'same' => n (no real change), 'failed' => n (bad change, no database, a write error)].
     * A plain INSERT on $pdo: it joins the caller's transaction if one is open (see the file header).
     */
    function rh_value_log_record($pdo, array $changes, array $who = [], ?callable $sink = null): array
    {
        $counts = ['written' => 0, 'same' => 0, 'failed' => 0];
        try {
            $session = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : [];
            $uid = (int)($who['id'] ?? ($session['admin_user_id'] ?? 0));
            $username = trim((string)($who['username'] ?? ($session['admin_username'] ?? '')));
            $username = function_exists('mb_substr') ? mb_substr($username, 0, 100) : substr($username, 0, 100);
            $ip = substr(trim((string)($who['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''))), 0, 45);
            $uid = $uid > 0 ? $uid : 0;
            $st = null;
            foreach ($changes as $c) {
                try {
                    if (!is_array($c)) {
                        throw new InvalidArgumentException('a change is not an array');
                    }
                    $page = is_string($c['page'] ?? null) ? $c['page'] : '';
                    $kind = is_string($c['kind'] ?? null) ? $c['kind'] : '';
                    $id = $c['id'] ?? null;
                    if (!rh_value_log_valid_page($page) || !isset(rh_value_log_kinds()[$kind])
                        || !(is_int($id) || (is_string($id) && ctype_digit($id))) || (int)$id < 0) {
                        throw new InvalidArgumentException('bad page, kind or id (page "' . substr($page, 0, 80) . '", kind "' . substr($kind, 0, 20) . '")');
                    }
                    $details = rh_value_log_details($page, is_scalar($c['item'] ?? null) ? (string)$c['item'] : '', (int)$id,
                        is_scalar($c['field'] ?? null) ? (string)$c['field'] : '', $c['old'] ?? null, $c['new'] ?? null, $kind);
                    if ($details === null) {
                        $counts['same']++;
                        continue;
                    }
                    $row = [$uid, $username, rh_value_log_action(), $details, $ip, date('Y-m-d H:i:s')];
                    if ($sink !== null) {
                        $sink($row);
                    } else {
                        if (!($pdo instanceof PDO)) {
                            throw new RuntimeException('no database connection');
                        }
                        if ($st === null) {
                            $st = $pdo->prepare('INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                        }
                        $st->execute($row);
                    }
                    $counts['written']++;
                } catch (Throwable $e) {
                    $counts['failed']++;
                    error_log('value change log write: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('value change log: ' . $e->getMessage());
        }
        return $counts;
    }
}
