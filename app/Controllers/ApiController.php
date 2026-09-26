<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiGuard;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Rbac;
use App\Core\Response;
use App\Core\Validator;
use App\Services\IncidentService;
use App\Services\IngestionService;
use App\Services\InquiryService;
use App\Services\ReconciliationService;
use App\Services\ReportService;

/**
 * Module 1/2/4 — Machine-to-machine API (Category 2 real-time inquiry, CBS ingestion, CIP feed).
 * Channel security (ApiGuard): HMAC-SHA256 request signing (timestamp.nonce.body),
 * ±5min clock window, nonce replay protection, 60 req/min rate limit, optional IP allow-list.
 * All payloads must carry schema_version "1.0"; errors use the typed envelope {error:{code,message}}.
 */
final class ApiController
{
    private function body(): array
    {
        $d = json_decode(ApiGuard::body(), true);
        if (!is_array($d)) ApiGuard::fail('INVALID_JSON', 'Request body must be a JSON object.', 400);
        return $d;
    }

    private function requireSchema(array $d): void
    {
        if (($d['schema_version'] ?? null) !== '1.0') {
            ApiGuard::fail('SCHEMA_VERSION', 'Payload must declare "schema_version":"1.0".', 422);
        }
    }

    /** POST /api/v1/loans — batch upsert of COBAC-standardized portfolio: {"schema_version":"1.0","loans":[...]} */
    public function ingestLoans(): void
    {
        $inst = ApiGuard::authenticate();
        $d = $this->body();
        $this->requireSchema($d);
        $records = $d['loans'] ?? null;
        if (!is_array($records) || !$records || !array_is_list($records)) {
            ApiGuard::fail('EMPTY_PAYLOAD', 'Body must contain a non-empty "loans" array.', 422);
        }
        $max = IngestionService::maxBatch();
        if (count($records) > $max) ApiGuard::fail('BATCH_TOO_LARGE', "At most $max records per request.", 413);

        $r = IngestionService::ingestBatch($records, (int)$inst['id'], 'API');
        Audit::log('DATA_WRITE', ['type' => 'batch', 'id' => ''], ['accepted' => $r['accepted'], 'rejected' => $r['rejected'], 'source' => 'API']);
        Response::json(['schema_version' => '1.0'] + $r, $r['accepted'] > 0 ? 200 : 422);
    }

    /**
     * POST /api/v1/inquiry — real-time consent-gated credit check.
     * Identity: master_ref | cni_number | niu | coop_member_id.
     * Consent: consent_id (previously recorded) OR consent_ref + consent_type + consent_signed_at
     * (+ consent_evidence_sha256, mandatory for PHYSICAL).
     */
    public function inquiry(): void
    {
        $inst = ApiGuard::authenticate();
        $d = $this->body();
        $this->requireSchema($d);

        $borrower = null;
        if (!empty($d['master_ref']) && is_string($d['master_ref'])) {
            $borrower = ReconciliationService::lookup($d['master_ref']);
        } else {
            $s = fn($k) => isset($d[$k]) && is_string($d[$k]) && $d[$k] !== '' ? $d[$k] : null;
            $borrower = ReconciliationService::findByIdentity($s('cni_number'), $s('niu'), $s('coop_member_id'));
        }
        if (!$borrower) {
            Audit::log('INQUIRY_NO_MATCH', null, ['channel' => 'API']);
            ApiGuard::fail('BORROWER_NOT_FOUND', 'No borrower matches the supplied identity in the registry.', 404);
        }

        $consentId = isset($d['consent_id']) ? Validator::int($d, 'consent_id', 1) : null;
        if ($consentId === null && !empty($d['consent_ref'])) {
            $type = Validator::enum($d, 'consent_type', InquiryService::CONSENT_TYPES);
            $signed = Validator::date($d, 'consent_signed_at');
            $sha = isset($d['consent_evidence_sha256']) && is_string($d['consent_evidence_sha256'])
                && preg_match('/^[a-f0-9]{64}$/i', $d['consent_evidence_sha256']) ? strtolower($d['consent_evidence_sha256']) : null;
            if (!$type || !$signed || !is_string($d['consent_ref'])) {
                ApiGuard::fail('CONSENT_INCOMPLETE', 'consent_ref, consent_type (DIGITAL|PHYSICAL) and consent_signed_at (YYYY-MM-DD) are required.', 422);
            }
            $consentId = InquiryService::recordConsent((int)$borrower['id'], (int)$inst['id'], $type, $d['consent_ref'], $signed, null,
                $sha ? ['sha256' => $sha] : []);
            if (!$consentId) ApiGuard::fail('CONSENT_REJECTED', (string)InquiryService::$error, 422);
        }

        $purpose = Validator::string($d, 'purpose', 255) ?? 'credit underwriting';
        $income = Validator::int($d, 'declared_monthly_income', 0) ?? 0;
        $report = InquiryService::runInquiry((int)$borrower['id'], (int)$inst['id'], null, 'API', $purpose, $consentId, $income);
        if (!$report) ApiGuard::fail('CONSENT_REQUIRED', (string)InquiryService::$error, 412);

        Response::json([
            'schema_version' => '1.0',
            'borrower' => [
                'master_ref' => $borrower['master_ref'],
                'full_name'  => $borrower['full_name'],
                'region'     => $borrower['region'],
            ],
            'consent_id' => (int)$report['consent']['id'],
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
                    'status'      => $l['status'],
                    'outstanding_xaf' => (int)$l['outstanding_xaf'],
                    'days_past_due' => (int)$l['days_past_due'],
                    'cobac_class' => $l['cobac_class'],
                ], $report['exposure']['loans']),
            ],
            'history' => [
                'worst_dpd_24m' => $report['history']['worst_dpd_24m'],
                'settled_count' => $report['history']['settled_count'],
                'written_off_count' => $report['history']['written_off_count'],
            ],
            'payment_incidents' => array_map(fn($pi) => [
                'type' => $pi['incident_type'], 'amount_xaf' => (int)$pi['amount_xaf'],
                'date' => $pi['incident_date'], 'resolved' => (bool)$pi['resolved'],
            ], $report['incidents']),
            'generated_at' => $report['generated_at'],
        ]);
    }

    /** POST /api/v1/incidents — CIP feed: {"schema_version":"1.0","incident":{...}} */
    public function reportIncident(): void
    {
        $inst = ApiGuard::authenticate();
        $d = $this->body();
        $this->requireSchema($d);
        $rec = $d['incident'] ?? null;
        if (!is_array($rec)) ApiGuard::fail('EMPTY_PAYLOAD', 'Body must contain an "incident" object.', 422);
        $id = IncidentService::report($rec, (int)$inst['id'], 'API');
        if (!$id) ApiGuard::fail('INCIDENT_REJECTED', (string)IncidentService::$error, 422);
        Response::json(['schema_version' => '1.0', 'id' => $id], 201);
    }

    /** GET /api/v1/supervisory-package — BEAC/COBAC feed (national session only). */
    public function supervisoryPackage(): void
    {
        if (!Auth::check() || !Auth::isNational() || !Rbac::can('compliance.reports')) {
            ApiGuard::fail('AUTH_REQUIRED', 'Regulator/bureau session required for the national supervisory package.', 401);
        }
        Audit::log('SUPERVISORY_PACKAGE', null, ['scope' => 'national', 'channel' => 'api']);
        Response::json(ReportService::supervisoryPackage(null));
    }
}
