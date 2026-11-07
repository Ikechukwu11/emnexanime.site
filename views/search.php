<?php
declare(strict_types=1);
/** @var string $q */
/** @var array|null $result */
/** @var array $genres */
/** @var array $trending */

$hasNext = !empty($result['pageInfo']['hasNextPage']);
$items   = (array) ($result['items'] ?? []);
$quick   = ['Frieren', 'One Piece', 'Attack on Titan', 'Jujutsu Kaisen', 'Solo Leveling', 'Chainsaw Man'];
?>
<div class="container page-head page-head--search">
    <p class="eyebrow">Search</p>
    <h1><?= $q !== '' ? 'Results for “' . e($q) . '”' : 'Find your next watch' ?></h1>
    <form class="search-page-form" action="<?= e(url('search')) ?>" method="get" role="search">
        <span class="search__icon"><?= View::icon('search', 18) ?></span>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by title — try “frieren”" autocomplete="off" data-search-focus aria-label="Search anime">
        <button class="btn btn--primary btn--sm" type="submit">Search</button>
    </form>
    <?php if ($q === ''): ?>
        <div class="quick-search">
            <span>Try</span>
            <?php foreach ($quick as $term): ?>
                <a class="chip chip--link" href="<?= e(search_url($term)) ?>"><?= e($term) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="container">
    <?php if ($q !== ''): ?>
        <p class="result-count">Showing <?= count($items) ?> of up to <?= (int) ($result['pageInfo']['total'] ?? count($items)) ?> results</p>

        <div class="grid"
             data-browse-grid
             data-feed="search"
             data-page="1"
             data-per-page="50"
             data-has-next="<?= $hasNext ? '1' : '0' ?>"
             data-params="<?= e(json_encode(['q' => $q], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
            <?php foreach ($items as $i => $item): ?>
                <?= View::card($item, ['eager' => $i < 8]) ?>
            <?php endforeach; ?>
        </div>

        <?php if (!$items): ?>
            <?= View::emptyState('Nothing matched', 'Try a shorter title, an alternate spelling, or browse a genre instead.', 'search') ?>
        <?php endif; ?>

        <div class="load-area" data-load-area <?= $hasNext ? '' : 'hidden' ?>>
            <div class="load-spinner" data-load-spinner hidden aria-hidden="true"><span></span><span></span><span></span></div>
            <button class="btn btn--ghost" type="button" data-load-more <?= $hasNext ? '' : 'hidden' ?>>Load more</button>
            <div class="load-sentinel" data-load-sentinel aria-hidden="true"></div>
        </div>
        <p class="end-note" data-end-note <?= $hasNext ? 'hidden' : '' ?>>You have reached the end of this list.</p>
    <?php else: ?>
        <section class="section-block">
            <div class="section-head">
                <div class="section-head__text">
                    <h2>Browse by genre</h2>
                    <p>Every genre in the catalog.</p>
                </div>
                <a class="section-head__link" href="<?= e(url('genres')) ?>">All genres <?= View::icon('arrow', 15) ?></a>
            </div>
            <div class="genre-grid">
                <?php foreach (array_slice($genres, 0, 12) as $i => $genre): ?>
                    <?= View::genreTile($genre, $i) ?>
                <?php endforeach; ?>
            </div>
        </section>

        <?= View::row('Trending right now', $trending, browse_link('trending'), ['subtitle' => 'A good place to start.']) ?>
    <?php endif; ?>
</div>
