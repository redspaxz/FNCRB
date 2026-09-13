<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Dashboard analytics engine: KPI aggregations for charting.
 * All queries are institution-scoped for participants and national for regulators.
 */
final class AnalyticsService
{
    public static function kpis(?int $institutionId): array
    {
        $scope = $institutionId ? "AND l.institution_id = " . (int)$institutionId : '';
        $scopeI = $institutionId ? "AND pi.institution_id = " . (int)$institutionId : '';

        $pdo = Database::pdo();

        // Portfolio by COBAC asset class
        $rows = $pdo->query(
            "SELECT l.cobac_class AS k, COUNT(*) AS n, SUM(l.outstanding_xaf) AS amt
             FROM loans l WHERE l.status='ACTIVE' $scope
             GROUP BY l.cobac_class"
        )->fetchAll();
        $byClass = ['HEALTHY'=>0,'WATCH'=>0,'UNCERTAIN'=>0,'DOUBTFUL'=>0,'COMPROMISED'=>0];
        $amtByClass = $byClass;
        foreach ($rows as $r) { $byClass[$r['k']] = (int)$r['n']; $amtByClass[$r['k']] = (int)$r['amt']; }

        // Outstanding exposure by institution (top 8)
        $rows = $pdo->query(
            "SELECT i.code AS k, SUM(l.outstanding_xaf) AS amt
             FROM loans l JOIN institutions i ON i.id = l.institution_id
             WHERE l.status='ACTIVE' AND i.category != 'REGULATOR' $scope
             GROUP BY i.code ORDER BY amt DESC LIMIT 8"
        )->fetchAll();
        $byInstitution = array_column($rows, 'amt', 'k');

        // Payment incidents by type
        $rows = $pdo->query(
            "SELECT pi.incident_type AS k, COUNT(*) AS n
             FROM payment_incidents pi WHERE 1=1 $scopeI
             GROUP BY pi.incident_type"
        )->fetchAll();
        $incidentsByType = array_column($rows, 'n', 'k');

        // Reported volume trend by month (last 12 periods)
        $rows = $pdo->query(
            "SELECT DATE_FORMAT(l.reported_at,'%Y-%m') AS k, COUNT(*) AS n, SUM(l.outstanding_xaf) AS amt
             FROM loans l WHERE 1=1 $scope
             GROUP BY k ORDER BY k DESC LIMIT 12"
        )->fetchAll();
        $trend = array_reverse(array_column($rows, 'n', 'k'));
        $trendAmt = array_reverse(array_column($rows, 'amt', 'k'));

        // Arrears distribution buckets
        $rows = $pdo->query(
            "SELECT CASE WHEN l.days_past_due=0 THEN 'current'
                         WHEN l.days_past_due<=30 THEN '1-30'
                         WHEN l.days_past_due<=90 THEN '31-90'
                         WHEN l.days_past_due<=180 THEN '91-180'
                         ELSE '180+' END AS k, COUNT(*) AS n
             FROM loans l WHERE l.status='ACTIVE' $scope GROUP BY k"
        )->fetchAll();
        $arrears = array_column($rows, 'n', 'k');
        $arrears = array_merge(
            array_intersect_key(array_flip(['current','1-30','31-90','91-180','180+']), $arrears),
            $arrears
        );

        return [
            'schema_version' => '1.0',
            'scope' => $institutionId ? 'institution' : 'national',
            'portfolio_by_class' => ['count' => $byClass, 'outstanding_xaf' => $amtByClass],
            'exposure_by_institution_xaf' => $byInstitution,
            'incidents_by_type' => $incidentsByType,
            'reporting_trend' => ['loans' => $trend, 'outstanding_xaf' => $trendAmt],
            'arrears_distribution' => $arrears,
        ];
    }
}
