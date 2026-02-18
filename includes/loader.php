<?php
if (function_exists('getSetting')) {
    $siteName = getSetting('site_name');
}

// Auto-detect current page slug from filename
$page_slug = '';
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $page_slug = strtolower(pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME));
    $page_slug = str_replace('_', '-', $page_slug);
}

// Fetch loader subtext from database
$loaderSubtext = '';
if (function_exists('getPageLoader') && $page_slug) {
    $loaderSubtext = getPageLoader($page_slug);
}
?>
<!-- Fancy Page Loader -->
<div id="page-loader">
    <div class="loader-content">
        <div class="loader-spinner">
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="spinner-center"></div>
        </div>
        <div class="loader-text"><?php echo htmlspecialchars($siteName); ?></div>
        <div class="loader-subtext"><?php echo htmlspecialchars($loaderSubtext); ?></div>
    </div>
</div>
