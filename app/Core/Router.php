<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal MVC router with {param} placeholders.
 * Routes are declared in app/routes.php.
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void    { $this->add('GET', $path, $handler); }
    public function post(string $path, array $handler): void   { $this->add('POST', $path, $handler); }

    private function add(string $method, string $path, array $handler): void
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[$method]['#^' . $regex . '$#'] = $handler;
    }

    /** Request path relative to the application base (e.g. "/loans"). */
    public static function path(string $uri): string
    {
        $path = (string)parse_url($uri, PHP_URL_PATH);
        // strip sub-directory base if the entry script lives in one (e.g. /fncrb/public).
        // only when SCRIPT_NAME actually points at a PHP entry script — the built-in
        // dev server sets it to the full URI for dotted paths (/reports/loans.csv).
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = (pathinfo($script, PATHINFO_EXTENSION) === 'php')
            ? rtrim(str_replace('\\', '/', dirname($script)), '/')
            : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');
        if (substr($path, -10) === '/index.php') {
            $path = substr($path, 0, -9); // treat /index.php as directory root
            $path = '/' . trim($path, '/');
        }
        return $path;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = self::path($uri);
        foreach ($this->routes[$method] ?? [] as $regex => $handler) {
            if (preg_match($regex, $path, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                [$class, $action] = $handler;
                $ctl = new $class();
                $ctl->$action($params);
                return;
            }
        }
        // known path, wrong verb
        foreach ($this->routes as $verb => $set) {
            if ($verb === $method) continue;
            foreach ($set as $regex => $_) {
                if (preg_match($regex, $path)) {
                    http_response_code(405);
                    (new \App\Controllers\PageController())->error(405, 'Method not allowed.');
                    return;
                }
            }
        }
        http_response_code(404);
        (new \App\Controllers\PageController())->notFound();
    }
}
