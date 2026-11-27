<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); } // never runnable over the web
/**
 * New Booking "Payment Received Now" (admin/create-booking.php). NO DATABASE: reads the page source only.
 * Story (owner 2026-11-27): a full payment was refused as "more than the balance due" because the page priced a
 * double/triple room with no price of its own at x1.2 / x1.4 while the save used the nightly price; the amount
 * also stopped following the total, and a refused save came back with the room and payment cleared.
 * Usage (repo root): php scripts/tests/create_booking_payment_test.php
 */
$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "PASS $l\n"; }
function bad(string $l, string $d = ''): void { global $fail; $fail++; echo "FAIL $l" . ($d !== '' ? ": $d" : '') . "\n"; }
function check(bool $c, string $l, string $d = ''): bool { $c ? ok($l) : bad($l, $d); return $c; }

$src = (string)file_get_contents($root . '/admin/create-booking.php');
check($src !== '', 'create-booking.php read');

// 1. The page and the save use the same tier fallback: no price of its own = the nightly price.
check(!preg_match('/price_per_night\'\]\s*\*\s*1\.[0-9]/', $src), 'no invented x1.2 / x1.4 tier price on the page');
foreach (['single', 'double', 'triple'] as $t) {
    check(strpos($src, "'price_$t'           => (float)(!empty(\$r['price_{$t}_occupancy']) ? \$r['price_{$t}_occupancy'] : \$r['price_per_night'])") !== false,
        "page $t price falls back to the nightly price");
    check(strpos($src, "'$t' => !empty(\$r_row['price_{$t}_occupancy']) ? (float)\$r_row['price_{$t}_occupancy'] : (float)\$r_row['price_per_night']") !== false
        || strpos($src, "'$t'   => !empty(\$r_row['price_{$t}_occupancy']) ? (float)\$r_row['price_{$t}_occupancy'] : (float)\$r_row['price_per_night']") !== false
        || preg_match("/'$t'\s*=>\s*!empty\(\\\$r_row\['price_{$t}_occupancy'\]\)\s*\?\s*\(float\)\\\$r_row\['price_{$t}_occupancy'\]\s*:\s*\(float\)\\\$r_row\['price_per_night'\]/", $src),
        "save $t price falls back to the nightly price");
}

// 2. The auto-filled amount keeps following the total: only typing clears data-auto.
check(strpos($src, 'oninput="this.removeAttribute(\'data-auto\'); checkPartialPayment()"') !== false, 'typing an amount clears data-auto');
$cpp = '';
if (preg_match('/function checkPartialPayment\(\) \{(.*?)\n        \}/s', $src, $m)) { $cpp = $m[1]; }
check($cpp !== '', 'checkPartialPayment found');
check(strpos($cpp, "removeAttribute('data-auto')") === false, 'checkPartialPayment does not clear data-auto');
// Step-by-step screen (plan Phase A, 2026-11-28): the amount follows the price check (the save's own total minus the
// guest credit) while it is auto-filled; a typed or disabled amount is never touched; paid / part paid / paid more
// are judged against that total with the save's 0.01 tolerance.
$cfa = '';
if (preg_match('/function cbFillAmount\(q\) \{(.*?)\n        \}/s', $src, $m)) { $cfa = $m[1]; }
check($cfa !== '', 'cbFillAmount found');
check(strpos($cfa, "if (!amt || amt.disabled) return false;") !== false
    && strpos($cfa, "if (amt.value && amt.getAttribute('data-auto') !== '1') return false;") !== false, 'a typed or disabled amount is never changed by the price check');
check(strpos($cfa, 'q.totals && q.totals.total_with_vat') !== false && strpos($cfa, 'Math.min(eligible, total)') !== false, 'the amount is the server total minus the eligible credit, capped at the total');
check(strpos($src, "if (!cbQ.quote && !amtEl.disabled && (!amtEl.value || amtEl.getAttribute('data-auto') === '1'))") !== false, 'before the first price check the amount follows the page estimate, as before');
check(strpos($src, 'const CB_TOL = 0.01;') !== false && strpos($cpp, 'overBy > CB_TOL') !== false && strpos($cpp, 'grand - CB_TOL') !== false, 'paid / part paid / over use the 0.01 tolerance');
check(strpos($cpp, "q ? (Number(q.totals.total_with_vat) || 0) : (cbQ.est || 0)") !== false, 'judged against the price check total (the page estimate only before the first check)');

// 2b. Tentative: the payment fields are cleared and disabled, so the browser does not post them (the save ignores
// them unless payment_received is '1').
check(strpos($src, "const payFields = [hid, el('paymentMethod'), amt, overCb];") !== false
    && strpos($src, "payFields.forEach(function (f) { if (f) f.disabled = true; });") !== false, 'tentative disables payment_received, method, amount and the overpay tick');
check(strpos($src, "\$payment_received     = isset(\$_POST['payment_received']) && \$_POST['payment_received'] === '1';") !== false, 'the save treats a missing payment_received as not paid');

// 2c. One step at a time: the browser's own checks are replaced by the page's step checks; Create still posts the form.
check(strpos($src, '<form method="POST" id="createBookingForm" data-offline-queue="1" novalidate>') !== false, 'the form has novalidate (the step checks run instead)');
check(strpos($src, 'IntersectionObserver') === false, 'no scroll observer: one step on screen at a time');
check(strpos($src, "el('overCollectedCb').required") === false && strpos($src, 'Tick "The guest paid more than owed" to confirm') !== false, 'the overpay tick is enforced by the step 4 check, not a hidden required');
check(strpos($src, "body.set('response', 'wizard');") !== false && strpos($src, "body.set('dry_run', '1');") !== false, 'the price check posts the form with response=wizard and dry_run=1');
check(strpos($src, 'if (submitOk) { submitOk = false; return; }') !== false && strpos($src, "window.addEventListener('submit', function (e) {") !== false, 'only the checked Create submit reaches the offline queue and the server');

// 3. A refused save brings back the room types and payment, never the overpay tick.
check(strpos($src, "\$_SERVER['REQUEST_METHOD'] === 'POST' && \$error !== ''") !== false, 'repost only after a refused POST');
check(strpos($src, 'const cbRepost = <?php echo json_encode($cb_repost, JSON_HEX_TAG') !== false, 'repost data reaches the page escaped');
check(strpos($src, "cbRepost.room_types.forEach(t => addRoomLine(t))") !== false, 'room lines come back');
check(strpos($src, 'payCb.checked = true;') !== false && strpos($src, '!payCb.disabled') !== false, 'payment comes back unless payment is disabled');
$rp = '';
if (preg_match('/\$cb_repost = \[(.*?)\];/s', $src, $m)) { $rp = $m[1]; }
check($rp !== '' && strpos($rp, 'confirm_overpay') === false, 'the overpay tick is never brought back');
check($rp !== '' && strpos($rp, "'step'       => isset(\$cbStep) ? (int)\$cbStep : 0,") !== false && strpos($rp, "'message'    => (string)\$error,") !== false, 'a refused save reopens the step it belongs to (read-only stage marker)');
check(preg_match("/in_array\(\\\$cbRpMethod, \['cash', 'bank_transfer', 'mobile_money', 'card', 'cheque', 'other'\], true\)/", $src) === 1, 'payment method is whitelisted');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
