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
 * Helper function to check room availability
 * Returns true if room is available, false if booked or blocked
 */
function isRoomAvailable($room_id, $check_in_date, $check_out_date, $exclude_booking_id = null) {
    global $pdo;
    try {
        // First check if there are any rooms available at all
        $room_stmt = $pdo->prepare("SELECT rooms_available, total_rooms FROM rooms WHERE id = ?");
        $room_stmt->execute([$room_id]);
        $room = $room_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room || $room['rooms_available'] <= 0) {
            return false; // No rooms available
        }
        
        // Check for blocked dates (both room-specific and global blocks)
        $blocked_sql = "
            SELECT COUNT(*) as blocked_dates
            FROM room_blocked_dates
            WHERE block_date >= ? AND block_date < ?
            AND (room_id = ? OR room_id IS NULL)
        ";
        $blocked_stmt = $pdo->prepare($blocked_sql);
        $blocked_stmt->execute([$check_in_date, $check_out_date, $room_id]);
        $blocked_result = $blocked_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($blocked_result['blocked_dates'] > 0) {
            return false; // Date is blocked
        }
        
        // Then check for overlapping bookings
        $sql = "
            SELECT COUNT(*) as bookings
            FROM bookings
            WHERE room_id = ?
            AND status IN ('pending', 'confirmed', 'checked-in')
            AND NOT (check_out_date <= ? OR check_in_date >= ?)
        ";
        $params = [$room_id, $check_in_date, $check_out_date];
        
        // Exclude a specific booking (useful when updating existing bookings)
        if ($exclude_booking_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_booking_id;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if number of overlapping bookings is less than available rooms
        $overlapping_bookings = $result['bookings'];
        $rooms_available = $room['rooms_available'];
        
        return $overlapping_bookings < $rooms_available;
    } catch (PDOException $e) {
        error_log("Error checking room availability: " . $e->getMessage());
        return false; // Assume unavailable on error
    }
}

/**
 * Enhanced function to check room availability with detailed conflict information
 * Returns array with availability status and conflict details
 */
function checkRoomAvailability($room_id, $check_in_date, $check_out_date, $exclude_booking_id = null) {
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
            AND status IN ('pending', 'confirmed', 'checked-in')
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
function getBlockedDates($room_id = null, $start_date = null, $end_date = null) {
    global $pdo;
    
    try {
        $sql = "
            SELECT
                rbd.id,
                rbd.room_id,
                r.name as room_name,
                rbd.block_date,
                rbd.block_type,
                rbd.reason,
                rbd.created_by,
                au.username as created_by_name,
                rbd.created_at
            FROM room_blocked_dates rbd
            LEFT JOIN rooms r ON rbd.room_id = r.id
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
        
        $sql .= " ORDER BY rbd.block_date ASC, rbd.room_id ASC";
        
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
            AND status IN ('pending', 'confirmed', 'checked-in')
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
function blockRoomDate($room_id, $block_date, $block_type = 'manual', $reason = null, $created_by = null) {
    global $pdo;
    
    try {
        // Validate block type
        $valid_types = ['maintenance', 'event', 'manual', 'full'];
        if (!in_array($block_type, $valid_types)) {
            $block_type = 'manual';
        }
        
        // Check if date is already blocked
        $check_sql = "
            SELECT id FROM room_blocked_dates
            WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
            AND block_date = ?
        ";
        $check_params = $room_id === null ? [$block_date] : [$room_id, $block_date];
        
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute($check_params);
        
        if ($check_stmt->fetch()) {
            // Date already blocked, update instead
            $update_sql = "
                UPDATE room_blocked_dates
                SET block_type = ?, reason = ?, created_by = ?
                WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
                AND block_date = ?
            ";
            $update_params = [$block_type, $reason, $created_by];
            if ($room_id !== null) {
                $update_params[] = $room_id;
            }
            $update_params[] = $block_date;
            
            $update_stmt = $pdo->prepare($update_sql);
            return $update_stmt->execute($update_params);
        }
        
        // Insert new blocked date
        $sql = "
            INSERT INTO room_blocked_dates (room_id, block_date, block_type, reason, created_by)
            VALUES (?, ?, ?, ?, ?)
        ";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$room_id, $block_date, $block_type, $reason, $created_by]);
    } catch (PDOException $e) {
        error_log("Error blocking room date: " . $e->getMessage());
        return false;
    }
}

/**
 * Unblock a specific date for a room or all rooms
 * Returns true on success, false on failure
 */
function unblockRoomDate($room_id, $block_date) {
    global $pdo;
    
    try {
        $sql = "
            DELETE FROM room_blocked_dates
            WHERE room_id " . ($room_id === null ? "IS NULL" : "= ?") . "
            AND block_date = ?
        ";
        $params = $room_id === null ? [$block_date] : [$room_id, $block_date];
        
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
function blockRoomDates($room_id, $dates, $block_type = 'manual', $reason = null, $created_by = null) {
    $blocked_count = 0;
    
    foreach ($dates as $date) {
        if (blockRoomDate($room_id, $date, $block_type, $reason, $created_by)) {
            $blocked_count++;
        }
    }
    
    return $blocked_count;
}

/**
 * Unblock multiple dates for a room or all rooms
 * Returns number of dates unblocked
 */
function unblockRoomDates($room_id, $dates) {
    $unblocked_count = 0;
    
    foreach ($dates as $date) {
        if (unblockRoomDate($room_id, $date)) {
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