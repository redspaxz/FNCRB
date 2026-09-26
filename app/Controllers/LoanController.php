<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http;
use App\Core\Pagination;
use App\Core\Rbac;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Services\CollateralService;
use App\Services\IncidentService;
use App\Services\IngestionService;
use App\Services\ReconciliationService;
use App\Services\ReportService;

final class LoanController
{
    public function index(): void
    {
        Rbac::requireAny(['loan.view.own', 'loan.view.all']);
        $isRegulator = Auth::isNational() && Rbac::can('loan.view.all');
        $sql = "SELECT l.*, i.code AS inst_code, i.name AS inst_name, b.full_name, b.master_ref
                FROM loans l
                JOIN institutions i ON i.id = l.institution_id
                JOIN borrowers b ON b.id = l.borrower_id";
        $where = [];
        $params = [];
        if (!$isRegulator) { $where[] = "l.institution_id = ?"; $params[] = Auth::institutionId(); }

        // whitelisted chart drill-down filters
        $class = strtoupper(trim((string)(is_string($_GET['class'] ?? null) ? $_GET['class'] : '')));
        if (!in_array($class, \App\Services\ClassificationService::CLASSES, true)) $class = '';
        else { $where[] = "l.cobac_class = ?"; $params[] = $class; }
        $inst = trim((string)(is_string($_GET['inst'] ?? null) ? $_GET['inst'] : ''));
        if ($inst !== '' && preg_match('/^[A-Z0-9-]{2,20}$/', $inst)) { $where[] = "i.code = ?"; $params[] = $inst; }
        else $inst = '';
        $period = trim((string)(is_string($_GET['period'] ?? null) ? $_GET['period'] : ''));
        if (preg_match('/^\d{4}-\d{2}$/', $period)) { $where[] = "DATE_FORMAT(l.reported_at, '%Y-%m') = ?"; $params[] = $period; }
        else $period = '';
        $status = Validator::enum($_GET, 'status', IngestionService::LOAN_STATUS) ?? '';
        if ($status !== '') { $where[] = "l.status = ?"; $params[] = $status; }
        $dpd = trim((string)(is_string($_GET['dpd'] ?? null) ? $_GET['dpd'] : ''));
        if ($dpd === '180') $dpd = '180+'; // tolerate an unencoded "+" decoded as space
        $map = [
            'current' => 'l.days_past_due = 0', '1-30' => 'l.days_past_due BETWEEN 1 AND 30',
            '31-90' => 'l.days_past_due BETWEEN 31 AND 90', '91-180' => 'l.days_past_due BETWEEN 91 AND 180',
            '180+' => 'l.days_past_due > 180',
        ];
        if (isset($map[$dpd])) { $where[] = $map[$dpd]; $where[] = "l.status IN " . \App\Services\ClassificationService::OPEN_SQL; }
        else $dpd = '';

        if ($where) $sql .= " WHERE " . implode(' AND ', $where);

        $page = Pagination::page();
        $perPage = Pagination::perPage();
        $count = Database::pdo()->prepare("SELECT COUNT(*) FROM ($sql) t");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $sql .= " ORDER BY l.updated_at DESC LIMIT $perPage OFFSET " . Pagination::offset($page, $perPage);
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        View::render('loans/index', [
            'loans' => $stmt->fetchAll(), 'isRegulator' => $isRegulator,
            'filters' => compact('class', 'inst', 'period', 'dpd', 'status'),
            'canReport' => Rbac::can('loan.report') && !Auth::isNational(),
            'pager' => Pagination::render('/loans', $page, $perPage, $total),
        ]);
    }

    /** Manual single-loan submission / update (small Cat-1 institutions without CBS integration). */
    public function create(): void
    {
        Rbac::require('loan.report');
        $this->form(null, ['borrower_identifier' => is_string($_GET['borrower'] ?? null) ? $_GET['borrower'] : '']);
    }

    private function form(?string $error, array $old): void
    {
        View::render('loans/create', ['error' => $error, 'old' => $old, 'national' => Auth::isNational()]);
    }

    public function store(): void
    {
        Rbac::require('loan.report');
        Csrf::verify();
        $instId = Auth::institutionId();
        if ($instId === null) { $this->form('Loan data is reported by the lending institution.', $_POST); return; }

        $identifier = trim((string)($_POST['borrower_identifier'] ?? ''));
        $borrower = $identifier !== '' ? ReconciliationService::lookup($identifier) : null;
        if (!$borrower) { $this->form('No registry borrower matches this identifier — register the borrower first.', $_POST); return; }

        $d = array_intersect_key($_POST, array_flip([
            'contract_ref', 'loan_type', 'principal_xaf', 'outstanding_xaf', 'monthly_payment_xaf', 'interest_rate_pct',
            'start_date', 'maturity_date', 'instalments_total', 'instalments_past_due', 'days_past_due', 'status',
        ]));
        $d['borrower'] = ['id' => (int)$borrower['id']];
        $d['reported_at'] = Validator::date($_POST, 'reported_at') ?? date('Y-m-d');

        $id = IngestionService::upsertLoan($d, $instId, 'MANUAL');
        \App\Services\DataQualityService::logSubmission($instId, 'MANUAL', 1, $id ? 1 : 0, $id ? 0 : 1);
        if (!$id) { $this->form('Submission rejected: ' . implode('; ', IngestionService::$errors), $_POST); return; }
        Audit::log('DATA_WRITE', ['type' => 'loan', 'id' => $id], ['action' => 'manual submit']);
        \App\Core\Flash::set('success', 'Loan record ' . $d['contract_ref'] . ' saved.');
        header('Location: ' . Rbac::baseUrl() . '/loans');
    }
}

