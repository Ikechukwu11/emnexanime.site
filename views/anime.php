<?php
declare(strict_types=1);
/** @var array $anime */

$id          = (int) $anime['id'];
$title       = title_of($anime);
$altTitle    = alt_title_of($anime);
$poster      = poster_of($anime);
$banner      = banner_of($anime);
$accent      = accent_of($anime);
$synopsis    = clean_description($anime['description'] ?? '');
$trailerId   = youtube_id($anime['trailer'] ?? null);
$episodes    = $anime['episodes'] ?? null;
$nextAiring  = $anime['nextAiringEpisode'] ?? null;
$relations   = array_values(array_filter((array) ($anime['relations'] ?? []), 'is_array'));
$recommended = array_values(array_filter((array) ($anime['recommendations'] ?? []), 'is_array'));
$characters  = array_values(array_filter((array) ($anime['characters'] ?? []), 'is_array'));
$seasons     = array_values(array_filter((array) ($anime['seasons'] ?? []), 'is_array'));
$tags        = array_values(array_filter((array) ($anime['tags'] ?? []), 'is_string'));
$isUpcoming  = ($anime['status'] ?? '') === 'NOT_YET_RELEASED';
$startDate   = $anime['startDate'] ?? null;
$endDate     = $anime['endDate'] ?? null;
$studioNames = array_map(static fn (array $s): string => (string) ($s['name'] ?? ''), array_filter((array) ($anime['studios'] ?? []), 'is_array'));

$startText = is_array($startDate) && !empty($startDate['year'])
    ? trim(($startDate['day'] ?? '') . ' ' . date('M', mktime(0, 0, 0, (int) ($startDate['month'] ?? 1), 1)) . ' ' . $startDate['year'])
    : '—';
$endText = is_array($endDate) && !empty($endDate['year'])
    ? trim(($endDate['day'] ?? '') . ' ' . date('M', mktime(0, 0, 0, (int) ($endDate['month'] ?? 1), 1)) . ' ' . $endDate['year'])
    : '—';

$watchlistAttrs = 'data-watchlist-toggle data-id="' . $id . '"'
    . ' data-title="' . e($title) . '"'
    . ' data-poster="' . e($poster) . '"'
    . ' data-year="' . e(year_of($anime)) . '"'
    . ' data-format="' . e(format_label($anime['format'] ?? null)) . '"'
    . ' data-score="' . (is_numeric($anime['rating'] ?? null) ? e(rating_of($anime)) : '') . '"';
