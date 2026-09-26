<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Module 4 — Automated Supervisory Feeds:
 * compliant reporting packages for BEAC / CNEF / COBAC inspectors.
 */
final class ReportService
{
    public static function portfolioQuality(?int $institutionId = null): array
    {
        $sql = "SELECT i.code, i.name, l.cobac_class,
                       COUNT(*) AS loans, SUM(l.outstanding_xaf) AS outstanding,
                       SUM(l.provision_xaf) AS provisions
                FROM loans l JOIN institutions i ON i.id = l.institution_id
                WHERE i.category != 'REGULATOR' AND l.status IN " . ClassificationService::OPEN_SQL;
        $params = [];
        if ($institutionId) { $sql .= " AND i.id = ?"; $params[] = $institutionId; }
        $sql .= " GROUP BY i.code, i.name, l.cobac_class ORDER BY i.code";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $key = $r['code'];
            $out[$key] ??= ['name' => $r['name'], 'classes' => [], 'outstanding_total' => 0, 'npl_outstanding' => 0, 'provisions_total' => 0];
            $out[$key]['classes'][$r['cobac_class']] = [
                'loans' => (int)$r['loans'],
                'outstanding' => (int)$r['outstanding'],
                'provisions' => (int)$r['provisions'],
            ];
            $out[$key]['outstanding_total'] += (int)$r['outstanding'];
            $out[$key]['provisions_total'] += (int)$r['provisions'];
            if (ClassificationService::isNpl((string)$r['cobac_class'])) {
                $out[$key]['npl_outstanding'] += (int)$r['outstanding'];
            }
        }
        foreach ($out as &$o) {
            $o['npl_ratio_pct'] = $o['outstanding_total'] > 0
                ? round($o['npl_outstanding'] / $o['outstanding_total'] * 100, 2) : 0.0;
        }
        unset($o);
        return $out;
    }

    public static function cipSummary(?int $institutionId = null): array
    {
        $sql = "SELECT i.code, i.name, pi.incident_type,
                       COUNT(*) AS cnt, SUM(pi.amount_xaf) AS amt,
                       SUM(pi.resolved = 0) AS open_cnt
                FROM payment_incidents pi JOIN institutions i ON i.id = pi.institution_id";
        $params = [];
        if ($institutionId) { $sql .= " WHERE pi.institution_id = ?"; $params[] = $institutionId; }
        $sql .= " GROUP BY i.code, i.name, pi.incident_type ORDER BY i.code";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Supervisory package (portfolio quality + CIP + concentration + audit chain status).
     * Callers MUST pass the requesting user's institution id; null = national scope
     * (regulators / bureau only).
     */
    public static function supervisoryPackage(?int $institutionId): array
    {
        [$chainOk, $brokenAt] = \App\Core\Audit::verifyChain();
        return [
            'schema_version' => '1.1',
            'generated_at'   => date('c'),
            'scope'          => $institutionId ? 'institution' : 'national',
            'definitions'    => [
                'portfolio' => 'open credits (ACTIVE, RESTRUCTURED)',
                'npl'       => 'open credits > 90 days past due (UNCERTAIN, DOUBTFUL, COMPROMISED)',
            ],
            'portfolio'      => self::portfolioQuality($institutionId),
            'payment_incidents' => self::cipSummary($institutionId),
            'concentration'  => ConcentrationService::report($institutionId),
            'audit_chain'    => ['intact' => $chainOk, 'broken_at_id' => $brokenAt],
        ];
    }
}
