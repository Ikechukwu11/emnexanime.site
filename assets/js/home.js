/* =============================================================================
 * EmnexAnime — home.js
 * Hero spotlight carousel only. Row arrows / continue-watching live in app.js.
 * ========================================================================== */
(function () {
    'use strict';
    const EMNX = window.EMNX;

    document.addEventListener('DOMContentLoaded', function () {
        const hero = EMNX.$('[data-hero]');
        if (!hero) return;

        const slides = EMNX.$$('[data-hero-slide]', hero);
        const dots = EMNX.$$('[data-hero-dot]', hero);
        const prev = EMNX.$('[data-hero-prev]', hero);
        const next = EMNX.$('[data-hero-next]', hero);
        if (slides.length < 2) return;

        let index = 0;
        let timer = null;
        let hovering = false;
        const INTERVAL = 7000;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function set(i) {
            index = (i + slides.length) % slides.length;
            slides.forEach(function (slide, k) {
                slide.classList.toggle('is-active', k === index);
                slide.setAttribute('aria-hidden', k === index ? 'false' : 'true');
            });
            dots.forEach(function (dot, k) {
                dot.classList.toggle('is-active', k === index);
                dot.setAttribute('aria-selected', k === index ? 'true' : 'false');
            });
        }

        function schedule() {
            if (timer) clearInterval(timer);
            if (reduceMotion) return;
            timer = setInterval(function () {
                if (!hovering && !document.hidden) set(index + 1);
            }, INTERVAL);
        }

        prev && prev.addEventListener('click', function () { set(index - 1); schedule(); });
        next && next.addEventListener('click', function () { set(index + 1); schedule(); });
        dots.forEach(function (dot, k) {
            dot.addEventListener('click', function () { set(k); schedule(); });
        });

        hero.addEventListener('mouseenter', function () { hovering = true; });
        hero.addEventListener('mouseleave', function () { hovering = false; });
        hero.addEventListener('focusin', function () { hovering = true; });
        hero.addEventListener('focusout', function () { hovering = false; });

        // Touch swipe.
        let startX = null;
        hero.addEventListener('touchstart', function (e) {
            startX = e.touches[0].clientX;
        }, { passive: true });
        hero.addEventListener('touchend', function (e) {
            if (startX === null) return;
            const dx = e.changedTouches[0].clientX - startX;
            if (Math.abs(dx) > 48) set(index + (dx < 0 ? 1 : -1));
            startX = null;
        }, { passive: true });

        set(0);
        schedule();
    });
})();
