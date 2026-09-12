<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Module 4 — Risk Concentration Ratios:
 * institutional exposure to single borrowers vs net equity (COBAC prudential limits).
 */
final class ConcentrationService
{
    /** Single-borrower limit as % of net equity — configurable in config.php. */
    private static function limitPct(): float
    {
        $c = require dirname(__DIR__, 2) . '/config/config.php';
        return (float)($c['security']['single_borrower_limit_pct'] ?? 25.0);
    }

    public static function report(?int $institutionId = null): array
    {
        $sql = "SELECT i.id, i.code, i.name, i.net_equity_xaf,
                       l.borrower_id, b.full_name, b.master_ref,
                       SUM(l.outstanding_xaf) AS exposure_xaf
                FROM loans l
                JOIN institutions i ON i.id = l.institution_id
                JOIN borrowers b ON b.id = l.borrower_id
                WHERE l.status = 'ACTIVE'
                  AND i.category != 'REGULATOR'";
        $params = [];
        if ($institutionId) { $sql .= " AND i.id = ?"; $params[] = $institutionId; }
        $sql .= " GROUP BY i.id, l.borrower_id ORDER BY exposure_xaf DESC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $byInst = [];
        foreach ($rows as $r) {
            $iid = (int)$r['id'];
            if (!isset($byInst[$iid])) {
                $byInst[$iid] = [
                    'institution' => $r['name'] . ' (' . $r['code'] . ')',
                    'net_equity_xaf' => (int)$r['net_equity_xaf'],
                    'top_exposures' => [],
                    'breaches' => [],
                ];
            }
            $equity = (int)$r['net_equity_xaf'];
            $pct = $equity > 0 ? round((int)$r['exposure_xaf'] / $equity * 100, 2) : null;
            $entry = [
                'borrower' => $r['full_name'] . ' [' . $r['master_ref'] . ']',
                'exposure_xaf' => (int)$r['exposure_xaf'],
                'pct_of_equity' => $pct,
            ];
            $byInst[$iid]['top_exposures'][] = $entry;
            if ($pct !== null && $pct > self::limitPct()) {
                $byInst[$iid]['breaches'][] = $entry;
            }
        }
        foreach ($byInst as &$inst) {
            $inst['top_exposures'] = array_slice($inst['top_exposures'], 0, 10);
        }
        return $byInst;
    }
}
