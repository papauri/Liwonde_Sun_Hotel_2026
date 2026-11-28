<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * New Booking dry run ("price check", plan .claude/CREATE_BOOKING_WIZARD_PLAN.md Phase B): static checks on
 * admin/create-booking.php and the shared credit eligibility rule (admin/includes/finance-booking-credit.php).
 * No database, no network, no sends.   php scripts/tests/create_booking_dryrun_test.php
 * The no-write proof over HTTP on Liwonde is scripts/tests/create_booking_dryrun_http.php.
 *
 * Real save unchanged (owner 2026-11-28): the fixtures below are the code as it was before the dry run (HEAD e9ecb8d):
 * the availability guard, the credit filter loop, the four response=json exits and the basic-validation messages.
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function check(bool $c, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS $label\n"; } else { $fail++; echo "FAIL $label" . ($detail !== '' ? ": $detail" : '') . "\n"; }
}
if (!defined('BALANCE_TOLERANCE')) { define('BALANCE_TOLERANCE', 0.01); } // config/database.php:69
require_once $root . '/admin/includes/finance-booking-credit.php';

$src = str_replace("\r\n", "\n", (string)file_get_contents($root . '/admin/create-booking.php')); // a CRLF checkout reads the same
$hStart = strpos($src, "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['create_booking'])) {");
$hEnd = $hStart === false ? false : strpos($src, '// A refused save shows this page again', $hStart);
check($hStart !== false && $hEnd !== false, 'the create_booking POST handler is found');
$h = (string)substr($src, (int)$hStart, (int)$hEnd - (int)$hStart);

/** [start, end] of the {...} block whose "{" is the first one at or after $from (brace count; strings in this code hold no braces that matter). */
function cbt_block(string $s, int $from): array
{
    $open = strpos($s, '{', $from);
    if ($open === false) { return [-1, -1]; }
    $depth = 0;
    for ($i = $open, $n = strlen($s); $i < $n; $i++) {
        if ($s[$i] === '{') { $depth++; }
        elseif ($s[$i] === '}') { $depth--; if ($depth === 0) { return [$open, $i]; } }
    }
    return [$open, -1];
}
/** Executable code only: comment lines and stage-marker lines dropped, whitespace collapsed. */
function cbt_norm(string $code): string
{
    $keep = [];
    foreach (preg_split('/\R/', $code) as $ln) {
        $t = trim($ln);
        if ($t === '' || strpos($t, '//') === 0 || strpos($t, '$cbStep') !== false) { continue; }
        $keep[] = $t;
    }
    return trim((string)preg_replace('/\s+/', ' ', implode(' ', $keep)));
}

// ── 1. dry_run is honoured ONLY together with response=wizard (correction 7) ──
$dryRe = "/\\\$cbDryRun\\s*=\\s*\\(\\\$_POST\\['response'\\] \\?\\? ''\\) === 'wizard' && \\(\\\$_POST\\['dry_run'\\] \\?\\? ''\\) === '1';/";
check(preg_match_all($dryRe, $src) === 1, 'the dry-run switch is response=wizard AND dry_run=1');
check(substr_count($src, "\$_POST['dry_run']") === 1, 'dry_run is read in that one place only');
check(preg_match_all('/\$cbDryRun\s*=[^=]/', $src) === 1, '$cbDryRun is set once and never changed');
$cbtDry = static function (array $post): bool { return ($post['response'] ?? '') === 'wizard' && ($post['dry_run'] ?? '') === '1'; }; // the same expression
check($cbtDry(['response' => 'wizard', 'dry_run' => '1']), 'response=wizard + dry_run=1 is a dry run');
check(!$cbtDry(['response' => 'json', 'dry_run' => '1']) && !$cbtDry(['dry_run' => '1']) && !$cbtDry(['response' => 'wizard'])
    && !$cbtDry(['response' => 'wizard', 'dry_run' => 'true']) && !$cbtDry(['response' => ['wizard'], 'dry_run' => ['1']]),
    'dry_run without response=wizard (the Assistant json replay, the plain form) or any other value is NOT a dry run');

