<?php
declare(strict_types=1);
/** @var string $content */
$title = $title ?? 'EmnexAnime';
$description = $description ?? 'Stream anime without the noise.';
$image = $image ?? null;
$canonical = $canonical ?? null;
$page = $page ?? 'page';
$bodyClass = $bodyClass ?? '';
$scripts = $scripts ?? [];
$jsonLd = $jsonLd ?? null;
$ogType = $ogType ?? 'website';
$noIndex = $noIndex ?? false;
$searchValue = $searchValue ?? '';
$siteName = 'EmnexAnime';
?>
<!doctype html>
<html lang="en" class="no-js">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <meta name="theme-color" content="#08090b">
    <meta name="color-scheme" content="dark">
    <?php if ($noIndex): ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <?php if ($canonical): ?>
        <link rel="canonical" href="<?= e($canonical) ?>">
    <?php endif; ?>

    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:type" content="<?= e($ogType) ?>">
    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e($description) ?>">
    <?php if ($canonical): ?>
        <meta property="og:url" content="<?= e($canonical) ?>"><?php endif; ?>
    <?php if ($image): ?>
        <meta property="og:image" content="<?= e($image) ?>"><?php endif; ?>
    <meta name="twitter:card" content="<?= $image ? 'summary_large_image' : 'summary' ?>">
    <meta name="twitter:title" content="<?= e($title) ?>">
    <meta name="twitter:description" content="<?= e($description) ?>">
    <?php if ($image): ?>
        <meta name="twitter:image" content="<?= e($image) ?>"><?php endif; ?>

    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/logo.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php if ($jsonLd): ?>
        <script type="application/ld+json"><?= json_for_script($jsonLd) ?></script>
    <?php endif; ?>
    <script>document.documentElement.classList.remove('no-js');</script>
</head>

