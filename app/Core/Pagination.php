<?php
declare(strict_types=1);

namespace App\Core;

/**
 * App-wide pagination. Controllers compute [total, rows]; the view calls
 * Pagination::render(path, page, perPage, total, extraQuery) for the control bar.
 * Query params (filters/search) are carried across pages automatically.
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 200;

    /** Read ?page from the request (1-based, sanitized). */
    public static function page(): int
    {
        $p = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        return $p ?: 1;
    }

    public static function perPage(int $default = self::DEFAULT_PER_PAGE): int
    {
        $p = filter_var($_GET['per_page'] ?? $default, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => self::MAX_PER_PAGE]]);
        return $p ?: $default;
    }

    public static function offset(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }

    public static function pageCount(int $total, int $perPage): int
    {
        return max(1, (int)ceil($total / max($perPage, 1)));
    }

    /** Bootstrap-styled pager (square corners per theme). Preserves current query params. */
    public static function render(string $path, int $page, int $perPage, int $total): string
    {
        $pages = self::pageCount($total, $perPage);
        if ($pages <= 1 && $total <= $perPage) {
            return $total ? '<div class="chart-caption">Showing all ' . $total . ' record(s)</div>'
                          : '<div class="chart-caption">No records</div>';
        }

        $q = $_GET;
        $mk = function (int $p) use ($path, $q) {
            $q['page'] = $p;
            return Rbac::baseUrl() . $path . '?' . http_build_query($q);
        };

        $from = ($page - 1) * $perPage + 1;
        $to = min($page * $perPage, $total);

        $html = '<nav aria-label="Table pagination"><ul class="pagination pagination-sm flex-wrap">';
        $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">'
               . '<a class="page-link" href="' . ($page <= 1 ? '#' : $mk($page - 1)) . '">&laquo;</a></li>';

        $window = 2;
        $links = array_unique(array_merge(
            [1, $pages],
            range(max(1, $page - $window), min($pages, $page + $window))
        ));
        sort($links);
        $prev = 0;
        foreach ($links as $p) {
            if ($p - $prev > 1) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            $html .= '<li class="page-item' . ($p === $page ? ' active' : '') . '">'
                   . '<a class="page-link" href="' . $mk($p) . '">' . $p . '</a></li>';
            $prev = $p;
        }

        $html .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '">'
               . '<a class="page-link" href="' . ($page >= $pages ? '#' : $mk($page + 1)) . '">&raquo;</a></li>';
        $html .= '</ul>';
        $html .= '<div class="chart-caption">Showing ' . $from . '–' . $to . ' of ' . $total
               . ' record(s) · ' . $perPage . ' per page</div></nav>';
        return $html;
    }
}
