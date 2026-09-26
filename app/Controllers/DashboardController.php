<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Rbac;
use App\Core\View;
use App\Services\AnalyticsService;
use App\Services\ClassificationService as CS;

final class DashboardController
{
    public function index(): void
    {
        if (!Auth::check()) {
            header('Location: ' . Rbac::baseUrl() . '/login');
            return;
        }

        $instId = Auth::institutionId();
        $isRegulator = $instId === null;
        $scope = $isRegulator ? '' : ' AND institution_id = ' . (int)$instId;
        $open = CS::OPEN_SQL;
        $npl = CS::NPL_SQL;

        $stats = Database::pdo()->query(
            "SELECT
                (SELECT COUNT(*) FROM institutions WHERE category != 'REGULATOR' AND status = 'ACTIVE') AS institutions,
                " . ($isRegulator
                    ? "(SELECT COUNT(*) FROM borrowers WHERE dup_of_id IS NULL)"
                    : "(SELECT COUNT(DISTINCT borrower_id) FROM loans WHERE status IN $open $scope)") . " AS borrowers,
                (SELECT COUNT(*) FROM loans WHERE status IN $open $scope) AS active_loans,
                (SELECT COALESCE(SUM(outstanding_xaf),0) FROM loans WHERE status IN $open $scope) AS outstanding,
                (SELECT COUNT(*) FROM loans WHERE status IN $open AND cobac_class IN $npl $scope) AS npl_loans,
                (SELECT COUNT(*) FROM payment_incidents WHERE resolved = 0 $scope) AS open_incidents"
        )->fetch();

        View::render('dashboard/index', [
            'stats' => $stats,
            'isRegulator' => $isRegulator,
        ]);
    }

    /** KPI feed for dashboard charts (JSON). */
    public function analytics(): void
    {
        if (!Auth::check()) {
            \App\Core\Response::json(["error" => ["code" => "AUTH_REQUIRED", "message" => "Session required."]], 401);
        }
        \App\Core\Response::json(AnalyticsService::kpis(Auth::institutionId()));
    }
}
