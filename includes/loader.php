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

    /* ====================================================================
       PAGE LOADER — single controller
       --------------------------------------------------------------------
       This inline script is the ONE owner of #page-loader for the whole
       site. It runs a tiny state machine (visible → hiding → hidden) and
       finalises the hide from the curtain's own `transitionend` event, with
       a timer used only as a safety net — never as the primary trigger.

       It replaces the previous setup where this file, page-transitions.js
       AND navigation-unified.js each independently poked the same element on
       hand-synced 850ms timers. That, combined with a CSS state that let the
       curtain animate back down on finalise, produced the mobile double
       flash. navigation-unified.js now simply calls window.LSHLoader.
       ==================================================================== */

    // ── SPA subtext map (unchanged behaviour) ────────────────────────────
    // Lets client-side navigation show the DESTINATION page's caption while
    // the curtain is up.
    window.PAGE_LOADER_SUBTEXTS = <?php echo json_encode($loaderSubtextMap); ?>;

    function normalizePageSlug(pageSlug) {
        if (!pageSlug) return '';
        return pageSlug.replace(/\.php$/, '').replace(/\/$/, '').toLowerCase();
    }

    function getLoaderSubtext(pageSlug) {
        if (!pageSlug) return null;
        var slug = normalizePageSlug(pageSlug);
        if (window.PAGE_LOADER_SUBTEXTS && window.PAGE_LOADER_SUBTEXTS[slug]) {
            return window.PAGE_LOADER_SUBTEXTS[slug];
        }
        if ((slug === '' || slug === 'home') &&
            window.PAGE_LOADER_SUBTEXTS && window.PAGE_LOADER_SUBTEXTS['index']) {
            return window.PAGE_LOADER_SUBTEXTS['index'];
        }
        return null;
    }

    function updateLoaderSubtext(destinationPage) {
        var subtitleEl = document.querySelector('.loader__subtitle');
        if (!subtitleEl) return;
        var subtext = getLoaderSubtext(destinationPage);
        subtitleEl.textContent = (subtext && subtext !== '') ? subtext : 'Loading...';
    }

    window.getLoaderSubtext = getLoaderSubtext;
    window.updateLoaderSubtext = updateLoaderSubtext;

    // ── Controller ───────────────────────────────────────────────────────
    var loader = document.getElementById('page-loader');
    if (!loader) return;

    var state = 'visible';   // 'visible' | 'hiding' | 'hidden'
    var safetyTimer = null;

    function prefersReduced() {
        return !!(window.matchMedia &&
                  window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function onTransitionEnd(e) {
        // Only the curtain's own transform settles the hide — ignore bubbling
        // transitions from child elements (content fade, etc.).
        if (e.target !== loader) return;
        if (e.propertyName && e.propertyName !== 'transform') return;
        finalizeHide();
    }

    function finalizeHide() {
        if (state !== 'hiding') return;   // guard against double-finalise
        state = 'hidden';
        clearTimeout(safetyTimer);
        loader.removeEventListener('transitionend', onTransitionEnd);
        // --hidden keeps transform at -100% and kills the transition, so this
        // commit is visually inert: no reversal, no flash.
        loader.classList.add('loader--hidden');
        loader.classList.remove('loader--hiding', 'loader--active');
        document.body.classList.add('page-loaded');
    }

    // Animated hide (initial page load, end of SPA navigation).
    function hide() {
        if (state !== 'visible') return;  // idempotent — never re-arm mid-hide
        window._pageLoaderNavigating = false;
        state = 'hiding';
        loader.classList.add('loader--hiding');
        loader.classList.remove('loader--active');
        loader.addEventListener('transitionend', onTransitionEnd);
        // Safety net only: transitionend can fail to fire on a backgrounded
        // tab, under reduced motion (no transition), or if the animation is
        // interrupted. Slightly longer than the CSS duration.
        clearTimeout(safetyTimer);
        safetyTimer = setTimeout(finalizeHide, prefersReduced() ? 80 : 950);
    }

    // Instant show (start of SPA navigation) — snap the curtain over the page
    // with no slide, then restore the transition for the next hide.
    function show(destinationPage) {
        window._pageLoaderNavigating = true;
        if (destinationPage) updateLoaderSubtext(destinationPage);
        clearTimeout(safetyTimer);
        loader.removeEventListener('transitionend', onTransitionEnd);
        loader.classList.add('loader--instant');
        loader.classList.remove('loader--hidden', 'loader--hiding');
        loader.classList.add('loader--active');
        void loader.offsetWidth;                 // force reflow while inert
        loader.classList.remove('loader--instant');
        state = 'visible';
        document.body.classList.remove('page-loaded');
    }

    // Instant hide (bfcache restore) — no animation, just gone.
    function hideInstant() {
        state = 'hidden';
        clearTimeout(safetyTimer);
        loader.removeEventListener('transitionend', onTransitionEnd);
        loader.classList.add('loader--instant', 'loader--hidden');
        loader.classList.remove('loader--hiding', 'loader--active');
        void loader.offsetWidth;
        loader.classList.remove('loader--instant');
        document.body.classList.add('page-loaded');
    }

    window.LSHLoader = { show: show, hide: hide, hideInstant: hideInstant };

    // ── Initial-load hide ────────────────────────────────────────────────
    function hideOnLoad() {
        // Brief perceptible beat, but never hide while a client-side
        // navigation is mid-flight (navigation owns the curtain then).
        setTimeout(function() {
            if (!window._pageLoaderNavigating) hide();
        }, 100);
    }

    if (document.readyState === 'complete') {
        hideOnLoad();
    } else {
        window.addEventListener('load', hideOnLoad);
    }

    // Hard safety: never leave the curtain stuck, even if `load` never fires.
    setTimeout(function() {
        if (!window._pageLoaderNavigating) hide();
    }, 3000);

    // Browser back/forward (bfcache) — the page is restored fully rendered,
    // so drop the curtain instantly rather than replaying the animation.
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) hideInstant();
    });
})();
</script>
