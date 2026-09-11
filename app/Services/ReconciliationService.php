<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Module 1 — Identity Reconciliation:
 * merges customer profiles across institutions using CNI, NIU or cooperative ID.
 */
final class ReconciliationService
{
    public static function findByIdentity(?string $cni, ?string $niu, ?string $coopId): ?array
    {
        $pdo = Database::pdo();
        foreach ([['cni_number', $cni], ['niu', $niu], ['coop_member_id', $coopId]] as [$col, $val]) {
            if ($val === null || $val === '') continue;
            $stmt = $pdo->prepare("SELECT * FROM borrowers WHERE $col = ? AND dup_of_id IS NULL LIMIT 1");
            $stmt->execute([$val]);
            $row = $stmt->fetch();
            if ($row) return $row;
        }
        return null;
    }

    public static function findOrCreate(array $d): array
    {
        // direct registry reference wins
        if (!empty($d['id'])) {
            $stmt = Database::pdo()->prepare("SELECT * FROM borrowers WHERE id = ? AND dup_of_id IS NULL");
            $stmt->execute([(int)$d['id']]);
            if ($row = $stmt->fetch()) return $row;
        }
        $existing = self::findByIdentity($d['cni_number'] ?? null, $d['niu'] ?? null, $d['coop_member_id'] ?? null);
        if ($existing) return $existing;

        $ref = 'FNB' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $stmt = Database::pdo()->prepare(
            "INSERT INTO borrowers (master_ref, full_name, date_of_birth, gender, type, cni_number, niu, coop_member_id, phone, region)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $ref, $d['full_name'], $d['date_of_birth'] ?? null, $d['gender'] ?? null,
            $d['type'] ?? 'INDIVIDUAL', $d['cni_number'] ?? null, $d['niu'] ?? null,
            $d['coop_member_id'] ?? null, $d['phone'] ?? null, $d['region'] ?? null,
        ]);
        $stmt2 = Database::pdo()->prepare("SELECT * FROM borrowers WHERE id = ?");
        $stmt2->execute([Database::pdo()->lastInsertId()]);
        return $stmt2->fetch();
    }

    /** Merge duplicates: repoint loans/incidents, mark duplicate row. */
    public static function merge(int $duplicateId, int $masterId): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE loans SET borrower_id = ? WHERE borrower_id = ?")->execute([$masterId, $duplicateId]);
            $pdo->prepare("UPDATE payment_incidents SET borrower_id = ? WHERE borrower_id = ?")->execute([$masterId, $duplicateId]);
            $pdo->prepare("UPDATE borrowers SET dup_of_id = ? WHERE id = ?")->execute([$masterId, $duplicateId]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return false;
        }
    }
}
