<?php
// Example local overrides — copy to config/config.local.php on each environment.
// config.local.php is git-ignored, so deploys never overwrite it.
return [
    'app' => [
        'env'      => 'production',
        // 32+ random characters, e.g. php -r "echo bin2hex(random_bytes(24));"
        // Encrypts 2FA seeds at rest: changing it later invalidates existing 2FA enrolments.
        'secret'   => 'GENERATE-32+-RANDOM-CHARS',
        'timezone' => 'Africa/Douala',
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'ttecwymc_fncrb',
        'user' => 'ttecwymc_fncrb',
        'pass' => 'YOUR-DB-PASSWORD',
    ],
];