?>
<section class="anime-hero" style="--hero-accent:<?= e($accent) ?>">
    <div class="anime-hero__bg" style="background-image:url('<?= e($banner) ?>')"></div>
    <div class="anime-hero__veil"></div>
    <div class="container anime-hero__inner">
        <div class="anime-poster">
            <span class="card__fallback" aria-hidden="true"><?= e(initials_of($title)) ?></span>
            <img src="<?= e($poster) ?>" alt="<?= e($title) ?> poster" width="230" height="345" fetchpriority="high" decoding="async">
            <?php if (is_numeric($anime['rating'] ?? null)): ?>
                <span class="anime-poster__score"><?= View::icon('star', 13) ?> <?= e(rating_of($anime)) ?></span>
            <?php endif; ?>
        </div>

        <div class="anime-intro">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="<?= e(url('/')) ?>">Home</a>
                <span>/</span>
                <a href="<?= e(browse_link('trending')) ?>">Anime</a>
                <span>/</span>
                <span aria-current="page"><?= e($title) ?></span>
            </nav>

            <h1 class="anime-title"><?= e($title) ?></h1>
            <?php if ($altTitle !== ''): ?><p class="anime-alt"><?= e($altTitle) ?></p><?php endif; ?>

            <div class="anime-chips">
                <span class="chip chip--status <?= e(strtolower((string) ($anime['status'] ?? ''))) ?>"><?= e(status_label($anime['status'] ?? null)) ?></span>
                <?php if (!empty($anime['format'])): ?><span class="chip"><?= e(format_label((string) $anime['format'])) ?></span><?php endif; ?>
                <?php if (season_text($anime) !== ''): ?><span class="chip"><?= e(season_text($anime)) ?></span><?php endif; ?>
                <?php if (!empty($episodes)): ?><span class="chip"><?= (int) $episodes ?> episodes</span><?php endif; ?>
                <?php if (!empty($anime['duration'])): ?><span class="chip"><?= (int) $anime['duration'] ?> min</span><?php endif; ?>
                <?php if (is_numeric($anime['popularity'] ?? null)): ?><span class="chip"><?= e(human_count((int) $anime['popularity'])) ?> members</span><?php endif; ?>
            </div>

            <div class="anime-genres">
                <?php foreach ((array) ($anime['genres'] ?? []) as $genre): ?>
                    <a class="genre-pill" href="<?= e(genre_url((string) $genre)) ?>"><?= e($genre) ?></a>
                <?php endforeach; ?>
            </div>

            <div class="anime-actions">
                <a class="btn btn--primary btn--lg" href="<?= e(watch_url($id, 1)) ?>"><?= View::icon('play', 17) ?> Watch episode 1</a>
                <button class="btn btn--ghost" type="button" <?= $watchlistAttrs ?>>
                    <span data-watchlist-icon><?= View::icon('plus', 17) ?></span>
                    <span data-watchlist-label>Add to watchlist</span>
                </button>
                <?php if ($trailerId !== ''): ?>
                    <button class="btn btn--ghost" type="button" data-trailer="<?= e($trailerId) ?>">Watch trailer</button>
                <?php endif; ?>
                <button class="btn btn--icon-only" type="button" data-share aria-label="Share this title" title="Share"><?= View::icon('arrow', 17) ?></button>
            </div>

            <?php if (is_array($nextAiring) && !empty($nextAiring['airingAt'])): ?>
                <p class="next-airing" data-countdown data-at="<?= (int) $nextAiring['airingAt'] ?>" data-episode="<?= (int) ($nextAiring['episode'] ?? 0) ?>">
                    <?= View::icon('clock', 15) ?>
                    <span>Next episode <?= (int) ($nextAiring['episode'] ?? 0) ?> in <b data-countdown-value>—</b></span>
                </p>
            <?php endif; ?>

            <?php if ($isUpcoming): ?>
                <p class="notice notice--inline">This title has not aired yet. You can still save it to your watchlist.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="container anime-layout">
    <div class="anime-main">
        <section class="panel" id="overview">
            <div class="panel__head">
                <h2>Synopsis</h2>
                <span class="panel__hint"><?= e((string) ($anime['source'] ?? '')) ?></span>
            </div>
            <div class="synopsis <?= mb_strlen($synopsis) > 420 ? 'is-clamped' : '' ?>" data-synopsis>
                <div class="synopsis__body"><?= description_html($anime['description'] ?? '') ?></div>
                <?php if (mb_strlen($synopsis) > 420): ?>
                    <button class="text-btn" type="button" data-synopsis-toggle>Read more</button>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel panel--episodes" id="episodes"
                 data-episodes
                 data-id="<?= $id ?>"
                 data-total="<?= (int) ($episodes ?? 0) ?>"
                 data-current="1">
            <div class="panel__head">
                <h2>Episodes <span class="panel__count" data-ep-count><?= !empty($episodes) ? (int) $episodes : '' ?></span></h2>
                <div class="ep-tools">
                    <label class="ep-jump">
                        <span>Jump to</span>
                        <input type="number" min="1" max="<?= !empty($episodes) ? (int) $episodes : 9999 ?>" inputmode="numeric" placeholder="#" data-ep-jump aria-label="Jump to episode">
                    </label>
                </div>
            </div>
            <div class="ep-list" data-ep-list>
                <div class="ep-placeholder">
                    <span class="load-spinner"><span></span><span></span><span></span></span>
                    <span>Episodes load when you reach this section…</span>
                </div>
            </div>
            <div class="panel__foot">
                <button class="btn btn--ghost btn--sm" type="button" data-ep-more hidden>Load more episodes</button>
                <p class="panel__note" data-ep-note>Episode data is fetched lazily and cached for six hours.</p>
            </div>
        </section>

        <?php if ($characters): ?>
        <section class="panel" id="characters">
            <div class="panel__head">
                <h2>Characters</h2>
                <span class="panel__hint"><?= count($characters) ?> main &amp; supporting</span>
            </div>
            <div class="character-grid">
                <?php foreach ($characters as $character): ?>
                    <?php
                    $cName = (string) ($character['name'] ?? 'Unknown');
                    $cImage = (string) ($character['image'] ?? '');
                    $va = $character['voiceActor'] ?? null;
                    ?>
                    <article class="character">
                        <div class="character__image">
                            <span class="card__fallback" aria-hidden="true"><?= e(initials_of($cName)) ?></span>
                            <?php if ($cImage !== ''): ?>
                                <img src="<?= e($cImage) ?>" alt="<?= e($cName) ?>" loading="lazy" decoding="async">
                            <?php endif; ?>
                        </div>
                        <div class="character__body">
                            <h3><?= e($cName) ?></h3>
                            <p class="character__role"><?= e(ucfirst(strtolower((string) ($character['role'] ?? '')))) ?></p>
                            <?php if (is_array($va) && !empty($va['name'])): ?>
                                <p class="character__va"><span>VA</span> <?= e((string) $va['name']) ?></p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($relations): ?>
        <section class="panel" id="related">
            <div class="panel__head">
                <h2>Related</h2>
                <span class="panel__hint">Sequels, prequels and side stories</span>
            </div>
            <div class="mini-grid" data-relations-grid>
                <?php foreach ($relations as $index => $relation): ?>
                    <div class="<?= $index >= 18 ? 'is-hidden-extra' : '' ?>" data-relation-item>
                        <?= View::posterCard($relation, (string) ($relation['relation'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($relations) > 18): ?>
                <button class="btn btn--ghost btn--sm" type="button" data-relations-more>Show all <?= count($relations) ?></button>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($recommended): ?>
            <div class="panel panel--flush">
                <?= View::row('You might also like', array_slice($recommended, 0, 18), null, ['subtitle' => 'Picked from the community’s recommendations.']) ?>
            </div>
        <?php endif; ?>
    </div>

    <aside class="anime-side">
        <section class="side-card">
            <h2>Details</h2>
            <dl class="detail-list">
                <div><dt>Format</dt><dd><?= e(format_label($anime['format'] ?? null)) ?></dd></div>
                <div><dt>Status</dt><dd><?= e(status_label($anime['status'] ?? null)) ?></dd></div>
                <div><dt>Episodes</dt><dd><?= !empty($episodes) ? (int) $episodes : 'Unknown' ?></dd></div>
                <div><dt>Duration</dt><dd><?= e(duration_label(isset($anime['duration']) ? (int) $anime['duration'] : null)) ?></dd></div>
                <div><dt>Season</dt><dd><?= e(season_text($anime) ?: '—') ?></dd></div>
                <div><dt>Started</dt><dd><?= e($startText) ?></dd></div>
                <div><dt>Ended</dt><dd><?= e($endText) ?></dd></div>
                <div><dt>Studio</dt><dd><?= e($studioNames ? implode(', ', array_slice($studioNames, 0, 3)) : '—') ?></dd></div>
                <div><dt>Source</dt><dd><?= e($anime['source'] ?? '—') ?></dd></div>
                <div><dt>Popularity</dt><dd><?= e(human_count(isset($anime['popularity']) ? (int) $anime['popularity'] : null)) ?></dd></div>
            </dl>
            <?php if (!empty($anime['siteUrl'])): ?>
                <a class="side-link" href="<?= e((string) $anime['siteUrl']) ?>" target="_blank" rel="noopener noreferrer">View on AniList <?= View::icon('arrow', 14) ?></a>
            <?php endif; ?>
        </section>

        <?php if ($seasons): ?>
        <section class="side-card">
            <h2>Seasons &amp; relations</h2>
            <ol class="season-list">
                <?php foreach ($seasons as $season): ?>
                    <?php
                    $sId = (int) ($season['id'] ?? 0);
                    $sRel = (string) ($season['relation'] ?? '');
                    $isCurrent = $sRel === 'CURRENT';
                    ?>
                    <li class="<?= $isCurrent ? 'is-current' : '' ?>">
                        <a href="<?= e(anime_url($sId)) ?>">
                            <span class="season-list__rel"><?= e(ucwords(strtolower(str_replace('_', ' ', $sRel)))) ?></span>
                            <span class="season-list__title"><?= e((string) ($season['title'] ?? 'Untitled')) ?></span>
                            <span class="season-list__meta"><?= e(format_label($season['format'] ?? null)) ?><?= !empty($season['year']) ? ' · ' . (int) $season['year'] : '' ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php endif; ?>

        <?php if ($tags): ?>
        <section class="side-card">
            <h2>Tags</h2>
            <div class="tag-pills">
                <?php foreach ($tags as $tag): ?>
                    <span class="chip chip--tag"><?= e($tag) ?></span>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </aside>
</div>
