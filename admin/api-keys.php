<?php
require_once 'admin-init.php';

if (($_SESSION['admin_role'] ?? '') !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$messageType = 'success';
$revealedKey = '';
$testClientPackageKey = '';
$liveTestResult = null;
$testMethodInput = 'GET';
$testEndpointInput = '/rooms';
$testPayloadInput = "";

$apiBaseUrl = rtrim((string)getSetting('site_url', ''), '/');
if ($apiBaseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $apiBaseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$apiBaseUrl .= '/api/index.php';

$permissionCatalog = [
    'rooms.read' => 'Read room catalog and pricing.',
    'availability.check' => 'Check room/date availability.',
    'bookings.create' => 'Create booking records.',
    'bookings.read' => 'Read booking details by ID/reference.',
    'payments.view' => 'Read payment records.',
    'payments.create' => 'Create payment records.',
    'payments.edit' => 'Update payment records.',
    'payments.delete' => 'Delete payment records.',
    'site_settings.read' => 'Read public site settings payload.',
    'blocked_dates.write' => 'Create/update/delete blocked dates.'
];

$permissionEndpointMap = [
    'rooms.read' => 'GET /api/index.php/rooms',
    'availability.check' => 'GET /api/index.php/availability',
    'bookings.create' => 'POST /api/index.php/bookings',
    'bookings.read' => 'GET /api/index.php/bookings?id=... and /api/index.php/bookings/{id}',
    'payments.view' => 'GET /api/index.php/payments and /api/index.php/payments/{id}',
    'payments.create' => 'POST /api/index.php/payments',
    'payments.edit' => 'PUT /api/index.php/payments/{id}',
    'payments.delete' => 'DELETE /api/index.php/payments/{id}',
    'site_settings.read' => 'GET /api/index.php/site-settings',
    'blocked_dates.write' => 'POST/PUT/DELETE /api/index.php/blocked-dates'
];

$permissionSampleSnippets = [
    'rooms.read' => "fetch('/api/index.php/rooms', {\n  headers: { 'X-API-Key': 'YOUR_API_KEY' }\n});",
    'availability.check' => "fetch('/api/index.php/availability?room_id=1&check_in=2026-05-10&check_out=2026-05-12', {\n  headers: { 'X-API-Key': 'YOUR_API_KEY' }\n});",
    'bookings.create' => "fetch('/api/index.php/bookings', {\n  method: 'POST',\n  headers: {\n    'Content-Type': 'application/json',\n    'X-API-Key': 'YOUR_API_KEY'\n  },\n  body: JSON.stringify({ guest_name: 'John Doe', room_id: 1 })\n});",
    'bookings.read' => "fetch('/api/index.php/bookings/12345', {\n  headers: { 'X-API-Key': 'YOUR_API_KEY' }\n});",
    'payments.view' => "fetch('/api/index.php/payments', {\n  headers: { 'X-API-Key': 'YOUR_API_KEY' }\n});",
    'payments.create' => "fetch('/api/index.php/payments', {\n  method: 'POST',\n  headers: {\n    'Content-Type': 'application/json',\n    'X-API-Key': 'YOUR_API_KEY'\n  },\n  body: JSON.stringify({ booking_id: 12345, amount: 50000 })\n});",
    'payments.edit' => "fetch('/api/index.php/payments/99', {\n  method: 'PUT',\n  headers: {\n    'Content-Type': 'application/json',\n    'X-API-Key': 'YOUR_API_KEY'\n  },\n  body: JSON.stringify({ amount: 65000 })\n});",
    'payments.delete' => "fetch('/api/index.php/payments/99', {\n  method: 'DELETE',\n  headers: { 'X-API-Key': 'YOUR_API_KEY' }\n});",
    'site_settings.read' => "fetch('/api/index.php/site-settings', {\n  headers: { 'X-API-Key': 'YOUR_API_KEY' }\n});",
    'blocked_dates.write' => "fetch('/api/index.php/blocked-dates', {\n  method: 'POST',\n  headers: {\n    'Content-Type': 'application/json',\n    'X-API-Key': 'YOUR_API_KEY'\n  },\n  body: JSON.stringify({ room_id: 1, block_date: '2026-05-10' })\n});"
];

function safePermissions(array $permissions, array $catalog): array {
    $valid = [];
    foreach ($permissions as $perm) {
        $perm = trim((string)$perm);
        if ($perm !== '' && isset($catalog[$perm])) {
            $valid[] = $perm;
        }
    }
    return array_values(array_unique($valid));
}

function runLiveApiRequest(string $apiBaseUrl, string $apiKey, string $method, string $endpoint, ?array $payload = null): array {
    $url = rtrim($apiBaseUrl, '/') . '/' . ltrim($endpoint, '/');
    $headers = [
        'X-API-Key: ' . $apiKey,
        'Content-Type: application/json'
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $bodyRaw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'body_raw' => $bodyRaw !== false ? (string)$bodyRaw : '',
            'body_json' => is_string($bodyRaw) ? json_decode($bodyRaw, true) : null,
            'transport_error' => $curlError !== '' ? $curlError : null,
        ];
    }

    $headerString = implode("\r\n", $headers) . "\r\n";
    $opts = [
        'http' => [
            'method' => $method,
            'header' => $headerString,
            'ignore_errors' => true,
            'timeout' => 20,
        ]
    ];
    if ($payload !== null) {
        $opts['http']['content'] = json_encode($payload);
    }

    $context = stream_context_create($opts);
    $bodyRaw = @file_get_contents($url, false, $context);
    $httpCode = 0;
    if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $httpCode = (int)$m[1];
    }

    return [
        'http_code' => $httpCode,
        'body_raw' => is_string($bodyRaw) ? $bodyRaw : '',
        'body_json' => is_string($bodyRaw) ? json_decode($bodyRaw, true) : null,
        'transport_error' => $bodyRaw === false ? 'Request failed (stream transport).' : null,
    ];
}

