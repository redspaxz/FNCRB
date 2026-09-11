<?php
declare(strict_types=1);
// CLI: batch ingestion from a COBAC-standardized JSON file (daily/monthly CBS export).
// Usage: php scripts/ingest_batch.php <institution_code> <portfolio.json>
// JSON: [{"borrower":{"full_name":"...","cni_number":"..."},"contract_ref":"...", ...}, ...]
require dirname(__DIR__) . '/app/bootstrap.php';

$code = $argv[1] ?? null;
$file = $argv[2] ?? null;
if (!$code || !$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/ingest_batch.php <institution_code> <portfolio.json>\n");
    exit(1);
}
$stmt = App\Core\Database::pdo()->prepare("SELECT id FROM institutions WHERE code = ? AND status='ACTIVE'");
$stmt->execute([$code]);
$instId = $stmt->fetchColumn();
if (!$instId) { fwrite(STDERR, "Institution not found/inactive: $code\n"); exit(1); }

$records = json_decode(file_get_contents($file), true);
if (!is_array($records)) { fwrite(STDERR, "Invalid JSON\n"); exit(1); }

$ok = 0;
foreach ($records as $rec) {
    if (App\Services\IngestionService::upsertLoan($rec, (int)$instId, 'BATCH')) $ok++;
    else fwrite(STDERR, "REJECTED: " . implode('; ', App\Services\IngestionService::$errors) . "\n");
}
App\Core\Audit::log('DATA_WRITE', ['type' => 'batch', 'id' => $file], ['institution' => $code, 'accepted' => $ok, 'total' => count($records)]);
echo "Ingested $ok/" . count($records) . " records for $code\n";
