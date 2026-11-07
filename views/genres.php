<?php
declare(strict_types=1);
/** @var array $genres */
?>
<div class="container page-head">
    <p class="eyebrow">Discover</p>
    <h1>Every genre, one click away</h1>
    <p class="page-head__sub">Jump into a mood. Each genre is ranked by popularity and loads infinitely as you scroll.</p>
</div>

<div class="container">
    <?php if ($genres): ?>
        <div class="genre-grid genre-grid--large">
            <?php foreach ($genres as $i => $genre): ?>
                <?= View::genreTile($genre, $i) ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?= View::emptyState('No genres', 'The API did not return any genres. Try again in a moment.', 'grid') ?>
    <?php endif; ?>

    <section class="info-strip">
        <article>
            <h2>How the catalog works</h2>
            <p>Metadata comes from AniList and is cached in SQLite on this server. Streams are resolved on demand, so each episode starts fast without hammering the upstream API.</p>
        </article>
        <article>
            <h2>Start anywhere</h2>
            <p>Not sure what to pick? <a href="<?= e(browse_link('trending')) ?>">Trending</a> is a good default, or hit the <a href="<?= e(url('random')) ?>">surprise me</a> button in the header.</p>
        </article>
    </section>
</div>
