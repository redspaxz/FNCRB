<?php
declare(strict_types=1);

// Fallback entry point for hosts where the root .htaccess rewrite is absent
// (e.g. dotfiles not deployed). Serving /fncrb/ or /fncrb/index.php works
// without mod_rewrite. Pretty URLs (/fncrb/login) still require the .htaccess.
require __DIR__ . '/public/index.php';