final class CollateralController
{
    public function index(): void
    {
        Rbac::requireAny(['collateral.view.own', 'loan.view.all']);
        $national = Auth::isNational() && Rbac::can('loan.view.all');
        $sql = "FROM collateral c
                JOIN institutions i ON i.id = c.institution_id
                JOIN loans l ON l.id = c.loan_id
                JOIN borrowers b ON b.id = l.borrower_id";
        $params = [];
        if (!$national) { $sql .= " WHERE c.institution_id = ?"; $params[] = Auth::institutionId(); }
        $page = Pagination::page();
        $perPage = Pagination::perPage();
        $count = Database::pdo()->prepare("SELECT COUNT(*) $sql");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = Database::pdo()->prepare("SELECT c.*, i.code AS inst_code, l.contract_ref, b.full_name $sql
            ORDER BY c.status = 'REGISTERED' DESC, c.id DESC LIMIT $perPage OFFSET " . Pagination::offset($page, $perPage));
        $stmt->execute($params);
        View::render('collateral/index', [
            'collateral' => $stmt->fetchAll(),
            'doublePledges' => CollateralService::detectDoublePledge($national ? null : Auth::institutionId()),
            'canManage' => Rbac::can('collateral.manage') && !Auth::isNational(),
            'types' => CollateralService::TYPES,
            'pager' => Pagination::render('/collateral', $page, $perPage, $total),
        ]);
    }

    public function store(): void
    {
        Rbac::require('collateral.manage');
        Http::verifyCsrf();
        $d = Http::input();
        $instId = Auth::institutionId();
        if ($instId === null) Http::done(false, 'Collateral is registered by the lending institution.', '/collateral', [], 403);
        $id = CollateralService::register($d, $instId);
        if ($id === CollateralService::DOUBLE_PLEDGE) {
            Audit::log('DOUBLE_PLEDGE_BLOCKED', ['type' => 'collateral', 'id' => 0], ['rccm' => (string)($d['rccm_registration_no'] ?? '')]);
            Http::done(false, 'RCCM double-pledge detected: this security is already registered as collateral. Registration refused.', '/collateral', [], 409);
        }
        if ($id === null) Http::done(false, (string)CollateralService::$error, '/collateral');
        Audit::log('DATA_WRITE', ['type' => 'collateral', 'id' => $id], ['action' => 'register']);
        Http::done(true, 'Collateral registered.', '/collateral', ['id' => $id]);
    }

    public function status(): void
    {
        Rbac::require('collateral.manage');
        Http::verifyCsrf();
        $d = Http::input();
        $instId = Auth::institutionId();
        $id = Validator::int($d, 'id', 1);
        $status = Validator::enum($d, 'status', ['RELEASED', 'FORECLOSED']);
        if ($instId === null || !$id || !$status) Http::done(false, 'Invalid request.', '/collateral');
        $ok = CollateralService::changeStatus($id, $instId, $status, (int)Auth::id());
        if ($ok) Audit::log('COLLATERAL_' . $status, ['type' => 'collateral', 'id' => $id]);
        Http::done($ok, $ok ? "Collateral marked $status." : (string)CollateralService::$error, '/collateral');
    }
}

final class IncidentController
{
    public function index(): void
    {
        Rbac::requireAny(['incident.view.own', 'incident.view.all']);
        $isRegulator = Auth::isNational() && Rbac::can('incident.view.all');
        $sql = "SELECT pi.*, i.code AS inst_code, b.full_name, b.master_ref
                FROM payment_incidents pi
                JOIN institutions i ON i.id = pi.institution_id
                JOIN borrowers b ON b.id = pi.borrower_id";
        $where = [];
        $params = [];
        if (!$isRegulator) { $where[] = "pi.institution_id = ?"; $params[] = Auth::institutionId(); }
        $type = Validator::enum($_GET, 'type', IncidentService::TYPES);
        if ($type) { $where[] = "pi.incident_type = ?"; $params[] = $type; }
        $state = Validator::enum($_GET, 'state', ['open', 'resolved']);
        if ($state) $where[] = $state === 'open' ? 'pi.resolved = 0' : 'pi.resolved = 1';
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);

