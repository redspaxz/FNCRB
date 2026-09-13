<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;

/**
 * Registry & System Performance — platform reliability, API activity,
 * and security posture for critical national infrastructure.
 */
final class SystemHealthService
{
    public static function kpis(): array
    {
        $pdo = Database::pdo();

        // API throughput per hour (last 24h) from signed-request nonces
        $rows = $pdo->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m-%d %H') h, COUNT(*) n
             FROM api_nonces WHERE created_at > (NOW() - INTERVAL 24 HOUR)
             GROUP BY h ORDER BY h"
        )->fetchAll();
        $apiPerHour = array_column($rows, 'n', 'h');

        $api24h = array_sum($apiPerHour);
        $rejected24h = (int)$pdo->query(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action='API_REJECTED' AND created_at > (NOW() - INTERVAL 24 HOUR)"
        )->fetchColumn();

        // security posture
        $failedLogins7d = (int)$pdo->query(
            "SELECT COUNT(*) FROM login_attempts
             WHERE success=0 AND created_at > (NOW() - INTERVAL 7 DAY)"
        )->fetchColumn();
        $mfaUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE totp_secret IS NOT NULL")->fetchColumn();
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $throttles7d = (int)$pdo->query(
            "SELECT COUNT(*) FROM audit_logs
             WHERE action IN ('LOGIN_THROTTLED','RATE_LIMITED') AND created_at > (NOW() - INTERVAL 7 DAY)"
        )->fetchColumn();

        [$chainOk, $brokenAt] = Audit::verifyChain();

        // data inventory
        $tables = ['borrowers', 'loans', 'inquiry_logs', 'payment_incidents', 'disputes', 'audit_logs'];
        $inventory = [];
        foreach ($tables as $t) {
            $inventory[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        }

        // audit activity by day (operational heartbeat)
        $rows = $pdo->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m-%d') d, COUNT(*) n
             FROM audit_logs WHERE created_at > (NOW() - INTERVAL 14 DAY)
             GROUP BY d ORDER BY d"
        )->fetchAll();
        $auditPerDay = array_column($rows, 'n', 'd');

        return [
            'api_requests_24h' => (int)$api24h,
            'api_rejections_24h' => $rejected24h,
            'api_per_hour' => $apiPerHour,
            'failed_logins_7d' => $failedLogins7d,
            'throttle_events_7d' => $throttles7d,
            'mfa_adoption_pct' => $totalUsers ? round($mfaUsers / $totalUsers * 100, 1) : null,
            'audit_chain_intact' => (bool)$chainOk,
            'audit_chain_broken_at' => $brokenAt,
            'audit_events_per_day' => $auditPerDay,
            'data_inventory_rows' => $inventory,
        ];
    }
}
