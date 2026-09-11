<?php
// Example local overrides — copy to config/config.local.php on each environment.
// config.local.php is git-ignored, so deploys never overwrite it.
return [
    'app' => [
        'env'   => 'production',
        'secret'=> 'GENERATE-32+-RANDOM-CHARS',
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'ttecwymc_fncrb',
        'user' => 'ttecwymc_fncrb',
        'pass' => 'YOUR-DB-PASSWORD',
    ],
];
