<?php
/**
 * Enhanced Cache Management System
 * Supports instant clearing, disabling, and automatic invalidation
 */

// Cache directory configuration
define('CACHE_DIR', __DIR__ . '/../cache');
define('IMAGE_CACHE_DIR', __DIR__ . '/../data/image-cache');

// Global cache enable/disable flag
define('CACHE_ENABLED', true); // This can be overridden by database setting

/**
 * Check if caching is globally enabled
 */
function isCacheEnabled($type = null) {
    try {
        // Check global setting from database
        global $pdo;
        if (!$pdo) {
            return CACHE_ENABLED;
        }
        
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'cache_global_enabled' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['setting_value'] == '0') {
            return false;
        }
        
        // Check specific cache type
        if ($type) {
            $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute(["cache_{$type}_enabled"]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['setting_value'] == '0') {
                return false;
            }
        }
    } catch (Exception $e) {
        // If database query fails, default to enabled
        error_log("Cache enable check failed: " . $e->getMessage());
    }
    
    return CACHE_ENABLED;
}

/**
 * Get cached value with readable filename
 * Respects cache enable/disable settings
 */
function getCache($key, $default = null, $type = 'settings') {
    try {
        // Check if caching is enabled for this type
        if (!isCacheEnabled($type)) {
            return $default;
        }
        
        // Ensure cache directory exists
        if (!file_exists(CACHE_DIR)) {
            return $default;
        }
        
        // Generate readable cache filename with prefix
        $cacheFile = CACHE_DIR . '/' . getReadableCacheFilename($key);
        
        if (!file_exists($cacheFile)) {
            return $default;
        }
        
        $data = @file_get_contents($cacheFile);
        if ($data === false) {
            return $default;
        }
        
        $cache = json_decode($data, true);
        if (!$cache || !isset($cache['data']) || !isset($cache['expiry'])) {
            return $default;
        }
        
        // Check if expired
        if (time() > $cache['expiry']) {
            @unlink($cacheFile);
            return $default;
        }
        
        return $cache['data'];
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Generate a human-readable cache filename with prefix
 */
function getReadableCacheFilename($key) {
    // Sanitize key to be filesystem-safe
    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
    // Generate short hash for uniqueness
    $shortHash = substr(md5($key), 0, 8);
    return "{$sanitized}_{$shortHash}.cache";
}

/**
 * Set cached value with readable filename
 * Respects cache enable/disable settings
 */
function setCache($key, $value, $ttl = 3600, $type = 'settings') {
    // Don't cache if disabled
    if (!isCacheEnabled($type)) {
        return false;
    }
    
    // Create cache directory if it doesn't exist
    if (!file_exists(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    
    $cacheFile = CACHE_DIR . '/' . getReadableCacheFilename($key);
    
    $cacheData = [
        'key' => $key,
        'data' => $value,
        'created' => time(),
        'expiry' => time() + $ttl,
        'ttl' => $ttl
    ];
    
    $data = json_encode($cacheData);
    return @file_put_contents($cacheFile, $data, LOCK_EX) !== false;
}

/**
 * Delete cached value with readable filename
 */
function deleteCache($key) {
    $cacheFile = CACHE_DIR . '/' . getReadableCacheFilename($key);
    if (file_exists($cacheFile)) {
        return @unlink($cacheFile);
    }
    return false;
}

/**
 * Clear all cache files instantly
 */
function clearCache() {
    $files = glob(CACHE_DIR . '/*.cache');
    $cleared = 0;
    if ($files) {
        foreach ($files as $file) {
            if (@unlink($file)) {
                $cleared++;
            }
        }
    }
    
    // Also clear image cache
    clearImageCache();
    
    // Clear in-memory cache
    global $_SITE_SETTINGS;
    if (isset($_SITE_SETTINGS)) {
        $_SITE_SETTINGS = [];
    }
    
    return $cleared;
}

/**
 * Clear image cache
 */
function clearImageCache() {
    $files = glob(IMAGE_CACHE_DIR . '/*.jpg');
    $cleared = 0;
    if ($files) {
        foreach ($files as $file) {
            if (@unlink($file)) {
                $cleared++;
            }
        }
    }
    return $cleared;
}

/**
 * List all cache files with their details
 */
function listCache() {
    $files = glob(CACHE_DIR . '/*.cache');
    $caches = [];
    
    foreach ($files as $file) {
        $data = @file_get_contents($file);
        if ($data) {
            $cache = json_decode($data, true);
            if ($cache) {
                $caches[] = [
                    'file' => basename($file),
                    'key' => $cache['key'] ?? 'unknown',
                    'size' => filesize($file),
                    'size_formatted' => formatBytes(filesize($file)),
                    'created' => $cache['created'] ?? null,
                    'created_formatted' => $cache['created'] ? date('Y-m-d H:i:s', $cache['created']) : 'unknown',
                    'expires' => $cache['expiry'] ?? null,
                    'expires_formatted' => $cache['expiry'] ? date('Y-m-d H:i:s', $cache['expiry']) : 'unknown',
                    'expired' => ($cache['expiry'] ?? 0) < time(),
                    'ttl' => ($cache['expiry'] ?? time()) - time()
                ];
            }
        }
    }
    
    // Sort by file name for easier reading
    usort($caches, function($a, $b) {
        return strcmp($a['file'], $b['file']);
    });
    
    return $caches;
}

/**
 * Clear cache by key pattern (supports wildcards)
 */
function clearCacheByPattern($pattern) {
    $files = glob(CACHE_DIR . '/*.cache');
    $cleared = 0;
    
    if ($files) {
        // Convert pattern to regex
        $regex = '/^' . str_replace('*', '.*', $pattern) . '$/';
        
        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data) {
                $cache = json_decode($data, true);
                if ($cache && isset($cache['key'])) {
                    if (preg_match($regex, $cache['key'])) {
                        if (@unlink($file)) {
                            $cleared++;
                        }
                    }
                }
            }
        }
    }
    
    return $cleared;
}

/**
 * Get cache statistics
 */
function getCacheStats() {
    // Ensure cache directory exists
    if (!file_exists(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    
    $files = @glob(CACHE_DIR . '/*.cache');
    if ($files === false) {
        $files = [];
    }
    
    $stats = [
        'total_files' => 0,
        'active_files' => 0,
        'expired_files' => 0,
        'total_size' => 0,
        'total_size_formatted' => '0 B',
        'oldest_file' => null,
        'newest_file' => null,
        'caches' => []
    ];
    
    $now = time();
    $oldest = PHP_INT_MAX;
    $newest = 0;
    
    if ($files) {
        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data) {
                $cache = @json_decode($data, true);
                if ($cache) {
                    $stats['total_files']++;
                    $stats['total_size'] += filesize($file);
                    
                    $created = $cache['created'] ?? 0;
                    if ($created < $oldest) {
                        $oldest = $created;
                        $stats['oldest_file'] = $cache['key'];
                    }
                    if ($created > $newest) {
                        $newest = $created;
                        $stats['newest_file'] = $cache['key'];
                    }
                    
                    if (($cache['expiry'] ?? 0) < $now) {
                        $stats['expired_files']++;
                    } else {
                        $stats['active_files']++;
                    }
                }
            }
        }
    }
    
    $stats['total_size_formatted'] = formatBytes($stats['total_size']);
    
    return $stats;
}

/**
 * Format bytes to human-readable format
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Clear specific cache by exact key
 * Alias for deleteCache() for consistency
 */
function clearSpecificCache($key) {
    return deleteCache($key);
}

/**
 * Clear all room-related cache instantly
 * Call this when rooms, prices, or images are updated
 */
function clearRoomCache() {
    // Clear all room-related caches
    $patterns = [
        'rooms_*',
        'table_rooms_*',
        'room_*',
        'facilities_*',
        'gallery_images',
        'hero_slides'
    ];
    
    $total = 0;
    foreach ($patterns as $pattern) {
        $total += clearCacheByPattern($pattern);
    }
    
    // Also clear image cache
    $total += clearImageCache();
    
    // Clear in-memory cache
    global $_SITE_SETTINGS;
    if (isset($_SITE_SETTINGS)) {
        unset($_SITE_SETTINGS['rooms']);
    }
    
    return $total;
}

/**
 * Clear all settings cache instantly
 * Call this when site settings are updated
 */
function clearSettingsCache() {
    return clearCacheByPattern('setting_*');
}

/**
 * Clear all email cache instantly
 * Call this when email settings are updated
 */
function clearEmailCache() {
    return clearCacheByPattern('email_*');
}

/**
 * Force cache refresh by clearing and immediately rebuilding
 * Useful for ensuring data is fresh
 */
function forceCacheRefresh($key, $callback, $ttl = 3600, $type = 'settings') {
    deleteCache($key);
    $data = $callback();
    if ($data !== null) {
        setCache($key, $data, $ttl, $type);
    }
    return $data;
}