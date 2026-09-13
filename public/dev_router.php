<?php
// Dev-only router for `php -S <host> -t public public/dev_router.php`:
// serve real files directly, route everything else through the front controller.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false; // built-in server serves the static file
}
require __DIR__ . '/index.php';