// ── 2. the dry run answers before the transaction; nothing above it writes ──
$posDryBlock = strpos($h, "if (\$cbDryRun) {\n            \$cbCheckAvailability();");
$posTxComment = strpos($h, '// ── Transaction: create bookings for all lines');
$posBegin = strpos($h, '$pdo->beginTransaction();');
check(substr_count($src, 'beginTransaction(') === 1 && substr_count($src, '// ── Transaction: create bookings for all lines') === 1, 'one transaction start and one Transaction comment in the file');
[$dbOpen, $dbClose] = $posDryBlock === false ? [-1, -1] : cbt_block($h, $posDryBlock);
$dryBlock = $dbClose > 0 ? substr($h, $dbOpen, $dbClose - $dbOpen + 1) : '';
check($posDryBlock !== false && $dbClose > 0 && $dbClose < $posTxComment && $posTxComment < $posBegin, 'the whole dry-run block sits before the Transaction comment and beginTransaction()');
check((bool)preg_match("/\\\$cbWizardReply\\(\\[\\s*'ok'\\s*=>\\s*true,\\s*'quote'.*\\]\\);\\s*\\}\$/s", $dryBlock), 'the dry-run block ends with the quote reply');
$replyDef = strpos($h, '$cbWizardReply = static function (array $reply): void {');
[$rdOpen, $rdClose] = $replyDef === false ? [-1, -1] : cbt_block($h, $replyDef);
$replyBody = $rdClose > 0 ? substr($h, $rdOpen, $rdClose - $rdOpen + 1) : '';
check((bool)preg_match('/echo json_encode\(\$reply[^;]*\);\s*exit;\s*\}$/', $replyBody), 'the wizard reply prints JSON and exits (nothing after it runs)');
// Every wizard reply is inside an "if ($cbDryRun) {" block.
$dryRanges = [];
for ($p = 0; ($p = strpos($h, 'if ($cbDryRun) {', $p)) !== false; $p++) { $dryRanges[] = cbt_block($h, $p); }
$calls = [];
for ($p = 0; ($p = strpos($h, '$cbWizardReply(', $p)) !== false; $p++) { $calls[] = $p; }
$wizRanges = [];
for ($p = 0; ($p = strpos($h, 'if ($cbWizard) {', $p)) !== false; $p++) { $wizRanges[] = cbt_block($h, $p); }
$inRanges = static function (int $c, array $ranges): bool { foreach ($ranges as [$o, $e]) { if ($c > $o && $c < $e) { return true; } } return false; };
$inDry = array_filter($calls, static fn($c) => $inRanges($c, $dryRanges));
$inWiz = array_filter($calls, static fn($c) => $inRanges($c, $wizRanges));
$outside = array_filter($calls, static fn($c) => !$inRanges($c, $dryRanges) && !$inRanges($c, $wizRanges));
check(count($inDry) === 3 && count($inWiz) === 5 && !$outside && count($calls) === 8,
    'wizard replies: three in a dry run (duplicate, quote, refusal), five in a Create (still saving, duplicate, success, saved-then-failed, refusal), none elsewhere',
    'calls ' . count($calls) . ', dry ' . count($inDry) . ', create ' . count($inWiz) . ', outside ' . count($outside));

// Writes / logs / locks / session / redirects between the handler start and the quote reply: each one guarded.
$preDry = substr($h, 0, (int)$dbClose);
preg_match_all('/INSERT\s+INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM|REPLACE\s+INTO|rh_log_event\(|logBookingAudit|\$_SESSION\[[^\]]+\]\s*=[^=]|GET_LOCK|header\(\'Location/', $preDry, $wm, PREG_OFFSET_CAPTURE);
$hits = array_map(static fn($m) => rtrim($m[0]), $wm[0]);
check($hits === ['GET_LOCK', "\$_SESSION['flash_message'] =", "header('Location", 'rh_log_event('], 'above the dry-run exit only the known side effects exist (idempotency lock, duplicate flash + redirect, blocked-override log)', json_encode($hits));
check(strpos($h, "if (!\$cbDryRun && (\$__idemKey = idem_normalize_uuid(\$__incomingClientUuid))) {\n            if ((int)\$pdo->query('SELECT GET_LOCK(") !== false, 'a dry run takes no GET_LOCK');
$posDupReply = strpos($h, "\$cbWizardReply(['ok' => true, 'duplicate' => true, 'redirect' => 'booking-details.php?id=' . (int)\$__existingBooking['id'],");
$posDupFlash = strpos($h, "\$_SESSION['flash_message'] = ['type' => 'success', 'text' => \$message];");
check($posDupReply !== false && $posDupFlash !== false && $posDupReply < $posDupFlash && strpos($h, 'idem_find_existing_booking($pdo, $__incomingClientUuid)') < $posDupReply,
    'a dry run still looks up the uuid and answers {ok, duplicate, redirect} before any flash or redirect');
check(strpos($h, "if (!\$cbDryRun) {\n                rh_log_event('create-booking', 'warning', 'Manual room price blocked: role may not override pricing', [") !== false
    && strpos($h, "throw new Exception('Your role does not have permission to override room pricing.');") !== false,
    'a blocked price override is refused in a dry run too, but only logged for a real save');
