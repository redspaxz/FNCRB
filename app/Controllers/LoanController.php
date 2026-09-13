<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Rbac;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Services\CollateralService;
use App\Services\IngestionService;
use App\Services\InquiryService;
use App\Services\ReportService;

final class LoanController
{
    public function index(): void
    {
        if (!Rbac::can('loan.view.own') && !Rbac::can('loan.view.all')) Rbac::require('loan.view.own');
        $isRegulator = \App\Core\Auth::institutionId() === null && Rbac::can('loan.view.all');
        $sql = "SELECT l.*, i.code AS inst_code, i.name AS inst_name, b.full_name, b.master_ref
                FROM loans l
                JOIN institutions i ON i.id = l.institution_id
                JOIN borrowers b ON b.id = l.borrower_id";
        $where = [];
        $params = [];
        if (!$isRegulator) { $where[] = "l.institution_id = ?"; $params[] = \App\Core\Auth::institutionId(); }

        // whitelisted chart drill-down filters
        $class = strtoupper(trim((string)($_GET['class'] ?? '')));
        if (!in_array($class, ['HEALTHY','WATCH','UNCERTAIN','DOUBTFUL','COMPROMISED'], true)) $class = '';
        else {
            $where[] = "l.cobac_class = ?";
            $params[] = $class;
        }
        $inst = trim((string)($_GET['inst'] ?? ''));
        if ($inst !== '' && preg_match('/^[A-Z0-9-]{2,20}$/', $inst)) {
            $where[] = "i.code = ?";
            $params[] = $inst;
        } else $inst = '';
        $period = trim((string)($_GET['period'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            $where[] = "DATE_FORMAT(l.reported_at, '%Y-%m') = ?";
            $params[] = $period;
        } else $period = '';
        $dpd = trim((string)($_GET['dpd'] ?? ''));
        $map = [
            'current' => 'l.days_past_due = 0', '1-30' => 'l.days_past_due BETWEEN 1 AND 30',
            '31-90' => 'l.days_past_due BETWEEN 31 AND 90', '91-180' => 'l.days_past_due BETWEEN 91 AND 180',
            '180+' => 'l.days_past_due > 180',
        ];
        if (isset($map[$dpd])) $where[] = $map[$dpd]; else $dpd = '';

        if ($where) $sql .= " WHERE " . implode(' AND ', $where);

        $page = \App\Core\Pagination::page();
        $perPage = \App\Core\Pagination::perPage();
        $count = Database::pdo()->prepare("SELECT COUNT(*) FROM ($sql) t");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $sql .= " ORDER BY l.updated_at DESC LIMIT $perPage OFFSET " . \App\Core\Pagination::offset($page, $perPage);
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        View::render('loans/index', [
            'loans' => $stmt->fetchAll(), 'isRegulator' => $isRegulator, 'filters' => compact('class', 'inst', 'period', 'dpd'),
            'pager' => \App\Core\Pagination::render('/loans', $page, $perPage, $total),
        ]);
    }

    /** Manual single-loan submission (small Cat-1 institutions without CBS integration). */
    public function create(): void
    {
        Rbac::require('loan.report');
        $borrowers = Database::pdo()->query(
            "SELECT id, master_ref, full_name FROM borrowers WHERE dup_of_id IS NULL ORDER BY full_name"
        )->fetchAll();
        View::render('loans/create', ['error' => null, 'borrowers' => $borrowers]);
    }

    public function store(): void
    {
        Rbac::require('loan.report');
        Csrf::verify();
        $instId = (int)\App\Core\Auth::institutionId();

        $borrowerId = Validator::int($_POST, 'borrower_id', 1);
        $d = [
            'borrower' => ['id' => $borrowerId],
            'full_name' => 'x', // resolved via borrower id below
            'contract_ref' => Validator::string($_POST, 'contract_ref', 50),
            'loan_type' => Validator::enum($_POST, 'loan_type',
                ['CONSUMER','MORTGAGE','BUSINESS','MICRO','PROJECT','OVERDRAFT','LEASE']),
            'principal_xaf' => Validator::int($_POST, 'principal_xaf', 1),
            'outstanding_xaf' => Validator::int($_POST, 'outstanding_xaf', 0),
            'monthly_payment_xaf' => Validator::int($_POST, 'monthly_payment_xaf', 0) ?? 0,
            'interest_rate_pct' => (float)($_POST['interest_rate_pct'] ?? 0),
            'start_date' => Validator::date($_POST, 'start_date'),
            'maturity_date' => Validator::date($_POST, 'maturity_date'),
            'instalments_total' => Validator::int($_POST, 'instalments_total', 0) ?? 0,
            'instalments_past_due' => Validator::int($_POST, 'instalments_past_due', 0) ?? 0,
            'days_past_due' => Validator::int($_POST, 'days_past_due', 0) ?? 0,
            'status' => Validator::enum($_POST, 'status', ['ACTIVE','SETTLED','WRITTEN_OFF','RESTRUCTURED']) ?? 'ACTIVE',
            'reported_at' => date('Y-m-d'),
        ];

        if (!$borrowerId || !$d['contract_ref'] || !$d['loan_type'] || !$d['principal_xaf']
            || $d['outstanding_xaf'] === null || !$d['start_date'] || !$d['maturity_date']) {
            View::render('loans/create', [
                'error' => 'All required fields must be completed.',
                'borrowers' => $this->borrowerList(),
            ]);
            return;
        }

        $id = IngestionService::upsertLoan($d, $instId, 'MANUAL');
        if (!$id) {
            View::render('loans/create', [
                'error' => 'Ingestion failed: ' . implode('; ', IngestionService::$errors),
                'borrowers' => $this->borrowerList(),
            ]);
            return;
        }
        Audit::log('DATA_WRITE', ['type' => 'loan', 'id' => $id], ['action' => 'manual submit']);
        header('Location: ' . Rbac::baseUrl() . '/loans');
    }

    private function borrowerList(): array
    {
        return Database::pdo()->query(
            "SELECT id, master_ref, full_name FROM borrowers WHERE dup_of_id IS NULL ORDER BY full_name"
        )->fetchAll();
    }
}

final class CollateralController
{
    public function index(): void
    {
        if (!Rbac::can('collateral.view.own') && !Rbac::can('loan.view.all')) Rbac::require('collateral.view.own');
        $isRegulator = \App\Core\Auth::institutionId() === null;
        $sql = "SELECT c.*, i.code AS inst_code, l.contract_ref, b.full_name
                FROM collateral c
                JOIN institutions i ON i.id = c.institution_id
                JOIN loans l ON l.id = c.loan_id
                JOIN borrowers b ON b.id = l.borrower_id";
        $params = [];
        if (!$isRegulator) { $sql .= " WHERE c.institution_id = ?"; $params[] = \App\Core\Auth::institutionId(); }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        View::render('collateral/index', [
            'collateral' => $stmt->fetchAll(),
            'doublePledges' => CollateralService::detectDoublePledge($isRegulator ? null : \App\Core\Auth::institutionId()),
        ]);
    }

    public function store(): void
    {
        Rbac::require('collateral.manage');
        Csrf::verifyJson();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $instId = (int)\App\Core\Auth::institutionId();
        $id = CollateralService::register($d, $instId);
        if ($id === -1) {
            \App\Core\Audit::log('DOUBLE_PLEDGE_BLOCKED', ['type' => 'collateral', 'id' => 0], ['rccm' => $_POST['rccm_registration_no'] ?? '']);
            Response::json(['error' => 'RCCM double-pledge detected: this security is already registered with another institution.'], 409);
        }
        if ($id === null) Response::json(['error' => 'Invalid collateral data.'], 422);
        \App\Core\Audit::log('DATA_WRITE', ['type' => 'collateral', 'id' => $id], ['action' => 'register']);
        Response::json(['ok' => true, 'id' => $id]);
    }
}

final class IncidentController
{
    public function index(): void
    {
        if (!Rbac::can('incident.view.own') && !Rbac::can('incident.view.all')) Rbac::require('incident.view.own');
        $isRegulator = \App\Core\Auth::institutionId() === null && Rbac::can('incident.view.all');
        $sql = "SELECT pi.*, i.code AS inst_code, b.full_name, b.master_ref
                FROM payment_incidents pi
                JOIN institutions i ON i.id = pi.institution_id
                JOIN borrowers b ON b.id = pi.borrower_id";
        $where = [];
        $params = [];
        if (!$isRegulator) { $where[] = "pi.institution_id = ?"; $params[] = \App\Core\Auth::institutionId(); }
        $type = strtoupper(trim((string)($_GET['type'] ?? '')));
        if (in_array($type, ['BOUNCED_CHEQUE','DEFAULTED_NOTE','UNAUTHORIZED_OVERDRAFT','FRAUD_INSTRUMENT'], true)) {
            $where[] = "pi.incident_type = ?";
            $params[] = $type;
        }
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);

        $page = \App\Core\Pagination::page();
        $perPage = \App\Core\Pagination::perPage();
        $count = Database::pdo()->prepare("SELECT COUNT(*) FROM ($sql) t");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $sql .= " ORDER BY pi.incident_date DESC LIMIT $perPage OFFSET " . \App\Core\Pagination::offset($page, $perPage);
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        View::render('incidents/index', [
            'incidents' => $stmt->fetchAll(), 'isRegulator' => $isRegulator, 'typeFilter' => $type ?: null,
            'pager' => \App\Core\Pagination::render('/incidents', $page, $perPage, $total),
        ]);
    }

    public function store(): void
    {
        Rbac::require('incident.report');
        Csrf::verifyJson();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $borrowerId = Validator::int($d, 'borrower_id', 1);
        $type = Validator::enum($d, 'incident_type',
            ['BOUNCED_CHEQUE','DEFAULTED_NOTE','UNAUTHORIZED_OVERDRAFT','FRAUD_INSTRUMENT']);
        $ref = Validator::string($d, 'instrument_ref', 60);
        $amt = Validator::int($d, 'amount_xaf', 1);
        $date = Validator::date($d, 'incident_date');
        if (!$borrowerId || !$type || !$ref || !$amt || !$date) {
            Response::json(['error' => 'Missing or invalid incident fields.'], 422);
        }
        $stmt = Database::pdo()->prepare(
            "INSERT INTO payment_incidents (institution_id, borrower_id, incident_type, instrument_ref, amount_xaf, incident_date)
             VALUES (?,?,?,?,?,?)"
        );
        $stmt->execute([\App\Core\Auth::institutionId(), $borrowerId, $type, $ref, $amt, $date]);
        $id = (int)Database::pdo()->lastInsertId();
        Audit::log('DATA_WRITE', ['type' => 'incident', 'id' => $id], ['action' => 'CIP report']);
        Response::json(['ok' => true, 'id' => $id], 201);
    }
}

final class ComplianceController
{
    public function index(): void
    {
        Rbac::require('compliance.reports');
        $instId = \App\Core\Auth::institutionId();
        View::render('compliance/index', [
            'portfolio' => ReportService::portfolioQuality($instId),
            'cip'       => ReportService::cipSummary($instId),
        ]);
    }

