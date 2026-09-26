<?php
declare(strict_types=1);

namespace App\Core;

/**
 * App-wide pagination. Controllers compute [total, rows]; the view calls
 * Pagination::render(path, page, perPage, total) for the control bar.
 * Query params (filters/search) are carried across pages automatically.
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 200;
    private const SIZES = [10, 25, 50, 100, 200];

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

    /** Scalar query params only (arrays/objects are dropped, never echoed). */
    private static function query(): array
    {
        return array_filter($_GET, fn($v, $k) => is_string($k) && is_scalar($v) && $k !== 'page', ARRAY_FILTER_USE_BOTH);
    }

    /** Bootstrap-styled pager (square corners per theme). Preserves current query params. */
    public static function render(string $path, int $page, int $perPage, int $total): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $pages = self::pageCount($total, $perPage);
        $q0 = self::query();
        $mk = function (int $p) use ($path, $q0, $h) {
            $q0['page'] = $p;
            return $h(Rbac::baseUrl() . $path . '?' . http_build_query($q0));
        };

        $options = '';
        $sizes = in_array($perPage, self::SIZES, true) ? self::SIZES : array_merge(self::SIZES, [$perPage]);
        sort($sizes);
        foreach ($sizes as $n) {
            $options .= '<option value="' . $n . '"' . ($n === $perPage ? ' selected' : '') . '>' . $n . '</option>';
        }
        $hidden = '';
        foreach ($q0 as $k => $v) {
            if ($k === 'per_page') continue;
            $hidden .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
        }
        $selId = 'per-page-' . substr(md5($path), 0, 6);
        $selector = '<form method="get" action="' . $h(Rbac::baseUrl() . $path) . '" class="pager-select-form d-inline-flex align-items-center gap-2 ms-3">'
            . $hidden
            . '<label class="mb-0" for="' . $selId . '" style="font-size:11.5px;">Rows</label>'
            . '<select id="' . $selId . '" name="per_page" class="form-select form-select-sm pager-select">' . $options . '</select>'
            . '<noscript><button class="btn btn-sm">Go</button></noscript></form>';

        if ($pages <= 1 && $page <= 1) {
            return ($total ? '<div class="chart-caption">Showing all ' . $total . ' record(s)</div>'
                          : '<div class="chart-caption">No records</div>')
                 . '<div class="d-flex justify-content-center mt-1">' . $selector . '</div>';
        }

        $html = '<nav aria-label="Table pagination"><ul class="pagination pagination-sm flex-wrap">';
        $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">'
               . '<a class="page-link" href="' . ($page <= 1 ? '#' : $mk(min($page - 1, $pages))) . '">&laquo;</a></li>';

        $window = 2;
        $links = array_unique(array_merge(
            [1, $pages],
            range(max(1, min($page, $pages) - $window), min($pages, $page + $window))
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
        if ($page > $pages) {
            $html .= '<div class="chart-caption">Page ' . $page . ' is beyond the last page (' . $pages . ') — '
                   . '<a href="' . $mk($pages) . '">go to last page</a>.</div></nav>';
        } else {
            $from = ($page - 1) * $perPage + 1;
            $to = min($page * $perPage, $total);
            $html .= '<div class="chart-caption">Showing ' . $from . '–' . $to . ' of ' . $total
                   . ' record(s) · ' . $perPage . ' per page</div></nav>';
        }
        return $html;
    }
}
