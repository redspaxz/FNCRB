<?php
declare(strict_types=1);
/**
 * CLI: late/missing reporting monitor (BEAC/COBAC periodicity control).
 * Flags institutions whose most recent loan reported_at is older than the
 * threshold, writes an audit entry and prints a supervisory alert table.
 *
 * Usage: php scripts/late_report_monitor.php [max_age_days]
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$maxAge = (int)($argv[1] ?? 35);
$pdo = \App\Core\Database::pdo();

$stmt = $pdo->prepare(
    "SELECT i.code, i.name, i.category, MAX(l.reported_at) AS last_report,
            DATEDIFF(NOW(), MAX(l.reported_at)) AS age_days
     FROM institutions i
     LEFT JOIN loans l ON l.institution_id = i.id
     WHERE i.status = 'ACTIVE' AND i.category != 'REGULATOR'
     GROUP BY i.id
     HAVING last_report IS NULL OR age_days > ?"
);
$stmt->execute([$maxAge]);
$late = $stmt->fetchAll();

if (!$late) {
    echo "All reporting institutions are within the {$maxAge}-day threshold.\n";
    exit(0);
}

echo str_pad('CODE', 14), str_pad('CATEGORY', 10), str_pad('LAST REPORT', 14), "INSTITUTION\n";
foreach ($late as $r) {
    echo str_pad($r['code'], 14), str_pad($r['category'], 10),
         str_pad($r['last_report'] ?? 'NEVER', 14), $r['name'], "\n";
}
\App\Core\Audit::log('LATE_REPORTING_DETECTED', null, [
    'threshold_days' => $maxAge,
    'institutions' => array_column($late, 'code'),
]);
exit(2); // non-zero so cron/monitoring can alert
