/* =============================================================================
 * EmnexAnime — schedule.js
 * Renders the 7-day airing calendar from server-embedded JSON
 * (#schedule-data). All times are converted to the visitor's local timezone;
 * items within ±2 minutes of airing are marked live with a countdown.
 *
 * Data contract (PHP → JS):
 *   [{airingAt: <unix seconds>, episode: 6,
 *     anime: {id, title|titles, poster, format, year, episodes|status…}}]
 * ========================================================================== */
(function () {
    'use strict';
    const EMNX = window.EMNX;

    document.addEventListener('DOMContentLoaded', function () {
        const root = EMNX.$('[data-schedule]');
        const dataNode = document.getElementById('schedule-data');
        if (!root || !dataNode || !EMNX.$('[data-schedule-tabs]', root)) return;

        let items = [];
        try { items = JSON.parse(dataNode.textContent || '[]'); } catch (e) { items = []; }
        items = (Array.isArray(items) ? items : []).filter(function (it) {
            return it && it.airingAt && it.anime && Number(it.anime.id);
        });
        if (!items.length) return;

        const tabsHost = EMNX.$('[data-schedule-tabs]', root);
        const daysHost = EMNX.$('[data-schedule-days]', root);

        /* ---- group by local calendar day ---- */
        const DAY_MS = 86400000;
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const groups = new Map(); // key: local midnight timestamp → {label, date, items[]}

        items.forEach(function (item) {
            const when = new Date(Number(item.airingAt) * 1000);
            const midnight = new Date(when); midnight.setHours(0, 0, 0, 0);
            const key = String(midnight.getTime());
            if (!groups.has(key)) {
                const offset = Math.round((midnight - today) / DAY_MS);
                groups.set(key, {
                    ts: midnight.getTime(),
                    offset: offset,
                    items: []
                });
            }
            groups.get(key).items.push({
                when: when,
                episode: Number(item.episode || 1),
                anime: item.anime
            });
        });

        const days = Array.from(groups.values()).sort(function (a, b) { return a.ts - b.ts; });
        const weekday = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        /* ---- tabs ---- */
        days.forEach(function (day, i) {
            const date = new Date(day.ts);
            const btn = document.createElement('button');
            btn.className = 'schedule-tab' + (i === 0 ? ' is-active' : '');
            btn.type = 'button';
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
            btn.innerHTML = '<span>' + EMNX.esc(
                day.offset === 0 ? 'Today' : (day.offset === 1 ? 'Tmrw' : weekday[date.getDay()])
            ) + '</span><b>' + date.getDate() + '</b>';
            btn.addEventListener('click', function () { activate(i); });
            tabsHost.appendChild(btn);
        });

        /* ---- days ---- */
        days.forEach(function (day, i) {
            const date = new Date(day.ts);
            const labelDate = weekday[date.getDay()] + ' · ' + monthNames[date.getMonth()] + ' ' + date.getDate()
                + (day.offset === 0 ? ' — today' : '');

            day.items.sort(function (a, b) { return a.when - b.when; });

            const block = document.createElement('div');
            block.className = 'schedule-day' + (i === 0 ? ' is-active' : '');
            block.hidden = i !== 0;
            block.innerHTML = '<div class="schedule-day__date"><span>' + EMNX.esc(labelDate) + '</span></div>'
                + '<div class="schedule-items" data-schedules></div>';

            const itemsHost = block.querySelector('[data-schedules]');
            day.items.forEach(function (entry) {
                itemsHost.appendChild(itemNode(entry));
            });
            daysHost.appendChild(block);
        });

        function activate(index) {
            EMNX.$$('.schedule-tab', tabsHost).forEach(function (btn, k) {
                btn.classList.toggle('is-active', k === index);
                btn.setAttribute('aria-selected', k === index ? 'true' : 'false');
            });
            EMNX.$$('.schedule-day', daysHost).forEach(function (block, k) {
                block.classList.toggle('is-active', k === index);
                block.hidden = k !== index;
            });
            const target = EMNX.$$('.schedule-day', daysHost)[index];
            if (target) target.hidden = false;
        }

        /* ---- item ---- */
        function itemNode(entry) {
            const anime = entry.anime;
            const animeId = Number(anime.id);
            const title = anime.title
                || (anime.titles && (anime.titles.english || anime.titles.romaji || anime.titles.native))
                || 'Untitled';
            const diff = Math.round((entry.when - Date.now()) / 1000);
            const liveWindow = diff <= 120 && diff >= -3600; // soon, or just aired

            const node = document.createElement('a');
            node.className = 'schedule-item' + (liveWindow ? ' schedule-item--live' : '');
            node.href = (EMNX.base || '') + '/anime/' + animeId;
            node.dataset.airingAt = String(entry.when.getTime() ? entry.when.getTime() : 0);
            node.dataset.diff = String(diff);
            node.dataset.episode = String(entry.episode);
            node.dataset.title = title;

            const fallback = initials(title);
            const formatBits = [];
            if (anime.format) formatBits.push(formatLabel(anime.format));
            if (anime.year) formatBits.push(String(anime.year));
            if (!anime.episodes && anime.status === 'RELEASING') formatBits.push('Airing');
            if (anime.episodes > 0) formatBits.push(anime.episodes + ' eps');

            node.innerHTML = '<span class="schedule-item__time">' + EMNX.esc(hhmm(entry.when)) + '</span>'
                + '<span class="schedule-item__poster">'
                + '<span class="card__fallback" aria-hidden="true">' + EMNX.esc(fallback) + '</span>'
                + (anime.poster ? '<img src="' + EMNX.esc(anime.poster) + '" alt="" loading="lazy" decoding="async">' : '')
                + '</span>'
                + '<span class="schedule-item__body">'
                + '<span class="schedule-item__title">' + EMNX.esc(title) + '</span>'
                + '<span class="schedule-item__meta">' + EMNX.esc(formatBits.join(' · ')) + '</span>'
                + '</span>'
                + '<span class="schedule-item__ep">EP ' + entry.episode + '</span>'
                + '<span class="schedule-item__countdown" data-cd>' + countdown(entry) + '</span>';

            // Catalog watch link on hover play? Keep simple: the whole card
            // links to the detail page; the user picks the episode there.
            return node;
        }

        function countdown(entry) {
            const diff = Math.round((entry.when - Date.now()) / 1000);
            if (diff <= 0) return 'airing';
            if (diff < 3600) return 'in ' + Math.floor(diff / 60) + 'm';
            if (diff < 86400) return 'in ' + Math.floor(diff / 3600) + 'h';
            return pretty(diff);
        }
        function pretty(s) {
            const d = Math.floor(s / 86400);
            return d + 'd';
        }
        function hhmm(date) {
            return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
        }
        function initials(title) {
            const words = String(title).replace(/[^\p{L}\p{N}\s]+/gu, ' ').trim().split(/\s+/);
            let out = '';
            for (const w of words) { if (!w) continue; out += w.charAt(0).toUpperCase(); if (out.length >= 2) break; }
            return out || 'EA';
        }
        function formatLabel(format) {
            const map = { TV: 'TV', TV_SHORT: 'TV Short', MOVIE: 'Movie', OVA: 'OVA', ONA: 'ONA', SPECIAL: 'Special', MUSIC: 'Music' };
            const key = String(format).toUpperCase();
            return map[key] || (format ? format.charAt(0) + format.slice(1).toLowerCase() : '—');
        }

        /* ---- keep countdown labels fresh ---- */
        let tick = 0;
        setInterval(function () {
            tick++;
            EMNX.$$('.schedule-item', daysHost).forEach(function (node) {
                const when = Number(node.dataset.airingAt || 0);
                if (!when) return;
                const entry = { when: new Date(when) };
                const cd = node.querySelector('[data-cd]');
                const diff = Math.round((entry.when - Date.now()) / 1000);
                if (diff <= 120 && diff >= -3600) node.classList.add('schedule-item--live');
                if (cd) {
                    const fresh = countdown(entry);
                    if (fresh !== cd.textContent) cd.textContent = fresh;
                }
                if (diff > 86400 && tick % 30 !== 0) return; // heavy update rarely needed
            });
        }, 30000);
    });
})();
