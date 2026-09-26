<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Tamper-evident audit trail.
 *
 * Every row is hash-chained: row_hash = SHA-256(prev_hash | canonical payload),
 * where the payload covers the row's stored content (action, entity, details,
 * user, institution, IP, millisecond timestamp). Verification recomputes each
 * hash, so deleting, inserting OR editing any row breaks the chain.
 *
 * - Rows are written on a dedicated connection under a named DB lock, so
 *   concurrent requests cannot fork the chain, and entries survive business
 *   transactions that roll back.
 * - hash_version 1 rows (written before this scheme) can only be checked for
 *   linkage; they are reported as "legacy" by verify().
 * - verify() is incremental from the last verified checkpoint; the nightly
 *   CLI (scripts/verify_audit_chain.php) runs a full pass.
 */
final class Audit
{
    private const LOCK = 'fncrb_audit_chain';
    private const TRAILING_WINDOW = 1000;
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function log(string $action, ?array $entity = null, array $details = []): void
    {
        $pdo = Database::pdo('audit');
        $user = Auth::user();
        $row = [
            'action'         => $action,
            'entity'         => isset($entity['type']) ? (string)$entity['type'] : null,
            'entity_id'      => (string)($entity['id'] ?? ''),
            'details'        => self::canonicalJson($details),
            'user_id'        => $user['id'] ?? null,
            'institution_id' => $user['institution_id'] ?? (ApiGuard::institution()['id'] ?? null),
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'ts_ms'          => (int)floor(microtime(true) * 1000),
        ];

        $pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 10)")->fetchColumn();
        try {
            $prev = $pdo->query("SELECT row_hash FROM audit_logs ORDER BY id DESC LIMIT 1")->fetchColumn();
            $row['prev_hash'] = $prev ?: self::GENESIS;
            $row['row_hash'] = self::hashRow($row);
            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, institution_id, action, entity, entity_id, details, ip_address,
                                         prev_hash, row_hash, hash_version, ts_ms)
                 VALUES (?,?,?,?,?,?,?,?,?,2,?)"
            )->execute([
                $row['user_id'], $row['institution_id'], $row['action'], $row['entity'], $row['entity_id'],
                $row['details'], $row['ip_address'], $row['prev_hash'], $row['row_hash'], $row['ts_ms'],
            ]);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')")->fetchColumn();
        }
    }

    /** Hash of a v2 row; all values normalized so DB round-trips hash identically. */
    public static function hashRow(array $r): string
    {
        $details = $r['details'];
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            $details = self::canonicalJson(is_array($decoded) ? $decoded : []);
        }
        $payload = json_encode([
            2,
            (string)$r['action'],
            $r['entity'] === null || $r['entity'] === '' ? null : (string)$r['entity'],
            (string)($r['entity_id'] ?? ''),
            $details,
            $r['user_id'] === null ? null : (int)$r['user_id'],
            $r['institution_id'] === null ? null : (int)$r['institution_id'],
            $r['ip_address'] === null ? null : (string)$r['ip_address'],
            (int)$r['ts_ms'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return hash('sha256', $r['prev_hash'] . '|' . $payload);
    }

    public static function canonicalJson(array $data): string
    {
        $sort = function ($v) use (&$sort) {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v, SORT_STRING);
            return array_map($sort, $v);
        };
        return json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Back-compat: [ok, firstBrokenId]. */
    public static function verifyChain(bool $full = false): array
    {
        $r = self::verify($full);
        return [$r['ok'], $r['broken_at']];
    }

    /**
     * @return array{ok:bool, broken_at:?int, reason:?string, checked:int, legacy_rows:int, full:bool}
     */
    public static function verify(bool $full = false): array
    {
        $pdo = Database::pdo('audit');
        $startId = 0;
        $prev = self::GENESIS;
        if (!$full) {
            $cp = $pdo->query("SELECT last_id, last_hash, broken_at, broken_reason FROM audit_checkpoints WHERE id = 1")->fetch();
            if ($cp && $cp['broken_at'] !== null) {
                // a failed full verification stays visible until a full pass succeeds
                return self::result(false, (int)$cp['broken_at'], (string)$cp['broken_reason'], 0, 0, $full);
            }
            if ($cp) {
                $chk = $pdo->prepare("SELECT row_hash FROM audit_logs WHERE id = ?");
                $chk->execute([$cp['last_id']]);
                if ($chk->fetchColumn() !== $cp['last_hash']) {
                    return self::result(false, (int)$cp['last_id'], 'checkpoint row missing or altered', 0, 0, $full);
                }
                // re-check a trailing window before the checkpoint as well (cheap extra coverage)
                $win = $pdo->prepare("SELECT id, row_hash FROM audit_logs WHERE id <= ? ORDER BY id DESC LIMIT 1 OFFSET " . self::TRAILING_WINDOW);
                $win->execute([$cp['last_id']]);
                $anchor = $win->fetch();
                if ($anchor) {
                    $startId = (int)$anchor['id'];
                    $prev = $anchor['row_hash'];
                } else {
                    $startId = 0;
                }
            }
        }

        $checked = 0;
        $legacy = 0;
        $lastId = $startId;
        $stmt = $pdo->prepare(
            "SELECT id, user_id, institution_id, action, entity, entity_id, details, ip_address,
                    prev_hash, row_hash, hash_version, ts_ms
             FROM audit_logs WHERE id > ? ORDER BY id ASC LIMIT 2000"
        );
        while (true) {
            $stmt->execute([$lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            foreach ($rows as $r) {
                if (($r['prev_hash'] ?? '') !== $prev) {
                    return self::fail($pdo, (int)$r['id'], 'link broken (row deleted, inserted or re-hashed)', $checked, $legacy, $full);
                }
                if ((int)$r['hash_version'] >= 2) {
                    if (!hash_equals(self::hashRow($r), (string)$r['row_hash'])) {
                        return self::fail($pdo, (int)$r['id'], 'row content altered', $checked, $legacy, $full);
                    }
                } else {
                    $legacy++;
                }
                $prev = $r['row_hash'];
                $lastId = (int)$r['id'];
                $checked++;
            }
        }

        if ($lastId > 0) {
            $pdo->prepare(
                "INSERT INTO audit_checkpoints (id, last_id, last_hash, verified_at) VALUES (1, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE last_id = VALUES(last_id), last_hash = VALUES(last_hash), verified_at = NOW()"
                . ($full ? ", broken_at = NULL, broken_reason = NULL" : "")
            )->execute([$lastId, $prev]);
        }
        return self::result(true, null, null, $checked, $legacy, $full);
    }

    /** Record a failure; a full-verification failure is persisted (sticky) for the incremental check. */
    private static function fail(PDO $pdo, int $id, string $reason, int $checked, int $legacy, bool $full): array
    {
        $pdo->prepare(
            "INSERT INTO audit_checkpoints (id, last_id, last_hash, verified_at, broken_at, broken_reason) VALUES (1, 0, '', NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE broken_at = VALUES(broken_at), broken_reason = VALUES(broken_reason)"
        )->execute([$id, $reason]);
        return self::result(false, $id, $reason, $checked, $legacy, $full);
    }

    private static function result(bool $ok, ?int $at, ?string $reason, int $checked, int $legacy, bool $full): array
    {
        return ['ok' => $ok, 'broken_at' => $at, 'reason' => $reason, 'checked' => $checked, 'legacy_rows' => $legacy, 'full' => $full];
    }
}
