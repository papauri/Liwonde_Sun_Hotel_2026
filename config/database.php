<?php
/**
 * Database Configuration
 * Hotel Website - Database Connection
 * Supports both LOCAL and PRODUCTION environments
 */

// Include caching system first
require_once __DIR__ . '/cache.php';

// Database configuration - multiple security options
// Priority: 1. Local config file, 2. Environment variables, 3. Hardcoded fallback

// Option 1: Check for local config file (for cPanel/production)
if (file_exists(__DIR__ . '/database.local.php')) {
    include __DIR__ . '/database.local.php';
} else {
    // Option 2: Use environment variables (for development)
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_name = getenv('DB_NAME') ?: '';
    $db_user = getenv('DB_USER') ?: '';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_port = getenv('DB_PORT') ?: '3306';
    $db_charset = 'utf8mb4';
}

// Validate that credentials are set
if (empty($db_host) || empty($db_name) || empty($db_user)) {
    die('Database credentials not configured. Please create config/database.local.php with your database credentials.');
}

// Define database constants
define('DB_HOST', $db_host);
define('DB_PORT', $db_port);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('DB_CHARSET', $db_charset);

// Create PDO connection with performance optimizations
try {
    // Diagnostic logging
    error_log("Database Connection Attempt:");
    error_log("  Host: " . DB_HOST);
    error_log("  Port: " . DB_PORT);
    error_log("  Database: " . DB_NAME);
    error_log("  User: " . DB_USER);
    error_log("  Environment Variables Set: " . (getenv('DB_HOST') ? 'YES' : 'NO'));
    
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false, // Disabled for remote DB to prevent connection pooling issues
        PDO::ATTR_TIMEOUT => 10, // Connection timeout in seconds (increased for remote DB)
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Buffer results for better performance
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Set timezone after connection
    $pdo->exec("SET time_zone = '+00:00'");
    
    error_log("Database Connection Successful!");
    
} catch (PDOException $e) {
    // Always show a beautiful custom error page (sleeping bear)
    $errorMsg = htmlspecialchars($e->getMessage());
    error_log("Database Connection Error: " . $e->getMessage());
    error_log("Error Code: " . $e->getCode());
    include_once __DIR__ . '/../includes/db-error.php';
    exit;
}

// Settings cache to avoid repeated queries
$_SITE_SETTINGS = [];

/**
 * Helper function to get setting value with file-based caching
 * DRAMATICALLY reduces database queries and remote connection overhead
 */
