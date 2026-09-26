<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http;
use App\Core\Rbac;
use App\Core\Response;
use App\Core\View;
use App\Services\DataQualityService;
use App\Services\MacroAnalyticsService;
use App\Services\DisputeService;
use App\Services\InquiryMetricsService;
use App\Services\ReconciliationService;
use App\Services\SystemHealthService;

/**
 * Four-pillar analytics: data provider quality, bureau operations,
 * registry/system performance, and consumer dispute management.
 * Institution users see their own figures only; platform health and the
 * macro view are national (bureau / regulator) only.
 */
final class AnalyticsController
{
    private function data(): array
    {
        $instId = Auth::institutionId();
        $national = $instId === null;
        return [
            'schema_version' => '1.1',
            'scope' => $national ? 'national' : 'institution',
            'data_quality' => DataQualityService::providerScorecard($instId),
            'bureau_ops' => InquiryMetricsService::kpis($instId),
            'system_health' => $national ? SystemHealthService::kpis() : null,
            'disputes' => DisputeService::kpis($instId),
            'macro' => $national ? MacroAnalyticsService::kpis() : null,
        ];
    }

    public function index(): void
    {
        Rbac::require('analytics.view');
        $d = $this->data();
        View::render('analytics/index', [
            'quality' => $d['data_quality'],
            'ops' => $d['bureau_ops'],
            'health' => $d['system_health'],
            'disputes' => $d['disputes'],
            'macro' => $d['macro'],
            'isRegulator' => $d['scope'] === 'national',
        ]);
    }

    public function json(): void
    {
        Rbac::require('analytics.view');
        Response::json($this->data());
    }
}

final class DisputeController
{
    public function index(): void
    {
        Rbac::requireAny(['disputes.file', 'disputes.work']);
        $canWork = Rbac::can('disputes.work');
        $instId = $canWork && Auth::isNational() ? null : Auth::institutionId();
        $status = is_string($_GET['status'] ?? null) ? trim($_GET['status']) : null;
        $status = in_array($status, DisputeService::STATUSES, true) ? $status : null;
        $page = \App\Core\Pagination::page();
        $perPage = \App\Core\Pagination::perPage();
        [$rows, $total] = DisputeService::list($instId, $status, $perPage, \App\Core\Pagination::offset($page, $perPage));
        View::render('disputes/index', [
            'disputes' => $rows,
            'canWork' => $canWork,
            'myInst' => Auth::institutionId(),
            'canFile' => Rbac::can('disputes.file') && !Auth::isNational(),
            'kpis' => DisputeService::kpis($instId),
            'statusFilter' => $status,
            'pager' => \App\Core\Pagination::render('/disputes', $page, $perPage, $total),
        ]);
    }

    private function form(?string $error, array $old = []): void
    {
        $insts = Database::pdo()->query(
            "SELECT id, code, name FROM institutions WHERE category != 'REGULATOR' ORDER BY code"
        )->fetchAll();
        View::render('disputes/create', ['error' => $error, 'old' => $old, 'institutions' => $insts, 'types' => DisputeService::types()]);
    }

    public function create(): void
    {
        Rbac::require('disputes.file');
        $this->form(null, ['borrower_identifier' => is_string($_GET['borrower'] ?? null) ? $_GET['borrower'] : '']);
    }

    public function store(): void
    {
        Rbac::require('disputes.file');
        Csrf::verify();
        $instId = Auth::institutionId();
        if (!$instId) { $this->form('Disputes must be filed by an institution user acting on behalf of the consumer.', $_POST); return; }

        $identifier = trim((string)($_POST['borrower_identifier'] ?? ''));
        $borrower = $identifier !== '' ? ReconciliationService::lookup($identifier) : null;
        if (!$borrower) { $this->form('No registry borrower matches this identifier.', $_POST); return; }

        $d = $_POST;
        $d['borrower_id'] = (int)$borrower['id'];
        $d['loan_id'] = '';
        $contract = trim((string)($_POST['contract_ref'] ?? ''));
        if ($contract !== '') {
            $stmt = Database::pdo()->prepare("SELECT id FROM loans WHERE borrower_id = ? AND contract_ref = ?"
                . (!empty($_POST['against_inst_id']) ? " AND institution_id = ?" : ""));
            $stmt->execute(array_merge([$borrower['id'], $contract], !empty($_POST['against_inst_id']) ? [(int)$_POST['against_inst_id']] : []));
            $loanIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            if (count($loanIds) !== 1) { $this->form('Contract reference not found for this borrower' . (count($loanIds) > 1 ? ' — select the institution.' : '.'), $_POST); return; }
            $d['loan_id'] = (string)$loanIds[0];
        }
        $id = DisputeService::file($d, $instId, (int)Auth::id());
        if (!$id) { $this->form((string)DisputeService::$error, $_POST); return; }
        \App\Core\Flash::set('success', 'Dispute filed. The statutory 30-day response window has started.');
        header('Location: ' . Rbac::baseUrl() . '/disputes');
    }

    /** POST JSON: {id, status, note, correction?{entity, entity_id, field, new_value}} */
    public function transition(): void
    {
        Rbac::require('disputes.work');
        Csrf::verifyJson();
        $d = Http::input();
        $id = (int)($d['id'] ?? 0);
        $status = (string)($d['status'] ?? '');
        $note = mb_substr(trim((string)($d['note'] ?? '')), 0, 2000);
        if (!in_array($status, ['UNDER_REVIEW', 'CORRECTED', 'REJECTED', 'WITHDRAWN'], true)) {
            Response::json(['error' => ['code' => 'BAD_STATUS', 'message' => 'Invalid target status.']], 422);
        }
        if (in_array($status, ['REJECTED', 'WITHDRAWN'], true) && mb_strlen($note) < 5) {
            Response::json(['error' => ['code' => 'NOTE_REQUIRED', 'message' => 'A resolution note is required.']], 422);
        }
        $correction = isset($d['correction']) && is_array($d['correction']) ? $d['correction'] : null;
        if (!DisputeService::transition($id, $status, (int)Auth::id(), $note, $correction)) {
            Response::json(['error' => ['code' => 'TRANSITION_FAILED', 'message' => DisputeService::$error]], 422);
        }
        Response::json(['ok' => true, 'id' => $id, 'status' => $status]);
    }

    /** Furnisher response by the institution whose data is disputed. */
    public function respond(): void
    {
        Rbac::require('disputes.file');
        Http::verifyCsrf();
        $d = Http::input();
        $instId = Auth::institutionId();
        $id = (int)($d['id'] ?? 0);
        if (!$instId || !$id) Http::done(false, 'Invalid request.', '/disputes');
        $ok = DisputeService::respond($id, $instId, (int)Auth::id(), (string)($d['response'] ?? ''));
        Http::done($ok, $ok ? 'Response recorded for the bureau reviewer.' : (string)DisputeService::$error, '/disputes');
    }
}
