/* =============================================================================
 * EmnexAnime — browse.js
 * Paged catalog loading for every grid marked with `data-browse-grid`
 * (browse pages, genre pages and the search results page).
 *
 * Contract (server → JS):
 *   [data-browse-grid] carries data-feed, data-page, data-per-page,
 *   data-has-next, data-params (JSON of q/genre/season/year).
 *   api.php?action=feed&type=… answers {ok, data:{items, pageInfo}, meta}.
 * ========================================================================== */
(function () {
    'use strict';
    const EMNX = window.EMNX;

    document.addEventListener('DOMContentLoaded', function () {
        const grid = EMNX.$('[data-browse-grid]');
        if (!grid) return;

        const feed    = grid.dataset.feed || 'trending';
        let page      = parseInt(grid.dataset.page, 10) || 1;
        const perPage = parseInt(grid.dataset.perPage, 10) || 24;
        let hasNext   = grid.dataset.hasNext === '1';
        const params  = JSON.parse(grid.dataset.params || '{}');
        const initial = grid.children.length; // server-rendered cards

        const area     = EMNX.$('[data-load-area]');
        const button   = EMNX.$('[data-load-more]');
        const spinner  = EMNX.$('[data-load-spinner]');
        const sentinel = EMNX.$('[data-load-sentinel]');
        const endNote  = EMNX.$('[data-end-note]');

        let loading = false;

        function setBusy(busy) {
            loading = busy;
            if (spinner) spinner.hidden = !busy;
            if (button) button.classList.toggle('is-loading', busy);
        }

        function finish() {
            if (hasNext) {
                if (spinner) spinner.hidden = true;
                if (button) button.hidden = false;
                if (endNote) endNote.hidden = true;
                if (area) area.hidden = false;
            } else {
                if (area) area.hidden = true;
                if (endNote) endNote.hidden = false;
            }
        }

        function loadNext() {
            if (loading || !hasNext) return;
            setBusy(true);
            const next = page + 1;
            const qs = {
                action: 'feed',
                type: feed,
                page: String(next),
                perPage: String(perPage)
            };
            ['q', 'genre', 'season', 'year'].forEach(function (key) {
                if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                    qs[key] = params[key];
                }
            });

            EMNX.fetchJSON(qs)
                .then(function (res) {
                    const items = (res.data.items || []).filter(function (i) { return i && Number(i.id); });
                    page = next;
                    const info = res.data.pageInfo || {};
                    hasNext = info.hasNextPage === undefined ? items.length === perPage : !!info.hasNextPage;
                    if (items.length) {
                        const frag = document.createElement('template');
                        frag.innerHTML = items.map(function (item) { return EMNX.cardHTML(item); }).join('');
                        while (frag.content.firstChild) grid.appendChild(frag.content.firstChild);
                    }
                    if (!items.length) hasNext = false;
                    setBusy(false);
                    finish();
                })
                .catch(function (err) {
                    setBusy(false);
                    EMNX.toast(err.message || 'Could not load more results.', 'error');
                });
        }

        if (!initial) {
            // Empty first page: make sure the end state is shown.
            hasNext = false;
        }

        button && button.addEventListener('click', loadNext);

        // Infinite scroll: loads the next page when the sentinel nears the
        // viewport. Capped at 5 auto-loads ahead of the user's intent; after
        // that, the Load more button takes over (protects the API quota).
        let autoLoads = 0;
        if (sentinel && 'IntersectionObserver' in window && hasNext) {
            const io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting && autoLoads < 5) {
                        const before = page;
                        loadNext();
                        if (page !== before) autoLoads += 1;
                    }
                });
                if (!hasNext) io.disconnect();
            }, { rootMargin: '600px 0px' });
            io.observe(sentinel);
            // A manual click re-arms auto loading for the next stretch.
            button && button.addEventListener('click', function () { autoLoads = 0; });
        }

        finish();
    });
})();
