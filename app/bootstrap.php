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
