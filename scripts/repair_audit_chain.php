<?php
declare(strict_types=1);
/**
 * CLI: audit-chain repair tool.
 *
 * Re-links the hash chain in id order after an AUTHORIZED operation removed or
 * archived rows (e.g. regulator evidence extraction). The repair itself is
 * recorded as an AUDIT_CHAIN_REPAIRED entry so the action is transparent.
 *
 * IMPORTANT: never use this to hide tampering — the gap in id sequence and the
 * repair entry remain visible, and only a SUPER_ADMIN/regulator should run it.
 *
 * Usage: php scripts/repair_audit_chain.php --confirm
 */
require dirname(__DIR__) . '/app/bootstrap.php';

if (($argv[1] ?? '') !== '--confirm') {
    fwrite(STDERR, "Refusing to run without --confirm.\nThis relinks the audit hash chain; ensure the break is authorized and documented.\n");
    exit(1);
}

$pdo = \App\Core\Database::pdo();
$rows = $pdo->query("SELECT id FROM audit_logs ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
if (!$rows) { echo "Nothing to repair (empty audit log).\n"; exit(0); }

$prev = str_repeat('0', 64);
$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE audit_logs SET prev_hash = ?, row_hash = ? WHERE id = ?");
    foreach ($rows as $id) {
        $rowHash = hash('sha256', $prev . '|repair-anchor|' . $id);
        $upd->execute([$prev, $rowHash, $id]);
        $prev = $rowHash;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Repair failed: {$e->getMessage()}\n");
    exit(1);
}

\App\Core\Audit::log('AUDIT_CHAIN_REPAIRED', null, ['rows_relinked' => count($rows)]);
echo "Chain repaired across " . count($rows) . " rows; repair entry appended.\n";
