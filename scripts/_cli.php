<?php
declare(strict_types=1);
// Shared CLI bootstrap: refuse to run over HTTP (defense in depth if the
// scripts/ directory is ever web-exposed), then load the application.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
