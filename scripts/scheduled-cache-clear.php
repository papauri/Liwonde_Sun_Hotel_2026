<?php
/**
 * Scheduled Cache Clearing Script
 * Run this via cron job to automatically clear cache based on settings
 * 
 * Cron Examples:
 * - Every Minute (for intervals < 1 minute): * * * * * php /path/to/scripts/scheduled-cache-clear.php
 * - Hourly: 0 * * * * php /path/to/scripts/scheduled-cache-clear.php
 * - Daily at 3 AM: 0 3 * * * php /path/to/scripts/scheduled-cache-clear.php
 * - Weekly: 0 3 * * 0 php /path/to/scripts/scheduled-cache-clear.php
 * 
 * Supported Intervals:
 * - 30sec: Every 30 seconds (requires cron running every minute)
 * - 1min: Every 1 minute
 * - 5min: Every 5 minutes
 * - 15min: Every 15 minutes
 * - 30min: Every 30 minutes
 * - hourly: Every hour
 * - 6hours: Every 6 hours
 * - 12hours: Every 12 hours
 * - daily: Once daily at specified time
 * - weekly: Once weekly (Sunday) at specified time
 * - custom: Custom interval in seconds (10-86400)
 */

// Production error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/cron-errors.log');

// Load database connection
require_once __DIR__ . '/../config/database.php';

// Fetch schedule settings
$stmt = $pdo->query("
    SELECT setting_key, setting_value 
    FROM site_settings 
    WHERE setting_key IN ('cache_schedule_enabled', 'cache_schedule_interval', 'cache_schedule_time', 'cache_custom_seconds', 'cache_last_run')
");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$schedule_enabled = isset($settings['cache_schedule_enabled']) ? (int)$settings['cache_schedule_enabled'] : 0;
$interval = $settings['cache_schedule_interval'] ?? 'daily';
$scheduled_time = $settings['cache_schedule_time'] ?? '00:00';
$custom_seconds = isset($settings['cache_custom_seconds']) ? (int)$settings['cache_custom_seconds'] : 60;
$last_run = isset($settings['cache_last_run']) ? (int)$settings['cache_last_run'] : 0;

// Check if scheduling is enabled
if (!$schedule_enabled) {
    error_log("Scheduled cache clear: Schedule is disabled");
    exit(0);
}

// Check if we should run based on interval and time
$now = time();
$current_hour = date('H');
$current_time = date('H:i');
$current_second = date('s');

// Parse scheduled time for time-based intervals
$scheduled_hour = '00';
$scheduled_minute = '00';
if (strpos($scheduled_time, ':') !== false) {
    list($scheduled_hour, $scheduled_minute) = explode(':', $scheduled_time);
}

$should_run = false;

switch ($interval) {
    case '30sec':
        // Run every 30 seconds
        $should_run = ($now - $last_run >= 30);
        break;
        
    case '1min':
        // Run every 1 minute (60 seconds)
        $should_run = ($now - $last_run >= 60);
        break;
        
    case '5min':
        // Run every 5 minutes (300 seconds)
        $should_run = ($now - $last_run >= 300);
        break;
        
    case '15min':
        // Run every 15 minutes (900 seconds)
        $should_run = ($now - $last_run >= 900);
        break;
        
    case '30min':
        // Run every 30 minutes (1800 seconds)
        $should_run = ($now - $last_run >= 1800);
        break;
        
    case 'hourly':
        // Run every hour (3600 seconds)
        $should_run = ($now - $last_run >= 3600);
        break;
        
    case '6hours':
        // Run every 6 hours (21600 seconds)
        $should_run = ($now - $last_run >= 21600);
        break;
        
    case '12hours':
        // Run every 12 hours (43200 seconds)
        $should_run = ($now - $last_run >= 43200);
        break;
        
    case 'daily':
        // Run once daily at scheduled time
        $should_run = ($current_time == $scheduled_time) && ($now - $last_run >= 60);
        break;
        
    case 'weekly':
        // Run once weekly at scheduled time (on Sunday)
        $should_run = (date('w') == 0) && ($current_time == $scheduled_time) && ($now - $last_run >= 60);
        break;
        
    case 'custom':
        // Run at custom interval (in seconds)
        $should_run = ($now - $last_run >= $custom_seconds);
        break;
}

if (!$should_run) {
    // Silent exit - not scheduled to run now
    exit(0);
}

// Load cache functions
require_once __DIR__ . '/../config/cache.php';

// Clear all cache
$cleared = clearCache();

// Update last run timestamp
$stmt = $pdo->prepare("
    INSERT INTO site_settings (setting_key, setting_value, updated_at)
    VALUES ('cache_last_run', ?, NOW())
    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
");
$stmt->execute([time(), time()]);

// Log the action
$log_file = __DIR__ . '/../logs/cache-clear.log';
$interval_display = $interval;
if ($interval === 'custom') {
    $interval_display = "custom ({$custom_seconds} seconds)";
}
$log_entry = date('Y-m-d H:i:s') . " - Scheduled cache clear executed. Interval: {$interval_display}, Files cleared: {$cleared}\n";

// Ensure log directory exists
$log_dir = dirname($log_file);
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

file_put_contents($log_file, $log_entry, FILE_APPEND);
error_log("Scheduled cache clear completed: {$cleared} files cleared");

exit(0);
?>