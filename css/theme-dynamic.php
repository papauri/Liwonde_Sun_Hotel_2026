<?php
/**
 * Static Theme CSS
 * Theme management has been removed; this file now serves fixed design tokens.
 */

$etag = md5('theme-static-v1');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    header('HTTP/1.1 304 Not Modified');
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600, must-revalidate');
header('ETag: ' . $etag);
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
?>

:root {
    /* Primary Colors */
    --navy: #0A1929;
    --deep-navy: #05090F;
    --theme-color: #0A1929;
    
    /* Accent Colors */
    --gold: #D4AF37;
    --dark-gold: #B8941F;
    --accent-color: #D4AF37;
    
    /* Neutral Colors */
    --white: #ffffff;
    --cream: #FBF8F3;
    --light-gray: #f8f9fa;
    --medium-gray: #6c757d;
    --dark-gray: #343a40;
    
    /* Status Colors */
    --success: #28a745;
    --danger: #dc3545;
    --warning: #ffc107;
    --info: #17a2b8;
    
    /* Typography */
    --font-sans: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-serif: 'Playfair Display', Georgia, serif;
    
    /* Shadows */
    --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.1);
    --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
    --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.1);
    --shadow-luxury: 0 8px 30px rgba(212, 175, 55, 0.15);
    
    /* Transitions */
    --transition-fast: 0.15s ease;
    --transition-base: 0.3s ease;
    --transition-slow: 0.5s ease;
    
    /* Border Radius */
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-xl: 24px;
    --radius-full: 9999px;
}