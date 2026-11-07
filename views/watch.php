<?php
declare(strict_types=1);
/** @var array $anime */
/** @var int $episode */
/** @var string $type */

$id = (int) $anime['id'];
$title = title_of($anime);
$poster = poster_of($anime);
$banner = banner_of($anime);
$total = (int) ($anime['episodes'] ?? 0);
$idMal = (int) ($anime['idMal'] ?? 0);
$prevEp = max(1, $episode - 1);
$nextEp = $episode + 1;
$hasNext = isset($hasNext) ? $hasNext : ($total <= 0 || $episode < $total);
$isDub = $type === 'dub';
$synopsis = excerpt_text(clean_description($anime['description'] ?? ''), 240);
?>
<div class="container watch-page">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= e(anime_url($id)) ?>"><?= View::icon('back', 14) ?> <?= e($title) ?></a>
        <span>/</span>
        <span aria-current="page">Episode <?= (int) $episode ?></span>
    </nav>

    <div class="watch-layout">
        <div class="watch-main">
            <div class="player" data-player data-id="<?= $id ?>" data-mal="<?= $idMal ?>"
                data-episode="<?= (int) $episode ?>" data-type="<?= e($type) ?>" data-title="<?= e($title) ?>"
                data-poster="<?= e($poster) ?>" data-banner="<?= e($banner) ?>" data-total="<?= $total ?>">
                <video class="player__video" data-video playsinline preload="metadata"
                    poster="<?= e($banner) ?>"></video>

                <div class="player__loader" data-player-loader>
                    <div class="player__loader-ring"></div>
                    <p data-player-status>Resolving stream…</p>
                </div>

                <div class="player__error" data-player-error hidden>
                    <span class="player__error-icon"><?= View::icon('close', 26) ?></span>
                    <h3 data-player-error-title>Stream unavailable</h3>
                    <p data-player-error-text>We could not resolve this episode.</p>
                    <div class="player__error-actions">
                        <button class="btn btn--primary btn--sm" type="button" data-player-retry>Try again</button>
                        <a class="btn btn--ghost btn--sm" href="<?= e(anime_url($id)) ?>">Back to series</a>
                    </div>
                </div>

                <!-- <button class="player__bigplay" type="button" data-bigplay aria-label="Play">
                    <?= View::icon('play', 30) ?>
                </button> -->

                <button class="player__skip player__skip--intro" type="button" data-skip-intro hidden>Skip
                    intro</button>
                <button class="player__skip player__skip--outro" type="button" data-skip-outro hidden>Skip
                    outro</button>

                <div class="player__controls" data-controls>
                    <div class="player__timeline">
                        <div class="player__buffered" data-buffered></div>
                        <div class="player__played" data-played></div>
                        <input class="player__seek" type="range" min="0" max="1000" value="0" step="1" aria-label="Seek"
                            data-seek>
                    </div>
                    <div class="player__bar">
                        <button class="player__btn" type="button" data-playpause
                            aria-label="Pause"><?= View::icon('play', 19) ?></button>
                        <button class="player__btn player__btn--mute" type="button" data-mute
                            aria-label="Mute"><?= View::icon('volume', 19) ?></button>
                        <input class="player__volume" type="range" min="0" max="1" step="0.05" value="1"
                            aria-label="Volume" data-volume>
                        <span class="player__time" data-time>0:00 <span>/</span> 0:00</span>
                        <span class="player__spacer"></span>
                        <label class="player__select-wrap" title="Playback speed">
                            <span class="sr-only">Playback speed</span>
                            <select class="player__select" data-speed aria-label="Playback speed">
                                <option value="0.5">0.5×</option>
                                <option value="0.75">0.75×</option>
                                <option value="1" selected>1×</option>
                                <option value="1.25">1.25×</option>
                                <option value="1.5">1.5×</option>
                                <option value="2">2×</option>
                            </select>
                        </label>
                        <label class="player__select-wrap" title="Quality">
                            <span class="sr-only">Quality</span>
                            <select class="player__select" data-quality aria-label="Quality">
                                <option value="auto">Auto</option>
                            </select>
                        </label>
                        <label class="player__select-wrap" title="Audio">
                            <span class="sr-only">Audio</span>
                            <select class="player__select" data-audio aria-label="Audio" hidden></select>
                        </label>
                        <label class="player__select-wrap player__select-wrap--subs" title="Subtitles">
                            <span class="sr-only">Subtitles</span>
                            <select class="player__select" data-subs aria-label="Subtitles">
                                <option value="-1">CC off</option>
                            </select>
                        </label>
                        <button class="player__btn" type="button" data-pip
                            aria-label="Picture in picture"><?= View::icon('pip', 18) ?></button>
                        <button class="player__btn" type="button" data-fullscreen
                            aria-label="Fullscreen"><?= View::icon('fullscreen', 18) ?></button>
                    </div>
                </div>
            </div>

            <div class="watch-head">
                <div class="watch-head__text">
                    <p class="eyebrow"><span class="live-dot"></span> Now playing · <?= $isDub ? 'Dub' : 'Sub' ?> ·
                        Episode <?= (int) $episode ?></p>
                    <h1><a href="<?= e(anime_url($id)) ?>"><?= e($title) ?></a></h1>
                    <p class="watch-head__ep" data-episode-title>Episode <?= (int) $episode ?></p>
                </div>
                <div class="watch-head__actions">
                    <div class="segmented" role="group" aria-label="Audio">
                        <a class="segmented__item <?= $isDub ? '' : 'is-active' ?>"
                            href="<?= e(watch_url($id, $episode, 'sub')) ?>" data-audio-type="sub">Sub</a>
                        <a class="segmented__item <?= $isDub ? 'is-active' : '' ?>"
                            href="<?= e(watch_url($id, $episode, 'dub')) ?>" data-audio-type="dub">Dub</a>
                    </div>
                    <label class="switch">
                        <input type="checkbox" data-autoplay-next>
                        <span>Autoplay next</span>
                    </label>
                </div>
            </div>

            <div class="watch-meta" data-source-meta hidden></div>

            <div class="watch-actions">
                <a class="btn btn--ghost btn--sm" href="<?= e(watch_url($id, $prevEp, $type)) ?>" data-prev-episode
                    <?= $episode <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                    <?= View::icon('back', 15) ?> Previous
                </a>
                <a class="btn btn--ghost btn--sm" href="<?= e(watch_url($id, $nextEp, $type)) ?>" data-next-episode
                    <?= !$hasNext ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                    Next episode <?= View::icon('arrow', 15) ?>
                </a>
                <button class="btn btn--ghost btn--sm" type="button" data-reload-stream>Reload stream</button>
                <a class="btn btn--ghost btn--sm" href="<?= e(anime_url($id)) ?>">Series details</a>
            </div>

            <div class="panel watch-about">
                <div class="panel__head">
                    <h2>About <?= e($title) ?></h2>
                    <span class="panel__hint"><?= e(status_label($anime['status'] ?? null)) ?></span>
                </div>
                <p><?= e($synopsis) ?></p>
                <div class="anime-chips">
                    <?php foreach (array_slice((array) ($anime['genres'] ?? []), 0, 5) as $genre): ?>
                        <a class="chip chip--link" href="<?= e(genre_url((string) $genre)) ?>"><?= e($genre) ?></a>
                    <?php endforeach; ?>
                    <?php if (season_text($anime) !== ''): ?><span
                            class="chip"><?= e(season_text($anime)) ?></span><?php endif; ?>
                </div>
            </div>
        </div>

        <aside class="watch-side">
            <section class="side-card side-card--episodes">
                <div class="panel__head">
                    <h2>Episodes</h2>
                    <span class="panel__hint" data-watch-ep-count><?= $total > 0 ? $total : '' ?></span>
                </div>
                <div class="watch-ep-filter">
                    <span><?= View::icon('search', 15) ?></span>
                    <input type="search" placeholder="Filter episodes…" autocomplete="off" data-watch-ep-filter
                        aria-label="Filter episodes">
                </div>
                <div class="watch-ep-list" data-watch-ep-list>
                    <div class="ep-placeholder"><span
                            class="load-spinner"><span></span><span></span><span></span></span><span>Loading
                            episodes…</span></div>
                </div>
                <button class="btn btn--ghost btn--sm btn--block" type="button" data-watch-ep-more hidden>Load
                    more</button>
            </section>

            <section class="side-card watch-shortcuts">
                <h2>Shortcuts</h2>
                <dl class="shortcut-list">
                    <div>
                        <dt><kbd>Space</kbd> / <kbd>K</kbd></dt>
                        <dd>Play / pause</dd>
                    </div>
                    <div>
                        <dt><kbd>←</kbd> <kbd>→</kbd></dt>
                        <dd>Seek 10 seconds</dd>
                    </div>
                    <div>
                        <dt><kbd>F</kbd></dt>
                        <dd>Fullscreen</dd>
                    </div>
                    <div>
                        <dt><kbd>M</kbd></dt>
                        <dd>Mute</dd>
                    </div>
                    <div>
                        <dt><kbd>N</kbd></dt>
                        <dd>Next episode</dd>
                    </div>
                </dl>
            </section>
        </aside>
    </div>
</div>