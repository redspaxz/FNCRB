<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;

/**
 * Consumer Rights & Dispute Management — statutory workflow under
 * national privacy/data-protection law (Cameroon Law 2010/012):
 * file → review → correct (with evidence) or reject, within the SLA window.
 */
final class DisputeService
{
    public const SLA_DAYS = 30;

    private const TYPES = ['INACCURATE_BALANCE','WRONG_CLASSIFICATION','NOT_MY_LOAN','DUPLICATE_IDENTITY','STALE_DATA','OTHER'];

    /** @var string|null last error */
    public static ?string $error = null;

    public static function types(): array { return self::TYPES; }

    public static function file(array $d, int $filedByInstId, int $userId): ?int
    {
        $borrowerId = (int)($d['borrower_id'] ?? 0);
        $type = (string)($d['dispute_type'] ?? '');
        $details = trim((string)($d['details'] ?? ''));
        $against = isset($d['against_inst_id']) && $d['against_inst_id'] !== '' ? (int)$d['against_inst_id'] : null;
        $loanId = isset($d['loan_id']) && $d['loan_id'] !== '' ? (int)$d['loan_id'] : null;

        if (!$borrowerId || !in_array($type, self::TYPES, true) || mb_strlen($details) < 10) {
            self::$error = 'Borrower, dispute type and details (min 10 chars) are required.';
            return null;
        }

        $ref = 'DSP-' . date('Y') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = Database::pdo()->prepare(
            "INSERT INTO disputes (reference, borrower_id, filed_by_inst_id, against_inst_id, dispute_type, loan_id, details, sla_due_at)
             VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL " . self::SLA_DAYS . " DAY))"
        );
        $stmt->execute([$ref, $borrowerId, $filedByInstId, $against, $type, $loanId, $details]);
        $id = (int)Database::pdo()->lastInsertId();
        Audit::log('DISPUTE_FILED', ['type' => 'dispute', 'id' => $id], ['reference' => $ref, 'type' => $type]);
        return $id;
    }

    public static function transition(int $disputeId, string $newStatus, int $userId, string $note = ''): bool
    {
        $allowed = [
            'OPEN' => ['UNDER_REVIEW', 'WITHDRAWN'],
            'UNDER_REVIEW' => ['CORRECTED', 'REJECTED', 'WITHDRAWN'],
        ];
        $stmt = Database::pdo()->prepare("SELECT * FROM disputes WHERE id = ?");
        $stmt->execute([$disputeId]);
        $d = $stmt->fetch();
        if (!$d) { self::$error = 'Dispute not found.'; return false; }

        $from = $d['status'];
        if (!in_array("$from>$newStatus", array_merge(
            ...array_map(fn($k, $v) => array_map(fn($s) => "$k>$s", $v), array_keys($allowed), $allowed)
        ), true)) {
            self::$error = "Illegal transition $from → $newStatus.";
            return false;
        }

        $resolved = in_array($newStatus, ['CORRECTED', 'REJECTED', 'WITHDRAWN'], true);
        $stmt = Database::pdo()->prepare(
            "UPDATE disputes SET status = ?, resolution_note = ?, resolved_by = " . ($resolved ? '?' : 'resolved_by') .
            ", resolved_at = " . ($resolved ? 'NOW()' : 'resolved_at') . " WHERE id = ?"
        );
        $stmt->execute($resolved
            ? [$newStatus, $note ?: null, $userId, $disputeId]
            : [$newStatus, $note ?: null, $disputeId]);

        Audit::log('DISPUTE_' . strtoupper($newStatus), ['type' => 'dispute', 'id' => $disputeId], ['note' => $note]);
        return true;
    }

    /** Apply a data correction as part of a CORRECTED dispute — evidence trail preserved. */
    public static function applyCorrection(int $disputeId, array $c, int $userId): bool
    {
        $entity = (string)($c['entity'] ?? '');
        $entityId = (int)($c['entity_id'] ?? 0);
        $field = (string)($c['field'] ?? '');
        $newValue = (string)($c['new_value'] ?? '');
        $allowed = ['loans' => ['outstanding_xaf', 'days_past_due', 'status', 'cobac_class', 'monthly_payment_xaf'],
                    'borrowers' => ['full_name', 'cni_number', 'niu', 'phone', 'region']];
        if (!isset($allowed[$entity]) || !in_array($field, $allowed[$entity], true) || !$entityId || $newValue === '') {
            self::$error = 'Invalid correction target (entity/field not correctable).';
            return false;
        }

        $stmt = Database::pdo()->prepare("SELECT `$field` FROM `$entity` WHERE id = ?");
        $stmt->execute([$entityId]);
        $old = $stmt->fetchColumn();
        if ($old === false) { self::$error = 'Target record not found.'; return false; }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE `$entity` SET `$field` = ? WHERE id = ?")->execute([$newValue, $entityId]);
            $pdo->prepare(
                "INSERT INTO data_corrections (dispute_id, entity, entity_id, field, old_value, new_value, corrected_by)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$disputeId, $entity, $entityId, $field, (string)$old, $newValue, $userId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            self::$error = 'Correction failed: ' . $e->getMessage();
            return false;
        }
        Audit::log('DATA_CORRECTED', ['type' => $entity, 'id' => $entityId],
            ['dispute_id' => $disputeId, 'field' => $field, 'old' => (string)$old, 'new' => $newValue]);
        return true;
    }

    /** @return array{0: array rows, 1: int total} */
    public static function list(?int $institutionId = null, ?string $status = null, int $limit = 25, int $offset = 0): array
    {
        $sql = "SELECT d.*, b.full_name, b.master_ref,
                       fb.code AS filed_by, ab.code AS against, u.full_name AS resolver
                FROM disputes d
                JOIN borrowers b ON b.id = d.borrower_id
                JOIN institutions fb ON fb.id = d.filed_by_inst_id
                LEFT JOIN institutions ab ON ab.id = d.against_inst_id
                LEFT JOIN users u ON u.id = d.resolved_by";
        $where = []; $params = [];
        if ($institutionId) { $where[] = "d.filed_by_inst_id = ?"; $params[] = $institutionId; }
        if ($status && in_array($status, ['OPEN','UNDER_REVIEW','CORRECTED','REJECTED','WITHDRAWN'], true)) {
            $where[] = "d.status = ?"; $params[] = $status;
        }
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $count = Database::pdo()->prepare("SELECT COUNT(*) FROM ($sql) t");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $sql .= " ORDER BY FIELD(d.status,'OPEN','UNDER_REVIEW','CORRECTED','REJECTED','WITHDRAWN'), d.sla_due_at ASC LIMIT $limit OFFSET $offset";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return [$stmt->fetchAll(), $total];
    }

    public static function kpis(): array
    {
        $pdo = Database::pdo();
        $byStatus = [];
        foreach ($pdo->query("SELECT status k, COUNT(*) n FROM disputes GROUP BY status")->fetchAll() as $r) {
            $byStatus[$r['k']] = (int)$r['n'];
        }
        $total = array_sum($byStatus);
        $resolved = ($byStatus['CORRECTED'] ?? 0) + ($byStatus['REJECTED'] ?? 0) + ($byStatus['WITHDRAWN'] ?? 0);
        $overSla = (int)$pdo->query(
            "SELECT COUNT(*) FROM disputes WHERE status IN ('OPEN','UNDER_REVIEW') AND sla_due_at < NOW()"
        )->fetchColumn();
        $avgDays = $pdo->query(
            "SELECT COALESCE(AVG(DATEDIFF(resolved_at, created_at)), NULL) FROM disputes WHERE resolved_at IS NOT NULL"
        )->fetchColumn();
        $corrections = (int)$pdo->query("SELECT COUNT(*) FROM data_corrections")->fetchColumn();
        return [
            'total' => $total,
            'by_status' => $byStatus,
            'resolution_rate_pct' => $total ? round($resolved / $total * 100, 1) : null,
            'over_sla' => $overSla,
            'avg_resolution_days' => $avgDays !== null ? round((float)$avgDays, 1) : null,
            'corrections_applied' => $corrections,
        ];
    }
}
