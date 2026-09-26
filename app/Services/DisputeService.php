<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;

/**
 * Consumer Rights & Dispute Management — statutory workflow under
 * national privacy/data-protection law (Cameroon Law 2010/012):
 * file → (furnisher response) → review → correct (with evidence) or reject,
 * within the SLA window.
 *
 * Visibility: bureau/regulator staff (disputes.work) see all disputes; an
 * institution sees disputes it filed AND disputes filed against its data, and
 * can answer the latter (furnisher response).
 */
final class DisputeService
{
    public const SLA_DAYS = 30;
    public const STATUSES = ['OPEN', 'UNDER_REVIEW', 'CORRECTED', 'REJECTED', 'WITHDRAWN'];

    private const TYPES = ['INACCURATE_BALANCE','WRONG_CLASSIFICATION','NOT_MY_LOAN','DUPLICATE_IDENTITY','STALE_DATA','OTHER'];
    private const TRANSITIONS = [
        'OPEN' => ['UNDER_REVIEW', 'WITHDRAWN'],
        'UNDER_REVIEW' => ['CORRECTED', 'REJECTED', 'WITHDRAWN'],
    ];
    /** Correctable fields per entity; loan corrections trigger reclassification. */
    private const CORRECTABLE = [
        'loans' => ['outstanding_xaf', 'days_past_due', 'status', 'monthly_payment_xaf'],
        'borrowers' => ['full_name', 'phone', 'region', 'date_of_birth'],
        'payment_incidents' => ['resolved'],
    ];

    /** @var string|null last error */
    public static ?string $error = null;

    public static function types(): array { return self::TYPES; }

