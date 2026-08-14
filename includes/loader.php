<?php
$siteName = '';
if (function_exists('getSetting')) {
    $siteName = getSetting('site_name') ?? '';
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

// Build page loader subtext mapping for client-side navigation
// This allows the loader to show the destination page's subtext during navigation.
// Driven straight off page_loaders (admin → Section Headers → Loading Screens),
// so a loader added in admin is picked up by SPA navigation without a code change.
$loaderSubtextMap = [];
if (function_exists('getAllPageLoaders')) {
    $loaderSubtextMap = getAllPageLoaders();
}
// Always track the current page so navigating back to it never falls back to
// the previous page's subtext.
if ($page_slug !== '' && !array_key_exists($page_slug, $loaderSubtextMap)) {
    $loaderSubtextMap[$page_slug] = (string)$loaderSubtext;
}

// Ballena-style split tagline for the loader (presentation only)
$loaderTagline = function_exists('getSetting') ? (string)(getSetting('site_tagline') ?? '') : '';
if (trim($loaderTagline) === '') {
    $loaderTagline = 'Where Comfort Meets Value';
}
$loaderWords = explode(' ', trim($loaderTagline));
$loaderHalf  = intdiv(count($loaderWords), 2) ?: 1;
$loaderLineTop    = implode(' ', array_slice($loaderWords, 0, $loaderHalf));
$loaderLineBottom = implode(' ', array_slice($loaderWords, $loaderHalf));
?>
<!-- Ballena-inspired Page Loader -->
<div id="page-loader" class="loader loader--active">
    <div class="loader__content">
        <div class="loader__line loader__line--top"><span><?php echo htmlspecialchars($loaderLineTop); ?></span></div>
        <div class="loader__title"><?php echo htmlspecialchars($siteName); ?></div>
        <div class="loader__line loader__line--bottom"><span><?php echo htmlspecialchars($loaderLineBottom); ?></span></div>
        <div class="loader__subtitle" data-default-subtext="<?php echo htmlspecialchars($loaderSubtext); ?>"><?php echo htmlspecialchars($loaderSubtext); ?></div>
        <div class="loader__progress">
            <div class="loader__progress-bar"></div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    // Page loader subtext mapping for client-side navigation
    // This allows showing the destination page's subtext during navigation
    window.PAGE_LOADER_SUBTEXTS = <?php echo json_encode($loaderSubtextMap); ?>;
    
    // Normalize page slug for consistent lookup
    function normalizePageSlug(pageSlug) {
        if (!pageSlug) return '';
        // Remove .php extension, trailing slashes, and convert to lowercase
        return pageSlug
            .replace(/\.php$/, '')
            .replace(/\/$/, '')
            .toLowerCase();
    }
    
    // Get subtext for a page slug, with fallback
    function getLoaderSubtext(pageSlug) {
        if (!pageSlug) return null;
        
        const normalizedSlug = normalizePageSlug(pageSlug);
        
        // Direct match
        if (window.PAGE_LOADER_SUBTEXTS && window.PAGE_LOADER_SUBTEXTS[normalizedSlug]) {
            return window.PAGE_LOADER_SUBTEXTS[normalizedSlug];
        }
        
        // Handle home/index variations
        if ((normalizedSlug === '' || normalizedSlug === 'home') && window.PAGE_LOADER_SUBTEXTS && window.PAGE_LOADER_SUBTEXTS['index']) {
            return window.PAGE_LOADER_SUBTEXTS['index'];
        }
        
        return null;
    }
    
    // Update loader subtext to show destination page
    function updateLoaderSubtext(destinationPage) {
        const subtitleEl = document.querySelector('.loader__subtitle');
        if (!subtitleEl) return;
        
        const subtext = getLoaderSubtext(destinationPage);
        if (subtext && subtext !== '') {
            // Use destination page's subtext
            subtitleEl.textContent = subtext;
        } else {
            // Use generic loading message instead of source page's subtext
            // This prevents showing the wrong page's subtext during navigation
            subtitleEl.textContent = 'Loading...';
        }
    }
    
    // Expose functions globally for navigation scripts
    window.getLoaderSubtext = getLoaderSubtext;
    window.updateLoaderSubtext = updateLoaderSubtext;
    
    // Hide loader when page is fully loaded
    function hideLoader() {
        const loader = document.getElementById('page-loader');
        if (loader) {
            // Use proper CSS transition sequence
            loader.classList.add('loader--hiding');
            loader.classList.remove('loader--active');

            // Must match .loader's transform transition duration in loader.css
            // (0.85s). Finalizing loader--hidden before that transition
            // finishes yanks the curtain's transform target back to its base
            // value mid-flight, which - because .loader's transition applies
            // to transform generally, not just the --hiding rule - replays
            // the curtain sliding back down over the page before it can
            // vanish: a visible "loader, page, loader again" flash.
            setTimeout(function() {
                loader.classList.add('loader--hidden');
                loader.classList.remove('loader--hiding');
            }, 850);
        }
    }
    
    // Hide on window load (only if navigation has not started)
    function hideLoaderOnPageLoad() {
        setTimeout(function() {
            if (!window._pageLoaderNavigating) {
                hideLoader();
            }
        }, 100);
    }

    if (document.readyState === 'complete') {
        hideLoaderOnPageLoad();
    } else {
        window.addEventListener('load', hideLoaderOnPageLoad);
    }
    
    // Fallback: hide after 3 seconds, but only if navigation is not in progress
    setTimeout(function() {
        if (!window._pageLoaderNavigating) {
            hideLoader();
        }
    }, 3000);
    
    // Handle browser back/forward (bfcache)
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            hideLoader();
        }
    });
})();
</script>
