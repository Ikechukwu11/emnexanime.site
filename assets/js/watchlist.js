/* =============================================================================
 * EmnexAnime — watchlist.js
 * Render the locally-stored watchlist onto the /watchlist page.
 * State lives in localStorage (EMNX.watchlist), written by app.js toggles and
 * the player. Nothing here talks to the server.
 * ========================================================================== */
(function () {
    'use strict';
    const EMNX = window.EMNX;

    document.addEventListener('DOMContentLoaded', function () {
        const grid   = EMNX.$('[data-watchlist-grid]');
        const empty  = EMNX.$('[data-watchlist-empty]');
        const summary= EMNX.$('[data-watchlist-summary]');
        const clearBtn = EMNX.$('[data-watchlist-clear]');
        if (!grid) return;

        function render() {
            const list = EMNX.watchlist.all().sort(function (a, b) {
                return (b.savedAt || 0) - (a.savedAt || 0);
            });

            grid.innerHTML = list.map(function (entry, i) {
                return EMNX.cardHTML(entry, {
                    remove: true,
                    playEp: 1,
                    // prefer the saved poster/title attributes
                });
            }).join('');

            if (empty) empty.hidden = list.length > 0;
            if (summary) {
                summary.textContent = list.length === 0
                    ? '0 titles saved'
                    : list.length + (list.length === 1 ? ' title saved' : ' titles saved');
            }
            if (clearBtn) clearBtn.hidden = list.length === 0;

            /* card meta line: emphasize year + format from the stored entry */
            EMNX.$$('.card', grid).forEach(function (card, index) {
                const entry = list[index];
                const play = EMNX.$('.card__play', card);
                if (play && entry) {
                    const progress = EMNX.progress.get(entry.id);
                    if (progress) play.href = (EMNX.base || '') + '/watch/' + entry.id + '/' + Number(progress.episode);
                }
            });
        }

        grid.addEventListener('click', function (event) {
            const btn = event.target.closest('[data-remove-id]');
            if (!btn) return;
            const id = Number(btn.dataset.removeId);
            const entry = EMNX.watchlist.find(id);
            EMNX.watchlist.remove(id);
            if (entry) EMNX.toast('Removed “' + entry.title + '” from your watchlist');
            render();
        });

        clearBtn && clearBtn.addEventListener('click', function () {
            if (clearBtn.dataset.armed !== '1') {
                clearBtn.dataset.armed = '1';
                clearBtn.textContent = 'Really clear everything?';
                setTimeout(function () {
                    clearBtn.dataset.armed = '';
                    clearBtn.textContent = 'Clear watchlist';
                }, 4000);
                return;
            }
            EMNX.watchlist.clear();
            EMNX.progress.clear();
            EMNX.toast('Watchlist cleared');
            clearBtn.dataset.armed = '';
            clearBtn.textContent = 'Clear watchlist';
            render();
        });

        render();
        window.addEventListener('pageshow', render);
    });
})();