$posCatch = strpos($h, '} catch (\Throwable $e) {');
$catch = $posCatch === false ? '' : substr($h, $posCatch);
$posCatchReply = strpos($catch, "if (\$cbDryRun) {");
$posCatchLog = strpos($catch, "rh_log_event('create-booking', 'error', 'Booking creation failed: '");
check($posCatchReply !== false && $posCatchLog !== false && $posCatchReply < $posCatchLog
    && strpos($catch, "'step' => rh_is_lock_conflict(\$e) ? 0 : \$cbStep, 'message' => \$error, 'field' => \$cbField") !== false,
    'a refused dry run answers {ok:false, step, message, field} before the error log line (no log row); a lock conflict is step 0');
$posMark = strpos($h, "if (\$cbDryRun && function_exists('rh_sa_hook_mark_refused')) {\n        rh_sa_hook_mark_refused();");
check($posMark !== false && $posMark < strpos($h, 'try {'), 'a dry run marks itself refused before anything runs (no Staff Activity row)');
$dryCode = (string)preg_replace('#//[^
]*#', '', $dryBlock); // comments out: only code counts
check(stripos($dryCode, 'applyCreditNote') === false && stripos($dryCode, 'INSERT') === false && stripos($dryCode, 'send') === false && stripos($dryCode, 'rh_log_event') === false,
    'the dry-run block applies no credit, inserts, sends and logs nothing');

// ── 2b. Create without reload (Phase C): response=wizard WITHOUT dry_run; the save is the plain save ──
check(substr_count($h, "\$cbWizard   = (\$_POST['response'] ?? '') === 'wizard' && !\$cbDryRun;") === 1, 'Create mode is response=wizard and not a dry run');
$cbtWiz = static function (array $post) use ($cbtDry): bool { return ($post['response'] ?? '') === 'wizard' && !$cbtDry($post); }; // the same expression
check($cbtWiz(['response' => 'wizard']) && !$cbtWiz(['response' => 'wizard', 'dry_run' => '1']) && !$cbtWiz(['response' => 'json']) && !$cbtWiz([]) && !$cbtWiz(['response' => ['wizard']]),
    'only response=wizard without dry_run=1 is a Create; the Assistant json replay and the plain form are not');
check(substr_count($src, "\$_POST['quote_total']") === 1, 'quote_total is read in one place only');
$posQuote = strpos($h, "\$cbQuoteTotal = \$_POST['quote_total'] ?? null;");
$quoteIf = false;
foreach ($wizRanges as [$o, $e]) { if ($posQuote !== false && $posQuote > $o && $posQuote < $e) { $quoteIf = substr($h, $o, $e - $o + 1); } }
check($quoteIf !== false && $posQuote > $dbClose && $posQuote < $posTxComment, 'the quote_total check is inside if ($cbWizard), after the dry-run block and before the Transaction comment (no lock, nothing written)');
check(is_string($quoteIf) && strpos($quoteIf, '$cbStep = 5;') !== false && strpos($quoteIf, 'round(abs($grand_total_with_vat - (float)$cbQuoteTotal), 2) > BALANCE_TOLERANCE') !== false
    && strpos($quoteIf, "'The price changed since you checked it: '") !== false && strpos($quoteIf, "'. Check the Review again.'") !== false
    && strpos($quoteIf, '!is_numeric($cbQuoteTotal)') !== false,
    'a total that differs by more than BALANCE_TOLERANCE (or a missing one) refuses at step 5, before anything is saved');
// The tolerance rule itself (same expression as the save): 0.01 passes, 0.02 refuses, a float wobble never refuses.
$cbtQuoteRefuses = static fn(float $grand, string $qt): bool => round(abs($grand - (float)$qt), 2) > BALANCE_TOLERANCE;
check(!$cbtQuoteRefuses(1000.00, '1000.00') && !$cbtQuoteRefuses(1000.01, '1000.00') && !$cbtQuoteRefuses(0.1 + 0.2, '0.30') && !$cbtQuoteRefuses(999.99, '1000.00')
    && $cbtQuoteRefuses(1000.02, '1000.00') && $cbtQuoteRefuses(1100.00, '1000.00') && $cbtQuoteRefuses(1000.00, '0'),
    'quote check: within 0.01 passes (incl. 0.1+0.2 vs 0.30), 0.02 or more refuses');
$posWizOk = strpos($h, "\$cbWizardReply(['ok' => true, 'redirect' => 'booking-details.php?id=' . (int)\$primary_id]);");
$posSuccFlash = strpos($h, "\$_SESSION['flash_message'] = [\n            'type' => 'success',");
$succGap = ($posWizOk !== false && $posSuccFlash !== false) ? substr($h, $posSuccFlash, $posWizOk - $posSuccFlash) : '';
$jsonExit3 = "if ((\$_POST['response'] ?? '') === 'json') { \$__jsonMsg = (string)\$_SESSION['flash_message']['text']; unset(\$_SESSION['flash_message']); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => true, 'booking_id' => (int)\$primary_id, 'reference' => (string)\$primary_ref, 'message' => \$__jsonMsg]); exit; } // Assistant replay (additive)";
$succGapNoJson = str_replace($jsonExit3, '', $succGap);
check($posWizOk !== false && $posSuccFlash !== false && $posSuccFlash < $posWizOk && strpos($succGap, $jsonExit3) !== false
    && strpos($succGapNoJson, 'unset(') === false && strpos($succGapNoJson, 'exit') === false,
    'a Create that saved answers {ok, redirect} after the success flash is set, and keeps the flash (nothing unset in between apart from the json branch)');
