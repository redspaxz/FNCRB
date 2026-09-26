<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;

/**
 * Module 3 — OHADA collateral: RCCM registration, double-pledge blocking and
 * detection, release/foreclosure lifecycle.
 */
final class CollateralService
{
    public const TYPES = ['NANTISSEMENT_EQUIPMENT','NANTISSEMENT_BUSINESS','VEHICLE_MORTGAGE','IMMOVABLE_MORTGAGE','PLEDGE','OTHER'];
    public const DOUBLE_PLEDGE = -1;

    /** @var string|null last validation error */
    public static ?string $error = null;

    /**
     * RCCM references pledged more than once while REGISTERED. For an institution,
     * only references in which it holds a pledge are returned (it learns that its
     * security is contested, and by whom — nothing about unrelated pledges).
     */
    public static function detectDoublePledge(?int $institutionId = null): array
    {
        $sql = "SELECT c.rccm_registration_no,
                       COUNT(*) AS pledges,
                       COUNT(DISTINCT c.institution_id) AS institutions,
                       GROUP_CONCAT(DISTINCT i.code ORDER BY i.code) AS institution_codes,
                       GROUP_CONCAT(c.id ORDER BY c.id) AS collateral_ids
                FROM collateral c
                JOIN institutions i ON i.id = c.institution_id
                WHERE c.status = 'REGISTERED' AND c.rccm_registration_no IS NOT NULL
                GROUP BY c.rccm_registration_no
                HAVING pledges > 1";
        $params = [];
        if ($institutionId) {
            $sql .= " AND SUM(c.institution_id = ?) > 0";
            $params[] = $institutionId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Register a security interest on one of the institution's own loans.
     * Returns the new id, DOUBLE_PLEDGE (-1) when the RCCM reference is already
     * registered (by anyone), or null on validation error (self::$error).
     */
    public static function register(array $d, int $institutionId): ?int
    {
        self::$error = null;
        $type = Validator::enum($d, 'collateral_type', self::TYPES);
        $desc = Validator::string($d, 'description', 255);
        $value = isset($d['estimated_value_xaf']) && $d['estimated_value_xaf'] !== '' ? Validator::int($d, 'estimated_value_xaf', 0) : 0;
        $rccm = isset($d['rccm_registration_no']) && $d['rccm_registration_no'] !== '' ? Validator::identifier($d, 'rccm_registration_no', 50) : null;
        $rccmDate = isset($d['rccm_registered_at']) && $d['rccm_registered_at'] !== '' ? Validator::date($d, 'rccm_registered_at') : null;

        $loan = null;
        if (!empty($d['loan_id'])) {
            $stmt = Database::pdo()->prepare("SELECT id, status FROM loans WHERE id = ? AND institution_id = ?");
            $stmt->execute([(int)$d['loan_id'], $institutionId]);
            $loan = $stmt->fetch();
        } elseif (!empty($d['contract_ref']) && is_string($d['contract_ref'])) {
            $stmt = Database::pdo()->prepare("SELECT id, status FROM loans WHERE contract_ref = ? AND institution_id = ?");
            $stmt->execute([trim($d['contract_ref']), $institutionId]);
            $loan = $stmt->fetch();
        }

        if (!$type) self::$error = 'collateral_type invalid';
        elseif (!$desc) self::$error = 'description required (max 255)';
        elseif ($value === null) self::$error = 'estimated_value_xaf must be an integer ≥ 0';
        elseif (!empty($d['rccm_registration_no']) && $rccm === null) self::$error = 'rccm_registration_no has an invalid format';
        elseif (!empty($d['rccm_registered_at']) && $rccmDate === null) self::$error = 'rccm_registered_at must be YYYY-MM-DD';
        elseif (!$loan) self::$error = 'loan not found in your institution portfolio';
        elseif (!in_array($loan['status'], ClassificationService::OPEN_STATUSES, true)) self::$error = 'collateral can only secure an open loan';
        if (self::$error) return null;

        $pdo = Database::pdo();
        $lock = $rccm !== null ? 'fncrb_rccm_' . substr(hash('sha256', $rccm), 0, 40) : null;
        if ($lock) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 10)")->fetchColumn();
        try {
            if ($rccm !== null) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM collateral WHERE rccm_registration_no = ? AND status = 'REGISTERED'");
                $stmt->execute([$rccm]);
                if ((int)$stmt->fetchColumn() > 0) return self::DOUBLE_PLEDGE;
            }
            $pdo->prepare(
                "INSERT INTO collateral (loan_id, institution_id, collateral_type, description, estimated_value_xaf, rccm_registration_no, rccm_registered_at)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([(int)$loan['id'], $institutionId, $type, $desc, $value, $rccm, $rccmDate]);
            return (int)$pdo->lastInsertId();
        } finally {
            if ($lock) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")")->fetchColumn();
        }
    }

    /** Release (mainlevée) or foreclose a security held by the institution. */
    public static function changeStatus(int $id, int $institutionId, string $status, int $userId): bool
    {
        self::$error = null;
        if (!in_array($status, ['RELEASED', 'FORECLOSED'], true)) { self::$error = 'invalid status'; return false; }
        $stmt = Database::pdo()->prepare(
            "UPDATE collateral SET status = ?, released_at = NOW(), released_by = ?
             WHERE id = ? AND institution_id = ? AND status = 'REGISTERED'"
        );
        $stmt->execute([$status, $userId, $id, $institutionId]);
        if ($stmt->rowCount() !== 1) { self::$error = 'collateral not found, not yours, or not currently registered'; return false; }
        return true;
    }
}
