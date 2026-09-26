<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Module 2 — Cross-Institution Exposure Engine.
 * Consolidates outstanding debts across Cat 1/2/3 MFIs and banks for one borrower.
 */
final class ExposureService
{
    public static function forBorrower(int $borrowerId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT l.*, i.name AS institution_name, i.code AS institution_code, i.category AS institution_category
             FROM loans l JOIN institutions i ON i.id = l.institution_id
             WHERE l.borrower_id = ? AND l.status IN " . ClassificationService::OPEN_SQL . "
             ORDER BY l.outstanding_xaf DESC"
        );
        $stmt->execute([$borrowerId]);
        $loans = $stmt->fetchAll();

        return [
            'loans' => $loans,
            'institution_count' => count(array_unique(array_column($loans, 'institution_id'))),
            'total_outstanding_xaf' => (int)array_sum(array_column($loans, 'outstanding_xaf')),
            'total_monthly_payment_xaf' => (int)array_sum(array_column($loans, 'monthly_payment_xaf')),
            'total_provision_xaf' => (int)array_sum(array_column($loans, 'provision_xaf')),
            'arrears_loan_count' => count(array_filter($loans, fn($l) => (int)$l['days_past_due'] > 30)),
        ];
    }

    /** Closed credits + 24-month worst-delinquency (positive and negative history). */
    public static function history(int $borrowerId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT l.contract_ref, l.loan_type, l.status, l.principal_xaf, l.start_date, l.maturity_date, l.reported_at,
                    i.code AS institution_code
             FROM loans l JOIN institutions i ON i.id = l.institution_id
             WHERE l.borrower_id = ? AND l.status NOT IN " . ClassificationService::OPEN_SQL . "
             ORDER BY l.reported_at DESC"
        );
        $stmt->execute([$borrowerId]);
        $closed = $stmt->fetchAll();

        $stmt = Database::pdo()->prepare(
            "SELECT MAX(dpd) FROM (
                SELECT h.days_past_due dpd FROM loan_history h JOIN loans l ON l.id = h.loan_id
                 WHERE l.borrower_id = ? AND h.reported_at >= (CURDATE() - INTERVAL 24 MONTH)
                UNION ALL
                SELECT l.days_past_due FROM loans l WHERE l.borrower_id = ? AND l.reported_at >= (CURDATE() - INTERVAL 24 MONTH)
             ) t"
        );
        $stmt->execute([$borrowerId, $borrowerId]);
        $worst = $stmt->fetchColumn();

        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM loans WHERE borrower_id = ?");
        $stmt->execute([$borrowerId]);

        return [
            'closed_loans' => $closed,
            'written_off_count' => count(array_filter($closed, fn($l) => $l['status'] === 'WRITTEN_OFF')),
            'settled_count' => count(array_filter($closed, fn($l) => $l['status'] === 'SETTLED')),
            'worst_dpd_24m' => $worst === null || $worst === false ? null : (int)$worst,
            'credit_accounts_ever' => (int)$stmt->fetchColumn(),
        ];
    }

    /** Guarantees where this borrower is guarantor (cross-liability exposure). */
    public static function guaranteesOf(int $borrowerId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT g.*, i.code AS institution_code, l.contract_ref
             FROM guarantors g
             JOIN loans l ON l.id = g.loan_id
             JOIN institutions i ON i.id = l.institution_id
             WHERE g.borrower_id = ? AND l.status IN " . ClassificationService::OPEN_SQL
        );
        $stmt->execute([$borrowerId]);
        return $stmt->fetchAll();
    }
}