$posWizDup = strpos($h, "\$cbWizardReply(['ok' => true, 'duplicate' => true, 'redirect' => 'booking-details.php?id=' . (int)\$__existingBooking['id']]);");
check($posWizDup !== false && $posDupFlash < $posWizDup && $posWizDup < strpos($h, "header('Location: booking-details.php?id=' . (int)\$__existingBooking['id']);"),
    'a repeated Create (same client_uuid) answers like a success, with the plain path\'s flash');
$pos409 = strpos($h, 'http_response_code(409);');
$posWiz409 = strpos($h, "if (\$cbWizard) {\n                    \$cbWizardReply(['ok' => false, 'step' => 0,");
check($pos409 !== false && $posWiz409 !== false && $pos409 < $posWiz409 && $posWiz409 < strpos($h, "echo '<!doctype html><meta charset=\"utf-8\"><title>Still saving</title>"),
    'a Create still being saved by the first press answers {ok:false, step 0} with 409');
$posCatchWiz = strpos($catch, 'if ($cbWizard) {');
check($posCatchWiz !== false && $posCatchLog < $posCatchWiz && $posCatchWiz < strpos($catch, "(\$_POST['response'] ?? '') === 'json'")
    && strpos($catch, "\$cbWizardReply(['ok' => false, 'step' => rh_is_lock_conflict(\$e) ? 0 : \$cbStep, 'message' => \$error, 'field' => \$cbField]);") !== false,
    'a refused Create is logged like a plain refused save, then answers {ok:false, step, message, field}');
check(!preg_match('/name=["\'](response|quote_total|dry_run)["\']/', $src) && strpos($src, "body.set('response', 'wizard');\n                    body.set('quote_total',") !== false,
    'response, quote_total and dry_run are never form fields: the page script adds them to its own fetch (a no-JS post stays the plain save)');
check(strpos($src, "if (go === 'queue') submitNow(); else createBusy(false);") !== false && strpos($src, "submitOk = false;\n                createBusy(false);\n            }") !== false,
    'offline: the form is handed to the offline queue with the button still disabled, re-enabled only after');
check(strpos($h, "\$pdo->commit();\n        \$cbSaved = true;") !== false && strpos($catch, "if (\$cbSaved) {") !== false && strpos($catch, "'saved' => true") !== false
    && strpos($catch, 'Nothing was saved') === false,
    'a failure after the commit says the booking was saved (never "nothing was saved")');
// ── 3. response=json (the Assistant's replay) is byte-for-byte as before ──
$jsonExits = [
    "if ((\$_POST['response'] ?? '') === 'json') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'error' => 'still_saving', 'message' => 'This booking is still being saved from the first submission. Check the Bookings list before trying again.']); exit; } // Assistant replay (additive)",
    "if ((\$_POST['response'] ?? '') === 'json') { unset(\$_SESSION['flash_message']); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => true, 'booking_id' => (int)\$__existingBooking['id'], 'reference' => (string)\$__existingBooking['booking_reference'], 'duplicate' => true, 'message' => 'Booking already created (reference ' . (string)\$__existingBooking['booking_reference'] . ').']); exit; } // Assistant replay (additive)",
    "if ((\$_POST['response'] ?? '') === 'json') { \$__jsonMsg = (string)\$_SESSION['flash_message']['text']; unset(\$_SESSION['flash_message']); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => true, 'booking_id' => (int)\$primary_id, 'reference' => (string)\$primary_ref, 'message' => \$__jsonMsg]); exit; } // Assistant replay (additive)",
    "if ((\$_POST['response'] ?? '') === 'json') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'error' => \$error, 'message' => \$error]); exit; } // Assistant replay (additive)",
];
foreach ($jsonExits as $i => $line) {
    check(substr_count($src, $line) === 1, 'response=json exit ' . ($i + 1) . ' is unchanged, byte for byte');
}
check(substr_count($src, "(\$_POST['response'] ?? '') === 'json'") === 4, 'exactly four json exits (scripts/tests/ai_assistant_test.php:1557)');
foreach (["\$message = 'Booking already created (reference ' . htmlspecialchars((string)\$__existingBooking['booking_reference']) . '). Duplicate submission ignored.';",
          "header('Location: booking-details.php?id=' . (int)\$__existingBooking['id']);", "header('Location: booking-details.php?id=' . \$primary_id);"] as $line) {
    check(substr_count($h, $line) === 1, 'unchanged: ' . substr($line, 0, 70));
}
$posWord = strpos($src, 'rh_email_wording_from_post($cbWordingKey');
check($posWord !== false && $posWord < strpos($src, '// ── Transaction: create bookings for all lines'), 'the wording is still checked before the Transaction comment (scripts/tests/email_wording_test.php:240)');

