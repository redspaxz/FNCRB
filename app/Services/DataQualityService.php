<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Data Ingestion & Quality Analytics — data provider performance.
 * Monitors accuracy (rejection rate), completeness (field population),
 * timeliness (reporting freshness vs threshold) per institution, with a
 * composite provider quality score.
 */
final class DataQualityService
{
    /** Reporting timeliness threshold in days (BEAC/COBAC periodicity). */
    public const FRESHNESS_DAYS = 35;

    public static function providerScorecard(?int $institutionId = null): array
    {
        $pdo = Database::pdo();
        $scope = $institutionId ? " AND i.id = " . (int)$institutionId : '';

        $insts = $pdo->query(
            "SELECT i.id, i.code, i.name, i.category FROM institutions i
             WHERE i.category != 'REGULATOR' AND i.status='ACTIVE' $scope ORDER BY i.code"
        )->fetchAll();

        // accuracy: accepted vs rejected submissions
        $acc = [];
        $stmt = $pdo->query(
            "SELECT institution_id, SUM(accepted) a, SUM(rejected) r FROM ingestion_log GROUP BY institution_id"
        );
        foreach ($stmt->fetchAll() as $r) $acc[(int)$r['institution_id']] = [(int)$r['a'], (int)$r['r']];

        // completeness: share of active loans with all COBAC-standard fields populated
        $comp = [];
        $stmt = $pdo->query(
            "SELECT institution_id,
                    COUNT(*) total,
                    SUM(CASE WHEN monthly_payment_xaf > 0 AND instalments_total > 0
                              AND interest_rate_pct > 0 AND maturity_date IS NOT NULL THEN 1 ELSE 0 END) complete
             FROM loans WHERE status='ACTIVE' GROUP BY institution_id"
        );
        foreach ($stmt->fetchAll() as $r) $comp[(int)$r['institution_id']] = [(int)$r['complete'], (int)$r['total']];

        // timeliness: freshness of the latest reported period
        $fresh = [];
        $stmt = $pdo->query(
            "SELECT institution_id, MAX(reported_at) last_report,
                    DATEDIFF(NOW(), MAX(reported_at)) age_days
             FROM loans GROUP BY institution_id"
        );
        foreach ($stmt->fetchAll() as $r) $fresh[(int)$r['institution_id']] = $r;

        // duplicate-identity signals (reconciliation queue)
        $dups = [];
        $stmt = $pdo->query(
            "SELECT COUNT(*) c FROM borrowers WHERE dup_of_id IS NOT NULL"
        );
        $dupTotal = (int)$stmt->fetchColumn();

        $out = [];
        foreach ($insts as $i) {
            $id = (int)$i['id'];
            [$accepted, $rejected] = $acc[$id] ?? [0, 0];
            $submitted = $accepted + $rejected;
            $accuracyPct = $submitted ? round($accepted / $submitted * 100, 1) : null;

            [$complete, $total] = $comp[$id] ?? [0, 0];
            $completenessPct = $total ? round($complete / $total * 100, 1) : null;

            $fr = $fresh[$id] ?? null;
            $ageDays = $fr ? (int)$fr['age_days'] : null;
            $timelinessPct = $ageDays === null ? null
                : round(max(0, min(100, (1 - $ageDays / self::FRESHNESS_DAYS) * 100)), 1);

            // composite score: accuracy 40% + completeness 30% + timeliness 30%
            $parts = [];
            if ($accuracyPct !== null) $parts[] = [$accuracyPct, 0.4];
            if ($completenessPct !== null) $parts[] = [$completenessPct, 0.3];
            if ($timelinessPct !== null) $parts[] = [$timelinessPct, 0.3];
            $wsum = array_sum(array_column($parts, 1));
            $score = $wsum ? round(array_sum(array_map(fn($p) => $p[0] * $p[1], $parts)) / $wsum, 1) : null;

            $out[] = [
                'institution' => $i['code'],
                'name' => $i['name'],
                'category' => $i['category'],
                'submissions' => $submitted,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'accuracy_pct' => $accuracyPct,
                'completeness_pct' => $completenessPct,
                'last_report' => $fr['last_report'] ?? null,
                'data_age_days' => $ageDays,
                'timeliness_pct' => $timelinessPct,
                'quality_score' => $score,
                'grade' => $score === null ? null : ($score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : 'D'))),
            ];
        }
        return ['providers' => $out, 'reconciliation_queue' => $dupTotal];
    }

    /** Log an ingestion submission (called after each batch/API ingest). */
    public static function logSubmission(int $institutionId, string $source, int $submitted, int $accepted, int $rejected): void
    {
        Database::pdo()->prepare(
            "INSERT INTO ingestion_log (institution_id, source, submitted_xaf, accepted, rejected) VALUES (?,?,?,?,?)"
        )->execute([$institutionId, $source, $submitted, $accepted, $rejected]);
    }
}
