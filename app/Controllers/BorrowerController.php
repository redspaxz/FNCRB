<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Pagination;
use App\Core\Rbac;
use App\Core\Validator;
use App\Core\View;
use App\Services\ExposureService;
use App\Services\InquiryService;
use App\Services\ReconciliationService;

/**
 * Borrower registry. Institution users only browse borrowers they have a
 * relationship with (own loans, own registrations, active consents); anyone
 * else must be located by an exact identifier, and the full file is only
 * disclosed through a consent-gated inquiry.
 */
final class BorrowerController
{
    public static function maskId(?string $v): string
    {
        if ($v === null || $v === '') return '—';
        return strlen($v) <= 4 ? '••••' : str_repeat('•', max(0, strlen($v) - 4)) . substr($v, -4);
    }

    public function index(): void
    {
        Rbac::require('borrower.manage');
        $q = trim((string)(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''));
        $q = mb_substr($q, 0, 80);
        $pdo = Database::pdo();
        $page = Pagination::page();
        $perPage = Pagination::perPage();
        $offset = Pagination::offset($page, $perPage);
        $instId = Auth::institutionId();

        $where = ["b.dup_of_id IS NULL"];
        $params = [];
        if ($instId !== null) {
            [$scope, $sp] = ReconciliationService::scopeSql('b', $instId);
            $where[] = $scope;
            $params = array_merge($params, $sp);
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(b.full_name LIKE ? OR b.cni_number LIKE ? OR b.niu LIKE ? OR b.master_ref LIKE ? OR b.coop_member_id LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $cond = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(*) FROM borrowers b WHERE $cond");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = $pdo->prepare("SELECT b.* FROM borrowers b WHERE $cond ORDER BY " . ($q !== '' ? 'b.full_name' : 'b.id DESC') . " LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);

        // Registry-wide exact-identifier match (minimal disclosure) for institution users
        $registryMatch = null;
        if ($instId !== null && $q !== '') {
            $m = ReconciliationService::lookup($q);
            if ($m && !ReconciliationService::institutionCanSee((int)$m['id'], $instId)) {
                $registryMatch = ['master_ref' => $m['master_ref'], 'full_name' => $m['full_name'], 'type' => $m['type']];
                Audit::log('BORROWER_LOOKUP', ['type' => 'borrower', 'id' => $m['id']], ['match' => 'exact identifier']);
            }
        }

        View::render('borrowers/index', [
            'borrowers' => $stmt->fetchAll(), 'q' => $q, 'registryMatch' => $registryMatch,
            'national' => $instId === null,
            'canReport' => Rbac::can('disputes.work'),
            'canInquire' => Rbac::can('inquiry.perform'),
            'pager' => Pagination::render('/borrowers', $page, $perPage, $total),
        ]);
    }

    public function create(): void
    {
        Rbac::require('borrower.manage');
        View::render('borrowers/create', ['error' => null, 'old' => []]);
    }

    public function store(): void
    {
        Rbac::require('borrower.manage');
        Csrf::verify();

        $d = [
            'full_name'      => Validator::string($_POST, 'full_name', 200),
            'type'           => Validator::enum($_POST, 'type', ['INDIVIDUAL', 'CORPORATE']) ?? 'INDIVIDUAL',
            'date_of_birth'  => Validator::date($_POST, 'date_of_birth'),
            'gender'         => Validator::enum($_POST, 'gender', ['M', 'F']),
            'cni_number'     => Validator::identifier($_POST, 'cni_number', 30),
            'niu'            => Validator::identifier($_POST, 'niu', 30),
            'coop_member_id' => Validator::identifier($_POST, 'coop_member_id', 40),
            'phone'          => Validator::string($_POST, 'phone', 25, 0) ?: null,
            'region'         => Validator::string($_POST, 'region', 60, 0) ?: null,
        ];
        $fail = fn(string $m) => View::render('borrowers/create', ['error' => $m, 'old' => $_POST]);
        if (!$d['full_name']) { $fail('Full name is required.'); return; }
        foreach (['cni_number', 'niu', 'coop_member_id'] as $f) {
            if (!empty($_POST[$f]) && $d[$f] === null) { $fail("$f has an invalid format (letters, digits, / _ . -)."); return; }
        }
        if (!$d['cni_number'] && !$d['niu'] && !$d['coop_member_id']) { $fail('At least one identifier (CNI, NIU or cooperative ID) is required.'); return; }
        if (!empty($_POST['date_of_birth']) && !$d['date_of_birth']) { $fail('Date of birth must be a valid date.'); return; }
        if (ReconciliationService::findByIdentity($d['cni_number'], $d['niu'], $d['coop_member_id'])) {
            $fail('A borrower with this CNI/NIU/cooperative ID already exists in the registry — search for it by identifier.');
            return;
        }
        $b = ReconciliationService::resolve($d, Auth::institutionId(), 'MANUAL');
        if (!$b) { $fail(ReconciliationService::$error ?? 'Registration failed.'); return; }
        Audit::log('DATA_WRITE', ['type' => 'borrower', 'id' => $b['id']], ['action' => 'create']);
        \App\Core\Flash::set('success', 'Borrower registered as ' . $b['master_ref'] . '.');
        header('Location: ' . Rbac::baseUrl() . '/borrowers?q=' . rawurlencode($b['master_ref']));
    }

    /** Consent-gated inquiry workflow (web channel). */
    public function inquiry(): void
    {
        Rbac::require('inquiry.perform');
        $error = null;
        $report = null;
        $borrower = null;
        $instId = Auth::institutionId();
        $identifier = trim((string)(is_string($_REQUEST['identifier'] ?? null) ? $_REQUEST['identifier'] : ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify();
            $consentRef = Validator::string($_POST, 'consent_ref', 80);
            $consentType = Validator::enum($_POST, 'consent_type', InquiryService::CONSENT_TYPES);
            $signedAt = Validator::date($_POST, 'consent_signed_at');
            $purpose = Validator::string($_POST, 'purpose', 255) ?? '';
            $income = Validator::int($_POST, 'declared_monthly_income', 0) ?? 0;
            $borrower = $identifier !== '' ? ReconciliationService::lookup($identifier) : null;

            if ($instId === null) {
                $error = 'Inquiries are performed by reporting institutions only.';
            } elseif (!$borrower) {
                $error = 'No borrower matches this identifier (CNI, NIU, cooperative ID or registry reference).';
                Audit::log('INQUIRY_NO_MATCH', null, ['channel' => 'WEB']);
            } elseif (!$consentRef || !$consentType || !$signedAt) {
                $error = 'Consent type, consent reference and signature date are required.';
            } elseif (empty($_POST['consent_attested'])) {
                $error = 'You must attest that the borrower signed the consent presented.';
            } elseif ($purpose === '') {
                $error = 'State the purpose of the inquiry.';
            } else {
                $evidence = InquiryService::storeEvidence($_FILES['consent_evidence'] ?? null);
                if ($evidence === null) {
                    $error = InquiryService::$error;
                } else {
                    $cid = InquiryService::recordConsent((int)$borrower['id'], $instId, $consentType, $consentRef, $signedAt, Auth::id(), $evidence);
                    if (!$cid) {
                        $error = InquiryService::$error;
                    } else {
                        $report = InquiryService::runInquiry((int)$borrower['id'], $instId, Auth::id(), 'WEB', $purpose, $cid, $income);
                        $error = $report ? null : InquiryService::$error;
                    }
                }
            }
        }

        View::render('borrowers/inquiry', [
            'report' => $report, 'error' => $error, 'borrower' => $report ? $borrower : null,
            'identifier' => $identifier, 'old' => $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [],
        ]);
    }

    /**
     * Consumer credit file (subject-access / dispute investigation): everything
     * the registry holds on one person, including who consulted it.
     */
    public function consumerReport(): void
    {
        Rbac::require('disputes.work');
        $ref = trim((string)(is_string($_GET['ref'] ?? null) ? $_GET['ref'] : ''));
        $b = $ref !== '' ? ReconciliationService::lookup($ref) : null;
        $file = null;
        if ($b) {
            $pdo = Database::pdo();
            $id = (int)$b['id'];
            $q = function (string $sql) use ($pdo, $id) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetchAll(); };
            $file = [
                'borrower' => $b,
                'loans' => $q("SELECT l.*, i.code AS inst_code FROM loans l JOIN institutions i ON i.id = l.institution_id WHERE l.borrower_id = ? ORDER BY l.status, l.reported_at DESC"),
                'history' => $q("SELECT h.*, l.contract_ref FROM loan_history h JOIN loans l ON l.id = h.loan_id WHERE l.borrower_id = ? ORDER BY h.reported_at DESC LIMIT 120"),
                'incidents' => $q("SELECT pi.*, i.code AS inst_code FROM payment_incidents pi JOIN institutions i ON i.id = pi.institution_id WHERE pi.borrower_id = ? ORDER BY pi.incident_date DESC"),
                'collateral' => $q("SELECT c.*, i.code AS inst_code, l.contract_ref FROM collateral c JOIN loans l ON l.id = c.loan_id JOIN institutions i ON i.id = c.institution_id WHERE l.borrower_id = ?"),
                'guarantees' => ExposureService::guaranteesOf($id),
                'inquiries' => $q("SELECT q.created_at, q.channel, q.purpose, i.code AS inst_code, c.consent_ref, c.consent_type FROM inquiry_logs q JOIN institutions i ON i.id = q.institution_id LEFT JOIN consents c ON c.id = q.consent_id WHERE q.borrower_id = ? ORDER BY q.created_at DESC LIMIT 200"),
                'consents' => $q("SELECT c.*, i.code AS inst_code FROM consents c JOIN institutions i ON i.id = c.institution_id WHERE c.borrower_id = ? ORDER BY c.granted_at DESC"),
                'disputes' => $q("SELECT d.reference, d.status, d.dispute_type, d.created_at, d.resolved_at FROM disputes d WHERE d.borrower_id = ? ORDER BY d.created_at DESC"),
            ];
            Audit::log('CONSUMER_FILE_VIEWED', ['type' => 'borrower', 'id' => $id], ['purpose' => 'subject access / dispute investigation']);
        }
        View::render('borrowers/report', ['ref' => $ref, 'file' => $file, 'notFound' => $ref !== '' && !$b]);
    }
}
