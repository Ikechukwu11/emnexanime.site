<?php
declare(strict_types=1);
/** @var int $status */
/** @var string $title */
/** @var string $message */
/** @var string $detail */
?>
<div class="container error-page">
    <div class="error-page__art" aria-hidden="true">
        <span class="error-page__code"><?= (int) $status ?></span>
        <span class="error-page__glow"></span>
    </div>
    <div class="error-page__body">
        <p class="eyebrow">Something interrupted the stream</p>
        <h1><?= e($title) ?></h1>
        <p><?= e($message) ?></p>
        <?php if ($detail !== ''): ?>
            <p class="error-page__detail"><?= e($detail) ?></p>
        <?php endif; ?>
        <div class="error-page__actions">
            <a class="btn btn--primary" href="<?= e(url('/')) ?>">Back home</a>
            <button class="btn btn--ghost" type="button" onclick="location.reload()">Try again</button>
            <a class="btn btn--ghost" href="<?= e(browse_link('trending')) ?>">Browse anime</a>
        </div>
        <?php if ($status === 502): ?>
            <p class="error-page__hint">The site keeps a SQLite cache and a file stream cache, so previously loaded pages and episodes may still work. If this is a fresh install, check that <code>keys.txt</code> contains a valid EmnexAnime API key.</p>
        <?php endif; ?>
    </div>
</div>
