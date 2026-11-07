<?php
declare(strict_types=1);
?>
<div class="container page-head">
    <p class="eyebrow">Your library</p>
    <h1>My watchlist</h1>
    <p class="page-head__sub">Saved in this browser only — no account, no server-side profile. Clear it whenever you want.</p>
</div>

<div class="container">
    <div class="watchlist-toolbar">
        <p class="result-count" data-watchlist-summary>0 titles saved</p>
        <button class="text-btn text-btn--danger" type="button" data-watchlist-clear hidden>Clear watchlist</button>
    </div>

    <div class="grid" data-watchlist-grid></div>

    <div class="empty-state" data-watchlist-empty>
        <span class="empty-state__icon"><?= View::icon('bookmark', 28) ?></span>
        <h3>Your watchlist is empty</h3>
        <p>Use the <b>+</b> button on any anime to save it here. Your picks stay in this browser and survive refreshes.</p>
        <a class="btn btn--primary btn--sm" href="<?= e(browse_link('trending')) ?>">Browse trending</a>
    </div>

    <section class="rowblock rowblock--continue" data-continue hidden>
        <div class="section-head">
            <div class="section-head__text">
                <h2>Continue watching</h2>
                <p>Recently opened episodes.</p>
            </div>
        </div>
        <div class="rowblock__viewport">
            <div class="rowblock__scroller" data-continue-scroller></div>
        </div>
    </section>
</div>
