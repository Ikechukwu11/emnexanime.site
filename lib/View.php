<?php
declare(strict_types=1);

final class View
{
    public static function icon(string $name, int $size = 18): string
    {
        $paths = [
            'play'     => '<path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" fill="currentColor"/>',
            'star'     => '<path d="m12 2.6 2.9 5.88 6.49.94-4.7 4.58 1.11 6.46L12 17.41 6.2 20.46l1.11-6.46-4.7-4.58 6.49-.94L12 2.6Z" fill="currentColor"/>',
            'search'   => '<circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="m16.5 16.5 4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            'chevron'  => '<path d="m9 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            'plus'     => '<path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'check'    => '<path d="m5 12.5 4.2 4.2L19 6.8" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
            'bookmark' => '<path d="M6 4.75A1.75 1.75 0 0 1 7.75 3h8.5A1.75 1.75 0 0 1 18 4.75V21l-6-3.6L6 21V4.75Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
            'clock'    => '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'calendar' => '<rect x="3.5" y="5" width="17" height="16" rx="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 3v4M16 3v4M4 10h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'volume'   => '<path d="M4 9v6h4l5 4V5L8 9H4Z" fill="currentColor"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'mute'     => '<path d="M4 9v6h4l5 4V5L8 9H4Z" fill="currentColor"/><path d="m16 9 5 6M21 9l-5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'settings' => '<circle cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 2.8v2.4M12 18.8v2.4M21.2 12h-2.4M5.2 12H2.8M18.5 5.5l-1.7 1.7M7.2 16.8l-1.7 1.7M18.5 18.5l-1.7-1.7M7.2 7.2 5.5 5.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'fullscreen' => '<path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            'pip'      => '<rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><rect x="12" y="11" width="7" height="6" rx="1" fill="currentColor"/>',
            'next'     => '<path d="M6 5.5v13l9-6.5-9-6.5Z" fill="currentColor"/><path d="M18 5v14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'back'     => '<path d="M19 12H5M11 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            'grid'     => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/>',
            'home'     => '<path d="m3.5 10.5 8.5-7 8.5 7V20a1.5 1.5 0 0 1-1.5 1.5h-4.5v-6h-5v6H5A1.5 1.5 0 0 1 3.5 20v-9.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>',
            'close'    => '<path d="m6 6 12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            'spark'    => '<path d="M12 2.5 14 9l6.5 2-6.5 2-2 6.5-2-6.5L3.5 11 10 9l2-6.5Z" fill="currentColor"/>',
        ];
        $path = $paths[$name] ?? $paths['play'];
        return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true">' . $path . '</svg>';
    }

    public static function card(array $anime, array $opts = []): string
    {
        $id       = (int) ($anime['id'] ?? 0);
        $title    = title_of($anime);
        $poster   = poster_of($anime);
        $accent   = accent_of($anime);
        $score    = $anime['rating'] ?? null;
        $format   = $anime['format'] ?? null;
        $meta     = meta_line($anime);
        $rank     = isset($opts['rank']) ? (int) $opts['rank'] : 0;
        $eager    = !empty($opts['eager']);
        $progress = $opts['progress'] ?? null;
        $extra    = (string) ($opts['class'] ?? '');
        $badge    = $opts['badge'] ?? '';

        $rankHtml = $rank > 0 ? '<span class="card__rank" aria-label="Rank ' . $rank . '">' . $rank . '</span>' : '';
        $badgeHtml = $badge !== '' ? '<span class="card__badge">' . e($badge) . '</span>' : '';
        $scoreHtml = is_numeric($score)
            ? '<span class="card__score">' . self::icon('star', 12) . number_format((float) $score, 1) . '</span>'
            : '';
        $formatHtml = $format ? '<span class="card__format">' . e(format_label((string) $format)) . '</span>' : '';
        $progressHtml = is_numeric($progress)
            ? '<span class="card__progress"><i style="width:' . max(0, min(100, (float) $progress)) . '%"></i></span>'
            : '';

        return '<article class="card ' . e($extra) . '" style="--card-accent:' . e($accent) . '">'
            . '<a class="card__media" href="' . e(anime_url($id)) . '" aria-label="' . e($title) . '">'
            . '<span class="card__fallback" aria-hidden="true">' . e(initials_of($title)) . '</span>'
            . '<img src="' . e($poster) . '" alt="' . e($title . ' poster') . '" loading="' . ($eager ? 'eager' : 'lazy') . '" decoding="async"'
            . ($eager ? ' fetchpriority="high"' : '') . '>'
            . '<span class="card__shade"></span>'
            . $rankHtml . $badgeHtml . $formatHtml . $scoreHtml . $progressHtml
            . '</a>'
            . '<a class="card__play" href="' . e(watch_url($id, 1)) . '" aria-label="Play ' . e($title) . '" title="Play episode 1">'
            . self::icon('play', 16) . '</a>'
            . '<div class="card__body">'
            . '<a class="card__title" href="' . e(anime_url($id)) . '">' . e($title) . '</a>'
            . ($meta !== '' ? '<p class="card__meta">' . e($meta) . '</p>' : '')
            . '</div>'
            . '</article>';
    }

