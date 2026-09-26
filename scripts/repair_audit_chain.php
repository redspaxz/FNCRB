<?php
declare(strict_types=1);
/**
 * CLI: audit-chain repair tool.
 *
 * Re-links the hash chain in id order after an AUTHORIZED operation removed or
 * archived rows (e.g. regulator evidence extraction), re-hashing every row with
 * the full-content (v2) scheme. The repair is recorded as an
 * AUDIT_CHAIN_REPAIRED entry carrying the pre-repair verification result, so
 * the action is transparent.
 *
 * IMPORTANT: never use this to hide tampering — run scripts/verify_audit_chain.php
 * first and document the break; only a SUPER_ADMIN/regulator should run it.
 * If the optional DB immutability triggers (database/audit_immutability.sql)
 * are installed, drop them before and re-create them after the repair.
 *
 * Usage: php scripts/repair_audit_chain.php --confirm "<justification / ticket ref>"
 */
require __DIR__ . '/_cli.php';

if (($argv[1] ?? '') !== '--confirm' || mb_strlen(trim($argv[2] ?? '')) < 5) {
    fwrite(STDERR, "Refusing to run without --confirm \"<justification>\".\nThis relinks the audit hash chain; ensure the break is authorized and documented.\n");
    exit(1);
}
$justification = trim($argv[2]);

$before = \App\Core\Audit::verify(true);
$pdo = \App\Core\Database::pdo('audit');
$pdo->query("SELECT GET_LOCK('fncrb_audit_chain', 30)")->fetchColumn();
$prev = str_repeat('0', 64);
$n = 0;
$pdo->beginTransaction();
try {
    $sel = $pdo->prepare(
        "SELECT id, user_id, institution_id, action, entity, entity_id, details, ip_address, ts_ms, created_at
         FROM audit_logs WHERE id > ? ORDER BY id ASC LIMIT 2000"
    );
    $upd = $pdo->prepare("UPDATE audit_logs SET prev_hash = ?, row_hash = ?, hash_version = 2, ts_ms = ? WHERE id = ?");
    $last = 0;
    while (true) {
        $sel->execute([$last]);
        $rows = $sel->fetchAll();
        if (!$rows) break;
        foreach ($rows as $r) {
            $r['ts_ms'] = $r['ts_ms'] !== null ? (int)$r['ts_ms'] : strtotime((string)$r['created_at']) * 1000;
            $r['prev_hash'] = $prev;
            $hash = \App\Core\Audit::hashRow($r);
            $upd->execute([$prev, $hash, $r['ts_ms'], $r['id']]);
            $prev = $hash;
            $last = (int)$r['id'];
            $n++;
        }
    }
    $pdo->exec("DELETE FROM audit_checkpoints");
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Repair failed: {$e->getMessage()}\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('fncrb_audit_chain')")->fetchColumn();
}

\App\Core\Audit::log('AUDIT_CHAIN_REPAIRED', null, [
    'rows_relinked' => $n, 'justification' => $justification,
    'before' => ['ok' => $before['ok'], 'broken_at' => $before['broken_at'], 'reason' => $before['reason']],
]);
echo "Chain repaired across $n rows; repair entry appended.\n";
