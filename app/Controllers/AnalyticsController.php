<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Rbac;
use App\Core\Response;
use App\Core\View;
use App\Services\DataQualityService;
use App\Services\MacroAnalyticsService;
use App\Services\DisputeService;
use App\Services\InquiryMetricsService;
use App\Services\SystemHealthService;

/**
 * Four-pillar analytics: data provider quality, bureau operations,
 * registry/system performance, and consumer dispute management.
 */
final class AnalyticsController
{
    public function index(): void
    {
        Rbac::require('analytics.view');
        $instId = \App\Core\Auth::institutionId();
        View::render('analytics/index', [
            'quality' => DataQualityService::providerScorecard($instId),
            'ops' => InquiryMetricsService::kpis($instId),
            'health' => SystemHealthService::kpis(),
            'disputes' => DisputeService::kpis(),
            'macro' => $instId === null ? MacroAnalyticsService::kpis() : null,
            'isRegulator' => $instId === null,
        ]);
    }

    public function json(): void
    {
        Rbac::require('analytics.view');
        $instId = \App\Core\Auth::institutionId();
        Response::json([
            'schema_version' => '1.0',
            'data_quality' => DataQualityService::providerScorecard($instId),
            'bureau_ops' => InquiryMetricsService::kpis($instId),
            'system_health' => SystemHealthService::kpis(),
            'disputes' => DisputeService::kpis(),
            'macro' => \App\Core\Auth::institutionId() === null ? MacroAnalyticsService::kpis() : null,
        ]);
    }
}

final class DisputeController
{
    public function index(): void
    {
        if (!Rbac::can('disputes.file') && !Rbac::can('disputes.work')) Rbac::require('disputes.work');
        $canWork = Rbac::can('disputes.work');
        $instId = $canWork ? null : \App\Core\Auth::institutionId(); // workers see all
        $status = trim((string)($_GET['status'] ?? '')) ?: null;
        $page = \App\Core\Pagination::page();
        $perPage = \App\Core\Pagination::perPage();
        [$rows, $total] = DisputeService::list($instId, $status, $perPage, \App\Core\Pagination::offset($page, $perPage));
        View::render('disputes/index', [
            'disputes' => $rows,
            'canWork' => $canWork,
            'kpis' => DisputeService::kpis(),
            'statusFilter' => $status,
            'pager' => \App\Core\Pagination::render('/disputes', $page, $perPage, $total),
        ]);
    }

    public function create(): void
    {
        Rbac::require('disputes.file');
        $borrowers = Database::pdo()->query(
            "SELECT id, master_ref, full_name FROM borrowers WHERE dup_of_id IS NULL ORDER BY full_name"
        )->fetchAll();
        $insts = Database::pdo()->query(
            "SELECT id, code, name FROM institutions WHERE category != 'REGULATOR' ORDER BY code"
        )->fetchAll();
        View::render('disputes/create', ['error' => null, 'dispute' => null, 'borrowers' => $borrowers, 'institutions' => $insts, 'types' => DisputeService::types()]);
    }

    public function store(): void
    {
        Rbac::require('disputes.file');
        Csrf::verify();
        $instId = (int)\App\Core\Auth::institutionId();
        if (!$instId) {
            $borrowers = Database::pdo()->query("SELECT id, master_ref, full_name FROM borrowers WHERE dup_of_id IS NULL ORDER BY full_name")->fetchAll();
            $insts = Database::pdo()->query("SELECT id, code, name FROM institutions WHERE category != 'REGULATOR' ORDER BY code")->fetchAll();
            View::render('disputes/create', ['error' => 'Disputes must be filed by an institution user acting on behalf of the consumer.', 'dispute' => null, 'borrowers' => $borrowers, 'institutions' => $insts, 'types' => DisputeService::types()]);
            return;
        }
        $id = DisputeService::file($_POST, $instId, (int)\App\Core\Auth::id());
        if (!$id) {
            $borrowers = Database::pdo()->query("SELECT id, master_ref, full_name FROM borrowers WHERE dup_of_id IS NULL ORDER BY full_name")->fetchAll();
            $insts = Database::pdo()->query("SELECT id, code, name FROM institutions WHERE category != 'REGULATOR' ORDER BY code")->fetchAll();
            View::render('disputes/create', ['error' => DisputeService::$error, 'dispute' => null, 'borrowers' => $borrowers, 'institutions' => $insts, 'types' => DisputeService::types()]);
            return;
        }
        header('Location: ' . Rbac::baseUrl() . '/disputes');
    }

    /** POST JSON: {id, status, note, correction?{entity, entity_id, field, new_value}} */
    public function transition(): void
    {
        Rbac::require('disputes.work');
        Csrf::verifyJson();
        $d = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        $status = (string)($d['status'] ?? '');
        $note = trim((string)($d['note'] ?? ''));
        if (!in_array($status, ['UNDER_REVIEW', 'CORRECTED', 'REJECTED', 'WITHDRAWN'], true)) {
            Response::json(['error' => ['code' => 'BAD_STATUS', 'message' => 'Invalid target status.']], 422);
        }
        if (in_array($status, ['REJECTED', 'WITHDRAWN'], true) && mb_strlen($note) < 5) {
            Response::json(['error' => ['code' => 'NOTE_REQUIRED', 'message' => 'A resolution note is required.']], 422);
        }
        // apply data correction before closing as CORRECTED
        if ($status === 'CORRECTED' && !empty($d['correction'])) {
            if (!DisputeService::applyCorrection($id, $d['correction'], (int)\App\Core\Auth::id())) {
                Response::json(['error' => ['code' => 'CORRECTION_FAILED', 'message' => DisputeService::$error]], 422);
            }
        }
        if (!DisputeService::transition($id, $status, (int)\App\Core\Auth::id(), $note)) {
            Response::json(['error' => ['code' => 'TRANSITION_FAILED', 'message' => DisputeService::$error]], 422);
        }
        Response::json(['ok' => true, 'id' => $id, 'status' => $status]);
    }
}