// ── 4. availability: ONE closure, the old body unchanged, used by the dry run and (under the lock) by the save ──
$availOrig = <<<'PHP'
        $requested_qty_by_room = [];
        foreach ($room_lines as $line) {
            $rid = (int)$line['room_id'];
            $requested_qty_by_room[$rid] = ($requested_qty_by_room[$rid] ?? 0) + (int)$line['qty'];
        }
        foreach ($requested_qty_by_room as $rid => $needed) {
            $avail = checkRoomAvailability($rid, $check_in_date, $check_out_date, null, (int)($child_guests_by_room[$rid] ?? 0), (int)($child_rooms_by_room[$rid] ?? 0));
            if (empty($avail['available'])) {
                throw new Exception($avail['error'] ?? 'Selected room is no longer available for those dates.');
            }
            $remaining = (int)($avail['remaining_rooms'] ?? 0);
            if ($remaining < $needed) {
                $room_label = $avail['room']['name'] ?? ('room type ' . $rid);
                throw new Exception('Only ' . max(0, $remaining) . ' room(s) of "' . $room_label . '" remain available for those dates, but ' . $needed . ' were requested.');
            }
        }
        // Joined rooms: a booking of a member room type committed a moment ago has no room yet, so the pair
        // check cannot see it; the member type's own remaining count must still cover the pairs being sold.
        $joined_pairs = [];
        foreach ($room_lines as $line) {
            foreach ((array)($line['combination_queue'] ?? []) as $pair) {
                $joined_pairs[] = $pair;
            }
        }
        if ($joined_pairs) {
            $joined_err = rh_joined_members_stock_error($joined_pairs, $check_in_date, $check_out_date);
            if ($joined_err !== '') {
                throw new Exception($joined_err);
            }
        }
PHP;
$closureHead = '$cbCheckAvailability = static function () use ($room_lines, $check_in_date, $check_out_date, $child_guests_by_room, $child_rooms_by_room, &$cbStep, &$cbField): void {';
$posClosure = strpos($h, $closureHead);
[$clOpen, $clClose] = $posClosure === false ? [-1, -1] : cbt_block($h, $posClosure);
$clBody = $clClose > 0 ? substr($h, $clOpen + 1, $clClose - $clOpen - 1) : '';
check(substr_count($src, $closureHead) === 1, 'the availability closure is defined once, capturing the same inputs the old block read');
check($clBody !== '' && cbt_norm($clBody) === cbt_norm($availOrig), 'the closure body is the old availability block (only the stage-marker lines are new)');
check(strpos($clBody, "if (!empty(\$avail['blocked_dates']) || new DateTime(\$check_in_date) < new DateTime('today')) { \$cbStep = 1; \$cbField = 'checkInDate'; }") !== false,
    'past or blocked dates point to step 1 (the same past test as checkRoomAvailability), other availability errors to step 2');
check(substr_count($src, '$requested_qty_by_room = [];') === 1 && substr_count($src, 'rh_joined_members_stock_error(') === 1, 'the old inline availability block is gone (no second copy)');
$callPos = [];
for ($p = 0; ($p = strpos($h, '$cbCheckAvailability();', $p)) !== false; $p++) { $callPos[] = $p; }
$posLock = strpos($h, 'rh_lock_room_types($pdo, $lock_type_ids);');
check(count($callPos) === 2 && $callPos[0] > $dbOpen && $callPos[0] < $dbClose && $callPos[1] > $posBegin && $callPos[1] > $posLock,
    'the closure runs in the dry run (no transaction, no lock) and in the save right after the room types are locked');
$between = substr($h, (int)$clClose, (int)$posLock - (int)$clClose);
check(!preg_match('/\$(room_lines|check_in_date|check_out_date|child_guests_by_room|child_rooms_by_room)\s*(\[[^\]]*\]\s*)*(=[^=>]|\.=|\+=|-=)|&\$(room_lines|child_guests_by_room|child_rooms_by_room)/', $between),
    'nothing the closure captured changes between its definition and the save\'s call (same inputs as the old inline block)');
