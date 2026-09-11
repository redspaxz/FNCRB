<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Module 2 — Systemic Scoring Engine.
 * Dynamic 300–850 score from DTI proxy, repayment consistency, cross-institution
 * exposure, payment incidents and regional risk factors. Persists snapshots.
 */
final class ScoringService
{
    private const REGION_RISK = [ // regional factor (illustrative)
        'Littoral' => 0.98, 'Centre' => 1.00, 'Ouest' => 1.00, 'Sud' => 1.02,
        'Nord' => 1.08, 'Adamaoua' => 1.06, 'Est' => 1.07, 'Nord-Ouest' => 1.04,
        'Sud-Ouest' => 1.05, 'Extrême-Nord' => 1.10, 'Douala' => 0.98, 'Yaoundé' => 1.00,
    ];

    public static function score(int $borrowerId, int $declaredMonthlyIncomeXaf = 0): array
    {
        $b = Database::pdo()->prepare("SELECT * FROM borrowers WHERE id = ?");
        $b->execute([$borrowerId]);
        $borrower = $b->fetch();

        $exp = ExposureService::forBorrower($borrowerId);

        // payment incidents (CIP)
        $inc = Database::pdo()->prepare(
            "SELECT COUNT(*) c, COALESCE(SUM(amount_xaf),0) amt FROM payment_incidents
             WHERE borrower_id = ? AND resolved = 0"
        );
        $inc->execute([$borrowerId]);
        [$incidentCount, $incidentAmt] = array_values($inc->fetch());

        // repayment consistency: share of instalments not past due
        $instalmentsTotal = array_sum(array_column($exp['loans'], 'instalments_total'));
        $instalmentsPastDue = array_sum(array_column($exp['loans'], 'instalments_past_due'));
        $consistency = $instalmentsTotal > 0
            ? 1 - ($instalmentsPastDue / max($instalmentsTotal, 1))
            : 1.0;

        // DTI (only computable with declared income; otherwise excluded)
        $dti = null;
        if ($declaredMonthlyIncomeXaf > 0) {
            $dti = round($exp['total_monthly_payment_xaf'] / $declaredMonthlyIncomeXaf * 100, 2);
        }

        // ---- score composition (300 base + behavioural points, cap 850) ----
        $score = 300;
        $score += (int) round(200 * max(0, min(1, $consistency)));                 // consistency
        $score += (int) round(120 * max(0, 1 - min(1, $exp['total_outstanding_xaf'] / max(1, 10_000_000)))); // leverage
        $score -= (int) round(60  * min($exp['institution_count'], 3) / 3 * 1.0);  // cross-institution spread
        $score -= $incidentCount * 40 + (int) min(80, $incidentAmt / 500_000);      // CIP incidents
        $score -= (int) round(50 * ($exp['arrears_loan_count'] > 0 ? 1 : 0));       // active arrears flag
        if ($dti !== null) {
            $score -= (int) round(min(100, max(0, ($dti - 33) * 2)));              // DTI above 33%
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
            'open_payment_incidents' => (int)$incidentCount,
            'arrears_loan_count' => $exp['arrears_loan_count'],
            'dti_pct' => $dti,
            'region' => $borrower['region'] ?? null,
        ];

        $stmt = Database::pdo()->prepare(
            "INSERT INTO score_snapshots (borrower_id, score, risk_grade, dti_pct, input_factors)
             VALUES (?,?,?,?,?)"
        );
        $stmt->execute([$borrowerId, $score, $grade, $dti, json_encode($factors)]);

        return ['score' => $score, 'risk_grade' => $grade, 'dti_pct' => $dti, 'factors' => $factors];
    }
}
