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
             WHERE l.borrower_id = ? AND l.status = 'ACTIVE'
             ORDER BY l.outstanding_xaf DESC"
        );
        $stmt->execute([$borrowerId]);
        $loans = $stmt->fetchAll();

        $totalOutstanding = array_sum(array_column($loans, 'outstanding_xaf'));
        $totalMonthly     = array_sum(array_column($loans, 'monthly_payment_xaf'));
        $totalProvision   = array_sum(array_column($loans, 'provision_xaf'));
        $arrearsLoans     = count(array_filter($loans, fn($l) => (int)$l['days_past_due'] > 30));

        return [
            'loans' => $loans,
            'institution_count' => count(array_unique(array_column($loans, 'institution_id'))),
            'total_outstanding_xaf' => (int)$totalOutstanding,
            'total_monthly_payment_xaf' => (int)$totalMonthly,
            'total_provision_xaf' => (int)$totalProvision,
            'arrears_loan_count' => $arrearsLoans,
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
             WHERE g.borrower_id = ?"
        );
        $stmt->execute([$borrowerId]);
        return $stmt->fetchAll();
    }
}
