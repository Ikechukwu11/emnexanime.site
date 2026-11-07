<?php
declare(strict_types=1);
/** @var array $schedule */
?>
<div class="container page-head">
    <p class="eyebrow">Airing calendar</p>
    <h1>Next 7 days</h1>
    <p class="page-head__sub">Every episode airing in the next week, grouped by your local day. Times update in your timezone.</p>
</div>

<div class="container">
    <?php if ($schedule): ?>
        <div class="schedule" data-schedule>
            <div class="schedule-tabs" data-schedule-tabs role="tablist" aria-label="Schedule days"></div>
            <div class="schedule-days" data-schedule-days></div>
        </div>
    <?php else: ?>
        <?= View::emptyState('The calendar is quiet', 'No upcoming episodes were returned. Check back later.', 'calendar') ?>
    <?php endif; ?>
</div>

<script type="application/json" id="schedule-data"><?= json_for_script($schedule) ?></script>