check((bool)preg_match('/\$cbCheckAvailability\(\);\s*\$cbStep = 5; \$cbField = \'\';\s*\$created_bookings = \[\];/', $h), 'after the save\'s check the insert loop follows as before');
// The old inline block left its working variables in the page scope; inside the closure they are local. Nothing after
// the closure (rest of the handler and the page) reads them, and $line is first re-set by the insert loop's foreach.
$posClosureSrc = (int)strpos($src, $closureHead);
$afterClosure = substr($src, $posClosureSrc + ($clClose - (int)$posClosure) + 1);
check($posClosure !== false && !preg_match('/\$(requested_qty_by_room|rid|needed|avail|remaining|room_label|joined_pairs|pair|joined_err)\b/', $afterClosure),
    'no variable the old inline availability block left behind is read after it, so the move into the closure loses nothing');
$afterSaveCall = substr($h, (int)($callPos[1] ?? 0));
check(isset($callPos[1]) && strpos($afterSaveCall, '$line') === strpos($afterSaveCall, 'foreach ($room_lines as $line) {') + strlen('foreach ($room_lines as '),
    'after the save\'s check, $line is first set by the insert loop (never read with the old block\'s leftover value)');

// ── 5. credit: ONE eligibility helper, used by the save (its old 0.005) and the dry run (0.01, correction 8) ──
check(substr_count($src, 'rh_cb_credit_queue(') === 2, 'the credit helper is called twice (save + dry run)');
check(strpos($dryBlock, "rh_cb_credit_queue(\$pdo, \$cbRawCreditIds, (string)\$guest_email, !empty(\$_POST['apply_credit_override']),") !== false
    && strpos($dryBlock, "trim((string)(\$_POST['apply_credit_override_reason'] ?? '')), BALANCE_TOLERANCE)['notes'];") !== false
    && strpos($dryBlock, 'if (!$is_tentative && is_array($cbRawCreditIds) && !empty($cbRawCreditIds)) {') !== false,
    'the dry run uses it at BALANCE_TOLERANCE, with the save\'s own inputs, never when tentative');
$posSaveCredit = strpos($h, "\$cnQueue = rh_cb_credit_queue(\$pdo, \$rawCreditIds, (string)\$guest_email, \$credit_override, \$credit_override_reason, 0.005)['notes'];");
check($posSaveCredit !== false && $posSaveCredit > $posBegin && strpos($h, 'if (!$is_tentative && is_array($rawCreditIds) && !empty($rawCreditIds)) {') < $posSaveCredit,
    'the save uses it at its old 0.005 threshold, after the commit, same condition as before');
check(strpos($src, 'FROM credit_notes WHERE id = ?') === false, 'the old inline credit filter is gone (no second copy of the rule)');
$credSrc = (string)file_get_contents($root . '/admin/includes/finance-booking-credit.php');
check(!preg_match('/INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|REPLACE\s+INTO|rh_log_event|\$_SESSION/i', $credSrc), 'the credit helper only reads');