    public static function file(array $d, int $filedByInstId, int $userId): ?int
    {
        self::$error = null;
        $borrowerId = (int)($d['borrower_id'] ?? 0);
        $type = (string)($d['dispute_type'] ?? '');
        $details = trim((string)($d['details'] ?? ''));
        $against = isset($d['against_inst_id']) && $d['against_inst_id'] !== '' ? (int)$d['against_inst_id'] : null;
        $loanId = isset($d['loan_id']) && $d['loan_id'] !== '' ? (int)$d['loan_id'] : null;

        if (!$borrowerId || !in_array($type, self::TYPES, true) || mb_strlen($details) < 10 || mb_strlen($details) > 5000) {
            self::$error = 'Borrower, dispute type and details (10–5000 chars) are required.';
            return null;
        }
        if ($loanId !== null) {
            $stmt = Database::pdo()->prepare("SELECT institution_id FROM loans WHERE id = ? AND borrower_id = ?");
            $stmt->execute([$loanId, $borrowerId]);
            $loanInst = $stmt->fetchColumn();
            if ($loanInst === false) { self::$error = 'The disputed credit does not belong to this borrower.'; return null; }
            $against = (int)$loanInst; // the furnisher of the disputed credit
        }
        if ($against !== null) {
            $stmt = Database::pdo()->prepare("SELECT 1 FROM institutions WHERE id = ? AND category != 'REGULATOR'");
            $stmt->execute([$against]);
            if (!$stmt->fetchColumn()) { self::$error = 'Unknown institution.'; return null; }
        }

        $pdo = Database::pdo();
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $ref = 'DSP-' . date('Y') . '-' . self::randomCode(5);
            try {
                $pdo->prepare(
                    "INSERT INTO disputes (reference, borrower_id, filed_by_inst_id, against_inst_id, dispute_type, loan_id, details, sla_due_at)
                     VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL " . self::SLA_DAYS . " DAY))"
                )->execute([$ref, $borrowerId, $filedByInstId, $against, $type, $loanId, $details]);
                $id = (int)$pdo->lastInsertId();
                Audit::log('DISPUTE_FILED', ['type' => 'dispute', 'id' => $id], ['reference' => $ref, 'type' => $type, 'against' => $against]);
                return $id;
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') throw $e; // reference collision → retry
            }
        }
        self::$error = 'Could not allocate a dispute reference — retry.';
        return null;
    }

    private static function randomCode(int $len): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $s;
    }

    public static function get(int $id): ?array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM disputes WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Transition a dispute; when closing as CORRECTED a correction must be
     * supplied and is applied in the same transaction (all-or-nothing).
     */
    public static function transition(int $disputeId, string $newStatus, int $userId, string $note = '', ?array $correction = null): bool
    {
        self::$error = null;
        $d = self::get($disputeId);
        if (!$d) { self::$error = 'Dispute not found.'; return false; }
        $from = $d['status'];
        if (!in_array($newStatus, self::TRANSITIONS[$from] ?? [], true)) {
            self::$error = "Illegal transition $from → $newStatus.";
            return false;
        }
        if ($newStatus === 'CORRECTED' && !$correction) {
            self::$error = 'A data correction is required to close a dispute as CORRECTED.';
            return false;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $applied = null;
            if ($newStatus === 'CORRECTED') {
                $applied = self::applyCorrection($d, $correction, $userId);
                if ($applied === null) { $pdo->rollBack(); return false; }
            }
            $resolved = in_array($newStatus, ['CORRECTED', 'REJECTED', 'WITHDRAWN'], true);
            $stmt = $pdo->prepare(
                "UPDATE disputes SET status = ?, resolution_note = ?,
                        resolved_by = " . ($resolved ? '?' : 'resolved_by') . ",
                        resolved_at = " . ($resolved ? 'NOW()' : 'resolved_at') . "
                 WHERE id = ? AND status = ?"
            );
            $stmt->execute($resolved
                ? [$newStatus, $note ?: null, $userId, $disputeId, $from]
                : [$newStatus, $note ?: null, $disputeId, $from]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                self::$error = 'Dispute was modified concurrently — reload and retry.';
                return false;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        if ($applied) {
            Audit::log('DATA_CORRECTED', ['type' => $applied['entity'], 'id' => $applied['entity_id']],
                ['dispute_id' => $disputeId, 'field' => $applied['field'], 'old' => $applied['old'], 'new' => $applied['new']]);
        }
        Audit::log('DISPUTE_' . $newStatus, ['type' => 'dispute', 'id' => $disputeId], ['note' => $note]);
        return true;
    }

    /**
     * Apply one field correction to a record that belongs to the disputed file.
     * Runs inside the caller's transaction. Returns the applied change or null.
     */
    private static function applyCorrection(array $dispute, array $c, int $userId): ?array
    {
        $entity = (string)($c['entity'] ?? '');
        $entityId = (int)($c['entity_id'] ?? 0);
        $field = (string)($c['field'] ?? '');
        $newValue = trim((string)($c['new_value'] ?? ''));
        if (!isset(self::CORRECTABLE[$entity]) || !in_array($field, self::CORRECTABLE[$entity], true) || !$entityId || $newValue === '') {
            self::$error = 'Invalid correction target (entity/field not correctable).';
            return null;
        }

        // the corrected record must belong to the disputed borrower (and loan, when the dispute names one)
        $owner = match ($entity) {
            'borrowers' => $entityId === (int)$dispute['borrower_id'],
            'loans' => self::loanBelongs($entityId, (int)$dispute['borrower_id'], $dispute['loan_id'] !== null ? (int)$dispute['loan_id'] : null),
            'payment_incidents' => self::incidentBelongs($entityId, (int)$dispute['borrower_id']),
        };
        if (!$owner) { self::$error = 'The corrected record is not part of the disputed credit file.'; return null; }

        $valid = match ($field) {
            'outstanding_xaf', 'monthly_payment_xaf' => ctype_digit($newValue),
            'days_past_due' => ctype_digit($newValue) && (int)$newValue <= 36500,
            'status' => in_array($newValue, IngestionService::LOAN_STATUS, true),
            'resolved' => in_array($newValue, ['0', '1'], true),
            'date_of_birth' => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $newValue),
            'full_name' => mb_strlen($newValue) <= 200,
            'phone' => mb_strlen($newValue) <= 25,
            'region' => mb_strlen($newValue) <= 60,
        };
        if (!$valid) { self::$error = "Invalid value for $field."; return null; }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT `$field` FROM `$entity` WHERE id = ? FOR UPDATE");
        $stmt->execute([$entityId]);
        $old = $stmt->fetchColumn();
        if ($old === false) { self::$error = 'Target record not found.'; return null; }

        $pdo->prepare("UPDATE `$entity` SET `$field` = ? WHERE id = ?")->execute([$newValue, $entityId]);
        if ($entity === 'payment_incidents' && $field === 'resolved') {
            $pdo->prepare("UPDATE payment_incidents SET resolved_at = IF(resolved = 1, CURDATE(), NULL),
                           resolution_note = 'Resolved by dispute correction' WHERE id = ?")->execute([$entityId]);
        }
        if ($entity === 'loans') {
            $l = $pdo->prepare("SELECT days_past_due, outstanding_xaf, status FROM loans WHERE id = ?");
            $l->execute([$entityId]);
            $row = $l->fetch();
            $cls = ClassificationService::classify((int)$row['days_past_due'], $row['status']);
            $pdo->prepare("UPDATE loans SET cobac_class = ?, provision_xaf = ? WHERE id = ?")
                ->execute([$cls, ClassificationService::provision($cls, (int)$row['outstanding_xaf']), $entityId]);
        }
        $pdo->prepare(
            "INSERT INTO data_corrections (dispute_id, entity, entity_id, field, old_value, new_value, corrected_by)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$dispute['id'], $entity, $entityId, $field, (string)$old, $newValue, $userId]);

        return ['entity' => $entity, 'entity_id' => $entityId, 'field' => $field, 'old' => (string)$old, 'new' => $newValue];
    }

    private static function loanBelongs(int $loanId, int $borrowerId, ?int $disputedLoanId): bool
    {
        if ($disputedLoanId !== null && $loanId !== $disputedLoanId) return false;
        $stmt = Database::pdo()->prepare("SELECT 1 FROM loans WHERE id = ? AND borrower_id = ?");
        $stmt->execute([$loanId, $borrowerId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function incidentBelongs(int $id, int $borrowerId): bool
    {
        $stmt = Database::pdo()->prepare("SELECT 1 FROM payment_incidents WHERE id = ? AND borrower_id = ?");
        $stmt->execute([$id, $borrowerId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Furnisher (institution whose data is disputed) records its response. */
    public static function respond(int $disputeId, int $institutionId, int $userId, string $response): bool
    {
        self::$error = null;
        $response = trim($response);
        if (mb_strlen($response) < 5 || mb_strlen($response) > 5000) { self::$error = 'Response must be 5–5000 characters.'; return false; }
        $stmt = Database::pdo()->prepare(
            "UPDATE disputes SET furnisher_response = ?, furnisher_responded_at = NOW(), furnisher_responded_by = ?
             WHERE id = ? AND against_inst_id = ? AND status IN ('OPEN','UNDER_REVIEW')"
        );
        $stmt->execute([$response, $userId, $disputeId, $institutionId]);
        if ($stmt->rowCount() !== 1) { self::$error = 'Dispute not found, not addressed to your institution, or already closed.'; return false; }
        Audit::log('DISPUTE_FURNISHER_RESPONSE', ['type' => 'dispute', 'id' => $disputeId]);
        return true;
    }

    /** @return array{0: array rows, 1: int total} */
    public static function list(?int $institutionId = null, ?string $status = null, int $limit = 25, int $offset = 0): array
    {
        $sql = "SELECT d.*, b.full_name, b.master_ref,
                       fb.code AS filed_by, ab.code AS against, u.full_name AS resolver, l.contract_ref
                FROM disputes d
                JOIN borrowers b ON b.id = d.borrower_id
                JOIN institutions fb ON fb.id = d.filed_by_inst_id
                LEFT JOIN institutions ab ON ab.id = d.against_inst_id
                LEFT JOIN loans l ON l.id = d.loan_id
                LEFT JOIN users u ON u.id = d.resolved_by";
        $where = []; $params = [];
        if ($institutionId) {
            $where[] = "(d.filed_by_inst_id = ? OR d.against_inst_id = ?)";
            $params[] = $institutionId; $params[] = $institutionId;
        }
        if ($status && in_array($status, self::STATUSES, true)) {
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

    /** KPIs — national for bureau/regulator, else limited to the institution's disputes. */
    public static function kpis(?int $institutionId = null): array
    {
        $pdo = Database::pdo();
        $iid = (int)$institutionId;
        $w = $institutionId ? "WHERE (filed_by_inst_id = $iid OR against_inst_id = $iid)" : "WHERE 1=1";
        $wd = $institutionId ? "WHERE (d.filed_by_inst_id = $iid OR d.against_inst_id = $iid)" : "";
        $byStatus = [];
        foreach ($pdo->query("SELECT status k, COUNT(*) n FROM disputes $w GROUP BY status")->fetchAll() as $r) {
            $byStatus[$r['k']] = (int)$r['n'];
        }
        $total = array_sum($byStatus);
        $resolved = ($byStatus['CORRECTED'] ?? 0) + ($byStatus['REJECTED'] ?? 0) + ($byStatus['WITHDRAWN'] ?? 0);
        $overSla = (int)$pdo->query(
            "SELECT COUNT(*) FROM disputes $w AND status IN ('OPEN','UNDER_REVIEW') AND sla_due_at < NOW()"
        )->fetchColumn();
        $avgDays = $pdo->query(
            "SELECT AVG(DATEDIFF(resolved_at, created_at)) FROM disputes $w AND resolved_at IS NOT NULL"
        )->fetchColumn();
        $corrections = (int)$pdo->query(
            "SELECT COUNT(*) FROM data_corrections dc JOIN disputes d ON d.id = dc.dispute_id $wd"
        )->fetchColumn();
        return [
            'total' => $total,
            'by_status' => $byStatus,
            'resolution_rate_pct' => $total ? round($resolved / $total * 100, 1) : null,
            'over_sla' => $overSla,
            'avg_resolution_days' => $avgDays !== null && $avgDays !== false ? round((float)$avgDays, 1) : null,
            'corrections_applied' => $corrections,
        ];
    }
}
