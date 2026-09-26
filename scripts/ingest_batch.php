<?php
declare(strict_types=1);
// CLI: batch ingestion from a COBAC-standardized JSON file (daily/monthly CBS export).
// Usage: php scripts/ingest_batch.php <institution_code> <portfolio.json>
// JSON: [{"borrower":{"full_name":"...","cni_number":"..."},"contract_ref":"...", ...}, ...]
//   or  {"schema_version":"1.0","loans":[...]}
require __DIR__ . '/_cli.php';

$code = $argv[1] ?? null;
$file = $argv[2] ?? null;
if (!$code || !$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/ingest_batch.php <institution_code> <portfolio.json>\n");
    exit(1);
}
$stmt = App\Core\Database::pdo()->prepare("SELECT id FROM institutions WHERE code = ? AND status='ACTIVE' AND category != 'REGULATOR'");
$stmt->execute([$code]);
$instId = $stmt->fetchColumn();
if (!$instId) { fwrite(STDERR, "Institution not found/inactive: $code\n"); exit(1); }

$data = json_decode((string)file_get_contents($file), true);
$records = is_array($data) && isset($data['loans']) ? $data['loans'] : $data;
if (!is_array($records) || !array_is_list($records)) { fwrite(STDERR, "Invalid JSON: expected a list of loan records\n"); exit(1); }

$r = App\Services\IngestionService::ingestBatch($records, (int)$instId, 'BATCH');
foreach ($r['errors'] as $err) {
    fwrite(STDERR, "REJECTED #{$err['index']}" . ($err['contract_ref'] ?? '' ? " ({$err['contract_ref']})" : '') . ': ' . implode('; ', $err['messages']) . "\n");
}
App\Core\Audit::log('DATA_WRITE', ['type' => 'batch', 'id' => basename($file)], [
    'institution' => $code, 'accepted' => $r['accepted'], 'rejected' => $r['rejected'], 'source' => 'BATCH',
    'sha256' => hash_file('sha256', $file),
]);
echo "Ingested {$r['accepted']}/" . count($records) . " records for $code ({$r['rejected']} rejected)\n";
exit($r['rejected'] > 0 ? 2 : 0);