// The old filter loop (HEAD e9ecb8d create-booking.php:1141-1175), verbatim, fed rows instead of one SELECT per id.
function cbt_old_credit_queue(array $rows, string $guest_email, bool $credit_override_active): array
{
    $cnQueue = [];
    foreach ($rows as $cn) {
        if (!$cn) {
            continue;
        }
        if (!in_array((string)$cn['status'], ['active', 'partially_applied'], true)) {
            continue;
        }
        if ($cn['expires_at'] !== null && (string)$cn['expires_at'] < date('Y-m-d')) {
            continue;
        }
        if ((float)$cn['balance'] <= 0.005) {
            continue;
        }
        $emailMatches = strcasecmp(trim((string)$cn['guest_email']), trim((string)$guest_email)) === 0;
        if (!$emailMatches && !$credit_override_active) {
            continue;
        }
        $cnQueue[] = [
            'id'       => (int)$cn['id'],
            'number'   => (string)$cn['credit_note_number'],
            'balance'  => (float)$cn['balance'],
            'override' => !$emailMatches,
            'cn_email' => (string)$cn['guest_email'],
        ];
    }
    return $cnQueue;
}
$today = date('Y-m-d');
$yday = date('Y-m-d', strtotime('-1 day'));
$tom = date('Y-m-d', strtotime('+1 day'));
$cn = static fn($id, $status, $bal, $email, $exp) => ['id' => (string)$id, 'credit_note_number' => 'CN-T-' . $id, 'balance' => $bal, 'guest_email' => $email, 'status' => $status, 'expires_at' => $exp];
$rows = [
    $cn(1, 'active', '100.00', 'guest@example.invalid', null),
    $cn(2, 'partially_applied', '50.50', '  Guest@Example.INVALID ', $tom),     // case + spaces still match
    $cn(3, 'fully_applied', '20.00', 'guest@example.invalid', null),
    $cn(4, 'expired', '20.00', 'guest@example.invalid', null),
    $cn(5, 'void', '20.00', 'guest@example.invalid', null),
    $cn(6, 'active', '20.00', 'guest@example.invalid', $yday),                 // expired yesterday
    $cn(7, 'active', '20.00', 'guest@example.invalid', $today),                // expires today: still usable
    $cn(8, 'active', '0.01', 'guest@example.invalid', null),                   // 0.01: the save queues it, applyCreditNote refuses it
    $cn(9, 'active', '0.00', 'guest@example.invalid', null),
    $cn(10, 'active', '0.02', 'guest@example.invalid', null),
    $cn(11, 'active', '300.00', 'someone.else@example.invalid', null),         // another account
    $cn(12, 'partially_applied', '6059.57', 'guest@example.invalid', $today . ' 23:59:59'),
];
$combos = [[false, ''], [true, ''], [true, '   '], [true, 'Guest asked to use her husband\'s credit'], [false, 'a reason without the tick']];
$same = true; $why = '';
foreach ($combos as [$ovr, $reasonRaw]) {
    $reason = trim($reasonRaw);                                    // the save trims the posted reason
    $old = cbt_old_credit_queue($rows, 'guest@example.invalid', $ovr && $reason !== '');
    $new = rh_cb_credit_pick($rows, 'guest@example.invalid', $ovr, $reason, $today, 0.005);
    if ($old !== $new['notes'] || $new['override_active'] !== ($ovr && $reason !== '')) { $same = false; $why = json_encode([$ovr, $reason, $old, $new]); }
}
check($same, 'real save: the helper at 0.005 returns exactly the old queue (same notes, order, casts, override flag) for every override/reason case', $why);
$ids = static fn(array $r) => array_column($r['notes'], 'id');
$dry = rh_cb_credit_pick($rows, 'guest@example.invalid', false, '', $today, BALANCE_TOLERANCE);
check($ids($dry) === [1, 2, 7, 10, 12], 'dry run (0.01): own active/partial notes, expiring today included; used, expired, void, 0.00 and 0.01 left out', json_encode($ids($dry)));
check($ids(rh_cb_credit_pick($rows, 'guest@example.invalid', false, '', $today, 0.005)) === [1, 2, 7, 8, 10, 12], 'the save (0.005) still queues the 0.01 note as before (applyCreditNote then refuses it: nothing applied either way)');
check(!in_array(11, $ids(rh_cb_credit_pick($rows, 'guest@example.invalid', true, '', $today, BALANCE_TOLERANCE)), true)
    && !in_array(11, $ids(rh_cb_credit_pick($rows, 'guest@example.invalid', true, '   ', $today, BALANCE_TOLERANCE)), true)
    && !in_array(11, $ids(rh_cb_credit_pick($rows, 'guest@example.invalid', false, 'reason', $today, BALANCE_TOLERANCE)), true),
    'another account\'s note needs the override tick AND a reason');
$ov = rh_cb_credit_pick($rows, 'guest@example.invalid', true, 'Guest asked', $today, BALANCE_TOLERANCE);
$n11 = array_values(array_filter($ov['notes'], static fn($n) => $n['id'] === 11));
check($ov['override_active'] && count($n11) === 1 && $n11[0]['override'] === true && $n11[0]['cn_email'] === 'someone.else@example.invalid'
    && $ov['notes'][0]['override'] === false && $ov['notes'][1]['override'] === false, 'with tick + reason it is included and flagged as an override; the guest\'s own notes are not');
check($ov['notes'][0]['balance'] === 100.0 && $ov['notes'][0]['id'] === 1 && $ov['notes'][0]['number'] === 'CN-T-1', 'figures are cast as the save used them (int id, float balance)');

// ── 6. basic validation split: same messages, same order; a real save throws the same combined text ──
$origMsgs = ['Please select at least one room.', 'Guest name is required.', 'Valid email is required.', 'Phone number is required.',
    'At least 1 guest required.', 'At least 1 adult is required.', 'Check-in date is required.', 'Check-out date is required.'];
preg_match_all("/\\\$errors\\[\\] = \\[(\\d), '(\\w+)', '([^']+)'\\];/", $h, $em, PREG_SET_ORDER);
$newMsgs = array_map(static fn($m) => $m[3], $em);
$steps = array_map(static fn($m) => (int)$m[1] . ':' . $m[2], $em);
check($newMsgs === $origMsgs, 'the eight validation messages are unchanged and in the same order', json_encode($newMsgs));
check($steps === ['2:roomLinesContainer', '3:guestName', '3:guestEmail', '3:guestPhone', '1:numGuests', '1:childGuests', '1:checkInDate', '1:checkOutDate'],
    'each message carries its step and field (rooms 2, guest 3, dates/guests 1)', json_encode($steps));
