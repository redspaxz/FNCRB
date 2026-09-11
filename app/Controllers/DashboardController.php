<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Rbac;
use App\Core\View;

final class DashboardController
{
    public function index(): void
    {
        if (!\App\Core\Auth::check()) {
            header('Location: ' . Rbac::baseUrl() . '/login');
            return;
        }

        $pdo = Database::pdo();
        $instId = \App\Core\Auth::institutionId();
        $isRegulator = $instId === null;

        $stats = $pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM institutions WHERE category != 'REGULATOR') AS institutions,
                (SELECT COUNT(*) FROM borrowers WHERE dup_of_id IS NULL) AS borrowers,
                (SELECT COUNT(*) FROM loans WHERE status='ACTIVE'" . (!$isRegulator ? " AND institution_id=" . (int)$instId : "") . ") AS active_loans,
                (SELECT COALESCE(SUM(outstanding_xaf),0) FROM loans WHERE status='ACTIVE'" . (!$isRegulator ? " AND institution_id=" . (int)$instId : "") . ") AS outstanding,
                (SELECT COUNT(*) FROM loans WHERE cobac_class IN ('DOUBTFUL','COMPROMISED')" . (!$isRegulator ? " AND institution_id=" . (int)$instId : "") . ") AS npl_loans,
                (SELECT COUNT(*) FROM payment_incidents WHERE resolved=0" . (!$isRegulator ? " AND institution_id=" . (int)$instId : "") . ") AS open_incidents"
        )->fetch();

        View::render('dashboard/index', [
            'stats' => $stats,
            'isRegulator' => $isRegulator,
        ]);
    }
}
