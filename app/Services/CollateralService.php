<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Module 3 — OHADA collateral: RCCM double-pledging detection.
 */
final class CollateralService
{
    /** Detect the same RCCM registration pledged to different institutions/loans. */
    public static function detectDoublePledge(?int $institutionId = null): array
    {
        $sql = "SELECT c.rccm_registration_no,
                       COUNT(*) AS pledges,
                       COUNT(DISTINCT c.institution_id) AS institutions,
                       GROUP_CONCAT(DISTINCT i.code) AS institution_codes,
                       GROUP_CONCAT(c.id) AS collateral_ids
                FROM collateral c
                JOIN institutions i ON i.id = c.institution_id
                WHERE c.status = 'REGISTERED' AND c.rccm_registration_no IS NOT NULL";
        $params = [];
        if ($institutionId) { $sql .= " AND c.institution_id = ?"; $params[] = $institutionId; }
        $sql .= " GROUP BY c.rccm_registration_no HAVING institutions > 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function register(array $d, int $institutionId): ?int
    {
        $types = ['NANTISSEMENT_EQUIPMENT','NANTISSEMENT_BUSINESS','VEHICLE_MORTGAGE','IMMOVABLE_MORTGAGE','PLEDGE','OTHER'];
        if (!in_array($d['collateral_type'] ?? '', $types, true)) return null;
        if (empty($d['loan_id']) || empty($d['description'])) return null;

        // OHADA double-pledge guard: same RCCM ref already registered elsewhere
        if (!empty($d['rccm_registration_no'])) {
            $stmt = Database::pdo()->prepare(
                "SELECT COUNT(*) FROM collateral
                 WHERE rccm_registration_no = ? AND status='REGISTERED' AND institution_id != ?"
            );
            $stmt->execute([$d['rccm_registration_no'], $institutionId]);
            if ((int)$stmt->fetchColumn() > 0) {
                return -1; // double-pledge signal
            }
        }

        $stmt = Database::pdo()->prepare(
            "INSERT INTO collateral (loan_id, institution_id, collateral_type, description, estimated_value_xaf, rccm_registration_no, rccm_registered_at)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            (int)$d['loan_id'], $institutionId, $d['collateral_type'], $d['description'],
            (int)($d['estimated_value_xaf'] ?? 0), $d['rccm_registration_no'] ?? null,
            $d['rccm_registered_at'] ?? null,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }
}
