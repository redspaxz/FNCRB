<?php
declare(strict_types=1);
// ---------------------------------------------------------------------
// FNCRB front controller — bootstrap, security headers, routing
// ---------------------------------------------------------------------

require dirname(__DIR__) . '/app/bootstrap.php';

\App\Core\Auth::start();
\App\Core\Lang::init();

// Security headers (OWASP secure headers)
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-XSS-Protection: 0');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; frame-ancestors 'none'");
if (($_SERVER['HTTPS'] ?? '') !== '') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

$router = new \App\Core\Router();
require dirname(__DIR__) . '/app/routes.php';

// API-key resolution for machine channels (must run before dispatch of /api routes)
\App\Core\ApiAuth::institution();

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
