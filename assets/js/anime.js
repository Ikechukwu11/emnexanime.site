/* =============================================================================
 * EmnexAnime — anime.js
 * Detail page behaviours:
 *   - lazy episode list (IntersectionObserver + batches + jump-to)
 *   - continue-watching adaptation of the primary CTA
 *   - synopsis "read more", relations expansion, trailer modal
 *   - next-episode countdown, share button
 * Episode data contract (api.php?action=episodes):
 *   { ok, data: { episodes: [{number, title, thumbnail}] }, meta }
 * ========================================================================== */
(function () {
    'use strict';
    const EMNX = window.EMNX;

    document.addEventListener('DOMContentLoaded', function () {
        setupSynopsis();
        setupRelations();
        setupTrailer();
        setupCountdown();
        setupShare();
        setupContinueCta();
        const eps = EMNX.$('[data-episodes][data-id]');
        if (eps) setupEpisodes(eps);
    });

    /* ------------------------------------------------------------ synopsis */
    function setupSynopsis() {
        const toggle = EMNX.$('[data-synopsis-toggle]');
        const body = EMNX.$('[data-synopsis]');
        if (!toggle || !body) return;
        toggle.addEventListener('click', function () {
            const clamped = body.classList.toggle('is-clamped');
            toggle.textContent = clamped ? 'Read more' : 'Read less';
            if (clamped) body.scrollIntoView({ block: 'nearest' });
        });
    }

    /* ------------------------------------------------------------ relations */
    function setupRelations() {
        const more = EMNX.$('[data-relations-more]');
        if (!more) return;
        more.addEventListener('click', function () {
            EMNX.$$('[data-relation-item].is-hidden-extra').forEach(function (node) {
                node.classList.remove('is-hidden-extra');
            });
            more.hidden = true;
        });
    }

    /* ------------------------------------------------------------- trailer */
    function setupTrailer() {
        const btn = EMNX.$('[data-trailer]');
        if (!btn) return;
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-trailer') || '';
            if (!/^[A-Za-z0-9_-]{6,20}$/.test(id)) return;
            EMNX.modal.open(
                '<div class="video-frame"><iframe src="https://www.youtube-nocookie.com/embed/' + EMNX.esc(id) +
                '?autoplay=1&rel=0" title="Trailer" allow="autoplay; encrypted-media; fullscreen" allowfullscreen loading="lazy"></iframe></div>'
            );
        });
    }

    /* ----------------------------------------------------------- countdown */
    function setupCountdown() {
        const node = EMNX.$('[data-countdown]');
        if (!node) return;
        const target = (parseInt(node.dataset.at, 10) || 0) * 1000;
        const value = EMNX.$('[data-countdown-value]', node);
        if (!(target > Date.now()) || !value) return;
        let done = false;
        const tick = function () {
            const left = Math.round((target - Date.now()) / 1000);
            if (left <= 0) {
                if (!done) {
                    value.textContent = 'airing around now';
                    done = true;
                }
                return;
            }
            value.textContent = EMNX.fmtCountdown(left);
            setTimeout(tick, 1000);
        };
        tick();
    }

    /* --------------------------------------------------------------- share */
    function setupShare() {
        const btn = EMNX.$('[data-share]');
        if (!btn) return;
        btn.addEventListener('click', function () {
            const data = {
                title: document.title,
                url: canonical() || location.href,
                text: document.querySelector('.anime-title') ? document.querySelector('.anime-title').textContent + ' on EmnexAnime' : ''
            };
            if (navigator.share) {
                navigator.share(data).catch(function () { /* user cancelled */ });
            } else if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(data.url).then(function () {
                    EMNX.toast('Link copied to clipboard');
                }, function () {
                    EMNX.toast('Could not copy the link.', 'error');
                });
            } else {
                EMNX.toast(data.url);
            }
        });
        function canonical() {
            const link = document.head.querySelector('link[rel="canonical"]');
            return link ? link.href : null;
        }
    }

    /* --------------------------------------------------- continue CTA tweak */
    function setupContinueCta() {
        const section = EMNX.$('[data-episodes][data-id]');
        if (!section) return;
        const id = Number(section.dataset.id);
        const saved = EMNX.progress.get(id);
        const cta = document.querySelector('.anime-actions .btn--primary');
        if (saved && cta && saved.percent < 96) {
            cta.href = (EMNX.base || '') + '/watch/' + id + '/' + Number(saved.episode);
            cta.innerHTML = EMNX.iconBtn('play', 17) + ' Continue · Episode ' + Number(saved.episode);
        }
    }

    /* ------------------------------------------------------------ episodes */
    function setupEpisodes(section) {
        const animeId = Number(section.dataset.id);
        const list = EMNX.$('[data-ep-list]', section);
        const moreBtn = EMNX.$('[data-ep-more]', section);
        const note = EMNX.$('[data-ep-note]', section);
        const jump = EMNX.$('[data-ep-jump]', section);
        const countBadge = document.querySelector('[data-ep-count]');
        if (!list) return;

        const BATCH = 100;
        let all = null;         // [{number,title,thumbnail}]
        let rendered = 0;       // how many rows currently appended
        let loading = false;

        const PRELOAD_PX = 800;
        const nearViewport = function () {
            const rect = section.getBoundingClientRect();
            return rect.top - (window.innerHeight || 800) < PRELOAD_PX && rect.bottom > -PRELOAD_PX;
        };

        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver(function (entries) {
                if (!entries.some(function (e) { return e.isIntersecting; })) return;
                io.disconnect();
                detachFallback();
                load();
            }, { rootMargin: PRELOAD_PX + 'px 0px' });
            io.observe(section);
        }

        // Deterministic fallback for environments where scrolling/IO is
        // throttled (e.g. embedded previews): position check on scroll + once
        // at startup.
        let fallbackTimer = null;
        function fallbackCheck() {
            if (all || loading) { detachFallback(); return; }
            if (nearViewport()) { detachFallback(); load(); }
        }
        function detachFallback() {
            window.removeEventListener('scroll', fallbackCheck, true);
            if (fallbackTimer) { clearInterval(fallbackTimer); fallbackTimer = null; }
        }
        window.addEventListener('scroll', fallbackCheck, true);
        fallbackTimer = setInterval(fallbackCheck, 1200);
        fallbackCheck();

        function load() {
            if (loading || all) return;
            loading = true;
            EMNX.fetchJSON({ action: 'episodes', id: String(animeId) })
                .then(function (res) {
                    loading = false;
                    all = (res.data.episodes || []).map(function (ep, i) {
                        return {
                            number: Number(ep.number || i + 1),
                            title: String(ep.title || '').trim(),
                            thumbnail: String(ep.thumbnail || '')
                        };
                    }).sort(function (a, b) { return a.number - b.number; });
                    if (all.length) {
                        section.dataset.total = String(all.length);
                        if (countBadge) countBadge.textContent = String(all.length);
                    }
                    renderBatch();
                })
                .catch(function (err) {
                    loading = false;
                    if (note) note.textContent = 'Could not load the episode list: ' + err.message;
                });
        }

        function rowHTML(ep) {
            const href = (EMNX.base || '') + '/watch/' + animeId + '/' + ep.number;
            const watchedProgress = EMNX.episodeProgressKey && EMNX.progress.episode(animeId, ep.number);
            const done = watchedProgress && watchedProgress.dur > 0 && watchedProgress.pos / watchedProgress.dur > 0.92;
            return '<a class="ep-row' + (done ? ' is-watched' : '') + '" href="' + EMNX.esc(href) + '">'
                + '<span class="ep-row__num">' + ep.number + '</span>'
                + '<span class="ep-row__thumb">'
                + '<span class="card__fallback" aria-hidden="true">' + ep.number + '</span>'
                + (ep.thumbnail ? '<img src="' + EMNX.esc(ep.thumbnail) + '" alt="" loading="lazy" decoding="async">' : '')
                + '</span>'
                + '<span class="ep-row__text">'
                + '<span class="ep-row__title">' + EMNX.esc(ep.title || 'Episode ' + ep.number) + '</span>'
                + '<span class="ep-row__sub">Episode ' + ep.number + (done ? ' · watched' : '') + '</span>'
                + '</span>'
                + '<span class="ep-row__play"> style="display:none"' + EMNX.iconBtn('play', 16) + '</span>'
                + '<span class="ep-row__chevron">' + chevron() + '</span>'
                + '</a>';
        }

        function chevron() {
            return '<svg class="icon" width="15" height="15" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }

        function renderBatch() {
            if (!all) return;
            const from = rendered;
            const to = Math.min(from + BATCH, all.length);
            if (from >= to) return;
            if (rendered === 0) list.innerHTML = '';
            const frag = document.createElement('template');
            frag.innerHTML = all.slice(from, to).map(function (ep) {
                return rowHTML(ep);
            }).join('');
            while (frag.content.firstChild) list.appendChild(frag.content.firstChild);
            rendered = to;

            if (moreBtn) {
                if (rendered < all.length) {
                    moreBtn.hidden = false;
                    moreBtn.textContent = 'Load episodes ' + (rendered + 1) + '–' + Math.min(rendered + BATCH, all.length);
                } else {
                    moreBtn.hidden = true;
                }
            }
            if (note) note.textContent = 'Showing ' + rendered + ' of ' + all.length + ' episodes';
        }

        // RowHTML renders absolute episode numbers already.
        moreBtn && moreBtn.addEventListener('click', renderBatch);

        jump && jump.addEventListener('change', function () {
            const target = parseInt(jump.value, 10);
            if (!all || !(target > 0)) return;
            const position = all.findIndex(function (ep) { return ep.number === target; });
            if (position === -1) {
                EMNX.toast('Episode ' + target + ' is not in the list.', 'error');
                return;
            }
            enoughFor(position);
            const rows = EMNX.$$('.ep-row', list);
            const row = rows[position];
            if (row) {
                row.scrollIntoView({ block: 'center', behavior: 'smooth' });
                row.classList.add('is-flash');
                setTimeout(function () { row.classList.remove('is-flash'); }, 1600);
            }
        });

        // Render batches until `position` is visible.
        function enoughFor(position) {
            while (rendered < Math.min(Math.floor((position + BATCH) / BATCH) * BATCH, all.length)) {
                renderBatch();
            }
        }
    }
})();
