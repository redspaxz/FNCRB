<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Rbac;
use App\Core\Validator;
use App\Core\View;
use App\Services\InquiryService;
use App\Services\ReconciliationService;

final class BorrowerController
{
    public function index(): void
    {
        Rbac::require('borrower.manage');
        $q = trim((string)($_GET['q'] ?? ''));
        $pdo = Database::pdo();
        if ($q !== '') {
            $stmt = $pdo->prepare(
                "SELECT * FROM borrowers
                 WHERE dup_of_id IS NULL AND (full_name LIKE ? OR cni_number LIKE ? OR niu LIKE ? OR master_ref LIKE ? OR coop_member_id LIKE ?)
                 ORDER BY full_name LIMIT 50"
            );
            $like = "%$q%";
            $stmt->execute([$like, $like, $like, $like, $like]);
        } else {
            $stmt = $pdo->query("SELECT * FROM borrowers WHERE dup_of_id IS NULL ORDER BY id DESC LIMIT 50");
        }
        View::render('borrowers/index', ['borrowers' => $stmt->fetchAll(), 'q' => $q]);
    }

    public function create(): void
    {
        Rbac::require('borrower.manage');
        View::render('borrowers/create', ['error' => null]);
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
            'cni_number'     => Validator::string($_POST, 'cni_number', 30, 0) ?: null,
            'niu'            => Validator::string($_POST, 'niu', 30, 0) ?: null,
            'coop_member_id' => Validator::string($_POST, 'coop_member_id', 40, 0) ?: null,
            'phone'          => Validator::string($_POST, 'phone', 25, 0) ?: null,
            'region'         => Validator::string($_POST, 'region', 60, 0) ?: null,
        ];
        if (!$d['full_name']) {
            View::render('borrowers/create', ['error' => 'Full name is required.']);
            return;
        }
        if (ReconciliationService::findByIdentity($d['cni_number'], $d['niu'], $d['coop_member_id'])) {
            View::render('borrowers/create', ['error' => 'A borrower with this CNI/NIU/cooperative ID already exists in the registry.']);
            return;
        }
        $b = ReconciliationService::findOrCreate($d);
        Audit::log('DATA_WRITE', ['type' => 'borrower', 'id' => $b['id']], ['action' => 'create']);
        header('Location: ' . Rbac::baseUrl() . '/borrowers');
    }

    /** Consent-gated inquiry workflow (web channel). */
    public function inquiry(): void
    {
        Rbac::require('inquiry.perform');
        $error = null;
        $report = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verify();
            $instId = (int)\App\Core\Auth::institutionId();
            $borrowerId = Validator::int($_POST, 'borrower_id', 1);
            $consentRef = Validator::string($_POST, 'consent_ref', 80);
            $consentType = Validator::enum($_POST, 'consent_type', ['DIGITAL', 'PHYSICAL']);
            $purpose = Validator::string($_POST, 'purpose', 255) ?? '';
            $income = Validator::int($_POST, 'declared_monthly_income', 0) ?? 0;

            if ($borrowerId && $consentRef && $consentType) {
                InquiryService::recordConsent($borrowerId, $instId, $consentType, $consentRef);
                $report = InquiryService::runInquiry($borrowerId, $instId, \App\Core\Auth::id(), 'WEB', $purpose, null, $income);
                $error = $report ? null : InquiryService::$error;
            } else {
                $error = 'Borrower, consent type and consent reference are required.';
            }
        }

        $borrowers = Database::pdo()->query(
            "SELECT id, master_ref, full_name, cni_number FROM borrowers WHERE dup_of_id IS NULL ORDER BY full_name"
        )->fetchAll();

        View::render('borrowers/inquiry', ['borrowers' => $borrowers, 'report' => $report, 'error' => $error]);
    }
}
