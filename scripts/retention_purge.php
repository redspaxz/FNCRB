<?php
declare(strict_types=1);
/**
 * CLI: data-retention housekeeping (Law 2010/012 / privacy hygiene).
 * - expire consents past retention (kept as rows, marked expired — they already
 *   self-expire via expires_at; here we hard-delete those long past)
 * - purge stale login_attempts
 * - prune score snapshots older than the configured horizon
 * - NEVER touches audit_logs (tamper-proof chain must remain complete)
 *
 * Usage: php scripts/retention_purge.php        (schedule monthly via cron)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$c = require dirname(__DIR__) . '/config/config.php';
$months = (int)($c['security']['consent_ttl_days'] ?? 90) >= 0 ? 24 : 24; // horizon in months

$pdo = \App\Core\Database::pdo();
$stats = [];

$pdo->beginTransaction();
try {
    $n = $pdo->exec("DELETE FROM consents WHERE expires_at < (NOW() - INTERVAL 12 MONTH)");
    $stats['consents_deleted'] = $n;

    $n = $pdo->exec("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 6 MONTH)");
    $stats['login_attempts_deleted'] = $n;

    $n = $pdo->exec("DELETE FROM score_snapshots WHERE computed_at < (NOW() - INTERVAL {$months} MONTH)");
    $stats['score_snapshots_deleted'] = $n;

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Purge failed: {$e->getMessage()}\n");
    exit(1);
}

\App\Core\Audit::log('RETENTION_PURGE', null, $stats);
echo json_encode($stats, JSON_PRETTY_PRINT), "\n";