check(strpos($h, '$cbShown = $cbDryRun ? array_values(array_filter($errors, static fn($cbErr) => $cbErr[0] === $cbStep)) : $errors;') !== false
    && strpos($h, "throw new Exception(implode(' ', array_column(\$cbShown, 2)));") !== false, 'only a dry run narrows the messages to one step');
// Every combination of failing checks: a real save's text equals the old implode(' ', $errors); a dry run's is the first step's messages.
$entries = array_map(static fn($m) => [(int)$m[1], $m[2], $m[3]], $em);
$allSame = count($entries) === 8; $dryOk = true;
for ($mask = 1; $mask < 256 && $allSame; $mask++) {
    $errs = []; $old = [];
    foreach ($entries as $i => $e) { if ($mask & (1 << $i)) { $errs[] = $e; $old[] = $origMsgs[$i]; } }
    $step = min(array_column($errs, 0));
    $realShown = $errs;                                                                  // $cbDryRun false
    $dryShown = array_values(array_filter($errs, static fn($x) => $x[0] === $step));    // $cbDryRun true
    $allSame = implode(' ', array_column($realShown, 2)) === implode(' ', $old);
    $dryOk = $dryOk && $dryShown && count(array_unique(array_column($dryShown, 0))) === 1 && $dryShown[0][0] === $step;
}
check($allSame, 'all 255 combinations: a real save refuses with exactly the old combined text');
check($dryOk, 'a dry run shows the lowest step with errors and only its messages');

// ── 7. stage markers and the two checks that become warnings in a dry run ──
check((bool)preg_match("/\\\$cbStep\\s*= 1; \\\$cbField = 'checkInDate';\\s*\\\$checkIn\\s*= new DateTime\\(\\\$check_in_date\\);\\s*\\\$cbField\\s*= 'checkOutDate';/", $h), 'dates: step 1 (check-in / check-out field)');
check(strpos($h, "\$cbStep = 2; \$cbField = 'roomLinesContainer';\n\n        // ── Build & validate room lines") !== false, 'room lines, seating and prices: step 2');
check(strpos($h, "\$cbStep = 4; \$cbField = 'overCollectedCb';\n        if (\$payment_received && \$amount_collected > \$grand_total_with_vat + BALANCE_TOLERANCE && empty(\$_POST['confirm_overpay'])) {") !== false
    && strpos($h, "if (!\$cbDryRun) {\n                throw new Exception(rh_payment_overpay_message());\n            }\n            \$cbWarnings[] = ['step' => 4, 'message' => rh_payment_overpay_message()];") !== false,
    'overpay without the tick: a real save refuses as before, a dry run returns a step-4 warning');
check(strpos($h, "if (!\$cbDryRun) {\n                throw new Exception('The booking was not created. ' . \$cbWording['error']);\n            }\n            \$cbWarnings[] = ['step' => 5, 'message' => (string)\$cbWording['error']];") !== false,
    'refused email wording: a real save refuses as before, a dry run returns a step-5 warning');
check(substr_count($h, "throw new Exception('Security token invalid — refresh the page.');") === 1 && strpos($h, "throw new Exception('Booking system is currently disabled.');") < strpos($h, '$cbStep = 2'),
    'CSRF and booking-disabled refusals happen while the marker is still 0');
foreach (['nights', 'currency', 'vat_mode', 'vat_rate', 'levy_rate', 'lines', 'totals', 'payment', 'credit', 'still_owed', 'email_key', 'warnings',
          'room_id', 'name', 'qty', 'occupancy', 'rows', 'line_total_with_vat', 'room_price', 'list_rate', 'rate_plan_label', 'child_supplement',
          'total_amount', 'net', 'vat_amount', 'levy_amount', 'total_with_vat', 'price_source', 'vat', 'levy', 'received', 'amount', 'status',
          'over_by', 'final_status', 'will_apply', 'notes'] as $k) {
    if (strpos($dryBlock, "'" . $k . "'") === false) { check(false, "quote key '$k' is in the dry-run reply"); }
}
check(strpos($dryBlock, "'status'       => \$payment_status_val,") !== false && strpos($dryBlock, "'final_status' => rh_pay_state_key(\$cbCash + \$cbWillApply, \$cbStillOwed, false, \$grand_total_with_vat, false),") !== false,
    'payment.status is the cash-only status the save stores; final_status follows what is still owed after credit');
check(strpos($dryBlock, '$cbWillApply = $cbOwed > BALANCE_TOLERANCE ? round(max(0.0, min($cbEligible, $cbOwed)), 2) : 0.0;') !== false
    && strpos($dryBlock, '$cbCash     = round($amount_collected, 2);') !== false,
    'will_apply = max(0, min(eligible, total - cash)) with cash after the payment normalisation; 0 when 0.01 or less is owed (applyCreditNote refuses)');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
