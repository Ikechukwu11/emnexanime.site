<?php
declare(strict_types=1);
/** @var array $hero */
/** @var array $sections */

$quickGenres = ['Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Romance', 'Sci-Fi', 'Slice of Life'];
?>
<?php if ($hero): ?>
<section class="hero" data-hero aria-roledescription="carousel" aria-label="Featured anime">
    <div class="hero__stage">
        <?php foreach ($hero as $i => $item): ?>
            <?php
            $id     = (int) ($item['id'] ?? 0);
            $title  = title_of($item);
            $alt    = alt_title_of($item);
            $accent = accent_of($item);
            $image  = banner_of($item);
            $status = status_label($item['status'] ?? null);
            $season = season_text($item);
            ?>
            <article class="hero__slide <?= $i === 0 ? 'is-active' : '' ?>" data-hero-slide style="--hero-accent:<?= e($accent) ?>">
                <div class="hero__bg" style="background-image:url('<?= e($image) ?>')"></div>
                <div class="hero__veil"></div>
                <div class="container hero__content">
                    <p class="hero__eyebrow"><span class="hero__eyebrow-dot"></span> Spotlight <span class="hero__eyebrow-sep">/</span> <?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></p>
                    <h1 class="hero__title"><?= e($title) ?></h1>
                    <?php if ($alt !== ''): ?><p class="hero__alt"><?= e($alt) ?></p><?php endif; ?>
                    <div class="hero__meta">
                        <?php if (is_numeric($item['rating'] ?? null)): ?>
                            <span class="chip chip--score"><?= View::icon('star', 13) ?><?= rating_of($item) ?></span>
                        <?php endif; ?>
                        <?php if ($season !== ''): ?><span class="chip"><?= e($season) ?></span><?php endif; ?>
                        <?php if (!empty($item['format'])): ?><span class="chip"><?= e(format_label((string) $item['format'])) ?></span><?php endif; ?>
                        <?php if (!empty($item['episodes'])): ?><span class="chip"><?= (int) $item['episodes'] ?> episodes</span><?php endif; ?>
                        <span class="chip chip--status <?= e(strtolower((string) ($item['status'] ?? ''))) ?>"><?= e($status) ?></span>
                    </div>
                    <p class="hero__genres"><?= e(genres_text($item, 4)) ?></p>
                    <div class="hero__actions">
                        <a class="btn btn--primary" href="<?= e(watch_url($id, 1)) ?>">
                            <?= View::icon('play', 16) ?> Watch episode 1
                        </a>
                        <a class="btn btn--ghost" href="<?= e(anime_url($id)) ?>">More info</a>
                        <button class="btn btn--square" type="button"
                                data-watchlist-toggle
                                data-id="<?= $id ?>"
                                data-title="<?= e($title) ?>"
                                data-poster="<?= e(poster_of($item)) ?>"
                                data-year="<?= e(year_of($item)) ?>"
                                data-format="<?= e(format_label($item['format'] ?? null)) ?>"
                                data-score="<?= is_numeric($item['rating'] ?? null) ? e(rating_of($item)) : '' ?>"
                                aria-label="Toggle watchlist for <?= e($title) ?>">
                            <span data-watchlist-icon><?= View::icon('plus', 18) ?></span>
                        </button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if (count($hero) > 1): ?>
    <div class="hero__nav">
        <button class="hero__arrow" type="button" data-hero-prev aria-label="Previous spotlight"><?= View::icon('back', 20) ?></button>
        <div class="hero__dots" role="tablist" aria-label="Spotlight slides">
            <?php foreach ($hero as $i => $_): ?>
                <button class="hero__dot <?= $i === 0 ? 'is-active' : '' ?>" type="button" data-hero-dot="<?= $i ?>" role="tab" aria-label="Slide <?= $i + 1 ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"></button>
            <?php endforeach; ?>
        </div>
        <button class="hero__arrow" type="button" data-hero-next aria-label="Next spotlight"><?= View::icon('arrow', 20) ?></button>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="container home-body">
    <nav class="quickgenres" aria-label="Popular genres">
        <span class="quickgenres__label">Browse by mood</span>
        <?php foreach ($quickGenres as $g): ?>
            <a class="chip chip--link" href="<?= e(genre_url($g)) ?>"><?= e($g) ?></a>
        <?php endforeach; ?>
        <a class="chip chip--link chip--more" href="<?= e(url('genres')) ?>">All genres <?= View::icon('chevron', 13) ?></a>
    </nav>

    <section class="rowblock rowblock--continue" data-continue hidden>
        <div class="section-head">
            <div class="section-head__text">
                <h2>Continue watching</h2>
                <p>Pick up exactly where you left off.</p>
            </div>
            <button class="section-head__link section-head__link--button" type="button" data-continue-clear>Clear</button>
        </div>
        <div class="rowblock__viewport">
            <div class="rowblock__scroller" data-continue-scroller></div>
        </div>
    </section>

    <?php foreach ($sections as $section): ?>
        <?php
        $isTrending = ($section['key'] ?? '') === 'trending';
        echo View::row(
            (string) $section['title'],
            (array) $section['items'],
            $section['href'] ?? null,
            [
                'ranked'   => $isTrending,
                'subtitle' => $isTrending ? 'Ranked by what is hot right now.' : '',
                'eager'    => true,
            ]
        );
        ?>
    <?php endforeach; ?>

    <?php if (!$sections): ?>
        <?= View::emptyState('Nothing to show yet', 'The catalog feed came back empty. Try a different page or come back in a moment.', 'spark') ?>
    <?php endif; ?>
</div>
