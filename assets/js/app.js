/* =============================================================================
 * EmnexAnime — app.js
 * Shared front-end runtime for every page. No dependencies, no build step.
 *
 * Exposes the `EMNX` namespace:
 *   - DOM + string utilities               (esc, el, fmtTime, …)
 *   - Internal-API fetch wrapper           (fetchJSON)
 *   - localStorage layer                   (store)
 *   - Watchlist + progress state           (watchlist, progress)
 *   - Card / suggestion renderers          (cardHTML, progressCardHTML)
 *   - Toast + modal helpers                (toast, modal)
 *
 * Behaviours set up here (once, on every page):
 *   - header search suggestions + mobile search toggle
 *   - watchlist toggle buttons (event delegation + state sync)
 *   - "continue watching" rows on any page that has one
 *   - horizontal row scrollers with arrow buttons
 *   - header scroll state, image fallbacks, "/" keyboard shortcut
 * ========================================================================== */
window.EMNX = window.EMNX || {};
(function () {
    'use strict';

    const EMNX = window.EMNX;

    /* ------------------------------------------------------- dom utilities */
    EMNX.$ = (sel, root) => (root || document).querySelector(sel);
    EMNX.$$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));

    EMNX.esc = function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };

    /* Formats seconds as h:mm:ss (>=1h) or m:ss. */
    EMNX.fmtTime = function (seconds) {
        const s = Math.max(0, Math.floor(Number(seconds) || 0));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const r = s % 60;
        const mm = (h > 0 ? String(m).padStart(2, '0') : String(m));
        return h > 0 ? h + ':' + mm + ':' + String(r).padStart(2, '0') : mm + ':' + String(r).padStart(2, '0');
    };

    /* Compact countdown text: "2:05:31", "8:12", or "any second". */
    EMNX.fmtCountdown = function (seconds) {
        const s = Math.max(0, Math.floor(Number(seconds) || 0));
        if (s < 60) return 'any second';
        return EMNX.fmtTime(s);
    };

    /* --------------------------------------------------------- api client */
    /**
     * Fetch an internal api.php action and unwrap {ok, data, meta}.
     * Resolves to the `data` payload; rejects with an Error whose message
     * comes from the server (safe to show to users).
     */
    EMNX.fetchJSON = function (params, options) {
        const o = options || {};
        const qs = new URLSearchParams(params || {});
        const url = EMNX.api + '?' + qs.toString();
        const init = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
        if (o.signal) init.signal = o.signal;
        return fetch(url, init).then(function (res) {
            return res.json().then(function (body) {
                if (!res.ok || !body || body.ok !== true) {
                    const message = (body && body.error) ? String(body.error) : ('Request failed (HTTP ' + res.status + ')');
                    const err = new Error(message);
                    err.status = res.status;
                    throw err;
                }
                body.meta = body.meta || {};
                return body;
            }, function () {
                throw new Error('The server sent an invalid response.');
            });
        });
    };

    /* ------------------------------------------------------- localStorage */
    EMNX.store = {
        get(key, fallback) {
            try {
                const raw = localStorage.getItem(key);
                if (raw == null) return fallback;
                const value = JSON.parse(raw);
                return value == null ? fallback : value;
            } catch (e) { return fallback; }
        },
        set(key, value) {
            try {
                if (value === undefined || value === null) {
                    localStorage.removeItem(key);
                } else {
                    localStorage.setItem(key, JSON.stringify(value));
                }
            } catch (e) { /* private mode / quota — non fatal */ }
        },
        size(obj) {
            return obj && typeof obj === 'object' ? Object.keys(obj).length : 0;
        }
    };

    /* --------------------------------------------------------- watchlist */
    /* Shape: [{id, title, poster, year, format, score, savedAt}] */
    EMNX.watchlist = {
        all() {
            const list = EMNX.store.get(EMNX.watchlistKey, []);
            return Array.isArray(list) ? list.filter(function (e) { return e && e.id; }) : [];
        },
        has(id) {
            return EMNX.watchlist.all().some(function (e) { return Number(e.id) === Number(id); });
        },
        find(id) {
            return EMNX.watchlist.all().find(function (e) { return Number(e.id) === Number(id); }) || null;
        },
        add(entry) {
            entry.id = Number(entry.id);
            entry.savedAt = Date.now();
            const list = EMNX.watchlist.all().filter(function (e) { return Number(e.id) !== entry.id; });
            list.push(entry);
            EMNX.watchlist._write(list);
            return list;
        },
        remove(id) {
            const list = EMNX.watchlist.all().filter(function (e) { return Number(e.id) !== Number(id); });
            EMNX.watchlist._write(list);
            return list;
        },
        toggle(entry) {
            return EMNX.watchlist.has(entry.id) ? (EMNX.watchlist.remove(entry.id), null) : (EMNX.watchlist.add(entry), entry);
        },
        clear() {
            EMNX.watchlist._write([]);
        },
        count() { return EMNX.watchlist.all().length; },
        syncBadges() {
            const n = EMNX.watchlist.count();
            EMNX.$$('[data-watchlist-count]').forEach(function (badge) {
                badge.textContent = n > 99 ? '99+' : String(n);
                badge.hidden = n === 0;
            });
        },
        syncButtons() {
            EMNX.$$('[data-watchlist-toggle][data-id]').forEach((btn) => {
                const saved = EMNX.watchlist.has(btn.dataset.id);
                const icon = EMNX.$('[data-watchlist-icon]', btn);
                const label = EMNX.$('[data-watchlist-label]', btn);
                btn.classList.toggle('is-saved', saved);
                btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
                if (icon) {
                    icon.innerHTML = saved
                        ? '<svg class="icon" width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 4.75A1.75 1.75 0 0 1 7.75 3h8.5A1.75 1.75 0 0 1 18 4.75V21l-6-3.6L6 21V4.75Z" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>'
                        : (icon._plusHTML || EMNX.iconBtn('plus', 17));
                }
                if (label) label.textContent = saved ? 'In watchlist' : 'Add to watchlist';
            });
        },
        _write(list) {
            EMNX.store.set(EMNX.watchlistKey, list.slice(-500));
            EMNX.watchlist.syncBadges();
            EMNX.watchlist.syncButtons();
        }
    };

    /* ----------------------------------------------------------- progress */
    /* `progressKey`   → { "<animeId>": {animeId, title, poster, episode, type, pos, dur, percent, updatedAt} }
       `epProgressKey` → { "<animeId>:<ep>": {pos, dur, updatedAt} } */
    EMNX.progress = {
        all() {
            const map = EMNX.store.get(EMNX.progressKey, {});
            return (map && typeof map === 'object') ? map : {};
        },
        recent(limit) {
            const entries = Object.keys(EMNX.progress.all()).map(function (k) { return EMNX.progress.all()[k]; });
            entries.sort(function (a, b) { return (b.updatedAt || 0) - (a.updatedAt || 0); });
            return entries.filter(function (e) { return e && e.animeId && e.percent > 0; }).slice(0, limit || 12);
        },
        get(animeId) {
            return EMNX.progress.all()[String(Number(animeId))] || null;
        },
        save(entry) {
            const map = EMNX.progress.all();
            entry.updatedAt = Date.now();
            map[String(Number(entry.animeId))] = entry;
            // Keep only the 40 most recent entries.
            const ids = Object.keys(map).sort(function (a, b) {
                return (map[b].updatedAt || 0) - (map[a].updatedAt || 0);
            });
            const trimmed = {};
            ids.slice(0, 40).forEach(function (id) { trimmed[id] = map[id]; });
            EMNX.store.set(EMNX.progressKey, trimmed);
        },
        remove(animeId) {
            const map = EMNX.progress.all();
            delete map[String(Number(animeId))];
            EMNX.store.set(EMNX.progressKey, map);
            EMNX.progress.trimEpisodes();
        },
        clear() {
            EMNX.store.set(EMNX.progressKey, {});
            EMNX.store.set(EMNX.episodeProgressKey, {});
        },
        episode(animeId, ep) {
            return EMNX.store.get(EMNX.episodeProgressKey, {})[Number(animeId) + ':' + Number(ep)] || null;
        },
        saveEpisode(animeId, ep, pos, dur) {
            const map = EMNX.store.get(EMNX.episodeProgressKey, {});
            map[Number(animeId) + ':' + Number(ep)] = {
                pos: Math.floor(pos || 0),
                dur: Math.floor(dur || 0),
                updatedAt: Date.now()
            };
            EMNX.store.set(EMNX.episodeProgressKey, map);
        },
        // Episodes for dropped/restarted shows are pruned on access.
        trimEpisodes() {
            const eps = EMNX.store.get(EMNX.episodeProgressKey, {});
            const alive = {};
            const map = EMNX.progress.all();
            Object.keys(eps).forEach(function (key) {
                const animeId = key.split(':')[0];
                if (map[animeId]) alive[key] = eps[key];
            });
            EMNX.store.set(EMNX.episodeProgressKey, alive);
        }
    };

    /* -------------------------------------------------------- renderers */
    EMNX.iconBtn = function (name, size) {
        const icons = {
            play: '<path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" fill="currentColor"/>',
            star: '<path d="m12 2.6 2.9 5.88 6.49.94-4.7 4.58 1.11 6.46L12 17.41 6.2 20.46l1.11-6.46-4.7-4.58 6.49-.94L12 2.6Z" fill="currentColor"/>',
            plus: '<path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
        };
        const path = icons[name] || icons.plus;
        return '<svg class="icon" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" aria-hidden="true">' + path + '</svg>';
    };

    /**
     * Mirror of the PHP `View::card()` markup so JS-rendered cards match
     * server-rendered cards exactly (same CSS hooks).
     */
    EMNX.cardHTML = function (item, opts) {
        const o = opts || {};
        const id = Number(item.id || 0);
        if (!id) return '';
        const title = item.title || item.titles && (item.titles.english || item.titles.romaji || item.titles.native) || 'Untitled';
        const poster = item.poster || item.image || '';
        const accent = /^#[0-9a-fA-F]{3,8}$/.test(item.color || '') ? item.color : '#39d353';
        const score = item.rating;
        const format = item.format || '';
        const year = item.year || '';
        const eps = item.episodes;
        let meta = [];
        if (year) meta.push(String(year));
        if (format) meta.push(String(format).replace(/_/g, ' ').toLowerCase().charAt(0).toUpperCase() + item.format.slice(1).toLowerCase());
        if (Number(eps) > 0) meta.push(Number(eps) + ' eps');
        else if (item.status === 'NOT_YET_RELEASED') meta.push('Upcoming');

        const base = (EMNX.base || '') + '/anime/';
        const watch = (EMNX.base || '') + '/watch/' + id + '/1';

        const fmt = format
            ? '<span class="card__format">' + EMNX.esc(formatLabel(format)) + '</span>'
            : '';
        const scoreHtml = (score !== null && score !== undefined && score !== '' && !isNaN(Number(score)))
            ? '<span class="card__score">' + EMNX.iconBtn('star', 12) + Number(score).toFixed(1) + '</span>'
            : '';
        const prog = (o.progress != null && !isNaN(Number(o.progress)))
            ? '<span class="card__progress"><i style="width:' + Math.max(0, Math.min(100, Number(o.progress))) + '%"></i></span>'
            : '';
        const badge = o.badge
            ? '<span class="card__badge">' + EMNX.esc(o.badge) + '</span>'
            : '';
        const remove = o.remove
            ? '<button class="card__remove" type="button" data-remove-id="' + id + '" aria-label="Remove ' + EMNX.esc(title) + ' from watchlist">' +
              '<svg class="icon" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>'
            : '';

        return '<article class="card' + (o.extraClass ? ' ' + EMNX.esc(o.extraClass) : '') + '" style="--card-accent:' + EMNX.esc(accent) + '">'
            + '<a class="card__media" href="' + EMNX.esc(base + id) + '" aria-label="' + EMNX.esc(title) + '">'
            + '<span class="card__fallback" aria-hidden="true">' + EMNX.esc(initials(title)) + '</span>'
            + (poster ? '<img src="' + EMNX.esc(poster) + '" alt="' + EMNX.esc(title) + ' poster" loading="lazy" decoding="async">' : '')
            + '<span class="card__shade"></span>'
            + badge + fmt + scoreHtml + prog
            + '</a>'
            + remove
            + (o.hidePlay ? '' : '<a class="card__play" href="' + EMNX.esc(o.playHref || watch) + '" aria-label="Play ' + EMNX.esc(title) + '" title="Play ' + EMNX.esc(o.playEp ? 'episode ' + o.playEp : 'episode 1') + '">' + EMNX.iconBtn('play', 16) + '</a>')
            + '<div class="card__body">'
            + '<a class="card__title" href="' + EMNX.esc(base + id) + '">' + EMNX.esc(title) + '</a>'
            + (o.subtitle ? '<p class="card__meta">' + EMNX.esc(o.subtitle) + '</p>'
                : (meta.length ? '<p class="card__meta">' + EMNX.esc(meta.join(' · ')) + '</p>' : ''))
            + '</div>'
            + '</article>';
    };

    function formatLabel(format) {
        const map = { TV: 'TV', TV_SHORT: 'TV Short', MOVIE: 'Movie', OVA: 'OVA', ONA: 'ONA', SPECIAL: 'Special', MUSIC: 'Music' };
        return map[String(format).toUpperCase()] || (format ? format.charAt(0).toUpperCase() + format.slice(1).toLowerCase() : '—');
    }

    function initials(title) {
        const words = String(title).replace(/[^\p{L}\p{N}\s]+/gu, ' ').trim().split(/\s+/);
        let out = '';
        for (const w of words) {
            if (!w) continue;
            out += w.charAt(0).toUpperCase();
            if (out.length >= 2) break;
        }
        return out || 'EA';
    }

    /* A "continue watching" card: same shell but links straight to the
       exact episode + includes a progress bar and badge. */
    EMNX.progressCardHTML = function (entry) {
        const percent = entry.percent != null ? Number(entry.percent) : (entry.dur > 0 ? (entry.pos / entry.dur) * 100 : 0);
        return EMNX.cardHTML({
            id: entry.animeId,
            title: entry.title,
            poster: entry.poster,
            color: entry.color || '',
            format: entry.format || '',
            rating: entry.score || ''
        }, {
            badge: 'EP ' + Number(entry.episode),
            progress: percent,
            playHref: (EMNX.base || '') + '/watch/' + Number(entry.animeId) + '/' + Number(entry.episode),
            playEp: Number(entry.episode),
            subtitle: 'Episode ' + Number(entry.episode) + (percent >= 100 ? ' · finished' : ' · ' + Math.round(Math.min(100, percent)) + '%')
        });
    };

    /* ------------------------------------------------------------- toasts */
    EMNX.toast = function (message, kind) {
        const stack = EMNX.$('[data-toasts]');
        if (!stack) return;
        const node = document.createElement('div');
        node.className = 'toast';
        node.innerHTML = '<span class="toast__icon">' + (kind === 'error'
            ? '<svg class="icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="m8.5 8.5 7 7m0-7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
            : '<svg class="icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.2 4.2L19 6.8" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>') + '</span>'
            + '<span>' + EMNX.esc(message) + '</span>';
        stack.appendChild(node);
        setTimeout(function () {
            node.classList.add('is-out');
            setTimeout(function () { node.remove(); }, 300);
        }, 3200);
    };

    /* -------------------------------------------------------------- modal */
    EMNX.modal = {
        el() { return EMNX.$('[data-modal]'); },
        body() { return EMNX.$('[data-modal-body]'); },
        open(innerHTML, opts) {
            const modal = this.el();
            if (!modal) return;
            const body = this.body();
            body.innerHTML = innerHTML;
            if (opts && opts.wide) modal.classList.add('is-wide');
            else modal.classList.remove('is-wide');
            modal.hidden = false;
            document.body.classList.add('modal-open');
            this._lastFocus = document.activeElement;
            const closer = EMNX.$('[data-modal-close]', modal);
            if (closer) closer.focus();
        },
        close() {
            const modal = this.el();
            if (!modal || modal.hidden) return;
            modal.hidden = true;
            this.body().innerHTML = '';
            document.body.classList.remove('modal-open');
            if (this._lastFocus && this._lastFocus.focus) this._lastFocus.focus();
        },
        isOpen() {
            const modal = this.el();
            return !!modal && !modal.hidden;
        }
    };

    /* =============================================================== setup */

    /* ---- continue-watching rows (home + watchlist pages) ---------------- */
    function setupContinueRows() {
        EMNX.$$('[data-continue][data-continue-scroller]').forEach(function (section) {
            const scroller = EMNX.$('[data-continue-scroller]', section);
            if (!scroller) return;
            const entries = EMNX.progress.recent(14);
            if (!entries.length) return; // stays hidden
            section.hidden = false;
            scroller.innerHTML = entries.map(function (e) { return EMNX.progressCardHTML(e); }).join('');
            const clearBtn = EMNX.$('[data-continue-clear]', section);
            if (clearBtn) {
                clearBtn.hidden = false;
                clearBtn.addEventListener('click', function () {
                    EMNX.progress.clear();
                    section.hidden = true;
                    EMNX.toast('Continue watching history cleared');
                });
            }
        });
    }

    /* ---- header search: suggestions + mobile toggle --------------------- */
    function setupSearch() {
        const wrap = EMNX.$('[data-search]');
        const panel = wrap && EMNX.$('[data-search-panel]', wrap);
        const input = wrap && EMNX.$('[data-search-input]', wrap);
        const toggle = EMNX.$('[data-search-toggle]');
        if (!wrap || !panel || !input) return;

        const form = input.closest('form');
        let debounce = null;
        let controller = null;
        let focusIndex = -1;

        function resultsFrom(res) {
            const items = (res.data.items || []).slice(0, 8);
            if (!items.length) {
                panel.innerHTML = '<div class="search-result__empty">No titles matched “' + EMNX.esc(input.value.trim()) + '”.</div>';
                return;
            }
            panel.innerHTML = items.map(function (item) {
                const title = item.title || (item.titles && (item.titles.english || item.titles.romaji)) || 'Untitled';
                return '<a class="search-result" href="' + EMNX.esc(EMNX.base + '/anime/' + Number(item.id)) + '">'
                    + '<span class="search-result__poster">'
                    + '<span class="card__fallback" aria-hidden="true">' + EMNX.esc(initials(title)) + '</span>'
                    + (item.poster ? '<img src="' + EMNX.esc(item.poster) + '" alt="" loading="lazy" decoding="async">' : '')
                    + '</span>'
                    + '<span class="search-result__body">'
                    + '<span class="search-result__title">' + EMNX.esc(title) + '</span>'
                    + '<span class="search-result__meta">' + EMNX.esc(suggestMeta(item)) + '</span>'
                    + '</span></a>';
            }).join('')
            + '<a class="search__hint" href="' + EMNX.esc(EMNX.searchUrl + '?q=' + encodeURIComponent(input.value.trim())) + '">'
            + 'Search all results <kbd>Enter ↵</kbd></a>';
        }

        function suggestMeta(item) {
            const bits = [];
            if (item.year) bits.push(item.year);
            if (item.format) bits.push(formatLabel(item.format));
            if (item.episodes > 0) bits.push(item.episodes + ' eps');
            else if (item.status) bits.push(item.status === 'RELEASING' ? 'Airing' : formatLabel(item.status));
            return bits.join(' · ') || 'Anime';
        }

        function renderError(message) {
            panel.innerHTML = '<div class="search-result__empty">' + EMNX.esc(message) + '</div>';
        }

        function closePanel() {
            panel.hidden = true;
            panel.innerHTML = '';
            focusIndex = -1;
            const header = EMNX.$('[data-site-header]');
            if (header) header.classList.remove('is-searching');
            toggle && toggle.setAttribute('aria-expanded', 'false');
        }

        function openPanel() {
            const header = EMNX.$('[data-site-header]');
            if (header) header.classList.add('is-searching');
            panel.hidden = false;
        }

        function request() {
            const q = input.value.trim();
            if (q.length < 2) { panel.hidden = true; panel.innerHTML = ''; return; }
            if (controller) controller.abort();
            controller = new AbortController();
            EMNX.fetchJSON({ action: 'feed', type: 'search', q: q, page: 1, perPage: 8 }, { signal: controller.signal })
                .then(function (res) {
                    const current = input.value.trim();
                    if (current !== q || current.length < 2) return; // stale
                    openPanel();
                    resultsFrom(res);
                    focusIndex = -1;
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    openPanel();
                    renderError(err.message || 'Search is unavailable right now.');
                });
        }

        input.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(request, 320);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 2 && panel.childNodes.length) openPanel();
        });

        input.addEventListener('keydown', function (event) {
            const links = EMNX.$$('a.search-result', panel);
            if (event.key === 'ArrowDown' && links.length) {
                event.preventDefault();
                focusIndex = Math.min(focusIndex + 1, links.length - 1);
            } else if (event.key === 'ArrowUp' && links.length) {
                event.preventDefault();
                focusIndex = Math.max(focusIndex - 1, 0);
            } else if (event.key === 'Escape') {
                closePanel();
                input.blur();
                return;
            } else if (event.key === 'Enter') {
                if (links[focusIndex]) { event.preventDefault(); links[focusIndex].click(); }
                return; // normal submit otherwise
            } else {
                return;
            }
            links.forEach(function (link, i) { link.classList.toggle('is-selected', i === focusIndex); });
            if (links[focusIndex]) links[focusIndex].scrollIntoView({ block: 'nearest' });
        });

        panel.addEventListener('click', function (event) {
            if (event.target.closest('a.search-result')) closePanel();
        });

        // Close when clicking outside or when focus leaves the widget.
        document.addEventListener('click', function (event) {
            if (wrap.contains(event.target)) return;
            closePanel();
        });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                if (EMNX.modal.isOpen()) return;
                if (document.activeElement !== input && !panel.contains(document.activeElement)) {
                    // Only auto-close, never erase content while suggestions are open.
                    if (panel.hidden) return;
                    if (!wrap.contains(document.activeElement)) panel.hidden = true;
                }
            }, 180);
        });

        /* mobile search toggle */
        if (toggle) {
            toggle.addEventListener('click', function () {
                const header = EMNX.$('[data-site-header]');
                const open = header.classList.contains('is-searching');
                header.classList.toggle('is-searching', !open);
                toggle.setAttribute('aria-expanded', String(!open));
                if (!open) { input.focus(); input.select(); }
            });
        }
    }

    /* ---- "/" focuses search unless the user is typing somewhere ---- */
    function setupShortcut() {
        document.addEventListener('keydown', function (event) {
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
            const tag = (document.activeElement && document.activeElement.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (EMNX.modal.isOpen()) return;
            const input = EMNX.$('[data-search-input]');
            if (!input) return;
            event.preventDefault();
            input.focus();
            input.select();
            if (window.matchMedia('(max-width: 980px)').matches) {
                const header = EMNX.$('[data-site-header]');
                if (header) header.classList.add('is-searching');
                input.focus();
            }
        });
    }

    /* ---- watchlist toggle buttons, page-wide ---- */
    function setupWatchlistButtons() {
        EMNX.$$('[data-watchlist-toggle][data-id]').forEach(function (btn) {
            const icon = EMNX.$('[data-watchlist-icon]', btn);
            if (icon) icon._plusHTML = icon.innerHTML;
        });
        document.addEventListener('click', function (event) {
            const btn = event.target.closest('[data-watchlist-toggle][data-id]');
            if (!btn) return;
            event.preventDefault();
            const entry = {
                id: Number(btn.dataset.id),
                title: btn.dataset.title || 'Untitled',
                poster: btn.dataset.poster || '',
                year: btn.dataset.year || '',
                format: btn.dataset.format || '',
                score: btn.dataset.score || ''
            };
            const currentlySaved = !!EMNX.watchlist.find(entry.id);
            EMNX.watchlist.toggle(entry);
            if (currentlySaved) {
                EMNX.progress.remove(entry.id);
                EMNX.toast('Removed from watchlist');
            } else {
                EMNX.toast('Saved “' + entry.title + '” to your watchlist');
            }
        });
        EMNX.watchlist.syncBadges();
        EMNX.watchlist.syncButtons();
        window.addEventListener('pageshow', function () { EMNX.watchlist.syncBadges(); EMNX.watchlist.syncButtons(); });
    }

    /* ---- horizontal scrollers with arrows ---- */
    function setupRowScrollers() {
        EMNX.$$('[data-rowblock]').forEach(function (block) {
            const scroller = EMNX.$('[data-row-scroller]', block);
            const prev = EMNX.$('[data-row-prev]', block);
            const next = EMNX.$('[data-row-next]', block);
            if (!scroller || !prev || !next) return;

            function update() {
                const max = scroller.scrollWidth - scroller.clientWidth;
                prev.disabled = scroller.scrollLeft <= 4;
                next.disabled = scroller.scrollLeft >= max - 4;
            }
            function step(dir) {
                scroller.scrollBy({ left: dir * Math.round(scroller.clientWidth * 0.85), behavior: 'smooth' });
            }
            prev.addEventListener('click', function () { step(-1); });
            next.addEventListener('click', function () { step(1); });
            scroller.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
            window.addEventListener('resize', update);
            update();
        });
    }

    /* ---- misc page chrome ---- */
    function setupChrome() {
        const header = EMNX.$('[data-site-header]');
        function onScroll() {
            if (header) header.classList.toggle('is-scrolled', window.scrollY > 8);
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        // Broken images reveal the initials fallback behind them.
        document.addEventListener('error', function (event) {
            const img = event.target;
            if (!(img instanceof HTMLImageElement)) return;
            img.style.opacity = '0';
            img.addEventListener('transitionend', function () { img.style.display = 'none'; }, { once: true });
        }, true);

        // Modal wiring.
        document.addEventListener('click', function (event) {
            const modal = EMNX.modal.el();
            if (!modal || modal.hidden) return;
            if (event.target.closest('[data-modal-close]') || event.target.classList.contains('modal__backdrop')) {
                EMNX.modal.close();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') EMNX.modal.close();
        });
    }

    /* ---- boot ---- */
    document.addEventListener('DOMContentLoaded', function () {
        setupChrome();
        setupSearch();
        setupShortcut();
        setupWatchlistButtons();
        setupContinueRows();
        setupRowScrollers();
    });
})();
