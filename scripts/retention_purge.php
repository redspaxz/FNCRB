<?php
declare(strict_types=1);
/**
 * CLI: data-retention housekeeping (Law 2010/012 / privacy hygiene).
 * Horizons come from config.php → 'retention'.
 * - consents: deleted once expired/revoked beyond the horizon AND no inquiry
 *   relies on them (a consent backing a logged inquiry is the lawful-basis
 *   evidence for that inquiry and is kept with it)
 * - stale login_attempts, API nonces, old score snapshots, old loan history
 * - NEVER touches audit_logs (tamper-evident chain must remain complete)
 *
 * Usage: php scripts/retention_purge.php        (schedule monthly via cron)
 */
require __DIR__ . '/_cli.php';

$r = App\Core\Config::get('retention');
$m = fn(string $k, int $d) => max(1, (int)($r[$k] ?? $d));

$pdo = \App\Core\Database::pdo();
$stats = [];

$pdo->beginTransaction();
try {
    $stats['consents_deleted'] = $pdo->exec(
        "DELETE c FROM consents c
         LEFT JOIN inquiry_logs q ON q.consent_id = c.id
         WHERE q.id IS NULL
           AND COALESCE(c.revoked_at, c.expires_at) < (NOW() - INTERVAL {$m('consent_months', 12)} MONTH)"
    );
    $stats['login_attempts_deleted'] = $pdo->exec(
        "DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL {$m('login_attempt_months', 6)} MONTH)"
    );
    $stats['api_nonces_deleted'] = $pdo->exec("DELETE FROM api_nonces WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    $stats['score_snapshots_deleted'] = $pdo->exec(
        "DELETE FROM score_snapshots WHERE computed_at < (NOW() - INTERVAL {$m('score_snapshot_months', 24)} MONTH)"
    );
    $stats['loan_history_deleted'] = $pdo->exec(
        "DELETE FROM loan_history WHERE reported_at < (CURDATE() - INTERVAL {$m('loan_history_months', 60)} MONTH)"
    );
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Purge failed: {$e->getMessage()}\n");
    exit(1);
}

// orphaned consent evidence files
$dir = dirname(__DIR__) . '/storage/consents';
$kept = $pdo->query("SELECT evidence_path FROM consents WHERE evidence_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$kept = array_flip(array_map('basename', $kept));
$stats['evidence_files_deleted'] = 0;
foreach (glob($dir . '/*.{pdf,jpg,png}', GLOB_BRACE) ?: [] as $f) {
    if (!isset($kept[basename($f)]) && filemtime($f) < time() - 86400 && unlink($f)) $stats['evidence_files_deleted']++;
}

\App\Core\Audit::log('RETENTION_PURGE', null, $stats);
echo json_encode($stats, JSON_PRETTY_PRINT), "\n";