    public static function posterCard(array $item, string $relation = ''): string
    {
        $id     = (int) ($item['id'] ?? 0);
        $title  = title_of($item);
        $poster = poster_of($item);
        $accent = accent_of($item);
        $sub    = '';
        if ($relation !== '') {
            $sub = str_replace('_', ' ', $relation);
        }
        if (!empty($item['year'])) {
            $sub = trim($sub . ' · ' . $item['year'], ' ·');
        }

        return '<a class="mini-card" href="' . e(anime_url($id)) . '" style="--card-accent:' . e($accent) . '">'
            . '<span class="mini-card__media">'
            . '<span class="card__fallback" aria-hidden="true">' . e(initials_of($title)) . '</span>'
            . '<img src="' . e($poster) . '" alt="' . e($title) . '" loading="lazy" decoding="async">'
            . '</span>'
            . '<span class="mini-card__title">' . e($title) . '</span>'
            . ($sub !== '' ? '<span class="mini-card__meta">' . e(ucwords(strtolower($sub))) . '</span>' : '')
            . '</a>';
    }

    public static function row(string $title, array $items, ?string $href = null, array $opts = []): string
    {
        if (!$items) {
            return '';
        }
        $subtitle = (string) ($opts['subtitle'] ?? '');
        $ranked   = !empty($opts['ranked']);
        $eager    = !empty($opts['eager']);
        $cards    = '';
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $cards .= self::card($item, [
                'rank'  => $ranked ? $i + 1 : 0,
                'eager' => $eager && $i < 6,
            ]);
        }
        if ($cards === '') {
            return '';
        }

        $link = $href !== null
            ? '<a class="section-head__link" href="' . e($href) . '">View all ' . self::icon('arrow', 15) . '</a>'
            : '';

        return '<section class="rowblock" data-rowblock>'
            . '<div class="section-head">'
            . '<div class="section-head__text"><h2>' . e($title) . '</h2>'
            . ($subtitle !== '' ? '<p>' . e($subtitle) . '</p>' : '') . '</div>'
            . $link
            . '</div>'
            . '<div class="rowblock__viewport">'
            . '<button class="rowblock__arrow rowblock__arrow--prev" type="button" data-row-prev aria-label="Scroll left">' . self::icon('back', 18) . '</button>'
            . '<div class="rowblock__scroller" data-row-scroller>' . $cards . '</div>'
            . '<button class="rowblock__arrow rowblock__arrow--next" type="button" data-row-next aria-label="Scroll right">' . self::icon('arrow', 18) . '</button>'
            . '</div>'
            . '</section>';
    }

    public static function genreTile(string $genre, int $index = 0): string
    {
        $hue = (int) (($index * 47) % 360);
        return '<a class="genre-tile" href="' . e(genre_url($genre)) . '" style="--hue:' . $hue . '">'
            . '<span class="genre-tile__name">' . e($genre) . '</span>'
            . '<span class="genre-tile__arrow">' . self::icon('arrow', 18) . '</span>'
            . '</a>';
    }

    public static function emptyState(string $title, string $text, string $icon = 'spark'): string
    {
        return '<div class="empty-state">'
            . '<span class="empty-state__icon">' . self::icon($icon, 28) . '</span>'
            . '<h3>' . e($title) . '</h3>'
            . '<p>' . e($text) . '</p>'
            . '</div>';
    }
}
