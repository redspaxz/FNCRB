<?php
// FNCRB configuration.
// Environment-specific overrides live in config/config.local.php (git-ignored),
// so deployments never clobber server credentials. Copy the 'db' (and optionally
// 'app') block there and adjust per environment. FNCRB_DB_* environment
// variables (CI / test runs) take precedence over both.
$config = [
    'app' => [
        'name'    => 'First National Credit Registry Bureau',
        'short'   => 'FNCRB',
        'env'     => 'development',   // development | production
        'base_url'=> '/FNCRB/public',
        'timezone'=> 'Africa/Douala',   // PHP and every DB session use this zone
        // Used to encrypt TOTP secrets at rest — MUST be overridden in production.
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
        'session_lifetime'   => 1800,        // 30 min inactivity timeout (logout)
        'session_absolute'   => 43200,       // 12 h absolute session lifetime
        'max_failed_logins'  => 5,           // per account → temporary account lock
        'max_failed_per_ip'  => 20,          // per source IP (password spraying)
        'lockout_minutes'    => 15,
        'min_password_len'   => 10,
        'csrf_token_name'    => '_csrf',
        'consent_ttl_days'   => 90,          // validity of a recorded borrower consent
        'consent_max_age_days' => 30,        // signature date may not be older than this when recorded
        'consent_evidence_max_bytes' => 5 * 1024 * 1024,
        // Prudential calibration — align with the official COBAC/BEAC figures
        // before production (see ConcentrationService / ClassificationService).
        'single_borrower_limit_pct' => 25.0,
    ],
    'api' => [
        'max_records_per_batch' => 5000,
        'max_body_bytes'        => 10 * 1024 * 1024,
    ],
    'retention' => [
        'consent_months'        => 12,  // after expiry/revocation, when no inquiry references it
        'login_attempt_months'  => 6,
        'score_snapshot_months' => 24,
        'loan_history_months'   => 60,
    ],
    // Macro-financial analytics denominators — update from BEAC/INS statistics.
    'macro' => [
        'adult_population'   => 14_000_000, // adults (15+) in Cameroon, illustrative
        'legal_entities'     => 250_000,    // registered companies (RCCM), illustrative
        'npl_dpd_threshold'  => 90,         // days past due beyond which an account is NPL
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
foreach (['host', 'port', 'name', 'user', 'pass'] as $k) {
    $v = getenv('FNCRB_DB_' . strtoupper($k));
    if ($v !== false) $config['db'][$k] = $k === 'port' ? (int)$v : $v;
}
return $config;
