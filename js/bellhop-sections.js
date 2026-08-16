/*
 * Bellhop-inspired editorial behaviour.
 *
 * Two jobs, both progressive enhancements — with JS off the rail is still a
 * native horizontal scroller and every card is still a plain link:
 *   1. reveal `[data-bh-anim]` elements on entry (adds `.is-animated`)
 *   2. drive the rooms rail: arrows, drag-to-scroll and the progress bar
 */
(function () {
    'use strict';

    if (window.__lshBellhopSectionsLoaded) return;
    window.__lshBellhopSectionsLoaded = true;

    // Marks that the reveal states are safe to apply. bellhop.css gates the
    // hidden start state on this class, so a script that never loads leaves
    // the rooms fully visible instead of blank.
    document.documentElement.classList.add('bh-js');

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    /* ---------------------------------------------------------------
       1. Entry reveals
       --------------------------------------------------------------- */

    function initReveals(root) {
        var nodes = root.querySelectorAll('[data-bh-anim]:not(.is-animated)');
        if (!nodes.length) return;

        if (reduceMotion.matches || !('IntersectionObserver' in window)) {
            Array.prototype.forEach.call(nodes, function (el) {
                el.classList.add('is-animated');
            });
            return;
        }

        var io = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-animated');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

        Array.prototype.forEach.call(nodes, function (el) {
            io.observe(el);
        });
    }

    /* ---------------------------------------------------------------
       2. The rail
       --------------------------------------------------------------- */

    function initRail(rail) {
        if (rail.__bhRailReady) return;
        rail.__bhRailReady = true;

        var track = rail.querySelector('[data-bh-rail-track]');
        if (!track) return;

        var section = rail.closest('.bellhop-section') || rail.parentNode;
        var nav = section.querySelector('[data-bh-rail-nav]');
        var prev = section.querySelector('[data-bh-rail-prev]');
        var next = section.querySelector('[data-bh-rail-next]');
        var progress = section.querySelector('[data-bh-rail-progress]');

        function maxScroll() {
            return Math.max(0, track.scrollWidth - track.clientWidth);
        }

        function step() {
            var card = track.querySelector('.bellhop-room-card');
            if (!card) return Math.round(track.clientWidth * 0.8);
            var gap = parseFloat(getComputedStyle(track).columnGap) || 0;
            return card.getBoundingClientRect().width + gap;
        }

        function sync() {
            var max = maxScroll();
            var scrollable = max > 1;

            if (nav) nav.hidden = !scrollable;
            if (prev) prev.disabled = track.scrollLeft <= 1;
            if (next) next.disabled = track.scrollLeft >= max - 1;

            if (progress) {
                if (!scrollable) {
                    progress.style.width = '100%';
                    progress.style.transform = 'translateX(0)';
                    return;
                }
                var ratio = track.clientWidth / track.scrollWidth;
                progress.style.width = (ratio * 100) + '%';
                // translateX is relative to the thumb's own width, so the full
                // travel is (1 - ratio) / ratio expressed as a percentage.
                progress.style.transform =
                    'translateX(' + ((track.scrollLeft / max) * (100 / ratio - 100)) + '%)';
            }
        }

        function scrollByStep(direction) {
            var behavior = reduceMotion.matches ? 'auto' : 'smooth';
            track.scrollBy({ left: direction * step(), behavior: behavior });
        }

        if (prev) prev.addEventListener('click', function () { scrollByStep(-1); });
        if (next) next.addEventListener('click', function () { scrollByStep(1); });

        track.addEventListener('scroll', sync, { passive: true });
        window.addEventListener('resize', sync);

        // Re-measure once lazy images have given the track its real width.
        Array.prototype.forEach.call(track.querySelectorAll('img'), function (img) {
            if (img.complete) return;
            img.addEventListener('load', sync, { once: true });
            img.addEventListener('error', sync, { once: true });
        });

        // Drag-to-scroll, pointer devices only. The `is-dragging` class kills
        // snap and suppresses the click that would otherwise fire on the card
        // link when a drag ends over it.
        var dragging = false;
        var moved = false;
        var startX = 0;
        var startScroll = 0;

        track.addEventListener('pointerdown', function (event) {
            if (event.pointerType === 'touch' || event.button !== 0) return;
            dragging = true;
            moved = false;
            startX = event.clientX;
            startScroll = track.scrollLeft;
        });

        track.addEventListener('pointermove', function (event) {
            if (!dragging) return;
            var delta = event.clientX - startX;
            if (!moved && Math.abs(delta) < 5) return;
            if (!moved) {
                moved = true;
                track.classList.add('is-dragging');
                // A real pointer captured by the OS (or a synthetic one that has
                // already lifted) can make setPointerCapture throw synchronously.
                // The drag should continue either way — capture only steers the
                // follow-up moves, it isn't required for scrollLeft updates.
                if (track.setPointerCapture) {
                    try { track.setPointerCapture(event.pointerId); }
                    catch (err) { /* pointer already inactive — keep dragging */ }
                }
            }
            track.scrollLeft = startScroll - delta;
        });

        function endDrag(event) {
            if (!dragging) return;
            dragging = false;
            if (!moved) return;
            if (track.releasePointerCapture && event.pointerId !== undefined) {
                try { track.releasePointerCapture(event.pointerId); } catch (e) { /* already released */ }
            }
            // Defer so the synthetic click lands while links are still inert.
            window.setTimeout(function () {
                track.classList.remove('is-dragging');
            }, 0);
        }

        track.addEventListener('pointerup', endDrag);
        track.addEventListener('pointercancel', endDrag);
        track.addEventListener('pointerleave', endDrag);

        sync();
    }

    function init(root) {
        initReveals(root);
        Array.prototype.forEach.call(root.querySelectorAll('[data-bh-rail]'), initRail);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    } else {
        init(document);
    }

    window.addEventListener('spa:contentLoaded', function () { init(document); });
})();
