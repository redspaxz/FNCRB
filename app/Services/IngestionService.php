<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Module 1 — Data Ingestion & Centralization:
 * batch/API ingestion of COBAC-standardized loan records with upsert semantics.
 */
final class IngestionService
{
    private const LOAN_TYPES  = ['CONSUMER','MORTGAGE','BUSINESS','MICRO','PROJECT','OVERDRAFT','LEASE'];
    private const LOAN_STATUS = ['ACTIVE','SETTLED','WRITTEN_OFF','RESTRUCTURED'];

    /** @var array errors accumulated in the last batch */
    public static array $errors = [];

    /**
     * Upsert a single standardized loan record.
     * $d keys: borrower{...}, contract_ref, loan_type, principal_xaf, outstanding_xaf,
     * monthly_payment_xaf, interest_rate_pct, start_date, maturity_date,
     * instalments_total, instalments_past_due, days_past_due, status, reported_at
     */
    public static function upsertLoan(array $d, int $institutionId, string $source = 'API'): ?int
    {
        $missing = [];
        foreach (['contract_ref', 'loan_type', 'principal_xaf', 'outstanding_xaf', 'start_date', 'maturity_date', 'reported_at'] as $k) {
            if (empty($d[$k])) $missing[] = $k;
        }
        if (empty($d['borrower']['full_name']) && empty($d['full_name'])) $missing[] = 'full_name';
        if ($missing) { self::$errors[] = "missing fields: " . implode(',', $missing); return null; }
        if (!in_array($d['loan_type'], self::LOAN_TYPES, true)) { self::$errors[] = "invalid loan_type"; return null; }
        $status = in_array($d['status'] ?? '', self::LOAN_STATUS, true) ? $d['status'] : 'ACTIVE';

        $borrower = ReconciliationService::findOrCreate($d['borrower']);

        $cls = ClassificationService::classify((int)($d['days_past_due'] ?? 0), $status);
        $prov = ClassificationService::provision($cls, (int)$d['outstanding_xaf']);

        $stmt = Database::pdo()->prepare(
            "INSERT INTO loans (institution_id, borrower_id, contract_ref, loan_type, currency, principal_xaf,
                outstanding_xaf, monthly_payment_xaf, interest_rate_pct, start_date, maturity_date,
                instalments_total, instalments_past_due, days_past_due, cobac_class, provision_xaf,
                status, reported_at, source)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                borrower_id=VALUES(borrower_id), loan_type=VALUES(loan_type), principal_xaf=VALUES(principal_xaf),
                outstanding_xaf=VALUES(outstanding_xaf), monthly_payment_xaf=VALUES(monthly_payment_xaf),
                interest_rate_pct=VALUES(interest_rate_pct), start_date=VALUES(start_date),
                maturity_date=VALUES(maturity_date), instalments_total=VALUES(instalments_total),
                instalments_past_due=VALUES(instalments_past_due), days_past_due=VALUES(days_past_due),
                cobac_class=VALUES(cobac_class), provision_xaf=VALUES(provision_xaf), status=VALUES(status),
                reported_at=VALUES(reported_at), source=VALUES(source)"
        );
        $ok = $stmt->execute([
            $institutionId, $borrower['id'], $d['contract_ref'], $d['loan_type'], 'XAF',
            (int)$d['principal_xaf'], (int)$d['outstanding_xaf'], (int)($d['monthly_payment_xaf'] ?? 0),
            (float)($d['interest_rate_pct'] ?? 0), $d['start_date'], $d['maturity_date'],
            (int)($d['instalments_total'] ?? 0), (int)($d['instalments_past_due'] ?? 0),
            (int)($d['days_past_due'] ?? 0), $cls, $prov, $status, $d['reported_at'], $source,
        ]);
        return $ok ? (int)(Database::pdo()->lastInsertId() ?: self::loanId($institutionId, $d['contract_ref'])) : null;
    }

    private static function loanId(int $instId, string $ref): int
    {
        $stmt = Database::pdo()->prepare("SELECT id FROM loans WHERE institution_id=? AND contract_ref=?");
        $stmt->execute([$instId, $ref]);
        return (int)$stmt->fetchColumn();
    }

    /** Reclassify an entire institution's portfolio (compliance batch job). */
    public static function reclassifyPortfolio(?int $institutionId = null): int
    {
        $sql = "SELECT id, days_past_due, outstanding_xaf, status FROM loans WHERE status='ACTIVE'";
        $params = [];
        if ($institutionId) { $sql .= " AND institution_id=?"; $params[] = $institutionId; }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $n = 0;
        $upd = Database::pdo()->prepare("UPDATE loans SET cobac_class=?, provision_xaf=? WHERE id=?");
        foreach ($stmt->fetchAll() as $l) {
            $cls = ClassificationService::classify((int)$l['days_past_due'], $l['status']);
            $upd->execute([$cls, ClassificationService::provision($cls, (int)$l['outstanding_xaf']), $l['id']]);
            $n++;
        }
        return $n;
    }
}
