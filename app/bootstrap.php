<?php
declare(strict_types=1);

// Bootstrap: load every Core/Services/Controllers class (modular monolith,
// no composer dependency required). Simple require-all autoloading.
$roots = [__DIR__ . '/Core', __DIR__ . '/Services', __DIR__ . '/Controllers'];
foreach ($roots as $dir) {
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        require_once $file;
    }
}

date_default_timezone_set((string)\App\Core\Config::get('app.timezone', 'Africa/Douala'));

// Errors: never leak stack traces or SQL in production; always log them.
if (\App\Core\Config::get('app.env') === 'production') {
    ini_set('display_errors', '0');
}
set_exception_handler(static function (\Throwable $e): void {
    $ref = bin2hex(random_bytes(4));
    error_log("FNCRB[$ref] " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error [$ref]: " . $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) http_response_code(500);
    $dev = \App\Core\Config::get('app.env') !== 'production';
    $msg = $dev ? $e->getMessage() : 'Internal error — reference ' . $ref;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $wantsJson = str_contains($uri, '/api/') || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
    if ($wantsJson) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'INTERNAL_ERROR', 'message' => $msg, 'reference' => $ref]]);
        return;
    }
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Error</title><div style="font-family:sans-serif;margin:10vh auto;max-width:560px;text-align:center">'
        . '<h1>500</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div>';
});
