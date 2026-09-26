<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Module 2 — Systemic Scoring Engine.
 * Dynamic 300–850 score from DTI proxy, repayment consistency, cross-institution
 * exposure, payment incidents, 24-month delinquency history, write-offs and
 * regional risk factors. Borrowers with no credit history are "NR" (not rated)
 * rather than receiving a default score. Every score carries adverse reason codes.
 */
final class ScoringService
{
    private const REGION_RISK = [ // regional factor (illustrative)
        'Littoral' => 0.98, 'Centre' => 1.00, 'Ouest' => 1.00, 'Sud' => 1.02,
        'Nord' => 1.08, 'Adamaoua' => 1.06, 'Est' => 1.07, 'Nord-Ouest' => 1.04,
        'Sud-Ouest' => 1.05, 'Extrême-Nord' => 1.10,
    ];

    public static function score(int $borrowerId, int $declaredMonthlyIncomeXaf = 0): array
    {
        $b = Database::pdo()->prepare("SELECT * FROM borrowers WHERE id = ?");
        $b->execute([$borrowerId]);
        $borrower = $b->fetch() ?: [];

        $exp = ExposureService::forBorrower($borrowerId);
        $hist = ExposureService::history($borrowerId);

        $inc = Database::pdo()->prepare(
            "SELECT COUNT(*) c, COALESCE(SUM(amount_xaf),0) amt FROM payment_incidents
             WHERE borrower_id = ? AND resolved = 0"
        );
        $inc->execute([$borrowerId]);
        [$incidentCount, $incidentAmt] = array_map('intval', array_values($inc->fetch()));

        // Thin file: no credit account ever reported and no incident → not rated.
        if ($hist['credit_accounts_ever'] === 0 && $incidentCount === 0) {
            return [
                'score' => null, 'risk_grade' => 'NR', 'dti_pct' => null, 'thin_file' => true,
                'reasons' => ['NO_CREDIT_HISTORY: no credit account reported to the registry — assess on other evidence'],
                'factors' => ['credit_accounts_ever' => 0],
            ];
        }

        $instalmentsTotal = array_sum(array_column($exp['loans'], 'instalments_total'));
        $instalmentsPastDue = array_sum(array_column($exp['loans'], 'instalments_past_due'));
        $consistency = $instalmentsTotal > 0 ? 1 - ($instalmentsPastDue / max($instalmentsTotal, 1)) : 1.0;

        $dti = $declaredMonthlyIncomeXaf > 0
            ? round($exp['total_monthly_payment_xaf'] / $declaredMonthlyIncomeXaf * 100, 2)
            : null;

        $reasons = [];
        $score = 300;
        $score += (int) round(200 * max(0, min(1, $consistency)));
        if ($consistency < 0.9) $reasons[] = 'MISSED_INSTALMENTS: ' . round((1 - $consistency) * 100) . '% of instalments past due';

        $leverage = (int) round(120 * max(0, 1 - min(1, $exp['total_outstanding_xaf'] / 10_000_000)));
        $score += $leverage;
        if ($leverage < 60) $reasons[] = 'HIGH_OUTSTANDING_DEBT: ' . number_format($exp['total_outstanding_xaf']) . ' XAF outstanding';

        $spread = (int) round(60 * min($exp['institution_count'], 3) / 3);
        $score -= $spread;
        if ($exp['institution_count'] >= 2) $reasons[] = 'MULTIPLE_LENDERS: credit open at ' . $exp['institution_count'] . ' institutions';

        $cipPenalty = $incidentCount * 40 + (int) min(80, $incidentAmt / 500_000);
        $score -= $cipPenalty;
        if ($incidentCount) $reasons[] = 'PAYMENT_INCIDENTS: ' . $incidentCount . ' unresolved CIP incident(s)';

        if ($exp['arrears_loan_count'] > 0) {
            $score -= 50;
            $reasons[] = 'CURRENT_ARREARS: ' . $exp['arrears_loan_count'] . ' account(s) more than 30 days past due';
        }
        if (($hist['worst_dpd_24m'] ?? 0) > 90) {
            $score -= 40;
            $reasons[] = 'SERIOUS_DELINQUENCY_24M: worst delinquency ' . $hist['worst_dpd_24m'] . ' days in the last 24 months';
        }
        if ($hist['written_off_count'] > 0) {
            $score -= 80;
            $reasons[] = 'WRITE_OFF: ' . $hist['written_off_count'] . ' credit(s) written off';
        }
        if ($hist['settled_count'] > 0 && $hist['written_off_count'] === 0) {
            $score += min(30, 10 * $hist['settled_count']); // positive history
        }
        if ($dti !== null) {
            $score -= (int) round(min(100, max(0, ($dti - 33) * 2)));
            if ($dti > 33) $reasons[] = 'HIGH_DEBT_TO_INCOME: ' . $dti . '% of declared income';
        }
        $score = (int) round($score * (self::REGION_RISK[$borrower['region'] ?? ''] ?? 1.0));
        $score = max(300, min(850, $score));

        $grade = match (true) {
            $score >= 720 => 'A', $score >= 640 => 'B', $score >= 560 => 'C',
            $score >= 480 => 'D', default => 'E',
        };

        $factors = [
            'consistency' => round($consistency, 3),
            'institution_count' => $exp['institution_count'],
            'total_outstanding_xaf' => $exp['total_outstanding_xaf'],
            'open_payment_incidents' => $incidentCount,
            'arrears_loan_count' => $exp['arrears_loan_count'],
            'worst_dpd_24m' => $hist['worst_dpd_24m'],
            'written_off' => $hist['written_off_count'],
            'settled' => $hist['settled_count'],
            'dti_pct' => $dti,
            'region' => $borrower['region'] ?? null,
        ];

        Database::pdo()->prepare(
            "INSERT INTO score_snapshots (borrower_id, score, risk_grade, dti_pct, input_factors) VALUES (?,?,?,?,?)"
        )->execute([$borrowerId, $score, $grade, $dti, json_encode($factors)]);

        return ['score' => $score, 'risk_grade' => $grade, 'dti_pct' => $dti, 'thin_file' => false,
                'reasons' => array_slice($reasons, 0, 4), 'factors' => $factors];
    }
}