        $page = Pagination::page();
        $perPage = Pagination::perPage();
        $count = Database::pdo()->prepare("SELECT COUNT(*) FROM ($sql) t");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $sql .= " ORDER BY pi.resolved ASC, pi.incident_date DESC LIMIT $perPage OFFSET " . Pagination::offset($page, $perPage);
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        View::render('incidents/index', [
            'incidents' => $stmt->fetchAll(), 'isRegulator' => $isRegulator, 'typeFilter' => $type, 'state' => $state,
            'canReport' => Rbac::can('incident.report') && !Auth::isNational(),
            'myInst' => Auth::institutionId(), 'types' => IncidentService::TYPES,
            'pager' => Pagination::render('/incidents', $page, $perPage, $total),
        ]);
    }

    public function store(): void
    {
        Rbac::require('incident.report');
        Http::verifyCsrf();
        $instId = Auth::institutionId();
        if ($instId === null) Http::done(false, 'Incidents are reported by the institution holding the instrument.', '/incidents', [], 403);
        $id = IncidentService::report(Http::input(), $instId, 'MANUAL');
        if (!$id) Http::done(false, (string)IncidentService::$error, '/incidents');
        Http::done(true, 'Payment incident reported to the CIP.', '/incidents', ['id' => $id]);
    }

    public function resolve(): void
    {
        Rbac::require('incident.report');
        Http::verifyCsrf();
        $d = Http::input();
        $instId = Auth::institutionId();
        $id = Validator::int($d, 'id', 1);
        if ($instId === null || !$id) Http::done(false, 'Invalid request.', '/incidents');
        $ok = IncidentService::resolve($id, $instId, (string)($d['resolved_at'] ?? ''), (string)($d['note'] ?? ''), Auth::id());
        Http::done($ok, $ok ? 'Incident marked as regularized.' : (string)IncidentService::$error, '/incidents');
    }
}

final class ComplianceController
{
    public function index(): void
    {
        Rbac::require('compliance.reports');
        $instId = Auth::institutionId();
        View::render('compliance/index', [
            'portfolio' => ReportService::portfolioQuality($instId),
            'cip'       => ReportService::cipSummary($instId),
            'canProvision' => Rbac::can('compliance.provisioning'),
        ]);
    }

    public function supervisoryPackage(): void
    {
        Rbac::require('compliance.reports');
        Audit::log('SUPERVISORY_PACKAGE', null, ['scope' => Auth::isNational() ? 'national' : 'institution']);
        Response::json(ReportService::supervisoryPackage(Auth::institutionId()));
    }

    public function reclassify(): void
    {
        Rbac::require('compliance.provisioning');
        Csrf::verifyJson();
        $n = IngestionService::reclassifyPortfolio(Auth::institutionId());
        Audit::log('PROVISIONING_RUN', null, ['loans_reclassified' => $n, 'scope' => Auth::isNational() ? 'national' : 'institution']);
        Response::json(['ok' => true, 'loans_reclassified' => $n]);
    }

    public function concentration(): void
    {
        Rbac::require('compliance.reports');
        Response::json(\App\Services\ConcentrationService::report(Auth::institutionId()));
    }
}

final class AuditController
{
    public function index(): void
    {
        Rbac::require('audit.view');
        $national = Auth::isNational();
        $page = Pagination::page();
        $perPage = Pagination::perPage(50);
        $offset = Pagination::offset($page, $perPage);
        $where = [];
        $params = [];
        if (!$national) { $where[] = 'a.institution_id = ?'; $params[] = Auth::institutionId(); }
        $action = Validator::identifier($_GET, 'action', 60);
        if ($action) { $where[] = 'a.action = ?'; $params[] = $action; }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $c = Database::pdo()->prepare("SELECT COUNT(*) FROM audit_logs a$w");
        $c->execute($params);
        $total = (int)$c->fetchColumn();
        $stmt = Database::pdo()->prepare(
            "SELECT a.*, u.full_name, i.code AS inst_code
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN institutions i ON i.id = a.institution_id
             $w ORDER BY a.id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $chain = \App\Core\Audit::verify(false);
        View::render('audit/index', [
            'logs' => $stmt->fetchAll(),
            'chain' => $chain,
            'national' => $national,
            'total' => $total,
            'action' => $action,
            'checkpoint' => Database::pdo('audit')->query("SELECT * FROM audit_checkpoints WHERE id = 1")->fetch() ?: null,
            'pager' => Pagination::render('/audit', $page, $perPage, $total),
        ]);
    }

    /** Full re-verification of the entire chain (bureau/regulator). */
    public function verify(): void
    {
        Rbac::require('audit.view');
        Csrf::verify();
        if (!Auth::isNational()) { http_response_code(403); (new PageController())->forbidden(); return; }
        $r = \App\Core\Audit::verify(true);
        \App\Core\Audit::log('AUDIT_CHAIN_VERIFIED', null, ['ok' => $r['ok'], 'checked' => $r['checked'], 'broken_at' => $r['broken_at'], 'legacy_rows' => $r['legacy_rows']]);
        \App\Core\Flash::set($r['ok'] ? 'success' : 'danger', $r['ok']
            ? "Full verification passed: {$r['checked']} entries ({$r['legacy_rows']} legacy link-only)."
            : "Full verification FAILED at #{$r['broken_at']}: {$r['reason']}.");
        header('Location: ' . \App\Core\Rbac::baseUrl() . '/audit');
    }
}
