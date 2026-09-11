<?php
// FNCRB configuration.
// Environment-specific overrides live in config/config.local.php (git-ignored),
// so deployments never clobber server credentials. Copy the 'db' (and optionally
// 'app') block there and adjust per environment.
$config = [
    'app' => [
        'name'    => 'First National Credit Registry Bureau',
        'short'   => 'FNCRB',
        'env'     => 'development',   // development | production
        'base_url'=> '/FNCRB/public',
        'secret'  => 'CHANGE-ME-32+random-chars-in-production',
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'fncrb',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name'       => 'FNCRBSESS',
        'session_lifetime'   => 1800,        // 30 min inactivity timeout
        'max_failed_logins'  => 5,
        'lockout_minutes'    => 15,
        'min_password_len'   => 10,
        'csrf_token_name'    => '_csrf',
        'consent_ttl_days'   => 90,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
return $config;
