<?php
declare(strict_types=1);
/** @var string $feedType */
/** @var array $items */
/** @var array $pageInfo */
/** @var string $title */
/** @var string $subtitle */
/** @var string $filters */
/** @var string $emptyText */
/** @var int $perPage */
/** @var array $params */

$hasNext = !empty($pageInfo['hasNextPage']) && !str_starts_with($feedType, 'home:');
$params  = $params ?? [];
$backLink  = $backLink ?? null;
$backLabel = $backLabel ?? null;
?>
<div class="container page-head">
    <?php if ($backLink): ?>
        <a class="back-link" href="<?= e($backLink) ?>"><?= View::icon('back', 15) ?> <?= e($backLabel ?? 'Back') ?></a>
    <?php endif; ?>
    <div class="page-head__row">
        <div>
            <p class="eyebrow"><?= str_starts_with($feedType, 'home:') ? 'Collection' : 'Catalog' ?></p>
            <h1><?= e($title) ?></h1>
            <p class="page-head__sub"><?= e($subtitle) ?></p>
        </div>
        <?php if ($filters !== ''): ?>
            <?= $filters ?>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <div class="grid"
         data-browse-grid
         data-feed="<?= e($feedType) ?>"
         data-page="1"
         data-per-page="<?= (int) $perPage ?>"
         data-has-next="<?= $hasNext ? '1' : '0' ?>"
         data-params="<?= e(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
        <?php foreach ($items as $i => $item): ?>
            <?= View::card($item, ['eager' => $i < 8]) ?>
        <?php endforeach; ?>
    </div>

    <?php if (!$items): ?>
        <?= View::emptyState('No results', $emptyText, 'search') ?>
    <?php endif; ?>

    <div class="load-area" data-load-area <?= $hasNext ? '' : 'hidden' ?>>
        <div class="load-spinner" data-load-spinner hidden aria-hidden="true"><span></span><span></span><span></span></div>
        <button class="btn btn--ghost" type="button" data-load-more <?= $hasNext ? '' : 'hidden' ?>>Load more</button>
        <div class="load-sentinel" data-load-sentinel aria-hidden="true"></div>
    </div>
    <p class="end-note" data-end-note <?= $hasNext ? 'hidden' : '' ?>>You have reached the end of this list.</p>
</div>
