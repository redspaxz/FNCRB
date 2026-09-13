<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Credit Bureau Operations & Inquiry Metrics — commercial activity,
 * bureau usage and demand from subscribing institutions.
 */
final class InquiryMetricsService
{
    public static function kpis(?int $institutionId = null): array
    {
        $pdo = Database::pdo();
        $scope = $institutionId ? " AND q.institution_id = " . (int)$institutionId : '';

        // inquiry volume per day (last 30 days)
        $rows = $pdo->query(
            "SELECT DATE_FORMAT(q.created_at,'%Y-%m-%d') d, COUNT(*) n
             FROM inquiry_logs q WHERE q.created_at > (NOW() - INTERVAL 30 DAY) $scope
             GROUP BY d ORDER BY d"
        )->fetchAll();
        $perDay = array_column($rows, 'n', 'd');

        // by channel
        $rows = $pdo->query(
            "SELECT q.channel k, COUNT(*) n FROM inquiry_logs q WHERE 1=1 $scope GROUP BY q.channel"
        )->fetchAll();
        $byChannel = array_column($rows, 'n', 'k');

        // by institution (demand ranking)
        $rows = $pdo->query(
            "SELECT i.code k, COUNT(*) n FROM inquiry_logs q
             JOIN institutions i ON i.id = q.institution_id WHERE 1=1 $scope
             GROUP BY i.code ORDER BY n DESC LIMIT 8"
        )->fetchAll();
        $byInstitution = array_column($rows, 'n', 'k');

        // unique borrowers queried (consumer reach)
        $n = $pdo->query("SELECT COUNT(DISTINCT q.borrower_id) FROM inquiry_logs q WHERE 1=1 $scope")->fetchColumn();

        // refusals (no consent) from audit trail
        $refused = $pdo->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action IN ('INQUIRY_REFUSED')"
        )->fetchColumn();

        $total = array_sum($byChannel);

        return [
            'total_inquiries' => (int)$total,
            'unique_borrowers_queried' => (int)$n,
            'last30_per_day' => $perDay,
            'by_channel' => $byChannel,
            'by_institution' => $byInstitution,
            'consent_refusals' => (int)$refused,
            'consent_compliance_pct' => $total ? round(($total / max($total + (int)$refused, 1)) * 100, 1) : null,
            'active_subscribers' => count(array_filter($byInstitution)),
        ];
    }
}