function buildTestClientPhpSnippets(string $apiBaseUrl, string $apiKey): array {
    $apiBaseEsc = addslashes($apiBaseUrl);
    $apiKeyEsc = addslashes($apiKey);

        $phpSnippetClass = <<<'PHP'
<?php
$apiBase = '__API_BASE__';
$apiKey = '__API_KEY__';

function callApi($method, $endpoint, $apiBase, $apiKey, $payload = null) {
        $ch = curl_init($apiBase . $endpoint);
        $headers = ['X-API-Key: ' . $apiKey, 'Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => json_decode($response, true), 'raw' => is_string($response) ? $response : ''];
}

function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function siteBaseFromApiBase($apiBase) {
    $parts = parse_url($apiBase);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $origin = $host !== '' ? $scheme . '://' . $host . $port : '';

    $path = $parts['path'] ?? '';
    $sitePath = preg_replace('#/api(?:/index\\.php)?/?$#i', '', $path);
    $sitePath = is_string($sitePath) ? rtrim($sitePath, '/') : '';

    if ($origin === '') {
        return '';
    }
    if ($sitePath === '' || $sitePath === '/') {
        return $origin;
    }
    return $origin . $sitePath;
}

function toAbsoluteUrl($urlValue, $apiBase) {
    if (!is_string($urlValue) || trim($urlValue) === '') {
        return '';
    }

    $value = trim($urlValue);
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    $parts = parse_url($apiBase);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $origin = $host !== '' ? $scheme . '://' . $host . $port : '';

    $path = $parts['path'] ?? '';
    $sitePath = preg_replace('#/api(?:/index\\.php)?/?$#i', '', $path);
    $sitePath = is_string($sitePath) ? rtrim($sitePath, '/') : '';

    if ($origin === '') {
        return $value;
    }

    if (strpos($value, '/') === 0) {
        if ($sitePath !== '' && (strpos($value, $sitePath . '/') === 0 || $value === $sitePath)) {
            return $origin . $value;
        }
        if ($sitePath !== '') {
            return $origin . $sitePath . $value;
        }
        return $origin . $value;
    }

    if ($sitePath !== '') {
        return $origin . $sitePath . '/' . ltrim($value, '/');
    }
    return $origin . '/' . ltrim($value, '/');
}

function siteRootFsPathFromApiBase($apiBase) {
    $parts = parse_url($apiBase);
    $path = $parts['path'] ?? '';
    $sitePath = preg_replace('#/api(?:/index\\.php)?/?$#i', '', $path);
    $sitePath = is_string($sitePath) ? rtrim($sitePath, '/') : '';

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($docRoot === '') {
        return '';
    }

    return str_replace('\\', '/', $docRoot . ($sitePath !== '' ? $sitePath : ''));
}

function findExistingRoomImage($room, $apiBase) {
    $siteRootFs = siteRootFsPathFromApiBase($apiBase);
    if ($siteRootFs === '') {
        return '';
    }

    $roomsDir = rtrim($siteRootFs, '/') . '/images/rooms';
    if (!is_dir($roomsDir)) {
        return '';
    }

    $roomId = $room['id'] ?? $room['room_id'] ?? null;
    if ($roomId !== null && $roomId !== '') {
        $cleanId = preg_replace('/[^0-9]/', '', (string)$roomId);
        if ($cleanId !== '') {
            $patterns = [
                $roomsDir . '/room_' . $cleanId . '_featured_*.*',
                $roomsDir . '/room_' . $cleanId . '_*.*',
            ];
            foreach ($patterns as $pattern) {
                $matches = glob($pattern);
                if (!empty($matches) && isset($matches[0])) {
                    return toAbsoluteUrl('images/rooms/' . basename((string)$matches[0]), $apiBase);
                }
            }
        }
    }

    $anyRoomImages = glob($roomsDir . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
    if (!empty($anyRoomImages) && isset($anyRoomImages[0])) {
        return toAbsoluteUrl('images/rooms/' . basename((string)$anyRoomImages[0]), $apiBase);
    }

    return '';
}

function pickImageUrl($room, $apiBase) {
    $candidates = [
        $room['image_url'] ?? null,
        $room['image'] ?? null,
        $room['main_image'] ?? null,
        $room['thumbnail'] ?? null,
        $room['featured_image'] ?? null,
    ];

    if (isset($room['gallery']) && is_array($room['gallery'])) {
        foreach ($room['gallery'] as $galleryItem) {
            if (is_array($galleryItem)) {
                $candidates[] = $galleryItem['image_url'] ?? null;
                $candidates[] = $galleryItem['url'] ?? null;
                $candidates[] = $galleryItem['image'] ?? null;
            } else {
                $candidates[] = $galleryItem;
            }
        }
    }
    if (isset($room['images']) && is_array($room['images'])) {
        foreach ($room['images'] as $imageItem) {
            if (is_array($imageItem)) {
                $candidates[] = $imageItem['image_url'] ?? null;
                $candidates[] = $imageItem['url'] ?? null;
                $candidates[] = $imageItem['image'] ?? null;
            } else {
                $candidates[] = $imageItem;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        $resolved = toAbsoluteUrl($candidate, $apiBase);
        if ($resolved !== '') {
            return $resolved;
        }
    }

    $existingRoomImage = findExistingRoomImage($room, $apiBase);
    if ($existingRoomImage !== '') {
        return $existingRoomImage;
    }

    return toAbsoluteUrl('images/hero/slide1.jpg', $apiBase);
}

    function roomLinkUrl($room, $apiBase) {
        $linkCandidates = [
            $room['url'] ?? null,
            $room['link'] ?? null,
            $room['room_url'] ?? null,
            $room['details_url'] ?? null,
            $room['page_url'] ?? null,
        ];

        foreach ($linkCandidates as $candidate) {
            $resolved = toAbsoluteUrl($candidate, $apiBase);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $slug = $room['slug'] ?? $room['room_slug'] ?? null;
        if (is_string($slug) && trim($slug) !== '') {
            return toAbsoluteUrl('room.php?slug=' . urlencode(trim($slug)), $apiBase);
        }

        $id = $room['id'] ?? $room['room_id'] ?? null;
        if ($id !== null && $id !== '') {
            return toAbsoluteUrl('room.php?id=' . urlencode((string)$id), $apiBase);
        }

        return toAbsoluteUrl('rooms-showcase.php', $apiBase);
    }

$roomsResponse = callApi('GET', '/rooms', $apiBase, $apiKey);
$body = is_array($roomsResponse['body']) ? $roomsResponse['body'] : [];
$rooms = [];
if (isset($body['data']['rooms']) && is_array($body['data']['rooms'])) {
    $rooms = $body['data']['rooms'];
} elseif (isset($body['rooms']) && is_array($body['rooms'])) {
        $rooms = $body['rooms'];
} elseif (isset($body['data']) && is_array($body['data']) && array_values($body['data']) === $body['data']) {
    $rooms = $body['data'];
} elseif (array_values($body) === $body) {
        $rooms = $body;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>Rooms Showcase</title>
    <style>
        :root {
            --bg: #f2f6ff;
            --surface: #ffffff;
            --line: #dbe4f0;
            --text: #0f172a;
            --muted: #475569;
            --brand: #0369a1;
            --brand-2: #0c4a6e;
        }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: radial-gradient(circle at top right, #dbeafe 0%, var(--bg) 45%, #eef2ff 100%); margin: 0; padding: 24px; color: var(--text); }
        .status { margin: 0 0 18px; font-size: 14px; color: #1e293b; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 18px; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; overflow: hidden; box-shadow: 0 16px 36px rgba(15,23,42,.10); transform: translateY(10px); opacity: 0; animation: reveal .55s ease forwards; }
        .card:nth-child(2) { animation-delay: .06s; }
        .card:nth-child(3) { animation-delay: .12s; }
        .card:nth-child(4) { animation-delay: .18s; }
        .card:hover { transform: translateY(-5px); box-shadow: 0 22px 42px rgba(15,23,42,.16); }
        .card-media { position: relative; aspect-ratio: 16 / 10; background: #0f172a; overflow: hidden; }
        .card img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .35s ease; }
        .card:hover img { transform: scale(1.04); }
        .hd-chip { position: absolute; right: 10px; top: 10px; background: rgba(15,23,42,.8); color: #fff; border-radius: 999px; padding: 5px 10px; font-size: 11px; letter-spacing: .03em; }
        .card-body { padding: 14px; }
        .title { margin: 0 0 6px; font-size: 20px; }
        .meta { margin: 4px 0; color: var(--muted); font-size: 13px; }
        .desc { margin: 8px 0 0; color: #334155; font-size: 14px; line-height: 1.5; }
        .actions { margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { border: 0; border-radius: 8px; padding: 8px 12px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-hd { background: #e0f2fe; color: #075985; }
        .btn-details { color: #fff; background: linear-gradient(135deg, var(--brand), var(--brand-2)); }
        .warn { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; border-radius: 10px; padding: 12px; }
        pre { white-space: pre-wrap; word-break: break-word; }
        .lightbox { position: fixed; inset: 0; background: rgba(2,6,23,.88); display: none; align-items: center; justify-content: center; padding: 24px; z-index: 50; }
        .lightbox.active { display: flex; animation: fadeIn .22s ease; }
        .lightbox-inner { max-width: 1100px; width: 100%; }
        .lightbox img { width: 100%; max-height: 85vh; object-fit: contain; border-radius: 12px; border: 1px solid rgba(255,255,255,.22); }
        .lightbox-close { margin-top: 10px; background: #fff; color: #0f172a; border: 0; border-radius: 8px; padding: 8px 12px; font-weight: 700; cursor: pointer; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes reveal { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>
    <p class="status"><strong>HTTP <?php echo (int)$roomsResponse['status']; ?></strong> from <?php echo e($apiBase . '/rooms'); ?></p>

    <?php if (!empty($rooms)): ?>
        <div class="grid">
            <?php foreach ($rooms as $room): ?>
                <?php
                    if (!is_array($room)) {
                            continue;
                    }
                    $name = $room['room_name'] ?? $room['name'] ?? $room['title'] ?? 'Room';
                    $description = $room['description'] ?? $room['short_description'] ?? '';
                    $price = $room['price_per_night'] ?? $room['price'] ?? $room['rate'] ?? null;
                    $capacity = $room['capacity'] ?? $room['max_guests'] ?? $room['occupancy'] ?? null;
                    $imageUrl = pickImageUrl($room, $apiBase);
                    $roomLink = roomLinkUrl($room, $apiBase);
                ?>
                <article class="card">
                    <figure class="card-media">
                        <img src="<?php echo e($imageUrl); ?>" alt="<?php echo e($name); ?>" loading="lazy" decoding="async" data-full="<?php echo e($imageUrl); ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'640\' height=\'420\' viewBox=\'0 0 640 420\'%3E%3Cdefs%3E%3ClinearGradient id=\'g\' x1=\'0\' x2=\'1\' y1=\'0\' y2=\'1\'%3E%3Cstop offset=\'0%25\' stop-color=\'%23cbd5e1\'/%3E%3Cstop offset=\'100%25\' stop-color=\'%23e2e8f0\'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=\'640\' height=\'420\' fill=\'url(%23g)\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' dominant-baseline=\'middle\' text-anchor=\'middle\' font-family=\'Arial,sans-serif\' font-size=\'28\' fill=\'%23334155\'%3ERoom image unavailable%3C/text%3E%3C/svg%3E';">
                        <span class="hd-chip">HD</span>
                    </figure>
                    <div class="card-body">
                        <h2 class="title"><?php echo e($name); ?></h2>
                        <?php if ($price !== null): ?><p class="meta">Price: <?php echo e($price); ?> per night</p><?php endif; ?>
                        <?php if ($capacity !== null): ?><p class="meta">Capacity: <?php echo e($capacity); ?> guests</p><?php endif; ?>
                        <?php if ($description !== ''): ?><p class="desc"><?php echo e($description); ?></p><?php endif; ?>
                        <div class="actions">
                            <button type="button" class="btn btn-hd js-open-hd" data-image="<?php echo e($imageUrl); ?>">View HD</button>
                            <a class="btn btn-details" href="<?php echo e($roomLink); ?>" target="_blank" rel="noopener noreferrer">Open details</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="warn">
            <p><strong>No room list was found in the API response payload.</strong></p>
            <p>Raw response shown below so you can map your exact field names.</p>
            <pre><?php echo e($roomsResponse['raw']); ?></pre>
        </div>
    <?php endif; ?>
    <div class="lightbox" id="hdLightbox" aria-hidden="true">
        <div class="lightbox-inner">
            <img id="hdLightboxImage" src="" alt="Room image preview">
            <button class="lightbox-close" id="hdLightboxClose" type="button">Close</button>
        </div>
    </div>
    <script>
        (function () {
            var lightbox = document.getElementById('hdLightbox');
            var lightboxImg = document.getElementById('hdLightboxImage');
            var closeBtn = document.getElementById('hdLightboxClose');

            function closeLightbox() {
                lightbox.classList.remove('active');
                lightboxImg.src = '';
                lightbox.setAttribute('aria-hidden', 'true');
            }

            document.querySelectorAll('.js-open-hd').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var src = this.getAttribute('data-image') || '';
                    if (!src) {
                        return;
                    }
                    lightboxImg.src = src;
                    lightbox.classList.add('active');
                    lightbox.setAttribute('aria-hidden', 'false');
                });
            });

            closeBtn.addEventListener('click', closeLightbox);
            lightbox.addEventListener('click', function (e) {
                if (e.target === lightbox) {
                    closeLightbox();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeLightbox();
                }
            });
        })();
    </script>
</body>
</html>
PHP;

        $phpSnippetAvailability = <<<'PHP'
<?php
$apiBase = '__API_BASE__';
$apiKey = '__API_KEY__';

function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

    function apiGet($apiBase, $apiKey, $endpoint, $query = []) {
        $url = $apiBase . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
                'status' => (int)$status,
                'raw' => is_string($response) ? $response : '',
                'json' => is_string($response) ? json_decode($response, true) : null
        ];
}

        $roomId = isset($_GET['room_id']) ? max(1, (int)$_GET['room_id']) : 1;
        $checkIn = isset($_GET['check_in']) ? trim((string)$_GET['check_in']) : date('Y-m-d', strtotime('+7 days'));
        $checkOut = isset($_GET['check_out']) ? trim((string)$_GET['check_out']) : date('Y-m-d', strtotime('+9 days'));
        $guestCount = isset($_GET['number_of_guests']) ? max(1, (int)$_GET['number_of_guests']) : 2;

        $result = apiGet($apiBase, $apiKey, '/availability', [
            'room_id' => $roomId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'number_of_guests' => $guestCount
        ]);

        $payload = is_array($result['json']) ? $result['json'] : [];
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>Check Availability</title>
    <style>
        :root {
            --bg: #f3f6fb;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --line: #dbe4f0;
            --brand: #0369a1;
            --brand-dark: #0c4a6e;
        }
        body { font-family: Arial, sans-serif; margin: 0; padding: 24px; background: radial-gradient(circle at top right, #e0f2fe 0%, var(--bg) 45%, #eef2ff 100%); color: var(--text); }
        .card { max-width: 860px; margin: 0 auto; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; box-shadow: 0 14px 34px rgba(15,23,42,.10); animation: rise .45s ease; }
        .title { margin: 0 0 12px; font-size: 26px; letter-spacing: -.02em; }
        .grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 10px; }
        input, button, select { border: 1px solid #c7d2e3; border-radius: 10px; padding: 10px; font-size: 14px; box-sizing: border-box; }
        input, select { background: #fff; }
        button { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; cursor: pointer; border: 0; font-weight: 600; }
        button:hover { filter: brightness(1.03); }
        .status { margin-top: 14px; padding: 12px; border-radius: 10px; font-weight: 600; }
        .ok { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .no { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .neutral { background: #e0f2fe; color: #0c4a6e; border: 1px solid #7dd3fc; }
        .meta { margin: 10px 0 0; color: #334155; font-size: 14px; }
        .room-card { margin-top: 12px; border: 1px solid var(--line); border-radius: 12px; padding: 12px; background: #fff; }
        .price { font-weight: 700; color: var(--brand); }
        .muted { color: var(--muted); font-size: 13px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-top: 12px; }
        .stat { border: 1px solid var(--line); background: #f8fbff; border-radius: 12px; padding: 10px; }
        .stat-label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
        .stat-value { margin-top: 3px; font-size: 18px; font-weight: 700; color: #0f172a; }
        pre { white-space: pre-wrap; word-break: break-word; background: #0b1220; color: #e2e8f0; border-radius: 10px; padding: 12px; border: 1px solid #1e293b; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
        @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="title">Room Availability</h1>
        <form method="GET" class="grid">
            <input type="number" min="1" name="room_id" value="<?php echo e($roomId); ?>" placeholder="Room ID">
            <input type="date" name="check_in" value="<?php echo e($checkIn); ?>">
            <input type="date" name="check_out" value="<?php echo e($checkOut); ?>">
            <input type="number" min="1" name="number_of_guests" value="<?php echo e($guestCount); ?>" placeholder="Guests">
            <button type="submit">Check availability</button>
        </form>

        <?php
            $isAvailable = isset($data['available']) ? (bool)$data['available'] : null;
            $statusClass = 'neutral';
            $statusText = 'Availability response loaded.';
            if ($isAvailable === true) {
                    $statusClass = 'ok';
                    $statusText = 'Available for selected dates.';
            } elseif ($isAvailable === false) {
                    $statusClass = 'no';
                    $statusText = 'Not available for selected dates.';
            }
        ?>
        <div class="status <?php echo e($statusClass); ?>">
            <strong>HTTP <?php echo (int)$result['status']; ?></strong> - <?php echo e($statusText); ?>
        </div>

        <p class="meta">Room ID: <?php echo e($roomId); ?> | Check-in: <?php echo e($checkIn); ?> | Check-out: <?php echo e($checkOut); ?> | Guests: <?php echo e($guestCount); ?></p>

        <?php
            $nights = isset($data['dates']['nights']) ? (int)$data['dates']['nights'] : null;
            $nightly = isset($data['pricing']['price_per_night']) ? (float)$data['pricing']['price_per_night'] : null;
            $total = isset($data['pricing']['total']) ? (float)$data['pricing']['total'] : null;
            $currency = (string)($data['pricing']['currency'] ?? '');
        ?>
        <div class="stats">
            <div class="stat">
                <div class="stat-label">Status</div>
                <div class="stat-value"><?php echo $isAvailable === true ? 'Available' : ($isAvailable === false ? 'Unavailable' : 'Unknown'); ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Nights</div>
                <div class="stat-value"><?php echo $nights !== null ? e($nights) : '-'; ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Nightly Rate</div>
                <div class="stat-value"><?php echo $nightly !== null ? e($currency . ' ' . number_format($nightly, 0)) : '-'; ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Estimated Total</div>
                <div class="stat-value"><?php echo $total !== null ? e($currency . ' ' . number_format($total, 0)) : '-'; ?></div>
            </div>
        </div>

        <?php if (isset($data['room']) && is_array($data['room'])): ?>
            <div class="room-card">
                <strong><?php echo e($data['room']['name'] ?? 'Room'); ?></strong>
                <?php if (!empty($data['pricing']['currency']) && isset($data['pricing']['price_per_night'])): ?>
                    <div class="price"><?php echo e($data['pricing']['currency']); ?> <?php echo e(number_format((float)$data['pricing']['price_per_night'], 0)); ?> / night</div>
                <?php endif; ?>
                <?php if (isset($data['dates']['nights'])): ?>
                    <div class="muted"><?php echo e((int)$data['dates']['nights']); ?> night(s)</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($data['conflicts']) && is_array($data['conflicts'])): ?>
            <p class="meta"><strong>Conflicting bookings:</strong></p>
            <pre><?php echo e(json_encode($data['conflicts'], JSON_PRETTY_PRINT)); ?></pre>
        <?php endif; ?>

        <?php if (is_array($result['json'])): ?>
            <pre><?php echo e(json_encode($result['json'], JSON_PRETTY_PRINT)); ?></pre>
        <?php else: ?>
            <pre><?php echo e($result['raw']); ?></pre>
        <?php endif; ?>
    </div>
</body>
</html>
PHP;

        $phpSnippetCreateBooking = <<<'PHP'
<?php
$apiBase = '__API_BASE__';
$apiKey = '__API_KEY__';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function apiGet($apiBase, $apiKey, $endpoint) {
    $ch = curl_init($apiBase . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => (int)$status,
        'raw' => is_string($response) ? $response : '',
        'json' => is_string($response) ? json_decode($response, true) : null
    ];
}

function createBooking($apiBase, $apiKey, $payload) {
    $ch = curl_init($apiBase . '/bookings');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => (int)$status,
        'raw' => is_string($response) ? $response : '',
        'json' => is_string($response) ? json_decode($response, true) : null,
    ];
}

function checkAvailability($apiBase, $apiKey, $payload) {
    $query = http_build_query([
        'room_id' => $payload['room_id'],
        'check_in' => $payload['check_in_date'],
        'check_out' => $payload['check_out_date'],
        'number_of_guests' => $payload['number_of_guests']
    ]);

    $ch = curl_init($apiBase . '/availability?' . $query);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => (int)$status,
        'raw' => is_string($response) ? $response : '',
        'json' => is_string($response) ? json_decode($response, true) : null,
    ];
}

$roomsResponse = apiGet($apiBase, $apiKey, '/rooms');
$roomsPayload = is_array($roomsResponse['json']) ? $roomsResponse['json'] : [];
$rooms = [];
if (isset($roomsPayload['data']['rooms']) && is_array($roomsPayload['data']['rooms'])) {
    $rooms = $roomsPayload['data']['rooms'];
} elseif (isset($roomsPayload['rooms']) && is_array($roomsPayload['rooms'])) {
    $rooms = $roomsPayload['rooms'];
} elseif (isset($roomsPayload['data']) && is_array($roomsPayload['data']) && array_values($roomsPayload['data']) === $roomsPayload['data']) {
    $rooms = $roomsPayload['data'];
}

$defaultRoomId = isset($rooms[0]['id']) ? (int)$rooms[0]['id'] : 1;

$payload = [
    'guest_name' => trim((string)($_POST['guest_name'] ?? 'Test Guest')),
    'guest_email' => trim((string)($_POST['guest_email'] ?? 'guest@example.com')),
    'guest_phone' => trim((string)($_POST['guest_phone'] ?? '+2650000000')),
    'guest_country' => trim((string)($_POST['guest_country'] ?? 'Malawi')),
    'guest_address' => trim((string)($_POST['guest_address'] ?? '')),
    'room_id' => max(1, (int)($_POST['room_id'] ?? $defaultRoomId)),
    'room_unit_id' => trim((string)($_POST['room_unit_id'] ?? '')),
    'check_in_date' => trim((string)($_POST['check_in_date'] ?? date('Y-m-d', strtotime('+7 days')))),
    'check_out_date' => trim((string)($_POST['check_out_date'] ?? date('Y-m-d', strtotime('+9 days')))),
    'number_of_guests' => max(1, (int)($_POST['number_of_guests'] ?? 2)),
    'special_requests' => trim((string)($_POST['special_requests'] ?? '')),
    'booking_type' => trim((string)($_POST['booking_type'] ?? 'standard'))
];

if ($payload['room_unit_id'] === '') {
    unset($payload['room_unit_id']);
} else {
    $payload['room_unit_id'] = (int)$payload['room_unit_id'];
}

$action = trim((string)($_POST['action'] ?? 'check_availability'));
$availabilityResult = null;
$bookingResult = null;
$availabilityOkToBook = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $availabilityResult = checkAvailability($apiBase, $apiKey, $payload);
    $availabilityJson = is_array($availabilityResult['json']) ? $availabilityResult['json'] : [];
    $availabilityData = isset($availabilityJson['data']) && is_array($availabilityJson['data']) ? $availabilityJson['data'] : $availabilityJson;
    $availabilityOkToBook = $availabilityResult['status'] >= 200
        && $availabilityResult['status'] < 300
        && !empty($availabilityData['available']);

    if ($action === 'create_booking') {
        if ($availabilityOkToBook) {
            $bookingResult = createBooking($apiBase, $apiKey, $payload);
        } else {
            $bookingResult = [
                'status' => 409,
                'raw' => 'Room not available for selected dates. Run availability check and choose other dates or room.',
                'json' => [
                    'success' => false,
                    'message' => 'Room not available for selected dates.'
                ],
            ];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>Create Booking</title>
    <style>
        :root {
            --bg: #f3f6fb;
            --card: #ffffff;
            --text: #0f172a;
            --line: #dbe4f0;
            --brand: #0369a1;
            --brand-dark: #0c4a6e;
        }
        body { font-family: Arial, sans-serif; margin: 0; padding: 24px; background: radial-gradient(circle at top left, #e0f2fe 0%, var(--bg) 50%, #ecfeff 100%); color: var(--text); }
        .card { max-width: 860px; margin: 0 auto; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; box-shadow: 0 14px 34px rgba(15,23,42,.10); animation: rise .45s ease; }
        .title { margin: 0 0 12px; font-size: 26px; letter-spacing: -.02em; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        input, button, select, textarea { border: 1px solid #c7d2e3; border-radius: 10px; padding: 10px; font-size: 14px; font-family: inherit; box-sizing: border-box; }
        input, select, textarea { background: #fff; }
        button { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: #fff; cursor: pointer; border: 0; font-weight: 600; }
        button:hover { filter: brightness(1.03); }
        .full { grid-column: 1 / -1; }
        .result { margin-top: 14px; padding: 12px; border-radius: 10px; font-weight: 600; }
        .meta { margin: 8px 0 0; color: #334155; font-size: 14px; }
        .steps { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 0 0 12px; }
        .step { border: 1px solid var(--line); border-radius: 12px; padding: 10px; background: #f8fbff; font-size: 13px; color: #334155; }
        .step strong { display: block; color: #0f172a; margin-bottom: 3px; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-top: 10px; }
        .summary-item { border: 1px solid var(--line); border-radius: 10px; background: #f8fbff; padding: 9px; }
        .summary-item .k { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
        .summary-item .v { margin-top: 3px; font-size: 16px; font-weight: 700; color: #0f172a; }
        .warn { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
        .ok { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .no { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        pre { white-space: pre-wrap; word-break: break-word; background: #0b1220; color: #e2e8f0; border-radius: 10px; padding: 12px; border: 1px solid #1e293b; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
        @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="title">Create Booking</h1>
        <div class="steps">
            <div class="step"><strong>Step 1</strong>Run availability check to confirm dates, rate, and conflicts.</div>
            <div class="step"><strong>Step 2</strong>Create booking only when room is available.</div>
        </div>
        <form method="POST" class="grid">
            <?php echo getCsrfField(); ?>
            <input name="guest_name" value="<?php echo e($payload['guest_name']); ?>" placeholder="Guest name">
            <input type="email" name="guest_email" value="<?php echo e($payload['guest_email']); ?>" placeholder="Guest email">
            <input name="guest_phone" value="<?php echo e($payload['guest_phone']); ?>" placeholder="Guest phone">
            <select name="room_id">
                <?php foreach ($rooms as $room): ?>
                    <?php $rid = (int)($room['id'] ?? 0); ?>
                    <option value="<?php echo $rid; ?>" <?php echo $rid === (int)$payload['room_id'] ? 'selected' : ''; ?>>
                        <?php echo e(($room['name'] ?? 'Room') . ' (ID ' . $rid . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input name="guest_country" value="<?php echo e($payload['guest_country']); ?>" placeholder="Country">
            <input type="date" name="check_in_date" value="<?php echo e($payload['check_in_date']); ?>">
            <input type="date" name="check_out_date" value="<?php echo e($payload['check_out_date']); ?>">
            <input type="number" min="1" name="number_of_guests" value="<?php echo e($payload['number_of_guests']); ?>" placeholder="Guests">
            <input name="room_unit_id" value="<?php echo e(isset($payload['room_unit_id']) ? $payload['room_unit_id'] : ''); ?>" placeholder="Room Unit ID (optional)">
            <input name="guest_address" class="full" value="<?php echo e($payload['guest_address']); ?>" placeholder="Address (optional)">
            <select name="booking_type" class="full">
                <option value="standard" <?php echo ($payload['booking_type'] ?? 'standard') === 'standard' ? 'selected' : ''; ?>>Standard Booking</option>
                <option value="tentative" <?php echo ($payload['booking_type'] ?? 'standard') === 'tentative' ? 'selected' : ''; ?>>Tentative Booking</option>
            </select>
            <textarea name="special_requests" class="full" rows="3" placeholder="Special requests (optional)"><?php echo e($payload['special_requests']); ?></textarea>
            <button name="action" value="check_availability" class="full" type="submit">1) Check availability first</button>
            <button name="action" value="create_booking" class="full" type="submit">2) Create booking</button>
        </form>

        <?php if (is_array($availabilityResult)): ?>
            <?php
                $availabilityJson = is_array($availabilityResult['json']) ? $availabilityResult['json'] : [];
                $availabilityData = isset($availabilityJson['data']) && is_array($availabilityJson['data']) ? $availabilityJson['data'] : $availabilityJson;
                $isAvailable = !empty($availabilityData['available']);
            ?>
            <div class="result <?php echo $isAvailable ? 'ok' : 'warn'; ?>">
                <strong>Availability HTTP <?php echo (int)$availabilityResult['status']; ?></strong>
                <?php if ($isAvailable): ?> - Room is available for selected dates.<?php else: ?> - Room is not available for selected dates.<?php endif; ?>
            </div>
            <div class="summary">
                <div class="summary-item">
                    <div class="k">Room</div>
                    <div class="v"><?php echo e((string)($availabilityData['room']['name'] ?? $payload['room_id'])); ?></div>
                </div>
                <div class="summary-item">
                    <div class="k">Nights</div>
                    <div class="v"><?php echo e(isset($availabilityData['dates']['nights']) ? (int)$availabilityData['dates']['nights'] : '-'); ?></div>
                </div>
                <div class="summary-item">
                    <div class="k">Total</div>
                    <div class="v"><?php echo e((string)(($availabilityData['pricing']['currency'] ?? '') . ' ' . number_format((float)($availabilityData['pricing']['total'] ?? 0), 0))); ?></div>
                </div>
                <div class="summary-item">
                    <div class="k">Booking Type</div>
                    <div class="v"><?php echo e(ucfirst((string)$payload['booking_type'])); ?></div>
                </div>
            </div>
            <?php if (isset($availabilityData['pricing']) && is_array($availabilityData['pricing'])): ?>
                <p class="meta">
                    Estimated total: <?php echo e(($availabilityData['pricing']['currency'] ?? '') . ' ' . number_format((float)($availabilityData['pricing']['total'] ?? 0), 0)); ?>
                    <?php if (isset($availabilityData['dates']['nights'])): ?>
                        for <?php echo e((int)$availabilityData['dates']['nights']); ?> night(s)
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="meta">Pricing details were not returned by availability endpoint.</p>
            <?php endif; ?>
            <?php if (!empty($availabilityData['conflicts']) && is_array($availabilityData['conflicts'])): ?>
                <p class="meta"><strong>Conflicting bookings:</strong></p>
                <pre><?php echo e(json_encode($availabilityData['conflicts'], JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>
            <?php if (is_array($availabilityResult['json'])): ?>
                <pre><?php echo e(json_encode($availabilityResult['json'], JSON_PRETTY_PRINT)); ?></pre>
            <?php else: ?>
                <pre><?php echo e($availabilityResult['raw']); ?></pre>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (is_array($bookingResult)): ?>
            <?php $bookingOk = $bookingResult['status'] >= 200 && $bookingResult['status'] < 300; ?>
            <div class="result <?php echo $bookingOk ? 'ok' : 'no'; ?>">
                <strong>Booking HTTP <?php echo (int)$bookingResult['status']; ?></strong>
                <?php if ($bookingOk): ?> - Booking created successfully.<?php else: ?> - Booking could not be created.<?php endif; ?>
            </div>
            <?php if (is_array($bookingResult['json'])): ?>
                <pre><?php echo e(json_encode($bookingResult['json'], JSON_PRETTY_PRINT)); ?></pre>
            <?php else: ?>
                <pre><?php echo e($bookingResult['raw']); ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
PHP;

    $phpSnippetClass = str_replace(['__API_BASE__', '__API_KEY__'], [$apiBaseEsc, $apiKeyEsc], $phpSnippetClass);
    $phpSnippetAvailability = str_replace(['__API_BASE__', '__API_KEY__'], [$apiBaseEsc, $apiKeyEsc], $phpSnippetAvailability);
    $phpSnippetCreateBooking = str_replace(['__API_BASE__', '__API_KEY__'], [$apiBaseEsc, $apiKeyEsc], $phpSnippetCreateBooking);

    return [
        'php_client_class' => $phpSnippetClass,
        'php_client_availability' => $phpSnippetAvailability,
        'php_client_booking' => $phpSnippetCreateBooking,
    ];
}

$testClient = null;
try {
    $stmt = $pdo->query("\n        SELECT id, client_name, client_email, client_website, is_active\n        FROM api_keys\n        WHERE client_name = 'Test Client'\n        ORDER BY id DESC\n        LIMIT 1\n    ");
    $testClient = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $testClient = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfValidation();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create_key') {
            $clientName = trim((string)($_POST['client_name'] ?? ''));
            $clientWebsite = trim((string)($_POST['client_website'] ?? ''));
            $clientEmail = trim((string)($_POST['client_email'] ?? ''));
            $rateLimit = max(1, (int)($_POST['rate_limit_per_hour'] ?? 100));
            $permissions = safePermissions((array)($_POST['permissions'] ?? []), $permissionCatalog);

            if ($clientName === '') {
                throw new Exception('Client name is required.');
            }
            if ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('A valid client email is required.');
            }
            if (empty($permissions)) {
                throw new Exception('Select at least one permission.');
            }

            $rawApiKey = bin2hex(random_bytes(32));
            $hashedApiKey = password_hash($rawApiKey, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("\n                INSERT INTO api_keys\n                    (api_key, client_name, client_website, client_email, permissions, rate_limit_per_hour, is_active)\n                VALUES (?, ?, ?, ?, ?, ?, 1)\n            ");
            $stmt->execute([
                $hashedApiKey,
                $clientName,
                $clientWebsite !== '' ? $clientWebsite : null,
                $clientEmail,
                json_encode($permissions),
                $rateLimit
            ]);

            $revealedKey = $rawApiKey;
            $message = 'API key created. Copy the key now; it is shown only once.';
            $messageType = 'success';
        }

        if ($action === 'update_key') {
            $keyId = (int)($_POST['key_id'] ?? 0);
            $clientName = trim((string)($_POST['client_name'] ?? ''));
            $clientWebsite = trim((string)($_POST['client_website'] ?? ''));
            $clientEmail = trim((string)($_POST['client_email'] ?? ''));
            $rateLimit = max(1, (int)($_POST['rate_limit_per_hour'] ?? 100));
            $permissions = safePermissions((array)($_POST['permissions'] ?? []), $permissionCatalog);

            if ($keyId <= 0) {
                throw new Exception('Invalid API key selection.');
            }
            if ($clientName === '') {
                throw new Exception('Client name is required.');
            }
            if ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('A valid client email is required.');
            }
            if (empty($permissions)) {
                throw new Exception('Select at least one permission.');
            }

            $stmt = $pdo->prepare("\n                UPDATE api_keys\n                SET client_name = ?,\n                    client_website = ?,\n                    client_email = ?,\n                    permissions = ?,\n                    rate_limit_per_hour = ?\n                WHERE id = ?\n            ");
            $stmt->execute([
                $clientName,
                $clientWebsite !== '' ? $clientWebsite : null,
                $clientEmail,
                json_encode($permissions),
                $rateLimit,
                $keyId
            ]);

            $message = 'API key profile updated successfully.';
            $messageType = 'success';
        }

        if ($action === 'toggle_status') {
            $keyId = (int)($_POST['key_id'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 0);
            if ($keyId <= 0) {
                throw new Exception('Invalid API key selection.');
            }

            $stmt = $pdo->prepare('UPDATE api_keys SET is_active = ? WHERE id = ?');
            $stmt->execute([$isActive ? 1 : 0, $keyId]);
            $message = $isActive ? 'API key activated.' : 'API key disabled.';
            $messageType = 'success';
        }

        if ($action === 'regenerate_key') {
            $keyId = (int)($_POST['key_id'] ?? 0);
            if ($keyId <= 0) {
                throw new Exception('Invalid API key selection.');
            }

            $rawApiKey = bin2hex(random_bytes(32));
            $hashedApiKey = password_hash($rawApiKey, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("\n                UPDATE api_keys\n                SET api_key = ?,\n                    last_used_at = NULL,\n                    usage_count = 0\n                WHERE id = ?\n            ");
            $stmt->execute([$hashedApiKey, $keyId]);

            $revealedKey = $rawApiKey;
            $message = 'API key regenerated. Copy the new key now.';
            $messageType = 'success';
        }

        if ($action === 'delete_key') {
            $keyId = (int)($_POST['key_id'] ?? 0);
            if ($keyId <= 0) {
                throw new Exception('Invalid API key selection.');
            }

            $stmt = $pdo->prepare('DELETE FROM api_keys WHERE id = ?');
            $stmt->execute([$keyId]);
            $message = 'API key deleted successfully.';
            $messageType = 'success';
        }

        if ($action === 'prepare_test_client_package') {
            $testClientId = (int)($_POST['test_client_id'] ?? 0);
            if ($testClientId <= 0) {
                throw new Exception('Test Client was not found in live DB.');
            }

            $rawApiKey = bin2hex(random_bytes(32));
            $hashedApiKey = password_hash($rawApiKey, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("\n                UPDATE api_keys\n                SET api_key = ?,\n                    last_used_at = NULL,\n                    usage_count = 0,\n                    is_active = 1\n                WHERE id = ?\n            ");
            $stmt->execute([$hashedApiKey, $testClientId]);

            $testClientPackageKey = $rawApiKey;
            $_SESSION['test_client_package_key'] = $rawApiKey;
            $message = 'Test Client package is ready. Copy the PHP tab code below and share with your client.';
            $messageType = 'success';
        }

        if ($action === 'run_test_client_request') {
            $testClientPackageKey = $testClientPackageKey !== ''
                ? $testClientPackageKey
                : (string)($_SESSION['test_client_package_key'] ?? '');

            if ($testClientPackageKey === '') {
                throw new Exception('Generate Test Client package key first, then run a live test.');
            }

            $testMethodInput = strtoupper(trim((string)($_POST['test_method'] ?? 'GET')));
            $testEndpointInput = trim((string)($_POST['test_endpoint'] ?? '/rooms'));
            $testPayloadInput = trim((string)($_POST['test_payload'] ?? ''));

            if (!in_array($testMethodInput, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
                throw new Exception('Invalid request method for live test.');
            }
            if ($testEndpointInput === '') {
                throw new Exception('Endpoint is required for live test.');
            }
            if ($testEndpointInput[0] !== '/') {
                $testEndpointInput = '/' . $testEndpointInput;
            }

            $payload = null;
            if ($testPayloadInput !== '' && in_array($testMethodInput, ['POST', 'PUT'], true)) {
                $payload = json_decode($testPayloadInput, true);
                if (!is_array($payload)) {
                    throw new Exception('Payload must be valid JSON object/array for POST or PUT requests.');
                }
            }

            $liveResponse = runLiveApiRequest($apiBaseUrl, $testClientPackageKey, $testMethodInput, $testEndpointInput, $payload);
            $liveTestResult = [
                'request' => [
                    'method' => $testMethodInput,
                    'endpoint' => $testEndpointInput,
                    'payload' => $payload,
                ],
                'response' => $liveResponse,
            ];

            $message = 'Live test executed from admin portal.';
            $messageType = 'success';
        }
    } catch (Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$selectedKeyId = isset($_GET['key_id']) ? (int)$_GET['key_id'] : 0;

$apiKeys = [];
try {
    $stmt = $pdo->query("\n        SELECT\n            ak.*,\n            (SELECT COUNT(*) FROM api_usage_logs WHERE api_key_id = ak.id) AS total_calls,\n            (SELECT COUNT(*) FROM api_usage_logs WHERE api_key_id = ak.id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS calls_last_hour\n        FROM api_keys ak\n        ORDER BY ak.created_at DESC\n    ");
    $apiKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($message === '') {
        $message = 'Error loading API keys: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$dailyUsage = [];
try {
    $stmt = $pdo->query("\n        SELECT DATE(created_at) AS usage_date,\n               COUNT(*) AS total_calls,\n               COUNT(DISTINCT api_key_id) AS unique_clients,\n               AVG(response_time) AS avg_response_time\n        FROM api_usage_logs\n        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)\n        GROUP BY DATE(created_at)\n        ORDER BY usage_date DESC\n        LIMIT 30\n    ");
    $dailyUsage = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dailyUsage = [];
}

$selectedKeyLogs = [];
if ($selectedKeyId > 0) {
    try {
        $stmt = $pdo->prepare("\n            SELECT endpoint, method, response_code, response_time, ip_address, created_at\n            FROM api_usage_logs\n            WHERE api_key_id = ?\n            ORDER BY created_at DESC\n            LIMIT 100\n        ");
        $stmt->execute([$selectedKeyId]);
        $selectedKeyLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $selectedKeyLogs = [];
    }
}

$selectedKeyDailyTrend = [];
if ($selectedKeyId > 0) {
    try {
        $stmt = $pdo->prepare("\n            SELECT DATE(created_at) AS usage_date,\n                   COUNT(*) AS total_calls,\n                   SUM(CASE WHEN response_code >= 400 THEN 1 ELSE 0 END) AS error_calls\n            FROM api_usage_logs\n            WHERE api_key_id = ?\n              AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)\n            GROUP BY DATE(created_at)\n            ORDER BY usage_date ASC\n        ");
        $stmt->execute([$selectedKeyId]);
        $selectedKeyDailyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $selectedKeyDailyTrend = [];
    }
}

$siteName = getSetting('site_name', 'Hotel');
if ($testClientPackageKey === '' && !empty($_SESSION['test_client_package_key'])) {
    $testClientPackageKey = (string)$_SESSION['test_client_package_key'];
}

if ((isset($_GET['download_php']) || isset($_GET['download_zip'])) && $testClientPackageKey !== '') {
    $snippets = buildTestClientPhpSnippets($apiBaseUrl, $testClientPackageKey);

    if (isset($_GET['download_php'])) {
        $snippetType = trim((string)$_GET['download_php']);
        if (!isset($snippets[$snippetType])) {
            http_response_code(400);
            echo 'Invalid snippet type.';
            exit;
        }

        $filenameMap = [
            'php_client_class' => 'test-client-api-client.php',
            'php_client_availability' => 'test-client-availability.php',
            'php_client_booking' => 'test-client-create-booking.php',
        ];
        $fileName = $filenameMap[$snippetType] ?? 'test-client-snippet.php';

        header('Content-Type: application/x-httpd-php; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($snippets[$snippetType]));
        echo $snippets[$snippetType];
        exit;
    }

    if (isset($_GET['download_zip'])) {
        if (!class_exists('ZipArchive')) {
            $textBundle = "ZIP export is unavailable on this server (ZipArchive extension not installed).\n" .
                "Below are all files in plain text format.\n\n" .
                "===== test-client-api-client.php =====\n" . $snippets['php_client_class'] . "\n\n" .
                "===== test-client-availability.php =====\n" . $snippets['php_client_availability'] . "\n\n" .
                "===== test-client-create-booking.php =====\n" . $snippets['php_client_booking'] . "\n";

            header('Content-Type: text/plain; charset=UTF-8');
            header('Content-Disposition: attachment; filename="test-client-php-api-package.txt"');
            header('Content-Length: ' . strlen($textBundle));
            echo $textBundle;
            exit;
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'api_pkg_');
        if ($zipPath === false) {
            http_response_code(500);
            echo 'Could not create temporary package file.';
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            http_response_code(500);
            echo 'Could not create ZIP package.';
            exit;
        }

        $zip->addFromString('test-client-api-client.php', $snippets['php_client_class']);
        $zip->addFromString('test-client-availability.php', $snippets['php_client_availability']);
        $zip->addFromString('test-client-create-booking.php', $snippets['php_client_booking']);
        $zip->addFromString('README.txt',
            "Test Client API Package\n\n" .
            "1. Use test-client-api-client.php to render rooms as visual cards with images.\n" .
            "2. test-client-availability.php checks availability endpoint.\n" .
            "3. test-client-create-booking.php creates a booking request.\n\n" .
            "Base URL: " . $apiBaseUrl . "\n" .
            "Generated: " . date('Y-m-d H:i:s') . "\n"
        );
        $zip->close();

        $zipBytes = @file_get_contents($zipPath);
        @unlink($zipPath);

        if ($zipBytes === false) {
            http_response_code(500);
            echo 'Could not read ZIP package.';
            exit;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="test-client-php-api-package.zip"');
        header('Content-Length: ' . strlen($zipBytes));
        echo $zipBytes;
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys Management - <?php echo htmlspecialchars($siteName); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">

    <style>
        .api-grid { display:grid; grid-template-columns: 1.1fr 0.9fr; gap: 16px; }
        .api-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; }
        .api-card h3 { margin:0 0 12px; font-size:16px; color:#0f172a; }
        .form-row { margin-bottom:10px; }
        .form-row label { display:block; margin-bottom:4px; font-size:12px; font-weight:600; color:#334155; }
        .form-row input, .form-row textarea { width:100%; }
        .perm-grid { display:grid; grid-template-columns: 1fr; gap:8px; border:1px solid #e5e7eb; border-radius:8px; padding:10px; max-height:240px; overflow:auto; }
        .perm-item { display:flex; gap:8px; align-items:flex-start; }
        .perm-item small { color:#64748b; }
        .api-table-wrap { overflow:auto; }
        .api-table { width:100%; border-collapse: collapse; font-size:12px; }
        .api-table th, .api-table td { border-bottom:1px solid #e5e7eb; padding:8px; text-align:left; vertical-align:top; }
        .api-table th { background:#f8fafc; color:#334155; font-weight:600; }
        .pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
        .pill-ok { background:#dcfce7; color:#166534; }
        .pill-off { background:#fee2e2; color:#991b1b; }
        .actions { display:flex; gap:6px; flex-wrap:wrap; }
        .btn-sm { padding:6px 9px; font-size:11px; border-radius:6px; border:1px solid transparent; cursor:pointer; }
        .btn-edit { background:#e0f2fe; color:#075985; }
        .btn-toggle { background:#fef3c7; color:#92400e; }
        .btn-regen { background:#ede9fe; color:#5b21b6; }
        .btn-delete { background:#fee2e2; color:#b91c1c; }
        .key-box { background:#0f172a; color:#f8fafc; border-radius:8px; padding:10px; font-family: monospace; word-break: break-all; }
        .helper-list { margin:0; padding-left:16px; color:#334155; }
        .helper-list li { margin:6px 0; }
        .split-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .row-highlight { background:#fffbeb; }
        .snippet-box { background:#0b1220; color:#e2e8f0; border-radius:8px; padding:10px; font-family:Consolas, monospace; white-space:pre-wrap; font-size:11px; }
        .snippet-actions { margin-top:8px; }
        .btn-copy { padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc; color:#1e293b; cursor:pointer; font-size:11px; }
        .chart-grid { display:grid; grid-template-columns:1fr; gap:8px; }
        .chart-row { display:grid; grid-template-columns:86px 1fr 70px; gap:8px; align-items:center; font-size:11px; }
        .bar-track { background:#f1f5f9; border-radius:999px; height:10px; overflow:hidden; }
        .bar-fill { height:10px; border-radius:999px; }
        .bar-calls { background:#2563eb; }
        .bar-errors { background:#dc2626; }
        .tab-strip { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
        .tab-btn { padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#0f172a; cursor:pointer; font-size:12px; }
        .tab-btn.active { background:#0f172a; color:#fff; border-color:#0f172a; }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }
        .preview-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px; font-size:12px; color:#334155; }
        .preview-card pre { margin:0; white-space:pre-wrap; font-family:Consolas, monospace; font-size:11px; color:#0f172a; }
        .test-grid { display:grid; grid-template-columns:120px 1fr; gap:8px; align-items:center; }
        @media (max-width: 1100px) { .api-grid { grid-template-columns: 1fr; } .split-2 { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<?php require_once 'includes/admin-header.php'; ?>

<main class="admin-main">
    <div class="admin-content">
        <div class="admin-page-header">
            <h2><i class="fas fa-key"></i> API Keys Management</h2>
            <p>Manage clients, permissions, rate limits, key rotation, and usage logs from one place.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $messageType === 'error' ? 'alert-error' : 'alert-success'; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($revealedKey !== ''): ?>
            <div class="api-card">
                <h3><i class="fas fa-shield-alt"></i> New API Key (Shown Once)</h3>
                <div class="key-box"><?php echo htmlspecialchars($revealedKey); ?></div>
            </div>
        <?php endif; ?>

        <div class="api-card">
            <h3><i class="fas fa-file-code"></i> Test Client PHP Package</h3>
            <p style="font-size:12px; color:#64748b; margin:0 0 10px;">Generate a fresh Test Client key, then copy one of the PHP tabs below. The first tab renders actual room cards and images, not raw JSON.</p>

            <?php if ($testClient): ?>
                <form method="POST" style="margin-bottom:10px;" onsubmit="return confirm('Generate a new key for Test Client? The previous key will stop working.');">
                    <?php echo getCsrfField(); ?>
                    <input type="hidden" name="action" value="prepare_test_client_package">
                    <input type="hidden" name="test_client_id" value="<?php echo (int)$testClient['id']; ?>">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-bolt"></i> Generate Test Client Package</button>
                </form>
                <p style="font-size:12px; color:#64748b; margin:0 0 10px;">
                    Client: <strong><?php echo htmlspecialchars((string)$testClient['client_name']); ?></strong>
                    | Email: <?php echo htmlspecialchars((string)$testClient['client_email']); ?>
                </p>
            <?php else: ?>
                <p style="font-size:12px; color:#b91c1c; margin:0;">Test Client record was not found in the live database.</p>
            <?php endif; ?>

            <?php if ($testClientPackageKey !== ''): ?>
                <?php
                    $snippetBundle = buildTestClientPhpSnippets($apiBaseUrl, $testClientPackageKey);
                    $phpSnippetClass = $snippetBundle['php_client_class'];
                    $phpSnippetAvailability = $snippetBundle['php_client_availability'];
                    $phpSnippetCreateBooking = $snippetBundle['php_client_booking'];
                ?>

                <div class="tab-strip" data-tab-group="php-client-tabs">
                    <button type="button" class="tab-btn active" data-tab-target="php_client_class">Rooms Showcase (HTML)</button>
                    <button type="button" class="tab-btn" data-tab-target="php_client_availability">Availability</button>
                    <button type="button" class="tab-btn" data-tab-target="php_client_booking">Create Booking</button>
                </div>

                <div class="tab-panel active" id="php_client_class" data-tab-panel="php-client-tabs">
                    <div class="snippet-box" id="snippet_php_client_class"><?php echo htmlspecialchars($phpSnippetClass); ?></div>
                    <div class="snippet-actions">
                        <button type="button" class="btn-copy" onclick="copySnippet('snippet_php_client_class', this)">Copy PHP</button>
                        <a class="btn-copy" href="api-keys.php?download_php=php_client_class" style="text-decoration:none; display:inline-block; margin-left:6px;">Download .php</a>
                    </div>
                </div>
                <div class="tab-panel" id="php_client_availability" data-tab-panel="php-client-tabs">
                    <div class="snippet-box" id="snippet_php_client_availability"><?php echo htmlspecialchars($phpSnippetAvailability); ?></div>
                    <div class="snippet-actions">
                        <button type="button" class="btn-copy" onclick="copySnippet('snippet_php_client_availability', this)">Copy PHP</button>
                        <a class="btn-copy" href="api-keys.php?download_php=php_client_availability" style="text-decoration:none; display:inline-block; margin-left:6px;">Download .php</a>
                    </div>
                </div>
                <div class="tab-panel" id="php_client_booking" data-tab-panel="php-client-tabs">
                    <div class="snippet-box" id="snippet_php_client_booking"><?php echo htmlspecialchars($phpSnippetCreateBooking); ?></div>
                    <div class="snippet-actions">
                        <button type="button" class="btn-copy" onclick="copySnippet('snippet_php_client_booking', this)">Copy PHP</button>
                        <a class="btn-copy" href="api-keys.php?download_php=php_client_booking" style="text-decoration:none; display:inline-block; margin-left:6px;">Download .php</a>
                    </div>
                </div>

                <div class="snippet-actions" style="margin-top:10px;">
                    <a class="btn-copy" href="api-keys.php?download_zip=1" style="text-decoration:none; display:inline-block;">Download ZIP Package</a>
                </div>

                <h3 style="margin-top:14px;"><i class="fas fa-flask"></i> Run Live Test From Website</h3>
                <form method="POST" style="margin-bottom:10px;">
                    <?php echo getCsrfField(); ?>
                    <input type="hidden" name="action" value="run_test_client_request">

                    <div class="test-grid" style="margin-bottom:8px;">
                        <label style="font-size:12px; color:#475569;">Method</label>
                        <select name="test_method" style="border:1px solid #cbd5e1; border-radius:6px; padding:6px;">
                            <option value="GET" <?php echo $testMethodInput === 'GET' ? 'selected' : ''; ?>>GET</option>
                            <option value="POST" <?php echo $testMethodInput === 'POST' ? 'selected' : ''; ?>>POST</option>
                            <option value="PUT" <?php echo $testMethodInput === 'PUT' ? 'selected' : ''; ?>>PUT</option>
                            <option value="DELETE" <?php echo $testMethodInput === 'DELETE' ? 'selected' : ''; ?>>DELETE</option>
                        </select>
                    </div>

                    <div class="test-grid" style="margin-bottom:8px;">
                        <label style="font-size:12px; color:#475569;">Endpoint</label>
                        <input type="text" name="test_endpoint" value="<?php echo htmlspecialchars($testEndpointInput); ?>" placeholder="/rooms" style="border:1px solid #cbd5e1; border-radius:6px; padding:6px;">
                    </div>

                    <div style="margin-bottom:8px;">
                        <label style="font-size:12px; color:#475569; display:block; margin-bottom:4px;">Payload JSON (for POST/PUT)</label>
                        <textarea name="test_payload" rows="4" style="width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:6px;" placeholder='{"guest_name":"Test Guest","room_id":1}'><?php echo htmlspecialchars($testPayloadInput); ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Run Live Test</button>
                </form>

                <?php if ($liveTestResult): ?>
                    <div class="preview-card">
                        <p style="margin:0 0 8px;"><strong>Request:</strong> <?php echo htmlspecialchars($liveTestResult['request']['method']); ?> <?php echo htmlspecialchars($liveTestResult['request']['endpoint']); ?></p>
                        <?php if ($liveTestResult['request']['payload'] !== null): ?>
                            <pre><?php echo htmlspecialchars(json_encode($liveTestResult['request']['payload'], JSON_PRETTY_PRINT)); ?></pre>
                        <?php endif; ?>
                        <p style="margin:8px 0 4px;"><strong>Response:</strong> HTTP <?php echo (int)$liveTestResult['response']['http_code']; ?></p>
                        <?php if (!empty($liveTestResult['response']['transport_error'])): ?>
                            <pre><?php echo htmlspecialchars((string)$liveTestResult['response']['transport_error']); ?></pre>
                        <?php elseif (is_array($liveTestResult['response']['body_json'])): ?>
                            <pre><?php echo htmlspecialchars(json_encode($liveTestResult['response']['body_json'], JSON_PRETTY_PRINT)); ?></pre>
                        <?php else: ?>
                            <pre><?php echo htmlspecialchars((string)$liveTestResult['response']['body_raw']); ?></pre>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <h3 style="margin-top:14px;"><i class="fas fa-eye"></i> Client Preview</h3>
                <div class="preview-card">
                                        <p style="margin:0 0 8px;">Success flow now renders a visual rooms grid (image, room name, price, capacity, description) with clickable cards that open room details.</p>
                    <p style="margin:8px 0 0;">And if the key is missing/invalid:</p>
                    <pre>{
  "success": false,
  "error": "Invalid API key",
  "code": 401
}</pre>
                </div>
            <?php endif; ?>
        </div>

        <div class="api-grid">
            <section>
                <div class="api-card">
                    <h3><i class="fas fa-plus-circle"></i> Create API Key</h3>
                    <form method="POST">
                        <?php echo getCsrfField(); ?>
                        <input type="hidden" name="action" value="create_key">

                        <div class="split-2">
                            <div class="form-row"><label>Client Name</label><input type="text" name="client_name" required></div>
                            <div class="form-row"><label>Client Email</label><input type="email" name="client_email" required></div>
                        </div>
                        <div class="split-2">
                            <div class="form-row"><label>Client Website</label><input type="url" name="client_website" placeholder="https://example.com"></div>
                            <div class="form-row"><label>Rate Limit (per hour)</label><input type="number" name="rate_limit_per_hour" min="1" max="200000" value="100" required></div>
                        </div>

                        <div class="form-row">
                            <label>Permissions</label>
                            <div class="perm-grid">
                                <?php foreach ($permissionCatalog as $perm => $desc): ?>
                                    <label class="perm-item">
                                        <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($perm); ?>" checked>
                                        <span>
                                            <strong><?php echo htmlspecialchars($perm); ?></strong><br>
                                            <small><?php echo htmlspecialchars($desc); ?></small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Generate Key</button>
                    </form>
                </div>

                <div class="api-card">
                    <h3><i class="fas fa-link"></i> Permissions Matrix</h3>
                    <div class="api-table-wrap">
                        <table class="api-table">
                            <thead><tr><th>Permission</th><th>What It Allows</th><th>Endpoint(s)</th><th>Example</th></tr></thead>
                            <tbody>
                            <?php foreach ($permissionCatalog as $perm => $desc): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($perm); ?></code></td>
                                    <td><?php echo htmlspecialchars($desc); ?></td>
                                    <td><?php echo htmlspecialchars($permissionEndpointMap[$perm] ?? '-'); ?></td>
                                    <td>
                                        <div class="snippet-box" id="snippet_<?php echo htmlspecialchars(str_replace('.', '_', $perm)); ?>"><?php echo htmlspecialchars($permissionSampleSnippets[$perm] ?? ''); ?></div>
                                        <div class="snippet-actions">
                                            <button type="button" class="btn-copy" onclick="copySnippet('snippet_<?php echo htmlspecialchars(str_replace('.', '_', $perm)); ?>', this)">Copy</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section>
                <div class="api-card">
                    <h3><i class="fas fa-list"></i> Existing Keys</h3>
                    <div class="api-table-wrap">
                        <table class="api-table">
                            <thead>
                            <tr>
                                <th>Client</th>
                                <th>Usage</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($apiKeys)): ?>
                                <tr><td colspan="4">No API keys found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($apiKeys as $key):
                                    $keyPerms = json_decode((string)$key['permissions'], true) ?: [];
                                ?>
                                    <tr class="<?php echo $selectedKeyId === (int)$key['id'] ? 'row-highlight' : ''; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars((string)$key['client_name']); ?></strong><br>
                                            <small><?php echo htmlspecialchars((string)$key['client_email']); ?></small><br>
                                            <small>Rate: <?php echo (int)$key['rate_limit_per_hour']; ?>/hr</small><br>
                                            <small>Perms: <?php echo count($keyPerms); ?></small>
                                        </td>
                                        <td>
                                            <small>Total: <?php echo (int)$key['total_calls']; ?></small><br>
                                            <small>Last hour: <?php echo (int)$key['calls_last_hour']; ?></small><br>
                                            <small>Last used: <?php echo $key['last_used_at'] ? date('M j, Y H:i', strtotime((string)$key['last_used_at'])) : 'Never'; ?></small>
                                        </td>
                                        <td>
                                            <?php if ((int)$key['is_active'] === 1): ?>
                                                <span class="pill pill-ok">Active</span>
                                            <?php else: ?>
                                                <span class="pill pill-off">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <a class="btn-sm btn-edit" href="api-keys.php?key_id=<?php echo (int)$key['id']; ?>">View</a>

                                                <form method="POST" style="display:inline;">
                                                    <?php echo getCsrfField(); ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo (int)$key['is_active'] === 1 ? 0 : 1; ?>">
                                                    <button class="btn-sm btn-toggle" type="submit"><?php echo (int)$key['is_active'] === 1 ? 'Disable' : 'Enable'; ?></button>
                                                </form>

                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Regenerate this API key? Current key will stop working immediately.');">
                                                    <?php echo getCsrfField(); ?>
                                                    <input type="hidden" name="action" value="regenerate_key">
                                                    <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                                    <button class="btn-sm btn-regen" type="submit">Rotate</button>
                                                </form>

                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this API key permanently?');">
                                                    <?php echo getCsrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_key">
                                                    <input type="hidden" name="key_id" value="<?php echo (int)$key['id']; ?>">
                                                    <button class="btn-sm btn-delete" type="submit">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($selectedKeyId > 0):
                    $selectedKey = null;
                    foreach ($apiKeys as $k) {
                        if ((int)$k['id'] === $selectedKeyId) {
                            $selectedKey = $k;
                            break;
                        }
                    }
                ?>
                    <?php if ($selectedKey):
                        $selectedPerms = json_decode((string)$selectedKey['permissions'], true) ?: [];
                    ?>
                    <div class="api-card">
                        <h3><i class="fas fa-pen"></i> Edit Key: <?php echo htmlspecialchars((string)$selectedKey['client_name']); ?></h3>
                        <form method="POST">
                            <?php echo getCsrfField(); ?>
                            <input type="hidden" name="action" value="update_key">
                            <input type="hidden" name="key_id" value="<?php echo (int)$selectedKey['id']; ?>">

                            <div class="split-2">
                                <div class="form-row"><label>Client Name</label><input type="text" name="client_name" required value="<?php echo htmlspecialchars((string)$selectedKey['client_name']); ?>"></div>
                                <div class="form-row"><label>Client Email</label><input type="email" name="client_email" required value="<?php echo htmlspecialchars((string)$selectedKey['client_email']); ?>"></div>
                            </div>
                            <div class="split-2">
                                <div class="form-row"><label>Client Website</label><input type="url" name="client_website" value="<?php echo htmlspecialchars((string)$selectedKey['client_website']); ?>"></div>
                                <div class="form-row"><label>Rate Limit (per hour)</label><input type="number" name="rate_limit_per_hour" min="1" max="200000" value="<?php echo (int)$selectedKey['rate_limit_per_hour']; ?>" required></div>
                            </div>
                            <div class="form-row">
                                <label>Permissions</label>
                                <div class="perm-grid">
                                    <?php foreach ($permissionCatalog as $perm => $desc): ?>
                                        <label class="perm-item">
                                            <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($perm); ?>" <?php echo in_array($perm, $selectedPerms, true) ? 'checked' : ''; ?>>
                                            <span>
                                                <strong><?php echo htmlspecialchars($perm); ?></strong><br>
                                                <small><?php echo htmlspecialchars($desc); ?></small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </form>
                    </div>

                    <div class="api-card">
                        <h3><i class="fas fa-history"></i> Latest 100 Calls for This Key</h3>
                        <div class="api-table-wrap">
                            <table class="api-table">
                                <thead><tr><th>Time</th><th>Method</th><th>Endpoint</th><th>Status</th><th>Time (s)</th><th>IP</th></tr></thead>
                                <tbody>
                                <?php if (empty($selectedKeyLogs)): ?>
                                    <tr><td colspan="6">No usage logs yet for this key.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($selectedKeyLogs as $log): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime((string)$log['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars((string)$log['method']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$log['endpoint']); ?></td>
                                        <td><?php echo (int)$log['response_code']; ?></td>
                                        <td><?php echo number_format((float)$log['response_time'], 4); ?></td>
                                        <td><?php echo htmlspecialchars((string)$log['ip_address']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="api-card">
                        <h3><i class="fas fa-chart-bar"></i> Per-Key Daily Trend (14 Days)</h3>
                        <?php
                            $maxCalls = 0;
                            foreach ($selectedKeyDailyTrend as $trendRow) {
                                $maxCalls = max($maxCalls, (int)$trendRow['total_calls']);
                            }
                            if ($maxCalls <= 0) {
                                $maxCalls = 1;
                            }
                        ?>
                        <?php if (empty($selectedKeyDailyTrend)): ?>
                            <p style="font-size:12px;color:#64748b;">No trend data yet for this key.</p>
                        <?php else: ?>
                            <div class="chart-grid">
                                <?php foreach ($selectedKeyDailyTrend as $trendRow):
                                    $calls = (int)$trendRow['total_calls'];
                                    $errors = (int)$trendRow['error_calls'];
                                    $errorRate = $calls > 0 ? ($errors / $calls) * 100 : 0;
                                    $callsWidth = (int)round(($calls / $maxCalls) * 100);
                                ?>
                                <div class="chart-row">
                                    <div><?php echo htmlspecialchars(date('M j', strtotime((string)$trendRow['usage_date']))); ?></div>
                                    <div class="bar-track"><div class="bar-fill bar-calls" style="width: <?php echo $callsWidth; ?>%;"></div></div>
                                    <div><?php echo $calls; ?> calls</div>
                                </div>
                                <div class="chart-row">
                                    <div style="color:#64748b;">Errors</div>
                                    <div class="bar-track"><div class="bar-fill bar-errors" style="width: <?php echo (int)round($errorRate); ?>%;"></div></div>
                                    <div><?php echo $errors; ?> (<?php echo number_format($errorRate, 1); ?>%)</div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="api-card">
                    <h3><i class="fas fa-chart-line"></i> Last 30 Days Usage</h3>
                    <div class="api-table-wrap">
                        <table class="api-table">
                            <thead><tr><th>Date</th><th>Total Calls</th><th>Unique Keys</th><th>Avg Time (s)</th></tr></thead>
                            <tbody>
                            <?php if (empty($dailyUsage)): ?>
                                <tr><td colspan="4">No usage data available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($dailyUsage as $day): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime((string)$day['usage_date'])); ?></td>
                                    <td><?php echo (int)$day['total_calls']; ?></td>
                                    <td><?php echo (int)$day['unique_clients']; ?></td>
                                    <td><?php echo number_format((float)$day['avg_response_time'], 4); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="api-card">
                    <h3><i class="fas fa-book"></i> How API Keys Work</h3>
                    <ol class="helper-list">
                        <li>Create one key per external website/client.</li>
                        <li>Choose only the permissions that client needs.</li>
                        <li>Client sends the key in <strong>X-API-Key</strong> header.</li>
                        <li>The API validates key, permissions, and hourly rate limit.</li>
                        <li>Rotate keys immediately if a key is exposed.</li>
                    </ol>
                </div>
            </section>
        </div>
    </div>
</main>
<script>
function copySnippet(elementId, button) {
    var el = document.getElementById(elementId);
    if (!el) {
        return;
    }
    var text = el.innerText || el.textContent || '';
    if (!text) {
        return;
    }

    function markCopied() {
        var oldText = button.textContent;
        button.textContent = 'Copied';
        setTimeout(function() {
            button.textContent = oldText;
        }, 1200);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            markCopied();
        });
        return;
    }

    var temp = document.createElement('textarea');
    temp.value = text;
    document.body.appendChild(temp);
    temp.select();
    document.execCommand('copy');
    document.body.removeChild(temp);
    markCopied();
}

document.querySelectorAll('[data-tab-group]').forEach(function(tabGroup) {
    tabGroup.querySelectorAll('[data-tab-target]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-tab-target');
            var groupName = tabGroup.getAttribute('data-tab-group');

            tabGroup.querySelectorAll('[data-tab-target]').forEach(function(otherBtn) {
                otherBtn.classList.remove('active');
            });
            this.classList.add('active');

            document.querySelectorAll('[data-tab-panel="' + groupName + '"]').forEach(function(panel) {
                panel.classList.remove('active');
            });

            var target = document.getElementById(targetId);
            if (target) {
                target.classList.add('active');
            }
        });
    });
});
</script>
</body>
</html>
