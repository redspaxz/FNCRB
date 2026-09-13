<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Macro-Financial & Credit Market Analytics — strategic executive view.
 * System-wide, anonymized credit health for central banks, regulators and
 * registry leadership. Regulator scope only (no institution breakdown of
 * individuals — aggregates only).
 */
final class MacroAnalyticsService
{
    public static function kpis(): array
    {
        $c = require dirname(__DIR__, 2) . '/config/config.php';
        $cfg = $c['macro'];
        $dpd = (int)$cfg['npl_dpd_threshold'];
        $pdo = Database::pdo();

        // ---------- 1. NPL indicator (national + by sector) ----------
        $npl = ['national' => self::nplRow("l.status='ACTIVE'", $dpd)];
        $rows = $pdo->query(
            "SELECT l.loan_type FROM loans l WHERE l.status='ACTIVE' GROUP BY l.loan_type"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $sector) {
            $npl['by_sector'][$sector] = self::nplRow("l.status='ACTIVE' AND l.loan_type = " . $pdo->quote($sector), $dpd);
        }

        // ---------- 2. Credit coverage ratio ----------
        $individuals = (int)$pdo->query(
            "SELECT COUNT(*) FROM borrowers WHERE dup_of_id IS NULL AND type='INDIVIDUAL'"
        )->fetchColumn();
        $corporates = (int)$pdo->query(
            "SELECT COUNT(*) FROM borrowers WHERE dup_of_id IS NULL AND type='CORPORATE'"
        )->fetchColumn();

        // ---------- 3. Credit growth (new facilities per month) ----------
        $rows = $pdo->query(
            "SELECT DATE_FORMAT(l.start_date,'%Y-%m') m, COUNT(*) n, SUM(l.principal_xaf) p
             FROM loans l
             WHERE l.start_date >= DATE_SUB(CURDATE(), INTERVAL 25 MONTH)
             GROUP BY m ORDER BY m"
        )->fetchAll();
        $series = [];
        foreach ($rows as $r) $series[$r['m']] = ['loans' => (int)$r['n'], 'principal_xaf' => (int)$r['p']];

        $months = array_keys($series);
        $lastM = $months[count($months) - 1] ?? null;
        $prevM = $months[count($months) - 2] ?? null;
        $yoyM = $months[count($months) - 13] ?? null;
        $mom = self::growth($lastM ? $series[$lastM]['loans'] : 0, $prevM ? $series[$prevM]['loans'] : 0);
        $yoy = self::growth($lastM ? $series[$lastM]['loans'] : 0, $yoyM ? $series[$yoyM]['loans'] : 0);

        // growth by sector (latest month vs previous)
        $bySector = [];
        if ($lastM) {
            $stmt = $pdo->prepare(
                "SELECT loan_type, COUNT(*) n FROM loans
                 WHERE DATE_FORMAT(start_date,'%Y-%m') = ? GROUP BY loan_type"
            );
            $stmt->execute([$lastM]);
            $cur = array_column($stmt->fetchAll(), 'n', 'loan_type');
            if ($prevM) {
                $stmt->execute([$prevM]);
                $prv = array_column($stmt->fetchAll(), 'n', 'loan_type');
                foreach (array_keys($cur + $prv) as $s) {
                    $bySector[$s] = self::growth((int)($cur[$s] ?? 0), (int)($prv[$s] ?? 0));
                }
            }
        }

        // ---------- 4. Indebtedness index ----------
        $rows = $pdo->query(
            "SELECT t.borrower_id, t.loans, t.outstanding, t.institutions FROM (
                SELECT l.borrower_id, COUNT(*) loans, SUM(l.outstanding_xaf) outstanding,
                       COUNT(DISTINCT l.institution_id) institutions
                FROM loans l WHERE l.status='ACTIVE' GROUP BY l.borrower_id
             ) t"
        )->fetchAll();
        $nBorrowers = count($rows);
        $avgLoans = $avgOut = 0;
        $dist = ['1' => 0, '2' => 0, '3+' => 0];
        $multiInst = 0;
        $overIndebted = 0; // >=3 active loans or >=3 institutions
        foreach ($rows as $r) {
            $avgLoans += (int)$r['loans'];
            $avgOut += (int)$r['outstanding'];
            if ((int)$r['institutions'] >= 2) $multiInst++;
            if ((int)$r['loans'] >= 3 || (int)$r['institutions'] >= 3) $overIndebted++;
            $dist[(int)$r['loans'] >= 3 ? '3+' : (string)(int)$r['loans']]++;
        }

        return [
            'schema_version' => '1.0',
            'npl' => $npl,
            'coverage' => [
                'individuals_in_registry' => $individuals,
                'corporates_in_registry' => $corporates,
                'adult_population' => (int)$cfg['adult_population'],
                'legal_entities' => (int)$cfg['legal_entities'],
                'individual_coverage_pct' => round($individuals / max((int)$cfg['adult_population'], 1) * 100, 2),
                'corporate_coverage_pct' => round($corporates / max((int)$cfg['legal_entities'], 1) * 100, 2),
            ],
            'credit_growth' => [
                'monthly_series' => $series,
                'last_period' => $lastM,
                'mom_growth_pct' => $mom,
                'yoy_growth_pct' => $yoy,
                'by_sector_mom_pct' => $bySector,
            ],
            'indebtedness' => [
                'borrowers_with_active_credit' => $nBorrowers,
                'avg_accounts_per_borrower' => $nBorrowers ? round($avgLoans / $nBorrowers, 2) : null,
                'avg_outstanding_xaf' => $nBorrowers ? (int)round($avgOut / $nBorrowers) : null,
                'accounts_distribution' => $dist,
                'multi_institution_borrowers' => $multiInst,
                'multi_institution_pct' => $nBorrowers ? round($multiInst / $nBorrowers * 100, 1) : null,
                'over_indebted_borrowers' => $overIndebted,
                'over_indebted_pct' => $nBorrowers ? round($overIndebted / $nBorrowers * 100, 1) : null,
            ],
        ];
    }

    private static function nplRow(string $where, int $dpd): array
    {
        $pdo = Database::pdo();
        $row = $pdo->query(
            "SELECT COUNT(*) total,
                    SUM(CASE WHEN days_past_due >= $dpd THEN 1 ELSE 0 END) npl_accounts,
                    COALESCE(SUM(outstanding_xaf),0) out_total,
                    COALESCE(SUM(CASE WHEN days_past_due >= $dpd THEN outstanding_xaf ELSE 0 END),0) out_npl
             FROM loans l WHERE $where"
        )->fetch();
        return [
            'total_accounts' => (int)$row['total'],
            'npl_accounts' => (int)$row['npl_accounts'],
            'npl_ratio_accounts_pct' => $row['total'] ? round($row['npl_accounts'] / $row['total'] * 100, 1) : null,
            'npl_ratio_value_pct' => $row['out_total'] ? round($row['out_npl'] / $row['out_total'] * 100, 1) : null,
            'npl_outstanding_xaf' => (int)$row['out_npl'],
        ];
    }

    private static function growth(int $cur, int $prev): ?float
    {
        if (!$prev) return $cur ? null : 0.0; // undefined when no base
        return round(($cur - $prev) / $prev * 100, 1);
    }
}
