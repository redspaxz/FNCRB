<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Tamper-proof audit trail: append-only rows hash-chained (row_hash = SHA-256 over
 * prev_hash + payload), so any deletion/mutation of a historical row breaks the chain
 * (detected by verifyChain).
 */
final class Audit
{
    public static function log(string $action, ?array $entity = null, array $details = []): void
    {
        $pdo = Database::pdo();
        $user = Auth::user();
        $userId = $user['id'] ?? null;
        $instId = $user['institution_id'] ?? (ApiAuth::institution()['id'] ?? null);

        $prev = $pdo->query("SELECT row_hash FROM audit_logs ORDER BY id DESC LIMIT 1")->fetchColumn();
        $prevHash = $prev ?: str_repeat('0', 64);

        $payload = json_encode([
            'action' => $action,
            'entity' => $entity,
            'details' => $details,
            'user_id' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            't' => microtime(true),
        ], JSON_UNESCAPED_UNICODE);

        $rowHash = hash('sha256', $prevHash . '|' . $payload);

        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (user_id, institution_id, action, entity, entity_id, details, ip_address, prev_hash, row_hash)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $userId, $instId, $action,
            $entity['type'] ?? null, (string)($entity['id'] ?? ''),
            json_encode($details, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $prevHash, $rowHash,
        ]);
    }

    /** Verify chain linkage; returns [ok, firstBrokenId]. */
    public static function verifyChain(): array
    {
        $rows = Database::pdo()->query(
            "SELECT id, prev_hash, row_hash FROM audit_logs ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $prev = str_repeat('0', 64);
        foreach ($rows as $r) {
            if (($r['prev_hash'] ?? '') !== $prev) {
                return [false, (int)$r['id']];
            }
            $prev = $r['row_hash'];
        }
        return [true, null];
    }
}