function getSetting($key, $default = '') {
    global $pdo, $_SITE_SETTINGS;
    
    // Check in-memory cache first (fastest)
    if (isset($_SITE_SETTINGS[$key])) {
        return $_SITE_SETTINGS[$key];
    }
    
    // Check file cache (much faster than database query)
    $cachedValue = getCache("setting_{$key}", null);
    if ($cachedValue !== null) {
        $_SITE_SETTINGS[$key] = $cachedValue;
        return $cachedValue;
    }
    
    // If no database connection, return default
    if ($pdo === null) {
        return $default;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        $value = $result ? $result['setting_value'] : $default;
        
        // Cache in memory
        $_SITE_SETTINGS[$key] = $value;
        
        // Cache in file for next request (1 hour TTL)
        setCache("setting_{$key}", $value, 3600);
        
        return $value;
    } catch (PDOException $e) {
        error_log("Error fetching setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Helper function to get email setting value with caching
 * Handles encrypted settings like passwords
 */
function getEmailSetting($key, $default = '') {
    global $pdo;
    
    try {
        // Check if email_settings table exists (cached)
        $table_exists = getCache("table_email_settings", null);
        if ($table_exists === null) {
            $table_exists = $pdo->query("SHOW TABLES LIKE 'email_settings'")->rowCount() > 0;
            setCache("table_email_settings", $table_exists, 86400); // Cache for 24 hours
        }
        
        if (!$table_exists) {
            // Fallback to site_settings for backward compatibility
            return getSetting($key, $default);
        }
        
        // Try file cache first
        $cachedValue = getCache("email_setting_{$key}", null);
        if ($cachedValue !== null) {
            return $cachedValue;
        }
        
        $stmt = $pdo->prepare("SELECT setting_value, is_encrypted FROM email_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return $default;
        }
        
        $value = $result['setting_value'];
        $is_encrypted = (bool)$result['is_encrypted'];
        
        // Handle encrypted values (like passwords)
        if ($is_encrypted && !empty($value)) {
            try {
                // Try to decrypt using database function
                $stmt = $pdo->prepare("SELECT decrypt_setting(?) as decrypted_value");
                $stmt->execute([$value]);
                $decrypted = $stmt->fetch();
                if ($decrypted && !empty($decrypted['decrypted_value'])) {
                    $value = $decrypted['decrypted_value'];
                } else {
                    $value = ''; // Don't expose encrypted data
                }
            } catch (Exception $e) {
                $value = ''; // Don't expose encrypted data on error
            }
        }
        
        // Cache the result (1 hour TTL for encrypted, 6 hours for unencrypted)
        $ttl = $is_encrypted ? 3600 : 21600;
        setCache("email_setting_{$key}", $value, $ttl);
        
        return $value;
    } catch (PDOException $e) {
        error_log("Error fetching email setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Helper function to get all email settings
 */
function getAllEmailSettings() {
    global $pdo;
    
    $settings = [];
    try {
        // Check if email_settings table exists
        $table_exists = $pdo->query("SHOW TABLES LIKE 'email_settings'")->rowCount() > 0;
        
        if (!$table_exists) {
            return $settings;
        }
        
        $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted, description FROM email_settings ORDER BY setting_group, setting_key");
        $results = $stmt->fetchAll();
        
        foreach ($results as $row) {
            $key = $row['setting_key'];
            $value = $row['setting_value'];
            $is_encrypted = (bool)$row['is_encrypted'];
            
            // Handle encrypted values
            if ($is_encrypted && !empty($value)) {
                try {
                    $stmt2 = $pdo->prepare("SELECT decrypt_setting(?) as decrypted_value");
                    $stmt2->execute([$value]);
                    $decrypted = $stmt2->fetch();
                    if ($decrypted && !empty($decrypted['decrypted_value'])) {
                        $value = $decrypted['decrypted_value'];
                    } else {
                        $value = ''; // Don't expose encrypted data
                    }
                } catch (Exception $e) {
                    $value = ''; // Don't expose encrypted data on error
                }
            }
            
            $settings[$key] = [
                'value' => $value,
                'encrypted' => $is_encrypted,
                'description' => $row['description']
            ];
        }
        
        return $settings;
    } catch (PDOException $e) {
        error_log("Error fetching all email settings: " . $e->getMessage());
        return $settings;
    }
}

/**
 * Helper function to update email setting
 */
function updateEmailSetting($key, $value, $description = null, $is_encrypted = false) {
    global $pdo;
    
    try {
        // Check if email_settings table exists
        $table_exists = $pdo->query("SHOW TABLES LIKE 'email_settings'")->rowCount() > 0;
        
        if (!$table_exists) {
            // Fallback to site_settings for backward compatibility
            return updateSetting($key, $value);
        }
        
        // Handle encryption if needed
        $final_value = $value;
        if ($is_encrypted && !empty($value)) {
            try {
                $stmt = $pdo->prepare("SELECT encrypt_setting(?) as encrypted_value");
                $stmt->execute([$value]);
                $encrypted = $stmt->fetch();
                if ($encrypted && !empty($encrypted['encrypted_value'])) {
                    $final_value = $encrypted['encrypted_value'];
                }
            } catch (Exception $e) {
                error_log("Error encrypting setting {$key}: " . $e->getMessage());
                return false;
            }
        }
        
        // Update or insert
        $sql = "INSERT INTO email_settings (setting_key, setting_value, is_encrypted, description) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                is_encrypted = VALUES(is_encrypted),
                description = VALUES(description),
                updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$key, $final_value, $is_encrypted ? 1 : 0, $description]);
        
        // Clear cache for this setting
        global $_SITE_SETTINGS;
        if (isset($_SITE_SETTINGS[$key])) {
            unset($_SITE_SETTINGS[$key]);
        }

        // Clear file cache for this email setting so changes are visible immediately.
        deleteCache("email_setting_{$key}");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating email setting: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper function to update setting (for backward compatibility)
 */
function updateSetting($key, $value) {
    global $pdo;
    
    try {
        $sql = "INSERT INTO site_settings (setting_key, setting_value) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$key, $value]);
        
        // Clear cache for this setting
        global $_SITE_SETTINGS;
        if (isset($_SITE_SETTINGS[$key])) {
            unset($_SITE_SETTINGS[$key]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error updating setting: " . $e->getMessage());
        return false;
    }
}

/**
 * Preload common settings for better performance
 */
function preloadCommonSettings() {
    $common_settings = [
        'site_name', 'site_description', 'currency_symbol',
        'phone_main', 'email_reservations', 'email_info',
        'social_facebook', 'social_instagram', 'social_twitter'
    ];
    
    foreach ($common_settings as $setting) {
        getSetting($setting);
    }
}

// Preload common settings for faster page loads
preloadCommonSettings();

/**
 * Helper function to get all settings by group
 */
function getSettingsByGroup($group) {
    global $pdo;
    
    // Check cache first
    $cached = getCache("settings_group_{$group}", null);
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_group = ?");
        $stmt->execute([$group]);
        $result = $stmt->fetchAll();
        
        // Cache for 30 minutes
        setCache("settings_group_{$group}", $result, 1800);
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error fetching settings: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached rooms with optional filters
 * Dramatically reduces database queries for room listings
 */
function getCachedRooms($filters = []) {
    global $pdo;
    
    // Create cache key from filters
    $cacheKey = 'rooms_' . md5(json_encode($filters));
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        $sql = "SELECT * FROM rooms WHERE is_active = 1";
        $params = [];
        
        if (!empty($filters['is_featured'])) {
            $sql .= " AND is_featured = 1";
        }
        
        $sql .= " ORDER BY display_order ASC, id ASC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cache for 15 minutes
        setCache($cacheKey, $rooms, 900);
        
        return $rooms;
    } catch (PDOException $e) {
        error_log("Error fetching rooms: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached facilities
 */
function getCachedFacilities($filters = []) {
    global $pdo;
    
    $cacheKey = 'facilities_' . md5(json_encode($filters));
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        $sql = "SELECT * FROM facilities WHERE is_active = 1";
        $params = [];
        
        if (!empty($filters['is_featured'])) {
            $sql .= " AND is_featured = 1";
        }
        
        $sql .= " ORDER BY display_order ASC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cache for 30 minutes
        setCache($cacheKey, $facilities, 1800);
        
        return $facilities;
    } catch (PDOException $e) {
        error_log("Error fetching facilities: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached gallery images
 */
function getCachedGalleryImages() {
    global $pdo;
    
    $cacheKey = 'gallery_images';
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        $stmt = $pdo->query("
            SELECT id, title, description, image_url, video_path, video_type, category, display_order 
            FROM hotel_gallery 
            WHERE is_active = 1 
            ORDER BY display_order ASC
        ");
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cache for 1 hour
        setCache($cacheKey, $images, 3600);
        
        return $images;
    } catch (PDOException $e) {
        error_log("Error fetching gallery images: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached hero slides
 */
function getCachedHeroSlides() {
    global $pdo;
    
    $cacheKey = 'hero_slides';
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        $stmt = $pdo->query("
            SELECT title, subtitle, description, primary_cta_text, primary_cta_link, 
                   secondary_cta_text, secondary_cta_link, image_path, 
                   video_path, video_type
            FROM hero_slides 
            WHERE is_active = 1 
            ORDER BY display_order ASC
        ");
        $slides = $stmt->fetchAll();
        
        // Cache for 1 hour
        setCache($cacheKey, $slides, 3600);
        
        return $slides;
    } catch (PDOException $e) {
        error_log("Error fetching hero slides: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached testimonials
 */
function getCachedTestimonials($limit = 3) {
    global $pdo;
    
    $cacheKey = "testimonials_{$limit}";
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM testimonials
            WHERE is_featured = 1 AND is_approved = 1
            ORDER BY display_order ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $testimonials = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cache for 30 minutes
        setCache($cacheKey, $testimonials, 1800);
        
        return $testimonials;
    } catch (PDOException $e) {
        error_log("Error fetching testimonials: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached policies
 */
function getCachedPolicies() {
    global $pdo;
    
    $cacheKey = 'policies';
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        $stmt = $pdo->query("
            SELECT slug, title, summary, content 
            FROM policies 
            WHERE is_active = 1 
            ORDER BY display_order ASC, id ASC
        ");
        $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cache for 1 hour
        setCache($cacheKey, $policies, 3600);
        
        return $policies;
    } catch (PDOException $e) {
        error_log("Error fetching policies: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get cached About Us content
 */
function getCachedAboutUs() {
    global $pdo;
    
    $cacheKey = 'about_us';
    $cached = getCache($cacheKey, null);
    if ($cached !== null) {
        return $cached;
    }
    
    try {
        // Get main about content
        $stmt = $pdo->prepare("SELECT * FROM about_us WHERE section_type = 'main' AND is_active = 1 ORDER BY display_order LIMIT 1");
        $stmt->execute();
        $about_content = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get features
        $stmt = $pdo->prepare("SELECT * FROM about_us WHERE section_type = 'feature' AND is_active = 1 ORDER BY display_order");
        $stmt->execute();
        $about_features = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get stats
        $stmt = $pdo->prepare("SELECT * FROM about_us WHERE section_type = 'stat' AND is_active = 1 ORDER BY display_order");
        $stmt->execute();
        $about_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [
            'content' => $about_content,
            'features' => $about_features,
            'stats' => $about_stats
        ];
        
        // Cache for 1 hour
        setCache($cacheKey, $result, 3600);
        
        return $result;
    } catch (PDOException $e) {
        error_log("Error fetching about us content: " . $e->getMessage());
        return ['content' => null, 'features' => [], 'stats' => []];
    }
}

/**
 * Invalidate all data caches when content changes
 */
function invalidateDataCaches() {
    // Clear all data caches
    $patterns = [
        'rooms_*',
        'facilities_*',
        'gallery_images',
        'hero_slides',
        'testimonials_*',
        'policies',
        'about_us',
        'settings_group_*'
    ];
    
    foreach ($patterns as $pattern) {
        $files = glob(CACHE_DIR . '/' . md5(str_replace('*', '', $pattern)) . '*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}

/**
 * Helper: fetch active page hero by page slug.
 * Returns associative array or null.
 */
function getPageHero(string $page_slug): ?array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM page_heroes
            WHERE page_slug = ? AND is_active = 1
            ORDER BY display_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([$page_slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching page hero ({$page_slug}): " . $e->getMessage());
        return null;
    }
}

/**
 * Helper: fetch active page hero by exact page URL (e.g. /restaurant.php).
 * Returns associative array or null.
 */
function getPageHeroByUrl(string $page_url): ?array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM page_heroes
            WHERE page_url = ? AND is_active = 1
            ORDER BY display_order ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([$page_url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching page hero by url ({$page_url}): " . $e->getMessage());
        return null;
    }
}

/**
 * Helper: get hero for the current request without hardcoding per-page slugs.
 * Strategy:
 *  1) Try exact match on page_url (SCRIPT_NAME).
 *  2) Fallback to slug derived from current filename (basename without .php).
 */
function getCurrentPageHero(): ?array {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($script) {
        $byUrl = getPageHeroByUrl($script);
        if ($byUrl) return $byUrl;
    }

    $path = $_SERVER['SCRIPT_FILENAME'] ?? $script;
    if (!$path) return null;

    $slug = strtolower(pathinfo($path, PATHINFO_FILENAME));
    $slug = str_replace('_', '-', $slug);

    return getPageHero($slug);
}

/**
 * Helper: fetch active page loader subtext by page slug.
 * Returns the subtext string if found and active, null otherwise.
 */
function getPageLoader(string $page_slug): ?string {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT subtext
            FROM page_loaders
            WHERE page_slug = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$page_slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['subtext'] : null;
    } catch (PDOException $e) {
        error_log("Error fetching page loader ({$page_slug}): " . $e->getMessage());
        return null;
    }
}

/**
 * Check whether a given table has a column.
 */
function hasTableColumn($table_name, $column_name) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', $table_name) . "` LIKE ?");
        $stmt->execute([$column_name]);
        if ((bool)$stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }

        // Fallback for environments where SHOW COLUMNS is restricted but SELECT is allowed.
        $pdo->query("SELECT `" . str_replace('`', '``', $column_name) . "` FROM `" . str_replace('`', '``', $table_name) . "` LIMIT 0");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Normalize occupancy values.
 */
function normalizeOccupancyType($occupancy_type) {
    $value = strtolower(trim((string)$occupancy_type));

    if (in_array($value, ['single', 'double', 'child'], true)) {
        return $value;
    }

    return 'double';
}

/**
 * Resolve child occupancy price.
 */
function getRoomChildOccupancyPrice(array $room) {
    if (!empty($room['price_child_occupancy'])) {
        return (float)$room['price_child_occupancy'];
    }

    return null;
}

/**
 * Ensure child occupancy schema exists.
 */
function ensureChildOccupancyInfrastructure() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return true;
    }

    try {
        if (!hasTableColumn('rooms', 'price_child_occupancy')) {
            try {
                $pdo->exec("ALTER TABLE rooms ADD COLUMN price_child_occupancy DECIMAL(10,2) NULL AFTER price_double_occupancy");
            } catch (PDOException $e) {
                $is_duplicate_column = stripos($e->getMessage(), 'Duplicate column name') !== false;
                if (!$is_duplicate_column && !hasTableColumn('rooms', 'price_child_occupancy')) {
                    throw $e;
                }
            }
        }

        try {
            $pdo->exec("ALTER TABLE bookings MODIFY COLUMN occupancy_type ENUM('single','double','child') NOT NULL DEFAULT 'double'");
        } catch (PDOException $e) {
            // Ignore managed-host DDL restrictions; runtime normalization remains active.
        }

        $checked = true;
        return true;
    } catch (PDOException $e) {
        error_log('Error ensuring child occupancy infrastructure: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ensure room unit infrastructure exists (table + columns used by booking/blocking).
 */
function ensureRoomUnitInfrastructure() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return true;
    }

    try {
        try {
            $pdo->exec("\n                CREATE TABLE IF NOT EXISTS room_units (\n                    id INT AUTO_INCREMENT PRIMARY KEY,\n                    room_id INT NOT NULL,\n                    unit_code VARCHAR(50) NOT NULL,\n                    unit_label VARCHAR(120) NOT NULL,\n                    is_active TINYINT(1) NOT NULL DEFAULT 1,\n                    notes VARCHAR(255) NULL,\n                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n                    UNIQUE KEY uniq_room_unit_code (room_id, unit_code),\n                    INDEX idx_room_active (room_id, is_active)\n                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n            ");
        } catch (PDOException $e) {
            // Some hosts block CREATE privilege even for IF NOT EXISTS.
            // Continue only if the table is already present.
            $existing_table_stmt = $pdo->query("SHOW TABLES LIKE 'room_units'");
            if (!$existing_table_stmt || $existing_table_stmt->rowCount() === 0) {
                throw $e;
            }
        }

        if (!hasTableColumn('bookings', 'room_unit_id')) {
            try {
                $pdo->exec("ALTER TABLE bookings ADD COLUMN room_unit_id INT NULL AFTER room_id");
            } catch (PDOException $e) {
                $is_duplicate_column = stripos($e->getMessage(), 'Duplicate column name') !== false;
                if (!$is_duplicate_column && !hasTableColumn('bookings', 'room_unit_id')) {
                    throw $e;
                }
            }
            try {
                $pdo->exec("ALTER TABLE bookings ADD INDEX idx_booking_room_unit (room_unit_id)");
            } catch (PDOException $e) {
                // Ignore duplicate-index and restricted DDL scenarios.
            }
        }

        if (!hasTableColumn('bookings', 'room_unit_assignment_source')) {
            try {
                $pdo->exec("ALTER TABLE bookings ADD COLUMN room_unit_assignment_source VARCHAR(20) NULL AFTER room_unit_id");
            } catch (PDOException $e) {
                $is_duplicate_column = stripos($e->getMessage(), 'Duplicate column name') !== false;
                if (!$is_duplicate_column && !hasTableColumn('bookings', 'room_unit_assignment_source')) {
                    throw $e;
                }
            }
        }

        if (!hasTableColumn('room_blocked_dates', 'room_unit_id')) {
            try {
                $pdo->exec("ALTER TABLE room_blocked_dates ADD COLUMN room_unit_id INT NULL AFTER room_id");
            } catch (PDOException $e) {
                $is_duplicate_column = stripos($e->getMessage(), 'Duplicate column name') !== false;
                if (!$is_duplicate_column && !hasTableColumn('room_blocked_dates', 'room_unit_id')) {
                    throw $e;
                }
            }
            try {
                $pdo->exec("ALTER TABLE room_blocked_dates ADD INDEX idx_blocked_room_unit_date (room_unit_id, block_date)");
            } catch (PDOException $e) {
                // Ignore duplicate-index and restricted DDL scenarios.
            }
        }

        $checked = true;
        return true;
    } catch (PDOException $e) {
        error_log('Error ensuring room unit infrastructure: ' . $e->getMessage());

        // Fallback: if schema already exists but DDL failed due privileges, continue.
        try {
            $table_exists_stmt = $pdo->query("SHOW TABLES LIKE 'room_units'");
            $table_exists = $table_exists_stmt && $table_exists_stmt->rowCount() > 0;

            if ($table_exists
                && hasTableColumn('bookings', 'room_unit_id')
                && hasTableColumn('room_blocked_dates', 'room_unit_id')) {
                $checked = true;
                return true;
            }
        } catch (PDOException $ignored) {
            // Keep original failure result.
        }

        return false;
    }
}

/**
 * Keep room units aligned to a room's total_rooms count.
 */
function syncRoomUnitsForRoom($room_id, $target_total_rooms = null) {
    global $pdo;

    if (!ensureRoomUnitInfrastructure()) {
        return false;
    }

    try {
        $room_stmt = $pdo->prepare("SELECT id, name, total_rooms, rooms_available FROM rooms WHERE id = ? LIMIT 1");
        $room_stmt->execute([$room_id]);
        $room = $room_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            return false;
        }

        $target = $target_total_rooms !== null ? (int)$target_total_rooms : (int)$room['total_rooms'];
        $room_total = (int)($room['total_rooms'] ?? 0);
        $room_available = (int)($room['rooms_available'] ?? 0);

        if ($target <= 0) {
            $target = max($room_total, $room_available, 1);
        }
        if ($target < 0) {
            $target = 0;
        }

        $units_stmt = $pdo->prepare("SELECT id, unit_code FROM room_units WHERE room_id = ? ORDER BY id ASC");
        $units_stmt->execute([$room_id]);
        $existing_units = $units_stmt->fetchAll(PDO::FETCH_ASSOC);
        $existing_count = count($existing_units);

        if ($existing_count < $target) {
            $insert_stmt = $pdo->prepare("\n                INSERT INTO room_units (room_id, unit_code, unit_label, is_active)\n                VALUES (?, ?, ?, 1)\n            ");

            for ($i = $existing_count + 1; $i <= $target; $i++) {
                $unit_code = (string)$i;
                $unit_label = $room['name'] . ' ' . $i;
                $insert_stmt->execute([$room_id, $unit_code, $unit_label]);
            }
        }

        $active_stmt = $pdo->prepare("SELECT id FROM room_units WHERE room_id = ? ORDER BY id ASC");
        $active_stmt->execute([$room_id]);
        $all_units = $active_stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($all_units as $index => $unit_id) {
            $is_active = ($index < $target) ? 1 : 0;
            $update_stmt = $pdo->prepare("UPDATE room_units SET is_active = ? WHERE id = ?");
            $update_stmt->execute([$is_active, $unit_id]);
        }

        return true;
    } catch (PDOException $e) {
        error_log('Error syncing room units: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get candidate room units that are free in a date range.
 */
function getAvailableRoomUnitsForDateRange($room_id, $check_in_date, $check_out_date, $exclude_booking_id = null) {
    global $pdo;

    if (!ensureRoomUnitInfrastructure()) {
        return [];
    }

    try {
        syncRoomUnitsForRoom($room_id);

        $units_stmt = $pdo->prepare("\n            SELECT id, room_id, unit_code, unit_label\n            FROM room_units\n            WHERE room_id = ? AND is_active = 1\n            ORDER BY id ASC\n        ");
        $units_stmt->execute([$room_id]);
        $units = $units_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($units)) {
            return [];
        }

        // Global room-type or hotel-wide blocks make all units unavailable for those dates.
        $global_block_stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM room_blocked_dates\n            WHERE block_date >= ? AND block_date < ?\n              AND room_unit_id IS NULL\n              AND (room_id = ? OR room_id IS NULL)\n        ");
        $global_block_stmt->execute([$check_in_date, $check_out_date, $room_id]);
        if ((int)$global_block_stmt->fetchColumn() > 0) {
            return [];
        }

        $available_units = [];

        foreach ($units as $unit) {
            $unit_block_stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM room_blocked_dates\n                WHERE block_date >= ? AND block_date < ?\n                  AND room_unit_id = ?\n            ");
            $unit_block_stmt->execute([$check_in_date, $check_out_date, $unit['id']]);
            if ((int)$unit_block_stmt->fetchColumn() > 0) {
                continue;
            }

            $booking_sql = "\n                SELECT COUNT(*)\n                FROM bookings\n                WHERE room_id = ?\n                  AND room_unit_id = ?\n                  AND status IN ('pending', 'tentative', 'confirmed', 'checked-in')\n                  AND NOT (check_out_date <= ? OR check_in_date >= ?)\n            ";
            $booking_params = [$room_id, $unit['id'], $check_in_date, $check_out_date];

            if ($exclude_booking_id) {
                $booking_sql .= " AND id != ?";
                $booking_params[] = $exclude_booking_id;
            }

            $booking_stmt = $pdo->prepare($booking_sql);
            $booking_stmt->execute($booking_params);

            if ((int)$booking_stmt->fetchColumn() === 0) {
                $available_units[] = $unit;
            }
        }

        return $available_units;
    } catch (PDOException $e) {
        error_log('Error finding available room units: ' . $e->getMessage());
        return [];
    }
}

/**
 * Allocate a room unit automatically or by a preferred unit id.
 * Returns unit id, null (legacy aggregate fallback), or false on failure.
 */
function allocateRoomUnitForBooking($room_id, $check_in_date, $check_out_date, $preferred_room_unit_id = null, $exclude_booking_id = null, &$error = null) {
    global $pdo;

    $error = null;

    if (!ensureRoomUnitInfrastructure()) {
        return null;
    }

    try {
        syncRoomUnitsForRoom($room_id);

        $units_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM room_units WHERE room_id = ? AND is_active = 1");
        $units_count_stmt->execute([$room_id]);
        $active_units_count = (int)$units_count_stmt->fetchColumn();

        if ($active_units_count === 0) {
            return null;
        }

        $available_units = getAvailableRoomUnitsForDateRange($room_id, $check_in_date, $check_out_date, $exclude_booking_id);
        if (empty($available_units)) {
            $error = 'No individual room unit is available for the selected dates.';
            return false;
        }

        if ($preferred_room_unit_id !== null) {
            $preferred_room_unit_id = (int)$preferred_room_unit_id;
            foreach ($available_units as $unit) {
                if ((int)$unit['id'] === $preferred_room_unit_id) {
                    return $preferred_room_unit_id;
                }
            }

            $error = 'The selected room unit is not available for the selected dates.';
            return false;
        }

        return (int)$available_units[0]['id'];
    } catch (PDOException $e) {
        error_log('Error allocating room unit: ' . $e->getMessage());
        $error = 'Could not allocate a room unit. Please try again.';
        return false;
    }
}

/**
 * Analyze room_unit_id backfill for active bookings and optionally apply updates.
 */
function analyzeActiveBookingRoomUnitBackfill($limit = 500, $apply_updates = false) {
    global $pdo;

    $response = [
        'success' => false,
        'mode' => $apply_updates ? 'apply' : 'preview',
        'processed' => 0,
        'updated' => 0,
        'skipped' => 0,
        'skipped_references' => [],
        'updatable_records' => [],
        'skipped_records' => [],
        'message' => ''
    ];

    if (!ensureRoomUnitInfrastructure()) {
        $response['message'] = 'Room unit infrastructure is not available.';
        return $response;
    }

    try {
        $limit = max(1, (int)$limit);

        $status_sql = "'pending','tentative','confirmed','checked-in'";
        $bookings_stmt = $pdo->prepare("\n            SELECT id, booking_reference, room_id, check_in_date, check_out_date\n            FROM bookings\n            WHERE room_unit_id IS NULL\n              AND status IN ({$status_sql})\n            ORDER BY room_id ASC, check_in_date ASC, id ASC\n            LIMIT {$limit}\n        ");
        $bookings_stmt->execute();
        $bookings = $bookings_stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['processed'] = count($bookings);
        if (empty($bookings)) {
            $response['success'] = true;
            $response['message'] = 'No active bookings require room unit backfill.';
            return $response;
        }

        // Track allocations made in this run to avoid assigning the same unit to overlapping ranges.
        $session_assignments = [];

        foreach ($bookings as $booking) {
            $room_id = (int)$booking['room_id'];
            $booking_id = (int)$booking['id'];
            $check_in_date = $booking['check_in_date'];
            $check_out_date = $booking['check_out_date'];
            $skip_reason = null;

            syncRoomUnitsForRoom($room_id);

            $units_stmt = $pdo->prepare("\n                SELECT id, unit_label\n                FROM room_units\n                WHERE room_id = ? AND is_active = 1\n                ORDER BY id ASC\n            ");
            $units_stmt->execute([$room_id]);
            $units = $units_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($units)) {
                $response['skipped']++;
                $skip_reason = 'No active units available for this room type';
                if (count($response['skipped_references']) < 20) {
                    $response['skipped_references'][] = $booking['booking_reference'];
                }
                if (count($response['skipped_records']) < 100) {
                    $response['skipped_records'][] = [
                        'booking_id' => $booking_id,
                        'booking_reference' => $booking['booking_reference'],
                        'room_id' => $room_id,
                        'check_in_date' => $check_in_date,
                        'check_out_date' => $check_out_date,
                        'reason' => $skip_reason
                    ];
                }
                continue;
            }

            $chosen_unit_id = null;
            $chosen_unit_label = null;

            foreach ($units as $unit) {
                $unit_id = (int)$unit['id'];

                // Skip if blocked at unit, room-type, or global level for any day in the booking range.
                $blocked_stmt = $pdo->prepare("\n                    SELECT COUNT(*)\n                    FROM room_blocked_dates\n                    WHERE block_date >= ? AND block_date < ?\n                      AND (\n                            room_unit_id = ?\n                            OR (room_unit_id IS NULL AND (room_id = ? OR room_id IS NULL))\n                          )\n                ");
                $blocked_stmt->execute([$check_in_date, $check_out_date, $unit_id, $room_id]);
                if ((int)$blocked_stmt->fetchColumn() > 0) {
                    $skip_reason = 'All candidate units are blocked for one or more dates';
                    continue;
                }

                // Ensure no overlap with already-assigned bookings in DB.
                $conflict_stmt = $pdo->prepare("\n                    SELECT COUNT(*)\n                    FROM bookings\n                    WHERE room_id = ?\n                      AND room_unit_id = ?\n                      AND id != ?\n                      AND status IN ({$status_sql})\n                      AND NOT (check_out_date <= ? OR check_in_date >= ?)\n                ");
                $conflict_stmt->execute([$room_id, $unit_id, $booking_id, $check_in_date, $check_out_date]);
                if ((int)$conflict_stmt->fetchColumn() > 0) {
                    $skip_reason = 'All candidate units overlap existing assigned bookings';
                    continue;
                }

                // Ensure no overlap with assignments made earlier in this same backfill pass.
                $has_session_conflict = false;
                if (!empty($session_assignments[$unit_id])) {
                    foreach ($session_assignments[$unit_id] as $range) {
                        if (!($range['check_out'] <= $check_in_date || $range['check_in'] >= $check_out_date)) {
                            $has_session_conflict = true;
                            break;
                        }
                    }
                }

                if ($has_session_conflict) {
                    $skip_reason = 'All candidate units conflict with assignments chosen in this pass';
                    continue;
                }

                $chosen_unit_id = $unit_id;
                $chosen_unit_label = $unit['unit_label'];
                break;
            }

            if ($chosen_unit_id === null) {
                $response['skipped']++;
                if (count($response['skipped_references']) < 20) {
                    $response['skipped_references'][] = $booking['booking_reference'];
                }
                if (count($response['skipped_records']) < 100) {
                    $response['skipped_records'][] = [
                        'booking_id' => $booking_id,
                        'booking_reference' => $booking['booking_reference'],
                        'room_id' => $room_id,
                        'check_in_date' => $check_in_date,
                        'check_out_date' => $check_out_date,
                        'reason' => $skip_reason ?: 'No eligible unit found'
                    ];
                }
                continue;
            }

            if (count($response['updatable_records']) < 100) {
                $response['updatable_records'][] = [
                    'booking_id' => $booking_id,
                    'booking_reference' => $booking['booking_reference'],
                    'room_id' => $room_id,
                    'check_in_date' => $check_in_date,
                    'check_out_date' => $check_out_date,
                    'assigned_unit_id' => $chosen_unit_id,
                    'assigned_unit_label' => $chosen_unit_label
                ];
            }

            if (!$apply_updates) {
                $response['updated']++;
                if (!isset($session_assignments[$chosen_unit_id])) {
                    $session_assignments[$chosen_unit_id] = [];
                }
                $session_assignments[$chosen_unit_id][] = [
                    'check_in' => $check_in_date,
                    'check_out' => $check_out_date
                ];
                continue;
            }

            $update_stmt = $pdo->prepare("\n                UPDATE bookings\n                SET room_unit_id = ?, room_unit_assignment_source = 'auto', updated_at = CURRENT_TIMESTAMP\n                WHERE id = ? AND room_unit_id IS NULL\n            ");
            $update_stmt->execute([$chosen_unit_id, $booking_id]);

            if ($update_stmt->rowCount() > 0) {
                $response['updated']++;
                if (!isset($session_assignments[$chosen_unit_id])) {
                    $session_assignments[$chosen_unit_id] = [];
                }
                $session_assignments[$chosen_unit_id][] = [
                    'check_in' => $check_in_date,
                    'check_out' => $check_out_date
                ];
            } else {
                $response['skipped']++;
                if (count($response['skipped_references']) < 20) {
                    $response['skipped_references'][] = $booking['booking_reference'];
                }
                if (count($response['skipped_records']) < 100) {
                    $response['skipped_records'][] = [
                        'booking_id' => $booking_id,
                        'booking_reference' => $booking['booking_reference'],
                        'room_id' => $room_id,
                        'check_in_date' => $check_in_date,
                        'check_out_date' => $check_out_date,
                        'reason' => 'Update did not apply (booking may have been modified concurrently)'
                    ];
                }
            }
        }

        $response['success'] = true;
        if ($apply_updates) {
            $response['message'] = "Backfill complete: {$response['updated']} updated, {$response['skipped']} skipped.";
        } else {
            $response['message'] = "Preview complete: {$response['updated']} can be updated, {$response['skipped']} would be skipped.";
        }
        return $response;
    } catch (PDOException $e) {
        error_log('Error backfilling booking room units: ' . $e->getMessage());
        $response['message'] = $apply_updates
            ? 'Database error during room unit backfill.'
            : 'Database error during backfill preview.';
        return $response;
    }
}

/**
 * Preview room_unit_id backfill without persisting changes.
 */
function previewActiveBookingRoomUnitBackfill($limit = 500) {
    return analyzeActiveBookingRoomUnitBackfill($limit, false);
}

/**
 * Backfill room_unit_id for existing active bookings where a valid unit can be assigned.
 */
function backfillActiveBookingRoomUnits($limit = 500) {
    return analyzeActiveBookingRoomUnitBackfill($limit, true);
}

/**
 * Helper function to check room availability
 * Returns true if room is available, false if booked or blocked
 */
function isRoomAvailable($room_id, $check_in_date, $check_out_date, $exclude_booking_id = null, $preferred_room_unit_id = null) {
    $availability = checkRoomAvailability($room_id, $check_in_date, $check_out_date, $exclude_booking_id, $preferred_room_unit_id);
    return !empty($availability['available']);
}

/**
 * Enhanced function to check room availability with detailed conflict information
 * Returns array with availability status and conflict details
 */
function checkRoomAvailability($room_id, $check_in_date, $check_out_date, $exclude_booking_id = null, $preferred_room_unit_id = null) {
    global $pdo;
    
    $result = [
        'available' => true,
        'conflicts' => [],
        'blocked_dates' => [],
        'room_exists' => false,
        'room' => null
    ];
    
    try {
        // Check if room exists and get details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? AND is_active = 1");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            $result['room_exists'] = false;
            $result['error'] = 'Room not found or inactive';
            return $result;
        }
        
        $result['room'] = $room;
        $result['room_exists'] = true;
        
        // Validate dates
        $check_in = new DateTime($check_in_date);
        $check_out = new DateTime($check_out_date);
        $today = new DateTime();
        
        if ($check_in < $today) {
            $result['available'] = false;
            $result['error'] = 'Check-in date cannot be in the past';
            return $result;
        }
        
        if ($check_out <= $check_in) {
            $result['available'] = false;
            $result['error'] = 'Check-out date must be after check-in date';
            return $result;
        }
        
        // Check if there are rooms available
        if ($room['rooms_available'] <= 0) {
            $result['available'] = false;
            $result['error'] = 'No rooms of this type are currently available';
            return $result;
        }
        
        // Check for blocked dates (both room-specific and global blocks)
        $blocked_sql = "
            SELECT
                id,
                room_id,
                block_date,
                block_type,
                reason
            FROM room_blocked_dates
            WHERE block_date >= ? AND block_date < ?
            AND (room_id = ? OR room_id IS NULL)
            ORDER BY block_date ASC
        ";
        $blocked_stmt = $pdo->prepare($blocked_sql);
        $blocked_stmt->execute([$check_in_date, $check_out_date, $room_id]);
        $blocked_dates = $blocked_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($blocked_dates)) {
            $result['available'] = false;
            $result['blocked_dates'] = $blocked_dates;
            $result['error'] = 'Selected dates are not available for booking';
            
            // Build blocked dates message
            $blocked_details = [];
            foreach ($blocked_dates as $blocked) {
                $blocked_date = new DateTime($blocked['block_date']);
                $room_name = $blocked['room_id'] ? $room['name'] : 'All rooms';
                $blocked_details[] = sprintf(
                    "%s on %s (%s)",
                    $room_name,
                    $blocked_date->format('M j, Y'),
                    $blocked['block_type']
                );
            }
            $result['blocked_message'] = implode('; ', $blocked_details);
            return $result;
        }
        
        // Prefer unit-aware availability when room units are configured.
        $units_available = getAvailableRoomUnitsForDateRange($room_id, $check_in_date, $check_out_date, $exclude_booking_id);
        if (!empty($units_available)) {
            $result['room_units_available'] = count($units_available);
            $result['available_units'] = $units_available;

            if ($preferred_room_unit_id !== null) {
                $preferred_room_unit_id = (int)$preferred_room_unit_id;
                $selected = null;
                foreach ($units_available as $unit) {
                    if ((int)$unit['id'] === $preferred_room_unit_id) {
                        $selected = $unit;
                        break;
                    }
                }

                if (!$selected) {
                    $result['available'] = false;
                    $result['error'] = 'Selected room unit is not available for the chosen dates';
                    return $result;
                }

                $result['suggested_room_unit_id'] = (int)$selected['id'];
                $result['suggested_room_unit_label'] = $selected['unit_label'];
            } else {
                $result['suggested_room_unit_id'] = (int)$units_available[0]['id'];
                $result['suggested_room_unit_label'] = $units_available[0]['unit_label'];
            }
        }

        // Check for overlapping bookings
        $sql = "
            SELECT
                id,
                booking_reference,
                check_in_date,
                check_out_date,
                status,
                guest_name
            FROM bookings
            WHERE room_id = ?
            AND (
                status IN ('pending', 'confirmed', 'checked-in')
                OR (
                    (status = 'tentative' OR is_tentative = 1)
                    AND (tentative_expires_at IS NULL OR tentative_expires_at > NOW())
                )
            )
            AND NOT (check_out_date <= ? OR check_in_date >= ?)
        ";
        $params = [$room_id, $check_in_date, $check_out_date];
        
        // Exclude specific booking for updates
        if ($exclude_booking_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_booking_id;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if number of overlapping bookings exceeds available rooms
        $overlapping_bookings = count($conflicts);
        $rooms_available = $room['rooms_available'];
        
        if ($overlapping_bookings >= $rooms_available) {
            $result['available'] = false;
            $result['conflicts'] = $conflicts;
            $result['error'] = 'Room is not available for the selected dates';
            
            // Build detailed conflict message
            $conflict_details = [];
            foreach ($conflicts as $conflict) {
                $conflict_check_in = new DateTime($conflict['check_in_date']);
                $conflict_check_out = new DateTime($conflict['check_out_date']);
                $conflict_details[] = sprintf(
                    "Booking %s (%s) from %s to %s",
                    $conflict['booking_reference'],
                    $conflict['guest_name'],
                    $conflict_check_in->format('M j, Y'),
                    $conflict_check_out->format('M j, Y')
                );
            }
            $result['conflict_message'] = implode('; ', $conflict_details);
        }
        
        // Calculate number of nights
        $interval = $check_in->diff($check_out);
        $result['nights'] = $interval->days;
        
        // Check if room has enough capacity for requested dates
        $max_guests = (int)$room['max_guests'];
        if ($max_guests > 0) {
            $result['max_guests'] = $max_guests;
        }
        
        return $result;
        
    } catch (PDOException $e) {
        error_log("Error checking room availability: " . $e->getMessage());
        $result['available'] = false;
        $result['error'] = 'Database error while checking availability';
        return $result;
    } catch (Exception $e) {
        error_log("Error checking room availability: " . $e->getMessage());
        $result['available'] = false;
        $result['error'] = 'Invalid date format';
        return $result;
    }
}

/**
 * Function to validate booking data before insertion/update
 * Returns array with validation status and error messages
 */
function validateBookingData($data) {
    $errors = [];
    
    // Required fields
    $required_fields = ['room_id', 'guest_name', 'guest_email', 'guest_phone', 'check_in_date', 'check_out_date', 'number_of_guests'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    
    // Email validation
    if (!empty($data['guest_email'])) {
        if (!filter_var($data['guest_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['guest_email'] = 'Invalid email address';
        }
    }
    
    // Phone number validation (basic)
    if (!empty($data['guest_phone'])) {
        $phone = preg_replace('/[^0-9+]/', '', $data['guest_phone']);
        if (strlen($phone) < 8) {
            $errors['guest_phone'] = 'Phone number is too short';
        }
    }
    
    // Number of guests validation
    if (!empty($data['number_of_guests'])) {
        $guests = (int)$data['number_of_guests'];
        if ($guests < 1) {
            $errors['number_of_guests'] = 'At least 1 guest is required';
        } elseif ($guests > 20) {
            $errors['number_of_guests'] = 'Maximum 20 guests allowed';
        }
    }
    
    // Date validation
    if (!empty($data['check_in_date']) && !empty($data['check_out_date'])) {
        try {
            $check_in = new DateTime($data['check_in_date']);
            $check_out = new DateTime($data['check_out_date']);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            
            if ($check_in < $today) {
                $errors['check_in_date'] = 'Check-in date cannot be in the past';
            }
            
            if ($check_out <= $check_in) {
                $errors['check_out_date'] = 'Check-out date must be after check-in date';
            }
            
    // Maximum stay duration (30 days)
    $max_stay = new DateTime();
    $max_stay->modify('+30 days');
    if ($check_out > $max_stay) {
        $errors['check_out_date'] = 'Maximum stay duration is 30 days';
    }
    
    // Maximum advance booking days (configurable setting)
    $max_advance_days = (int)getSetting('max_advance_booking_days', 30);
    $max_advance_date = new DateTime();
    $max_advance_date->modify('+' . $max_advance_days . ' days');
    if ($check_in > $max_advance_date) {
        $errors['check_in_date'] = "Bookings can only be made up to {$max_advance_days} days in advance. Please select an earlier check-in date.";
    }
    
        } catch (Exception $e) {
            $errors['dates'] = 'Invalid date format';
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Function to validate booking with room availability check
 * Combines data validation and availability checking
 */
function validateBookingWithAvailability($data, $exclude_booking_id = null) {
    // First validate data
    $validation = validateBookingData($data);
    if (!$validation['valid']) {
        return [
            'valid' => false,
            'errors' => $validation['errors'],
            'type' => 'validation'
        ];
    }
    
    // Then check room availability
    $availability = checkRoomAvailability(
        $data['room_id'],
        $data['check_in_date'],
        $data['check_out_date'],
        $exclude_booking_id
    );
    
    if (!$availability['available']) {
        return [
            'valid' => false,
            'errors' => [
                'availability' => $availability['error'],
                'conflicts' => $availability['conflict_message'] ?? 'No specific conflicts found'
            ],
            'type' => 'availability',
            'conflicts' => $availability['conflicts']
        ];
    }
    
    // Check if number of guests exceeds room capacity
    if (isset($availability['max_guests']) && isset($data['number_of_guests'])) {
        if ((int)$data['number_of_guests'] > (int)$availability['max_guests']) {
            return [
                'valid' => false,
                'errors' => [
                    'number_of_guests' => "Room capacity is {$availability['max_guests']} guests"
                ],
                'type' => 'capacity'
            ];
        }
    }
    
    return [
        'valid' => true,
        'availability' => $availability
    ];
}

/**
 * Get blocked dates for a specific room or all rooms
 * Returns array of blocked date records
 */
function getBlockedDates($room_id = null, $start_date = null, $end_date = null, $room_unit_id = null) {
    global $pdo;
    
    try {
        $sql = "
            SELECT
                rbd.id,
                rbd.room_id,
                r.name as room_name,
                rbd.room_unit_id,
                ru.unit_label as room_unit_label,
                rbd.block_date,
                rbd.block_type,
                rbd.reason,
                rbd.created_by,
                au.username as created_by_name,
                rbd.created_at
            FROM room_blocked_dates rbd
            LEFT JOIN rooms r ON rbd.room_id = r.id
            LEFT JOIN room_units ru ON rbd.room_unit_id = ru.id
            LEFT JOIN admin_users au ON rbd.created_by = au.id
            WHERE 1=1
        ";
        $params = [];
        
        if ($room_id !== null) {
            $sql .= " AND (rbd.room_id = ? OR rbd.room_id IS NULL)";
            $params[] = $room_id;
        }
        
        if ($start_date !== null) {
            $sql .= " AND rbd.block_date >= ?";
            $params[] = $start_date;
        }
        
        if ($end_date !== null) {
            $sql .= " AND rbd.block_date <= ?";
            $params[] = $end_date;
        }

        if ($room_unit_id !== null) {
            $sql .= " AND (rbd.room_unit_id = ? OR rbd.room_unit_id IS NULL)";
            $params[] = $room_unit_id;
        }
        
        $sql .= " ORDER BY rbd.block_date ASC, rbd.room_id ASC, rbd.room_unit_id ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $blocked_dates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $blocked_dates;
    } catch (PDOException $e) {
        error_log("Error fetching blocked dates: " . $e->getMessage());
        return [];
    }
}

/**
 * Get available dates for a specific room within a date range
 * Returns array of available dates
 */
function getAvailableDates($room_id, $start_date, $end_date) {
    global $pdo;
    
    try {
        $available_dates = [];
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        // Get room details
        $stmt = $pdo->prepare("SELECT rooms_available FROM rooms WHERE id = ?");
        $stmt->execute([$room_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room || $room['rooms_available'] <= 0) {
            return [];
        }
        
        $rooms_available = $room['rooms_available'];
        
        // Get blocked dates
        $blocked_sql = "
            SELECT block_date
            FROM room_blocked_dates
            WHERE block_date >= ? AND block_date <= ?
            AND (room_id = ? OR room_id IS NULL)
        ";
        $blocked_stmt = $pdo->prepare($blocked_sql);
        $blocked_stmt->execute([$start_date, $end_date, $room_id]);
        $blocked_dates = $blocked_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get booked dates
        $booked_sql = "
            SELECT DISTINCT DATE(check_in_date) as date
            FROM bookings
            WHERE room_id = ?
            AND (
                status IN ('pending', 'confirmed', 'checked-in')
                OR (
                    (status = 'tentative' OR is_tentative = 1)
                    AND (tentative_expires_at IS NULL OR tentative_expires_at > NOW())
                )
            )
            AND check_in_date <= ?
            AND check_out_date > ?
        ";
        $booked_stmt = $pdo->prepare($booked_sql);
        $booked_stmt->execute([$room_id, $end_date, $start_date]);
        $booked_dates = $booked_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Count bookings per date
        $booking_counts = [];
        foreach ($booked_dates as $date) {
            if (!isset($booking_counts[$date])) {
                $booking_counts[$date] = 0;
            }
            $booking_counts[$date]++;
        }
        
        // Build available dates array
        while ($current <= $end) {
            $date_str = $current->format('Y-m-d');
            
            // Check if date is blocked
            if (in_array($date_str, $blocked_dates)) {
                $current->modify('+1 day');
                continue;
            }
            
            // Check if date has available rooms
            $bookings_on_date = isset($booking_counts[$date_str]) ? $booking_counts[$date_str] : 0;
            
            if ($bookings_on_date < $rooms_available) {
                $available_dates[] = [
                    'date' => $date_str,
                    'available' => true,
                    'rooms_left' => $rooms_available - $bookings_on_date
                ];
            }
            
            $current->modify('+1 day');
        }
        
        return $available_dates;
    } catch (PDOException $e) {
        error_log("Error fetching available dates: " . $e->getMessage());
        return [];
    }
}

/**
 * Block a specific date for a room or all rooms
 * Returns true on success, false on failure
 */
function blockRoomDate($room_id, $block_date, $block_type = 'manual', $reason = null, $created_by = null, $room_unit_id = null) {
    global $pdo;
    
    try {
        // Validate block type
        $valid_types = ['maintenance', 'event', 'manual', 'full'];
        if (!in_array($block_type, $valid_types)) {
            $block_type = 'manual';
        }
        
        if ($room_unit_id !== null) {
            $room_unit_id = (int)$room_unit_id;
            $unit_stmt = $pdo->prepare("SELECT room_id FROM room_units WHERE id = ? LIMIT 1");
            $unit_stmt->execute([$room_unit_id]);
            $unit_room_id = $unit_stmt->fetchColumn();

            if (!$unit_room_id) {
                return false;
            }

            if ($room_id === null) {
                $room_id = (int)$unit_room_id;
            }
        }

        // Check if date is already blocked
        $check_sql = "
            SELECT id FROM room_blocked_dates
            WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
            AND room_unit_id " . ($room_unit_id === null ? "IS NULL" : "= ?") . "
            AND block_date = ?
        ";
        $check_params = [];
        if ($room_id !== null) {
            $check_params[] = $room_id;
        }
        if ($room_unit_id !== null) {
            $check_params[] = $room_unit_id;
        }
        $check_params[] = $block_date;
        
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute($check_params);
        
        if ($check_stmt->fetch()) {
            // Date already blocked, update instead
            $update_sql = "
                UPDATE room_blocked_dates
                SET block_type = ?, reason = ?, created_by = ?
                WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
                AND room_unit_id " . ($room_unit_id === null ? "IS NULL" : "= ?") . "
                AND block_date = ?
            ";
            $update_params = [$block_type, $reason, $created_by];
            if ($room_id !== null) {
                $update_params[] = $room_id;
            }
            if ($room_unit_id !== null) {
                $update_params[] = $room_unit_id;
            }
            $update_params[] = $block_date;
            
            $update_stmt = $pdo->prepare($update_sql);
            return $update_stmt->execute($update_params);
        }
        
        // Insert new blocked date
        $sql = "
            INSERT INTO room_blocked_dates (room_id, room_unit_id, block_date, block_type, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$room_id, $room_unit_id, $block_date, $block_type, $reason, $created_by]);
    } catch (PDOException $e) {
        error_log("Error blocking room date: " . $e->getMessage());
        return false;
    }
}

/**
 * Unblock a specific date for a room or all rooms
 * Returns true on success, false on failure
 */
function unblockRoomDate($room_id, $block_date, $room_unit_id = null) {
    global $pdo;
    
    try {
        $sql = "
            DELETE FROM room_blocked_dates
            WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
            AND room_unit_id " . ($room_unit_id === null ? "IS NULL" : "= ?") . "
            AND block_date = ?
        ";
        $params = [];
        if ($room_id !== null) {
            $params[] = $room_id;
        }
        if ($room_unit_id !== null) {
            $params[] = $room_unit_id;
        }
        $params[] = $block_date;
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Error unblocking room date: " . $e->getMessage());
        return false;
    }
}

/**
 * Block multiple dates for a room or all rooms
 * Returns number of dates blocked
 */
function blockRoomDates($room_id, $dates, $block_type = 'manual', $reason = null, $created_by = null, $room_unit_id = null) {
    $blocked_count = 0;
    
    foreach ($dates as $date) {
        if (blockRoomDate($room_id, $date, $block_type, $reason, $created_by, $room_unit_id)) {
            $blocked_count++;
        }
    }
    
    return $blocked_count;
}

/**
 * Unblock multiple dates for a room or all rooms
 * Returns number of dates unblocked
 */
function unblockRoomDates($room_id, $dates, $room_unit_id = null) {
    $unblocked_count = 0;
    
    foreach ($dates as $date) {
        if (unblockRoomDate($room_id, $date, $room_unit_id)) {
            $unblocked_count++;
        }
    }
    
    return $unblocked_count;
}

/**
 * ============================================
 * TENTATIVE BOOKING SYSTEM HELPER FUNCTIONS
 * ============================================
 */

/**
 * Convert a tentative booking to a standard booking
 * Returns true on success, false on failure
 */
function convertTentativeBooking($booking_id, $admin_user_id = null) {
    global $pdo;
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get current booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'tentative'");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            $pdo->rollBack();
            return false;
        }
        
        // Update booking status to pending
        $update_stmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'pending',
                is_tentative = 0,
                tentative_expires_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update_stmt->execute([$booking_id]);
        
        // Log the action
        logTentativeBookingAction($booking_id, 'converted', [
            'converted_by' => $admin_user_id,
            'previous_status' => 'tentative',
            'new_status' => 'pending',
            'previous_is_tentative' => 1,
            'new_is_tentative' => 0
        ]);
        
        $pdo->commit();
        return true;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error converting tentative booking: " . $e->getMessage());
        return false;
    }
}

/**
 * Cancel a tentative booking
 * Returns true on success, false on failure
 */
function cancelTentativeBooking($booking_id, $admin_user_id = null, $reason = null) {
    global $pdo;
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get current booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'tentative'");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            $pdo->rollBack();
            return false;
        }
        
        // Update booking status to cancelled
        $update_stmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'cancelled',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update_stmt->execute([$booking_id]);
        
        // Log the action
        logTentativeBookingAction($booking_id, 'cancelled', [
            'cancelled_by' => $admin_user_id,
            'previous_status' => 'tentative',
            'new_status' => 'cancelled',
            'reason' => $reason
        ]);
        
        $pdo->commit();
        return true;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error cancelling tentative booking: " . $e->getMessage());
        return false;
    }
}

/**
 * Get tentative bookings with optional filters
 * Returns array of tentative bookings
 */
function getTentativeBookings($filters = []) {
    global $pdo;
    
    try {
        $sql = "
            SELECT
                b.*,
                r.name as room_name,
                r.price_per_night,
                au.username as admin_username
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN admin_users au ON b.updated_by = au.id
            WHERE b.is_tentative = 1
        ";
        $params = [];
        
        // Filter by status
        if (!empty($filters['status'])) {
            $sql .= " AND b.status = ?";
            $params[] = $filters['status'];
        }
        
        // Filter by room
        if (!empty($filters['room_id'])) {
            $sql .= " AND b.room_id = ?";
            $params[] = $filters['room_id'];
        }
        
        // Filter by expiration status
        if (!empty($filters['expiration_status'])) {
            $now = date('Y-m-d H:i:s');
            if ($filters['expiration_status'] === 'expired') {
                $sql .= " AND b.tentative_expires_at < ?";
                $params[] = $now;
            } elseif ($filters['expiration_status'] === 'active') {
                $sql .= " AND b.tentative_expires_at >= ?";
                $params[] = $now;
            }
        }
        
        // Filter by date range
        if (!empty($filters['date_from'])) {
            $sql .= " AND b.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND b.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        // Search by guest name or email
        if (!empty($filters['search'])) {
            $sql .= " AND (b.guest_name LIKE ? OR b.guest_email LIKE ? OR b.booking_reference LIKE ?)";
            $search_term = '%' . $filters['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $sql .= " ORDER BY b.created_at DESC";
        
        // Limit results
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $bookings;
        
    } catch (PDOException $e) {
        error_log("Error fetching tentative bookings: " . $e->getMessage());
        return [];
    }
}

/**
 * Get bookings expiring within X hours
 * Returns array of bookings expiring soon
 */
function getExpiringTentativeBookings($hours = 24) {
    global $pdo;
    
    try {
        $now = date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
        
        $stmt = $pdo->prepare("
            SELECT
                b.*,
                r.name as room_name,
                TIMESTAMPDIFF(HOUR, NOW(), b.tentative_expires_at) as hours_until_expiration
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.is_tentative = 1
            AND b.status = 'tentative'
            AND b.tentative_expires_at >= ?
            AND b.tentative_expires_at <= ?
            ORDER BY b.tentative_expires_at ASC
        ");
        $stmt->execute([$now, $cutoff]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $bookings;
        
    } catch (PDOException $e) {
        error_log("Error fetching expiring tentative bookings: " . $e->getMessage());
        return [];
    }
}

/**
 * Get expired tentative bookings
 * Returns array of expired bookings
 */
function getExpiredTentativeBookings() {
    global $pdo;
    
    try {
        $now = date('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare("
            SELECT
                b.*,
                r.name as room_name,
                TIMESTAMPDIFF(HOUR, b.tentative_expires_at, NOW()) as hours_since_expiration
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.is_tentative = 1
            AND b.status = 'tentative'
            AND b.tentative_expires_at < ?
            ORDER BY b.tentative_expires_at ASC
        ");
        $stmt->execute([$now]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $bookings;
        
    } catch (PDOException $e) {
        error_log("Error fetching expired tentative bookings: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark a tentative booking as expired
 * Returns true on success, false on failure
 */
function markTentativeBookingExpired($booking_id) {
    global $pdo;
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get current booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND status = 'tentative'");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            $pdo->rollBack();
            return false;
        }
        
        // Update booking status to expired
        $update_stmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'expired',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update_stmt->execute([$booking_id]);
        
        // Log the action
        logTentativeBookingAction($booking_id, 'expired', [
            'previous_status' => 'tentative',
            'new_status' => 'expired',
            'expired_at' => date('Y-m-d H:i:s')
        ]);
        
        $pdo->commit();
        return true;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error marking tentative booking as expired: " . $e->getMessage());
        return false;
    }
}

/**
 * Log an action for a tentative booking
 * Returns true on success, false on failure
 */
function logTentativeBookingAction($booking_id, $action, $details = []) {
    global $pdo;
    
    try {
        // Check if tentative_booking_log table exists
        $table_exists = $pdo->query("SHOW TABLES LIKE 'tentative_booking_log'")->rowCount() > 0;
        
        if (!$table_exists) {
            // Table doesn't exist, skip logging
            return true;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO tentative_booking_log (booking_id, action, details, created_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$booking_id, $action, json_encode($details)]);
        
        return true;
        
    } catch (PDOException $e) {
        error_log("Error logging tentative booking action: " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure backup table exists for archived hard-deleted records.
 */
function ensureDeletedRecordsBackupTable() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return true;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS deleted_records_backup (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_table VARCHAR(100) NOT NULL,
            source_id VARCHAR(100) NOT NULL,
            row_data LONGTEXT NOT NULL,
            metadata LONGTEXT NULL,
            deleted_by INT NULL,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_source (source_table, source_id),
            INDEX idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $pdo->exec($sql);
        $checked = true;
        return true;
    } catch (PDOException $e) {
        error_log("Error ensuring deleted_records_backup table: " . $e->getMessage());
        return false;
    }
}

/**
 * Backup a row before hard delete.
 */
function backupRecordBeforeDelete($table, $id, $primaryKey = 'id', $metadata = []) {
    global $pdo;

    if (!ensureDeletedRecordsBackupTable()) {
        return false;
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $primaryKey)) {
        error_log("Invalid table or primary key passed to backupRecordBeforeDelete");
        return false;
    }

    try {
        $safeTable = str_replace('`', '``', $table);
        $safePrimary = str_replace('`', '``', $primaryKey);

        $select = $pdo->prepare("SELECT * FROM `{$safeTable}` WHERE `{$safePrimary}` = ? LIMIT 1");
        $select->execute([$id]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $deletedBy = $metadata['deleted_by'] ?? null;
        $insert = $pdo->prepare("INSERT INTO deleted_records_backup (source_table, source_id, row_data, metadata, deleted_by) VALUES (?, ?, ?, ?, ?)");
        $insert->execute([
            $table,
            (string)$id,
            json_encode($row),
            json_encode($metadata),
            $deletedBy
        ]);

        return true;
    } catch (PDOException $e) {
        error_log("Error backing up deleted record: " . $e->getMessage());
        return false;
    }
}

/**
 * Get tentative booking statistics
 * Returns array with statistics
 */
function getTentativeBookingStatistics() {
    global $pdo;
    
    try {
        $now = date('Y-m-d H:i:s');
        $reminder_cutoff = date('Y-m-d H:i:s', strtotime("+24 hours"));
        
        // Get total tentative bookings
        $stmt = $pdo->query("
            SELECT COUNT(*) as total
            FROM bookings
            WHERE is_tentative = 1
            AND status = 'tentative'
        ");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get expiring soon (within 24 hours)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as expiring_soon
            FROM bookings
            WHERE is_tentative = 1
            AND status = 'tentative'
            AND tentative_expires_at >= ?
            AND tentative_expires_at <= ?
        ");
        $stmt->execute([$now, $reminder_cutoff]);
        $expiring_soon = $stmt->fetch(PDO::FETCH_ASSOC)['expiring_soon'];
        
        // Get expired
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as expired
            FROM bookings
            WHERE is_tentative = 1
            AND status = 'tentative'
            AND tentative_expires_at < ?
        ");
        $stmt->execute([$now]);
        $expired = $stmt->fetch(PDO::FETCH_ASSOC)['expired'];
        
        // Get converted (standard bookings that were tentative)
        $stmt = $pdo->query("
            SELECT COUNT(*) as converted
            FROM bookings
            WHERE is_tentative = 0
            AND status IN ('pending', 'confirmed', 'checked-in', 'checked-out')
            AND tentative_expires_at IS NOT NULL
        ");
        $converted = $stmt->fetch(PDO::FETCH_ASSOC)['converted'];
        
        return [
            'total' => (int)$total,
            'expiring_soon' => (int)$expiring_soon,
            'expired' => (int)$expired,
            'converted' => (int)$converted,
            'active' => (int)($total - $expired)
        ];
        
    } catch (PDOException $e) {
        error_log("Error fetching tentative booking statistics: " . $e->getMessage());
        return [
            'total' => 0,
            'expiring_soon' => 0,
            'expired' => 0,
            'converted' => 0,
            'active' => 0
        ];
    }
}

/**
 * Check if a booking can be converted (is tentative and not expired)
 * Returns array with status and message
 */
function canConvertTentativeBooking($booking_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            return [
                'can_convert' => false,
                'reason' => 'Booking not found'
            ];
        }
        
        if ($booking['is_tentative'] != 1) {
            return [
                'can_convert' => false,
                'reason' => 'This is not a tentative booking'
            ];
        }
        
        if ($booking['status'] === 'expired') {
            return [
                'can_convert' => false,
                'reason' => 'This booking has expired'
            ];
        }
        
        if ($booking['status'] === 'cancelled') {
            return [
                'can_convert' => false,
                'reason' => 'This booking has been cancelled'
            ];
        }
        
        if ($booking['status'] !== 'tentative') {
            return [
                'can_convert' => false,
                'reason' => 'Booking has already been converted'
            ];
        }
        
        // Check if expired
        if ($booking['tentative_expires_at'] && $booking['tentative_expires_at'] < date('Y-m-d H:i:s')) {
            return [
                'can_convert' => false,
                'reason' => 'This booking has expired'
            ];
        }
        
        return [
            'can_convert' => true,
            'expires_at' => $booking['tentative_expires_at']
        ];
        
    } catch (PDOException $e) {
        error_log("Error checking if booking can be converted: " . $e->getMessage());
        return [
            'can_convert' => false,
            'reason' => 'Database error'
        ];
    }
}

/**
 * Check date-range capacity for a room while holding a row lock on the room.
 * IMPORTANT: Call this from inside an active transaction for concurrency safety.
 */
function hasRoomDateCapacity($room_id, $check_in_date, $check_out_date, $exclude_booking_id = null, &$error = null) {
    global $pdo;

    $error = null;

    try {
        $room_stmt = $pdo->prepare("SELECT id, name, total_rooms, rooms_available, is_active FROM rooms WHERE id = ? FOR UPDATE");
        $room_stmt->execute([$room_id]);
        $room = $room_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room || (int)$room['is_active'] !== 1) {
            $error = 'Selected room is not available.';
            return false;
        }

        $sql = "
            SELECT COUNT(*)
            FROM bookings
            WHERE room_id = ?
              AND status IN ('pending', 'tentative', 'confirmed', 'checked-in')
              AND NOT (check_out_date <= ? OR check_in_date >= ?)
        ";

        $params = [$room_id, $check_in_date, $check_out_date];
        if ($exclude_booking_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_booking_id;
        }

        $count_stmt = $pdo->prepare($sql);
        $count_stmt->execute($params);
        $overlap_count = (int)$count_stmt->fetchColumn();

        if ($overlap_count >= (int)$room['total_rooms']) {
            $error = 'Room capacity reached for the selected dates. Please choose different dates or another room type.';
            return false;
        }

        return true;
    } catch (PDOException $e) {
        error_log('Error checking room date capacity: ' . $e->getMessage());
        $error = 'Could not verify room capacity. Please try again.';
        return false;
    }
}

/**
 * Reserve one physical room inventory slot for a date range.
 * IMPORTANT: Call this from inside an active transaction.
 */
function reserveRoomForDateRange($room_id, $check_in_date, $check_out_date, $exclude_booking_id = null, &$error = null) {
    global $pdo;

    if (!hasRoomDateCapacity($room_id, $check_in_date, $check_out_date, $exclude_booking_id, $error)) {
        return false;
    }

    try {
        $reserve_stmt = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0");
        $reserve_stmt->execute([$room_id]);

        if ($reserve_stmt->rowCount() === 0) {
            $error = 'No physical inventory left for this room type.';
            return false;
        }

        return true;
    } catch (PDOException $e) {
        error_log('Error reserving room inventory: ' . $e->getMessage());
        $error = 'Could not reserve room inventory. Please try again.';
        return false;
    }
}

/**
 * Ensure room-booking additional charges table exists.
 */
function ensureBookingAdditionalChargesTable() {
    global $pdo;

    static $checked = false;
    if ($checked) {
        return true;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS booking_additional_charges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            charge_type ENUM('menu', 'other', 'levy') NOT NULL DEFAULT 'other',
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_booking_active (booking_id, is_active),
            INDEX idx_charge_type (charge_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $pdo->exec($sql);
        $checked = true;
        return true;
    } catch (PDOException $e) {
        error_log('Error ensuring booking_additional_charges table: ' . $e->getMessage());
        return false;
    }
}

/**
 * Recalculate booking financial totals after date changes or additional charges.
 */
function recalculateRoomBookingFinancials($booking_id) {
    global $pdo;

    ensureChildOccupancyInfrastructure();

    if (!ensureBookingAdditionalChargesTable()) {
        throw new Exception('Could not initialize booking charges storage.');
    }

    $booking_stmt = $pdo->prepare("SELECT b.*, r.* FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
    $booking_stmt->execute([$booking_id]);
    $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found for financial recalculation.');
    }

    $nights = max(1, (int)$booking['number_of_nights']);
    $occupancy_type = normalizeOccupancyType($booking['occupancy_type'] ?? 'double');

    $night_rate = (float)$booking['price_per_night'];
    if ($occupancy_type === 'single' && !empty($booking['price_single_occupancy'])) {
        $night_rate = (float)$booking['price_single_occupancy'];
    } elseif ($occupancy_type === 'double' && !empty($booking['price_double_occupancy'])) {
        $night_rate = (float)$booking['price_double_occupancy'];
    } elseif ($occupancy_type === 'child') {
        $child_rate = getRoomChildOccupancyPrice($booking);
        if ($child_rate !== null) {
            $night_rate = $child_rate;
        }
    }

    $room_base_amount = round($night_rate * $nights, 2);

    $charges_stmt = $pdo->prepare("SELECT id, charge_type, amount FROM booking_additional_charges WHERE booking_id = ? AND is_active = 1");
    $charges_stmt->execute([$booking_id]);
    $charges = $charges_stmt->fetchAll(PDO::FETCH_ASSOC);

    $extra_charges_total = 0.0;
    $levy_charge_id = null;
    $levy_total = 0.0;

    foreach ($charges as $charge) {
        $amount = (float)$charge['amount'];
        if ($charge['charge_type'] === 'levy') {
            $levy_total += $amount;
            if ($levy_charge_id === null) {
                $levy_charge_id = (int)$charge['id'];
            }
        } else {
            $extra_charges_total += $amount;
        }
    }

    $levy_enabled = in_array(getSetting('tourist_levy_enabled', '0'), ['1', 1, true, 'true', 'on'], true);
    $levy_rate = $levy_enabled ? (float)getSetting('tourist_levy_rate', 0) : 0.0;

    if ($levy_charge_id !== null && $levy_enabled && $levy_rate > 0) {
        $levy_total = round($room_base_amount * ($levy_rate / 100), 2);
        $levy_update = $pdo->prepare("UPDATE booking_additional_charges SET amount = ?, description = ? WHERE id = ?");
        $levy_update->execute([$levy_total, 'Tourist levy (' . number_format($levy_rate, 2) . '%)', $levy_charge_id]);
    }

    $subtotal = round($room_base_amount + $extra_charges_total + $levy_total, 2);

    $vat_enabled = in_array(getSetting('vat_enabled', '0'), ['1', 1, true, 'true', 'on'], true);
    $vat_rate = $vat_enabled ? (float)getSetting('vat_rate', 0) : 0.0;
    $vat_amount = round($subtotal * ($vat_rate / 100), 2);
    $grand_total = round($subtotal + $vat_amount, 2);

    $paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE booking_type = 'room' AND booking_id = ? AND payment_status = 'completed' AND deleted_at IS NULL");
    $paid_stmt->execute([$booking_id]);
    $amount_paid = round((float)$paid_stmt->fetchColumn(), 2);
    $amount_due = round(max(0, $grand_total - $amount_paid), 2);

    $last_stmt = $pdo->prepare("SELECT MAX(payment_date) FROM payments WHERE booking_type = 'room' AND booking_id = ? AND payment_status = 'completed' AND deleted_at IS NULL");
    $last_stmt->execute([$booking_id]);
    $last_payment_date = $last_stmt->fetchColumn();

    $update_stmt = $pdo->prepare("UPDATE bookings SET total_amount = ?, vat_rate = ?, vat_amount = ?, total_with_vat = ?, amount_paid = ?, amount_due = ?, last_payment_date = ?, updated_at = NOW() WHERE id = ?");
    $update_stmt->execute([
        $subtotal,
        $vat_rate,
        $vat_amount,
        $grand_total,
        $amount_paid,
        $amount_due,
        $last_payment_date ?: null,
        $booking_id
    ]);

    return [
        'room_base_amount' => $room_base_amount,
        'extra_charges_total' => $extra_charges_total,
        'levy_total' => $levy_total,
        'subtotal' => $subtotal,
        'vat_rate' => $vat_rate,
        'vat_amount' => $vat_amount,
        'grand_total' => $grand_total,
        'amount_paid' => $amount_paid,
        'amount_due' => $amount_due
    ];
}

/**
 * Process refunds when booking amount decreases (shortening, charge removal, etc).
 * Automatically creates refund payment records if customer overpaid.
 * IMPORTANT: Uses transaction, call from transaction context or create new one.
 */
function processRefundIfNeeded($booking_id, $old_total, $new_total) {
    global $pdo;

    $old_total = round((float)$old_total, 2);
    $new_total = round((float)$new_total, 2);

    // Get current booking state
    $stmt = $pdo->prepare("SELECT amount_paid FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    $amount_paid = round((float)$booking['amount_paid'], 2);
    $refund_amount = 0;

    // Scenario 1: Amount decreased, customer paid more than new total
    if ($new_total < $old_total && $amount_paid > $new_total) {
        $refund_amount = round($amount_paid - $new_total, 2);
    }

    // Only process refund if amount is positive
    if ($refund_amount > 0.00) {
        // Create refund payment record
        $refund_ref = 'REF' . date('Ym') . strtoupper(substr(uniqid(), -6));

        // Calculate refund VAT proportionally
        $stmt = $pdo->prepare("SELECT vat_rate FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $vat_rate = (float)$stmt->fetch(PDO::FETCH_ASSOC)['vat_rate'];

        $refund_vat = round($refund_amount * ($vat_rate / 100), 2);
        $refund_total = $refund_amount + $refund_vat;

        $insert_stmt = $pdo->prepare("
            INSERT INTO payments (
                payment_reference, booking_type, booking_id, booking_reference,
                payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_status, notes, processed_by, receipt_number
            ) SELECT 
                ?, 'room', ?, booking_reference,
                NOW(), ?, ?, ?, ?,
                'refund', 'pending', CONCAT('Auto-refund: overpayment due to booking modification. Old amount: ', ?, ', New amount: ', ?), NULL, NULL
            FROM bookings WHERE id = ?
        ");

        $insert_stmt->execute([
            $refund_ref,
            $booking_id,
            -$refund_amount,     // Negative amount for refund
            $vat_rate,
            -$refund_vat,        // Negative VAT
            -$refund_total,      // Negative total
            $old_total,
            $new_total,
            $booking_id
        ]);

        return [
            'refund_created' => true,
            'refund_reference' => $refund_ref,
            'refund_amount' => $refund_amount,
            'refund_vat' => $refund_vat,
            'refund_total' => $refund_total
        ];
    }

    return [
        'refund_created' => false,
        'reason' => $refund_amount === 0 ? 'No overpayment detected' : 'Amount increased, no refund due'
    ];
}

/**
 * Process full refund when booking is cancelled.
 * Creates refund payment records and updates booking.
 * IMPORTANT: Expects active transaction context.
 */
function processBookingCancellationRefund($booking_id, $cancellation_reason = 'Guest cancellation') {
    global $pdo;

    // Get booking details
    $stmt = $pdo->prepare("
        SELECT id, booking_reference, amount_paid, total_with_vat, vat_rate
        FROM bookings
        WHERE id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    $amount_paid = round((float)$booking['amount_paid'], 2);
    $vat_rate = round((float)$booking['vat_rate'], 2);
    $refund_result = [
        'refund_processed' => false,
        'messages' => []
    ];

    // Only process refund if something was paid
    if ($amount_paid > 0) {
        // Recalculate VAT for refund
        $refund_vat = round($amount_paid * ($vat_rate / 100), 2);
        $refund_total = $amount_paid + $refund_vat;

        // Generate refund reference
        $refund_ref = 'REF' . date('Ym') . strtoupper(substr(uniqid(), -6));

        // Check for duplicate refund already created
        $check_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM payments
            WHERE booking_type = 'room'
            AND booking_id = ?
            AND payment_method = 'refund'
            AND payment_status IN ('pending', 'completed')
            AND deleted_at IS NULL
        ");
        $check_stmt->execute([$booking_id]);
        $existing_refund = (int)$check_stmt->fetchColumn();

        if ($existing_refund > 0) {
            $refund_result['messages'][] = 'Refund already exists for this cancellation';
            return $refund_result;
        }

        // Create refund payment record
        $insert_stmt = $pdo->prepare("
            INSERT INTO payments (
                payment_reference, booking_type, booking_id, booking_reference,
                payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                payment_method, payment_status, notes, processed_by, created_at
            ) VALUES (?, 'room', ?, ?, NOW(), ?, ?, ?, ?, 'refund', 'pending', ?, NULL, NOW())
        ");

        $insert_stmt->execute([
            $refund_ref,
            $booking_id,
            $booking['booking_reference'],
            -$amount_paid,      // Negative for refund
            $vat_rate,
            -$refund_vat,       // Negative VAT
            -$refund_total,     // Negative total
            "Cancellation refund: {$cancellation_reason}"
        ]);

        // Mark all pending/failed payments for this booking as cancelled
        $cancel_stmt = $pdo->prepare("
            UPDATE payments
            SET payment_status = 'cancelled', notes = CONCAT(notes, ' | Cancelled due to booking cancellation')
            WHERE booking_type = 'room'
            AND booking_id = ?
            AND payment_status IN ('pending', 'failed')
            AND deleted_at IS NULL
        ");
        $cancel_stmt->execute([$booking_id]);

        $refund_result['refund_processed'] = true;
        $refund_result['refund_reference'] = $refund_ref;
        $refund_result['refund_amount'] = $amount_paid;
        $refund_result['refund_vat'] = $refund_vat;
        $refund_result['refund_total'] = $refund_total;
        $refund_result['messages'][] = "Refund {$refund_ref} created for {$amount_paid}";
    } else {
        $refund_result['messages'][] = 'No payment received yet, no refund needed';
    }

    return $refund_result;
}

/**
 * Validate accounting integrity for a booking.
 * Checks: amount_paid <= total_with_vat, amount_due >= 0, reconciliation
 * Returns array with validation status and any discrepancies.
 */
function validateBookingAccounting($booking_id) {
    global $pdo;

    $validation = [
        'valid' => true,
        'warnings' => [],
        'errors' => [],
        'reconciliation' => []
    ];

    try {
        // Get booking totals
        $stmt = $pdo->prepare("
            SELECT
                id, booking_reference, total_amount, amount_paid, amount_due,
                total_with_vat, vat_amount, vat_rate, status
            FROM bookings
            WHERE id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Booking not found';
            return $validation;
        }

        $total_with_vat = round((float)$booking['total_with_vat'], 2);
        $amount_paid = round((float)$booking['amount_paid'], 2);
        $amount_due = round((float)$booking['amount_due'], 2);
        $vat_amount = round((float)$booking['vat_amount'], 2);
        $total_amount = round((float)$booking['total_amount'], 2);

        // Check 1: amount_paid shouldn't exceed total_with_vat
        if ($amount_paid > $total_with_vat + 0.01) {  // Allow 1 cent rounding
            $validation['errors'][] = "Amount paid ({$amount_paid}) exceeds total ({$total_with_vat})";
            $validation['valid'] = false;
        }

        // Check 2: amount_due should be non-negative
        if ($amount_due < -0.01) {  // Allow 1 cent rounding
            $validation['warnings'][] = "Amount due is negative ({$amount_due}), customer is owed a refund";
        }

        // Check 3: Reconcile amount_due calculation
        $calculated_due = round($total_with_vat - $amount_paid, 2);
        if (abs($calculated_due - $amount_due) > 0.01) {
            $validation['errors'][] = "Amount due mismatch: stored = {$amount_due}, calculated = {$calculated_due}";
            $validation['valid'] = false;
        }

        // Check 4: Get actual payments from payments table
        $payments_stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN payment_status = 'completed' THEN total_amount ELSE 0 END) as completed_total,
                SUM(CASE WHEN payment_status = 'pending' THEN total_amount ELSE 0 END) as pending_total,
                COUNT(*) as payment_count
            FROM payments
            WHERE booking_type = 'room'
            AND booking_id = ?
            AND deleted_at IS NULL
        ");
        $payments_stmt->execute([$booking_id]);
        $payments = $payments_stmt->fetch(PDO::FETCH_ASSOC);

        $completed_total = round((float)($payments['completed_total'] ?? 0), 2);
        $pending_total = round((float)($payments['pending_total'] ?? 0), 2);

        // Refunds are negative, so they reduce the total
        $validation['reconciliation'] = [
            'booking_amount_paid' => $amount_paid,
            'payments_table_completed' => $completed_total,
            'payments_table_pending' => $pending_total,
            'payment_records' => (int)$payments['payment_count'],
            'matches' => abs($amount_paid - $completed_total) <= 0.01
        ];

        // Check 5: If cancelled, verify refund exists
        if ($booking['status'] === 'cancelled') {
            $refund_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM payments
                WHERE booking_type = 'room'
                AND booking_id = ?
                AND payment_method = 'refund'
                AND deleted_at IS NULL
            ");
            $refund_stmt->execute([$booking_id]);
            $has_refund = (int)$refund_stmt->fetchColumn() > 0;

            if ($amount_paid > 0 && !$has_refund) {
                $validation['errors'][] = "Booking is cancelled but no refund record found (paid amount: {$amount_paid})";
                $validation['valid'] = false;
            }
        }

        return $validation;

    } catch (PDOException $e) {
        $validation['valid'] = false;
        $validation['errors'][] = "Database error: " . $e->getMessage();
        return $validation;
    }
}

/**
 * Generate comprehensive accounting report for a booking.
 * Includes payment timeline, refunds, and full reconciliation.
 */
function generateBookingAccountingReport($booking_id) {
    global $pdo;

    $report = [
        'booking_id' => $booking_id,
        'generated_at' => date('Y-m-d H:i:s'),
        'booking_summary' => [],
        'payment_history' => [],
        'refund_history' => [],
        'validation' => []
    ];

    try {
        // Get booking summary
        $stmt = $pdo->prepare("
            SELECT
                id, booking_reference, guest_name, guest_email,
                check_in_date, check_out_date, number_of_nights,
                total_amount, amount_paid, amount_due, total_with_vat,
                vat_rate, vat_amount, status, created_at, updated_at
            FROM bookings
            WHERE id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $report['error'] = 'Booking not found';
            return $report;
        }

        $report['booking_summary'] = [
            'reference' => $booking['booking_reference'],
            'guest' => $booking['guest_name'],
            'email' => $booking['guest_email'],
            'dates' => $booking['check_in_date'] . ' to ' . $booking['check_out_date'],
            'nights' => (int)$booking['number_of_nights'],
            'status' => $booking['status'],
            'created' => $booking['created_at'],
            'updated' => $booking['updated_at']
        ];

        // Get all payments (including refunds)
        $payments_stmt = $pdo->prepare("
            SELECT
                id, payment_reference, payment_date, payment_amount,
                vat_amount, total_amount, payment_status, payment_method,
                notes, receipt_number, created_at
            FROM payments
            WHERE booking_type = 'room'
            AND booking_id = ?
            AND deleted_at IS NULL
            ORDER BY payment_date ASC, created_at ASC
        ");
        $payments_stmt->execute([$booking_id]);
        $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payments as $payment) {
            $entry = [
                'reference' => $payment['payment_reference'],
                'date' => $payment['payment_date'],
                'amount' => (float)$payment['payment_amount'],
                'vat' => (float)$payment['vat_amount'],
                'total' => (float)$payment['total_amount'],
                'status' => $payment['payment_status'],
                'method' => $payment['payment_method'],
                'receipt' => $payment['receipt_number'],
                'notes' => $payment['notes']
            ];

            if ($payment['payment_method'] === 'refund' || (float)$payment['payment_amount'] < 0) {
                $report['refund_history'][] = $entry;
            } else {
                $report['payment_history'][] = $entry;
            }
        }

        // Calculate totals
        $total_received = array_reduce($report['payment_history'], function($sum, $p) {
            return $sum + ($p['status'] === 'completed' ? $p['total'] : 0);
        }, 0);

        $total_refunded = array_reduce($report['refund_history'], function($sum, $p) {
            return $sum + ($p['status'] === 'completed' ? abs($p['total']) : 0);
        }, 0);

        $report['summary'] = [
            'total_amount' => (float)$booking['total_amount'],
            'total_with_vat' => (float)$booking['total_with_vat'],
            'vat_rate' => (float)$booking['vat_rate'],
            'vat_amount' => (float)$booking['vat_amount'],
            'amount_paid' => (float)$booking['amount_paid'],
            'amount_due' => (float)$booking['amount_due'],
            'total_received' => round($total_received, 2),
            'total_refunded' => round($total_refunded, 2),
            'net_received' => round($total_received - $total_refunded, 2)
        ];

        // Validate accounting
        $report['validation'] = validateBookingAccounting($booking_id);

        return $report;

    } catch (PDOException $e) {
        $report['error'] = 'Database error: ' . $e->getMessage();
        return $report;
    }
}