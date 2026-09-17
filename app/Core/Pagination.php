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
        $q0 = $_GET;
        unset($q0['page']);
        $mk = function (int $p) use ($path, $q0) {
            $q0['page'] = $p;
            return Rbac::baseUrl() . $path . '?' . http_build_query($q0);
        };

        // per-page selector (GET form preserves current filters; auto-submits via app.js)
        $options = '';
        foreach ([10, 25, 50, 100, 200] as $n) {
            $sel = $n === $perPage ? ' selected' : '';
            $options .= "<option value=\"$n\"$sel>$n</option>";
        }
        $hidden = '';
        foreach ($q0 as $k => $v) {
            if ($k === 'per_page') continue;
            $hidden .= '<input type="hidden" name="' . htmlspecialchars((string)$k) . '" value="' . htmlspecialchars((string)$v) . '">';
        }
        $selector = '<form method="get" action="' . Rbac::baseUrl() . $path . '" class="pager-select-form d-inline-flex align-items-center gap-2 ms-3">'
            . $hidden
            . '<label class="mb-0" for="per-page-sel" style="font-size:11.5px;">Rows</label>'
            . '<select name="per_page" class="form-select form-select-sm pager-select">' . $options . '</select>'
            . '<noscript><button class="btn btn-sm">Go</button></noscript></form>';

        if ($pages <= 1 && $total <= $perPage) {
            return ($total ? '<div class="chart-caption">Showing all ' . $total . ' record(s)</div>'
                          : '<div class="chart-caption">No records</div>')
                 . '<div class="d-flex justify-content-center mt-1">' . $selector . '</div>';
        }

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
        $html .= '</ul><div class="d-flex align-items-center flex-wrap">' . $selector . '</div>';
        $html .= '<div class="chart-caption">Showing ' . $from . '–' . $to . ' of ' . $total
               . ' record(s) · ' . $perPage . ' per page</div></nav>';
        return $html;
    }
}
