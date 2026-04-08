<?php
/**
 * Campaign Attribution Helpers
 *
 * Tracks UTM campaign data from paid social links and makes attribution
 * data available for booking and conference inquiry inserts.
 */

function campaignAttributionEnsureSession()
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
}

function campaignAttributionSanitize($value, $maxLength = 120)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $value = preg_replace('/[^a-zA-Z0-9_\-\.\/: ]/', '', $value);
    $value = substr($value, 0, $maxLength);

    return $value !== '' ? $value : null;
}

function campaignAttributionNormalizePath($path)
{
    $path = trim((string)$path);
    if ($path === '') {
        return '/';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return substr($path, 0, 255);
}

function campaignAttributionGetTableColumns(PDO $pdo, $table, $refresh = false)
{
    static $cache = [];

    if (!$refresh && isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns[] = $row['Field'];
        }
    } catch (Throwable $e) {
        $columns = [];
    }

    $cache[$table] = $columns;
    return $columns;
}

function campaignAttributionHasColumn(PDO $pdo, $table, $column)
{
    $columns = campaignAttributionGetTableColumns($pdo, $table);
    return in_array($column, $columns, true);
}

function ensureMarketingCampaignInfrastructure(PDO $pdo)
{
    static $ready = false;

    if ($ready) {
        return;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS marketing_campaigns (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_name VARCHAR(150) NOT NULL,
            platform ENUM('facebook', 'instagram', 'mixed') NOT NULL DEFAULT 'facebook',
            objective ENUM('bookings', 'events', 'traffic', 'awareness') NOT NULL DEFAULT 'bookings',
            destination_path VARCHAR(255) NOT NULL DEFAULT '/booking.php',
            utm_source VARCHAR(100) NOT NULL,
            utm_medium VARCHAR(100) NOT NULL DEFAULT 'paid_social',
            utm_campaign VARCHAR(150) NOT NULL,
            utm_content VARCHAR(150) DEFAULT NULL,
            utm_term VARCHAR(150) DEFAULT NULL,
            status ENUM('draft', 'active', 'paused', 'ended') NOT NULL DEFAULT 'draft',
            budget_amount DECIMAL(12,2) DEFAULT NULL,
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_utm_campaign (utm_campaign),
            INDEX idx_platform_status (platform, status),
            INDEX idx_dates (start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS marketing_campaign_clicks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            session_id VARCHAR(128) NOT NULL,
            utm_source VARCHAR(100) DEFAULT NULL,
            utm_medium VARCHAR(100) DEFAULT NULL,
            utm_campaign VARCHAR(150) DEFAULT NULL,
            utm_content VARCHAR(150) DEFAULT NULL,
            utm_term VARCHAR(150) DEFAULT NULL,
            landing_page VARCHAR(255) DEFAULT NULL,
            referrer VARCHAR(255) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            click_count INT UNSIGNED NOT NULL DEFAULT 1,
            first_clicked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_clicked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_campaign_session (campaign_id, session_id),
            INDEX idx_campaign_time (campaign_id, first_clicked_at),
            CONSTRAINT fk_campaign_clicks_campaign
                FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $bookingColumns = campaignAttributionGetTableColumns($pdo, 'bookings');
        $conferenceColumns = campaignAttributionGetTableColumns($pdo, 'conference_inquiries');

        $bookingAlter = [
            'campaign_id' => "ALTER TABLE bookings ADD COLUMN campaign_id INT UNSIGNED NULL AFTER occupancy_type",
            'campaign_platform' => "ALTER TABLE bookings ADD COLUMN campaign_platform VARCHAR(50) NULL AFTER campaign_id",
            'utm_source' => "ALTER TABLE bookings ADD COLUMN utm_source VARCHAR(100) NULL AFTER campaign_platform",
            'utm_medium' => "ALTER TABLE bookings ADD COLUMN utm_medium VARCHAR(100) NULL AFTER utm_source",
            'utm_campaign' => "ALTER TABLE bookings ADD COLUMN utm_campaign VARCHAR(150) NULL AFTER utm_medium",
            'utm_content' => "ALTER TABLE bookings ADD COLUMN utm_content VARCHAR(150) NULL AFTER utm_campaign",
            'utm_term' => "ALTER TABLE bookings ADD COLUMN utm_term VARCHAR(150) NULL AFTER utm_content",
            'utm_landing_page' => "ALTER TABLE bookings ADD COLUMN utm_landing_page VARCHAR(255) NULL AFTER utm_term",
            'campaign_referrer' => "ALTER TABLE bookings ADD COLUMN campaign_referrer VARCHAR(255) NULL AFTER utm_landing_page",
            'campaign_attributed_at' => "ALTER TABLE bookings ADD COLUMN campaign_attributed_at DATETIME NULL AFTER campaign_referrer"
        ];

        foreach ($bookingAlter as $column => $sql) {
            if (!in_array($column, $bookingColumns, true)) {
                $pdo->exec($sql);
            }
        }

        try {
            $pdo->exec("ALTER TABLE bookings ADD INDEX idx_bookings_campaign (campaign_id)");
        } catch (Throwable $e) {
            // ignore duplicate index errors
        }

        $conferenceAlter = [
            'campaign_id' => "ALTER TABLE conference_inquiries ADD COLUMN campaign_id INT UNSIGNED NULL AFTER notes",
            'campaign_platform' => "ALTER TABLE conference_inquiries ADD COLUMN campaign_platform VARCHAR(50) NULL AFTER campaign_id",
            'utm_source' => "ALTER TABLE conference_inquiries ADD COLUMN utm_source VARCHAR(100) NULL AFTER campaign_platform",
            'utm_medium' => "ALTER TABLE conference_inquiries ADD COLUMN utm_medium VARCHAR(100) NULL AFTER utm_source",
            'utm_campaign' => "ALTER TABLE conference_inquiries ADD COLUMN utm_campaign VARCHAR(150) NULL AFTER utm_medium",
            'utm_content' => "ALTER TABLE conference_inquiries ADD COLUMN utm_content VARCHAR(150) NULL AFTER utm_campaign",
            'utm_term' => "ALTER TABLE conference_inquiries ADD COLUMN utm_term VARCHAR(150) NULL AFTER utm_content",
            'utm_landing_page' => "ALTER TABLE conference_inquiries ADD COLUMN utm_landing_page VARCHAR(255) NULL AFTER utm_term",
            'campaign_referrer' => "ALTER TABLE conference_inquiries ADD COLUMN campaign_referrer VARCHAR(255) NULL AFTER utm_landing_page",
            'campaign_attributed_at' => "ALTER TABLE conference_inquiries ADD COLUMN campaign_attributed_at DATETIME NULL AFTER campaign_referrer"
        ];

        foreach ($conferenceAlter as $column => $sql) {
            if (!in_array($column, $conferenceColumns, true)) {
                $pdo->exec($sql);
            }
        }

        try {
            $pdo->exec("ALTER TABLE conference_inquiries ADD INDEX idx_conf_campaign (campaign_id)");
        } catch (Throwable $e) {
            // ignore duplicate index errors
        }

        try {
            $pdo->exec("ALTER TABLE bookings ADD CONSTRAINT fk_bookings_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE SET NULL");
        } catch (Throwable $e) {
            // ignore duplicate/unsupported constraint creation
        }

        try {
            $pdo->exec("ALTER TABLE conference_inquiries ADD CONSTRAINT fk_conference_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE SET NULL");
        } catch (Throwable $e) {
            // ignore duplicate/unsupported constraint creation
        }

        // Refresh static column cache after possible ALTER operations.
        campaignAttributionGetTableColumns($pdo, 'bookings', true);
        campaignAttributionGetTableColumns($pdo, 'conference_inquiries', true);

    } catch (Throwable $e) {
        error_log('Campaign infrastructure error: ' . $e->getMessage());
    }

    $ready = true;
}

function campaignAttributionDetectPlatform($utmSource)
{
    $source = strtolower((string)$utmSource);
    if (strpos($source, 'insta') !== false) {
        return 'instagram';
    }
    if (strpos($source, 'fb') !== false || strpos($source, 'facebook') !== false) {
        return 'facebook';
    }
    return 'mixed';
}

function captureCampaignAttribution(PDO $pdo)
{
    campaignAttributionEnsureSession();

    $utm = [
        'utm_source' => campaignAttributionSanitize($_GET['utm_source'] ?? null, 100),
        'utm_medium' => campaignAttributionSanitize($_GET['utm_medium'] ?? null, 100),
        'utm_campaign' => campaignAttributionSanitize($_GET['utm_campaign'] ?? null, 150),
        'utm_content' => campaignAttributionSanitize($_GET['utm_content'] ?? null, 150),
        'utm_term' => campaignAttributionSanitize($_GET['utm_term'] ?? null, 150),
    ];

    if ($utm['utm_source'] === null && $utm['utm_campaign'] === null) {
        return;
    }

    ensureMarketingCampaignInfrastructure($pdo);

    $campaignId = null;
    $platform = campaignAttributionDetectPlatform($utm['utm_source']);

    try {
        if ($utm['utm_campaign'] !== null) {
            $stmt = $pdo->prepare("SELECT id, platform FROM marketing_campaigns WHERE utm_campaign = ? LIMIT 1");
            $stmt->execute([$utm['utm_campaign']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $campaignId = (int)$row['id'];
                $platform = $row['platform'] ?: $platform;
            }
        }
    } catch (Throwable $e) {
        error_log('Campaign lookup error: ' . $e->getMessage());
    }

    $attribution = [
        'campaign_id' => $campaignId,
        'campaign_platform' => $platform,
        'utm_source' => $utm['utm_source'],
        'utm_medium' => $utm['utm_medium'],
        'utm_campaign' => $utm['utm_campaign'],
        'utm_content' => $utm['utm_content'],
        'utm_term' => $utm['utm_term'],
        'utm_landing_page' => campaignAttributionNormalizePath(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
        'campaign_referrer' => campaignAttributionSanitize($_SERVER['HTTP_REFERER'] ?? '', 255),
        'campaign_attributed_at' => date('Y-m-d H:i:s')
    ];

    $_SESSION['campaign_attribution'] = $attribution;

    if (!headers_sent()) {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(
            'campaign_attribution',
            json_encode($attribution),
            [
                'expires' => time() + (60 * 60 * 24 * 30),
                'path' => '/',
                'secure' => $isSecure,
                'httponly' => false,
                'samesite' => 'Lax'
            ]
        );
    }

    if ($campaignId) {
        try {
            $sessionId = session_id() ?: 'anonymous';
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
            if ($ip && strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }

            $clickStmt = $pdo->prepare("INSERT INTO marketing_campaign_clicks (
                    campaign_id, session_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                    landing_page, referrer, ip_address, user_agent, click_count
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    click_count = click_count + 1,
                    landing_page = VALUES(landing_page),
                    referrer = VALUES(referrer),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    last_clicked_at = CURRENT_TIMESTAMP
            ");
            $clickStmt->execute([
                $campaignId,
                $sessionId,
                $utm['utm_source'],
                $utm['utm_medium'],
                $utm['utm_campaign'],
                $utm['utm_content'],
                $utm['utm_term'],
                $attribution['utm_landing_page'],
                $attribution['campaign_referrer'],
                $ip,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)
            ]);
        } catch (Throwable $e) {
            error_log('Campaign click logging error: ' . $e->getMessage());
        }
    }
}

function getCurrentCampaignAttribution()
{
    campaignAttributionEnsureSession();

    if (!empty($_SESSION['campaign_attribution']) && is_array($_SESSION['campaign_attribution'])) {
        return $_SESSION['campaign_attribution'];
    }

    if (!empty($_COOKIE['campaign_attribution'])) {
        $decoded = json_decode($_COOKIE['campaign_attribution'], true);
        if (is_array($decoded)) {
            $_SESSION['campaign_attribution'] = $decoded;
            return $decoded;
        }
    }

    return null;
}

function extractCampaignAttributionFromRequest(array $input)
{
    $payload = isset($input['utm']) && is_array($input['utm']) ? $input['utm'] : $input;

    $attribution = [
        'campaign_id' => isset($payload['campaign_id']) ? (int)$payload['campaign_id'] : null,
        'campaign_platform' => campaignAttributionSanitize($payload['campaign_platform'] ?? null, 50),
        'utm_source' => campaignAttributionSanitize($payload['utm_source'] ?? null, 100),
        'utm_medium' => campaignAttributionSanitize($payload['utm_medium'] ?? null, 100),
        'utm_campaign' => campaignAttributionSanitize($payload['utm_campaign'] ?? null, 150),
        'utm_content' => campaignAttributionSanitize($payload['utm_content'] ?? null, 150),
        'utm_term' => campaignAttributionSanitize($payload['utm_term'] ?? null, 150),
        'utm_landing_page' => campaignAttributionNormalizePath($payload['utm_landing_page'] ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')),
        'campaign_referrer' => campaignAttributionSanitize($payload['campaign_referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? null), 255),
        'campaign_attributed_at' => date('Y-m-d H:i:s')
    ];

    if ($attribution['campaign_platform'] === null) {
        $attribution['campaign_platform'] = campaignAttributionDetectPlatform($attribution['utm_source']);
    }

    if ($attribution['utm_source'] === null && $attribution['utm_campaign'] === null && !$attribution['campaign_id']) {
        return null;
    }

    return $attribution;
}

function getAttributionInsertData(PDO $pdo, $table, array $attribution = null)
{
    if ($attribution === null) {
        return ['columns' => [], 'values' => []];
    }

    $supportedColumns = campaignAttributionGetTableColumns($pdo, $table);
    if (empty($supportedColumns)) {
        return ['columns' => [], 'values' => []];
    }

    $map = [
        'campaign_id' => isset($attribution['campaign_id']) ? (int)$attribution['campaign_id'] : null,
        'campaign_platform' => $attribution['campaign_platform'] ?? null,
        'utm_source' => $attribution['utm_source'] ?? null,
        'utm_medium' => $attribution['utm_medium'] ?? null,
        'utm_campaign' => $attribution['utm_campaign'] ?? null,
        'utm_content' => $attribution['utm_content'] ?? null,
        'utm_term' => $attribution['utm_term'] ?? null,
        'utm_landing_page' => $attribution['utm_landing_page'] ?? null,
        'campaign_referrer' => $attribution['campaign_referrer'] ?? null,
        'campaign_attributed_at' => $attribution['campaign_attributed_at'] ?? date('Y-m-d H:i:s')
    ];

    $columns = [];
    $values = [];

    foreach ($map as $column => $value) {
        if (in_array($column, $supportedColumns, true)) {
            $columns[] = $column;
            $values[] = $value;
        }
    }

    return ['columns' => $columns, 'values' => $values];
}