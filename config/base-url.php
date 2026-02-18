<?php
/**
 * Base URL Configuration
 * Automatically detects the base path for subdirectory installations
 * 
 * This file should be included before any HTML output
 */

// Get the base path from the current script location
// This handles installations in subdirectories like /hotelsmw/
function getBasePath() {
    // Get the directory name of the current script
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    
    // If we're in the root directory, return empty string
    if ($scriptDir === '/' || $scriptDir === '\\') {
        return '';
    }
    
    // Remove trailing slash if present
    return rtrim($scriptDir, '/');
}

// Define base path constant
if (!defined('BASE_PATH')) {
    define('BASE_PATH', getBasePath());
}

// Helper function to generate URLs
function siteUrl($path = '') {
    $basePath = BASE_PATH;
    $path = ltrim($path, '/');
    
    if (empty($basePath)) {
        return '/' . $path;
    }
    
    return $basePath . '/' . $path;
}

// Helper function to generate asset URLs
function assetUrl($path) {
    return siteUrl($path);
}
?>
