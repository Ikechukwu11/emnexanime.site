/* =============================================================================
 * EmnexAnime — search.js
 * Search page UX. The results grid itself (data-browse-grid + infinite
 * scroll) is handled by browse.js, which is loaded alongside this file.
 * ========================================================================== */
(function () {
    'use strict';
    const EMNX = window.EMNX;

    document.addEventListener('DOMContentLoaded', function () {
        const input = EMNX.$('[data-search-focus]');
        if (input && window.matchMedia('(min-width: 981px)').matches) {
            input.focus();
            const end = input.value.length;
            input.setSelectionRange(end, end);
        }

        /* remember recent searches (chips could be surfaced later) */
        const headerInput = EMNX.$('[data-search-input]');
        const FORM_KEY = 'emnx.recent-searches.v1';
        function remember(term) {
            const clean = (term || '').trim();
            if (clean.length < 2) return;
            const recent = EMNX.store.get(FORM_KEY, []).filter(function (t) {
                return typeof t === 'string' && t.toLowerCase() !== clean.toLowerCase();
            });
            recent.unshift(clean.slice(0, 60));
            EMNX.store.set(FORM_KEY, recent.slice(0, 12));
        }
        document.querySelectorAll('form.search-page-form, [data-search] form').forEach(function (form) {
            form.addEventListener('submit', function () {
                const field = form.querySelector('input[type="search"]');
                remember(field && field.value);
            });
        });
        void headerInput;
    });
})();
