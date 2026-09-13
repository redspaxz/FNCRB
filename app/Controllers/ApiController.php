<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Rbac;
use App\Core\Response;
use App\Core\Validator;
use App\Services\IngestionService;
use App\Services\DataQualityService;
use App\Services\InquiryService;
use App\Services\ReportService;

/**
 * Module 1/2/4 — Machine-to-machine API (Category 2 real-time inquiry, CBS ingestion).
 * Channel security (ApiGuard): HMAC-SHA256 request signing (timestamp.nonce.body),
 * ±5min clock window, nonce replay protection, 60 req/min rate limit, optional IP allow-list.
 * All payloads must carry schema_version "1.0"; errors use the typed envelope {error:{code,message}}.
 */
final class ApiController
{
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private function requireSchema(array $d): void
    {
        if (($d['schema_version'] ?? null) !== '1.0') {
            \App\Core\ApiGuard::fail('SCHEMA_VERSION', 'Payload must declare "schema_version":"1.0".', 422);
        }
    }

    /** POST /api/v1/loans — batch upsert of COBAC-standardized portfolio. */
    public function ingestLoans(): void
    {
        $inst = \App\Core\ApiGuard::authenticate();

        $d = $this->body();
        $this->requireSchema($d);
        $records = $d['loans'] ?? ($d ?: null);
        if (!is_array($records) || !$records) \App\Core\ApiGuard::fail('EMPTY_PAYLOAD', 'Request body must contain loan records.', 422);

        $accepted = 0; $errors = [];
        foreach ($records as $i => $rec) {
            // flatten borrower block
            if (isset($rec['borrower']) && is_array($rec['borrower'])) {
                $rec['borrower'] = $rec['borrower'];
            }
            $id = IngestionService::upsertLoan($rec, (int)$inst['id'], 'API');
            if ($id) { $accepted++; }
            else { $errors[] = ['index' => $i, 'messages' => IngestionService::$errors]; IngestionService::$errors = []; }
        }
        Audit::log('DATA_WRITE', ['type' => 'batch', 'id' => ''], ['accepted' => $accepted, 'rejected' => count($errors)]);
        \App\Services\DataQualityService::logSubmission((int)$inst['id'], 'API', count($records), $accepted, count($errors));
        Response::json(['schema_version' => '1.0', 'accepted' => $accepted, 'rejected' => count($errors), 'errors' => $errors],
            $accepted > 0 ? 200 : 422);
    }

    /** POST /api/v1/inquiry — real-time consent-gated credit check. */
    public function inquiry(): void
    {
        $inst = \App\Core\ApiGuard::authenticate();

        $d = $this->body();
        $this->requireSchema($d);
        // identity: registry id, master_ref, cni or niu
        $borrower = null;
        $pdo = Database::pdo();
        if (!empty($d['borrower_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM borrowers WHERE id = ?");
            $stmt->execute([(int)$d['borrower_id']]);
            $borrower = $stmt->fetch();
        } elseif (!empty($d['master_ref'])) {
            $stmt = $pdo->prepare("SELECT * FROM borrowers WHERE master_ref = ?");
            $stmt->execute([$d['master_ref']]);
            $borrower = $stmt->fetch();
        } else {
            $cni = $d['cni_number'] ?? null; $niu = $d['niu'] ?? null; $coop = $d['coop_member_id'] ?? null;
            $borrower = \App\Services\ReconciliationService::findByIdentity($cni, $niu, $coop);
        }
        if (!$borrower) \App\Core\ApiGuard::fail('BORROWER_NOT_FOUND', 'No borrower matches the supplied identity in the registry.', 404);

        // consent must be presented with the request OR pre-recorded
        $consentId = isset($d['consent_id']) ? (int)$d['consent_id'] : null;
        if ($consentId === null && !empty($d['consent_ref'])) {
            $cid = InquiryService::recordConsent(
                (int)$borrower['id'], (int)$inst['id'],
                $d['consent_type'] ?? 'DIGITAL', $d['consent_ref']
            );
            $consentId = $cid;
        }

        $report = InquiryService::runInquiry(
            (int)$borrower['id'], (int)$inst['id'],
            \App\Core\Auth::id(), 'API',
            $d['purpose'] ?? 'credit underwriting', $consentId,
            (int)($d['declared_monthly_income'] ?? 0)
        );
        if (!$report) \App\Core\ApiGuard::fail('CONSENT_REQUIRED', InquiryService::$error, 412);

        Response::json([
            'borrower' => [
                'master_ref' => $borrower['master_ref'],
                'full_name'  => $borrower['full_name'],
                'region'     => $borrower['region'],
            ],
            'score'    => $report['score'],
            'exposure' => [
                'institution_count' => $report['exposure']['institution_count'],
                'total_outstanding_xaf' => $report['exposure']['total_outstanding_xaf'],
                'total_monthly_payment_xaf' => $report['exposure']['total_monthly_payment_xaf'],
                'arrears_loan_count' => $report['exposure']['arrears_loan_count'],
                'loans' => array_map(fn($l) => [
                    'institution' => $l['institution_code'],
                    'category'    => $l['institution_category'],
                    'contract_ref'=> $l['contract_ref'],
                    'loan_type'   => $l['loan_type'],
                    'outstanding_xaf' => (int)$l['outstanding_xaf'],
                    'days_past_due' => (int)$l['days_past_due'],
                    'cobac_class' => $l['cobac_class'],
                ], $report['exposure']['loans']),
            ],
            'payment_incidents' => array_map(fn($pi) => [
                'type' => $pi['incident_type'], 'amount_xaf' => (int)$pi['amount_xaf'],
                'date' => $pi['incident_date'], 'resolved' => (bool)$pi['resolved'],
            ], $report['incidents']),
            'schema_version' => '1.0',
            'generated_at' => $report['generated_at'],
        ]);
    }

    /** GET /api/v1/supervisory-package — BEAC/COBAC feed. */
    public function supervisoryPackage(): void
    {
        // regulator session or super-admin only (not available to API keys)
        \App\Core\Auth::start();
        if (!\App\Core\Auth::check() || !Rbac::can('compliance.reports')) {
            \App\Core\ApiGuard::fail('AUTH_REQUIRED', 'Regulator session required for the supervisory package.', 401);
        }
        Response::json(ReportService::supervisoryPackage());
    }
}