    public function supervisoryPackage(): void
    {
        Rbac::require('compliance.reports');
        Response::json(ReportService::supervisoryPackage(\App\Core\Auth::institutionId()));
    }

    public function reclassify(): void
    {
        Rbac::require('compliance.provisioning');
        Csrf::verifyJson();
        $n = IngestionService::reclassifyPortfolio(\App\Core\Auth::institutionId());
        Audit::log('PROVISIONING_RUN', null, ['loans_reclassified' => $n]);
        Response::json(['ok' => true, 'loans_reclassified' => $n]);
    }

    public function concentration(): void
    {
        Rbac::require('compliance.reports');
        Response::json(ReportService::supervisoryPackage()['concentration']);
    }
}

final class AuditController
{
    public function index(): void
    {
        Rbac::require('audit.view');
        $page = \App\Core\Pagination::page();
        $perPage = \App\Core\Pagination::perPage(50);
        $total = (int)Database::pdo()->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
        $offset = \App\Core\Pagination::offset($page, $perPage);
        $stmt = Database::pdo()->query(
            "SELECT a.*, u.full_name, i.code AS inst_code
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN institutions i ON i.id = a.institution_id
             ORDER BY a.id DESC LIMIT $perPage OFFSET $offset"
        );
        [$chainOk, $brokenAt] = \App\Core\Audit::verifyChain();
        View::render('audit/index', [
            'logs' => $stmt->fetchAll(),
            'chainOk' => $chainOk,
            'brokenAt' => $brokenAt,
            'pager' => \App\Core\Pagination::render('/audit', $page, $perPage, $total),
        ]);
    }
}
