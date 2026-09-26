<?php
declare(strict_types=1);
// ---------------------------------------------------------------------
// FNCRB front controller — bootstrap, security headers, routing
// ---------------------------------------------------------------------

require dirname(__DIR__) . '/app/bootstrap.php';

// Security headers (OWASP secure headers). All scripts are external files, so
// inline script execution is refused; inline style attributes remain allowed.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-XSS-Protection: 0');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$path = \App\Core\Router::path($_SERVER['REQUEST_URI'] ?? '/');

// Machine API is stateless (ApiGuard); only browser routes get a session.
if (!str_starts_with($path, '/api/v1/') || $path === '/api/v1/supervisory-package') {
    \App\Core\Auth::start();
    \App\Core\Lang::init();
    \App\Core\Auth::enforce($path);
}

$router = new \App\Core\Router();
require dirname(__DIR__) . '/app/routes.php';

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