<body class="<?= e($bodyClass) ?>" data-page="<?= e($page) ?>">
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="site-header" data-site-header>
        <div class="container header-inner">
            <a class="brand" href="<?= e(url('/')) ?>" aria-label="EmnexAnime home">
                <span class="brand__mark"><?= View::icon('play', 18) ?></span>
                <span class="brand__name">Emnex<span>Anime</span></span>
            </a>

            <nav class="main-nav" aria-label="Primary">
                <a href="<?= e(url('/')) ?>"
                    class="<?= trim(nav_active('/')) === 'is-active' ? 'is-active' : '' ?>">Home</a>
                <a href="<?= e(browse_link('trending')) ?>"
                    class="<?= trim(nav_active('/browse/trending')) ?>">Trending</a>
                <a href="<?= e(browse_link('seasonal')) ?>"
                    class="<?= trim(nav_active('/browse/seasonal')) ?>">Seasonal</a>
                <a href="<?= e(browse_link('popular')) ?>"
                    class="<?= trim(nav_active('/browse/popular')) ?>">Popular</a>
                <a href="<?= e(url('genres')) ?>" class="<?= trim(nav_active('/genres')) ?>">Genres</a>
                <a href="<?= e(url('schedule')) ?>" class="<?= trim(nav_active('/schedule')) ?>">Schedule</a>
            </nav>

            <div class="header-actions">
                <div class="search" data-search>
                    <form class="search__form" action="<?= e(url('search')) ?>" method="get" role="search">
                        <span class="search__icon"><?= View::icon('search', 17) ?></span>
                        <input class="search__input" type="search" name="q" value="<?= e($searchValue) ?>"
                            placeholder="Search anime…" autocomplete="off" spellcheck="false" aria-label="Search anime"
                            data-search-input>
                        <kbd class="search__kbd" aria-hidden="true">/</kbd>
                    </form>
                    <div class="search__panel" data-search-panel hidden aria-live="polite"></div>
                </div>
                <button class="icon-btn search-toggle" type="button" data-search-toggle aria-label="Open search"
                    aria-expanded="false">
                    <?= View::icon('search', 19) ?>
                </button>
                <a class="icon-btn watchlist-link" href="<?= e(url('watchlist')) ?>" aria-label="My watchlist">
                    <?= View::icon('bookmark', 19) ?>
                    <span class="count-badge" data-watchlist-count hidden>0</span>
                </a>
                <a class="icon-btn random-link" href="<?= e(url('random')) ?>" aria-label="Surprise me"
                    title="Surprise me">
                    <?= View::icon('spark', 18) ?>
                </a>
            </div>
        </div>
    </header>

    <main id="main">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <div class="footer-brand">
                <a class="brand brand--footer" href="<?= e(url('/')) ?>">
                    <span class="brand__mark"><?= View::icon('play', 16) ?></span>
                    <span class="brand__name">Emnex<span>Anime</span></span>
                </a>
                <p>Anime discovery and streaming with a fast, quiet interface. No iframes, no ad breaks.</p>
                <p class="footer-fine">Metadata by <a href="https://anilist.co" target="_blank"
                        rel="noopener noreferrer">AniList</a>. Streams are resolved from third-party sources through the
                    EmnexAnime API. No media is hosted here. Personal &amp; educational use only.</p>
            </div>
            <div class="footer-links">
                <div>
                    <h3>Browse</h3>
                    <a href="<?= e(browse_link('trending')) ?>">Trending</a>
                    <a href="<?= e(browse_link('seasonal')) ?>">This season</a>
                    <a href="<?= e(browse_link('popular')) ?>">Popular</a>
                    <a href="<?= e(browse_link('recent')) ?>">New episodes</a>
                </div>
                <div>
                    <h3>Discover</h3>
                    <a href="<?= e(url('genres')) ?>">Genres</a>
                    <a href="<?= e(url('schedule')) ?>">Schedule</a>
                    <a href="<?= e(url('search')) ?>">Search</a>
                    <a href="<?= e(url('watchlist')) ?>">Watchlist</a>
                </div>
                <div>
                    <h3>Elsewhere</h3>
                    <a href="https://api.emnexanime.site/" target="_blank" rel="noopener noreferrer">API docs</a>
                    <a href="https://anilist.co" target="_blank" rel="noopener noreferrer">AniList</a>
                </div>
            </div>
        </div>
        <div class="container footer-bottom">
            <span>© <?= date('Y') ?> <?= e($siteName) ?>. Built with vanilla PHP, SQLite and JS.</span>
            <span class="footer-cache">SQLite metadata cache · file stream cache</span>
        </div>
    </footer>

    <nav class="mobile-nav" aria-label="Mobile navigation">
        <a href="<?= e(url('/')) ?>" class="<?= trim(nav_active('/')) === 'is-active' ? 'is-active' : '' ?>">
            <?= View::icon('home', 20) ?><span>Home</span>
        </a>
        <a href="<?= e(browse_link('trending')) ?>" class="<?= trim(nav_active('/browse/trending')) ?>">
            <?= View::icon('grid', 20) ?><span>Browse</span>
        </a>
        <a href="<?= e(url('schedule')) ?>" class="<?= trim(nav_active('/schedule')) ?>">
            <?= View::icon('calendar', 20) ?><span>Schedule</span>
        </a>
        <a href="<?= e(url('watchlist')) ?>" class="<?= trim(nav_active('/watchlist')) ?>">
            <?= View::icon('bookmark', 20) ?><span>Watchlist</span>
        </a>
    </nav>

    <div class="toast-stack" data-toasts aria-live="polite" aria-atomic="true"></div>
    <div class="modal" data-modal hidden>
        <div class="modal__backdrop" data-modal-close></div>
        <div class="modal__dialog" role="dialog" aria-modal="true" aria-label="Dialog">
            <button class="modal__close icon-btn" type="button" data-modal-close
                aria-label="Close dialog"><?= View::icon('close', 20) ?></button>
            <div class="modal__body" data-modal-body></div>
        </div>
    </div>

    <noscript>
        <div class="noscript">JavaScript is disabled. Browsing and metadata still work, but the player, search
            suggestions and lazy episode lists need it.</div>
    </noscript>

    <script>
        window.EMNX = <?= json_for_script([
            'base' => BASE_PATH,
            'api' => url('api.php'),
            'home' => url('/'),
            'searchUrl' => url('search'),
            'watchlistUrl' => url('watchlist'),
            'page' => $page,
            'watchBase' => url('watch'),
            'animeBase' => url('anime'),
            'watchlistKey' => 'emnx.watchlist.v1',
            'progressKey' => 'emnx.progress.v1',
            'episodeProgressKey' => 'emnx.episode-progress.v1',
        ]) ?>;
    </script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
    <?php if (in_array('watch.js', $scripts, true)): ?>
        <script src="<?= e(asset('vendor/hls.min.js')) ?>" defer></script>
    <?php endif; ?>
    <?php foreach (array_unique($scripts) as $script): ?>
        <script src="<?= e(asset('js/' . $script . '?v=2')) ?>" defer></script>
    <?php endforeach; ?>
</body>

</html>