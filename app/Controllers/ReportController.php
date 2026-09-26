<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Export;
use App\Core\Rbac;
use App\Services\ReportService;

/**
 * Downloadable report artifacts (CSV / XLSX) for institutions and supervisors.
 * Every export is audited with row count and SHA-256; both formats carry an
 * X-Report-SHA256 integrity header. Text cells are formula-injection safe.
 */
final class ReportController
{
    private const LOAN_HEADERS = [
        'Institution', 'BorrowerRef', 'Borrower', 'Contract', 'LoanType', 'PrincipalXAF',
        'OutstandingXAF', 'MonthlyPaymentXAF', 'DaysPastDue', 'COBACClass', 'ProvisionXAF',
        'Status', 'ReportedAt',
    ];

    private function loanRows(): array
    {
        Rbac::requireAny(['loan.view.own', 'loan.view.all']);
        $isRegulator = Auth::isNational() && Rbac::can('loan.view.all');
        $sql = "SELECT i.code AS inst_code, b.master_ref, b.full_name, l.contract_ref, l.loan_type,
                       l.principal_xaf, l.outstanding_xaf, l.monthly_payment_xaf, l.days_past_due,
                       l.cobac_class, l.provision_xaf, l.status, l.reported_at
                FROM loans l
                JOIN institutions i ON i.id = l.institution_id
                JOIN borrowers b ON b.id = l.borrower_id";
        $params = [];
        if (!$isRegulator) { $sql .= " WHERE l.institution_id = ?"; $params[] = Auth::institutionId(); }
        $sql .= " ORDER BY i.code, l.updated_at DESC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn($l) => [
            $l['inst_code'], $l['master_ref'], $l['full_name'], $l['contract_ref'], $l['loan_type'],
            (int)$l['principal_xaf'], (int)$l['outstanding_xaf'], (int)$l['monthly_payment_xaf'],
            (int)$l['days_past_due'], $l['cobac_class'], (int)$l['provision_xaf'], $l['status'], $l['reported_at'],
        ], $stmt->fetchAll());
    }

    public function loansCsv(): void
    {
        Export::csv('fncrb-portfolio-' . date('Ymd') . '.csv', $this->loanRows(), self::LOAN_HEADERS);
    }

    public function loansXlsx(): void
    {
        Export::xlsx('fncrb-portfolio-' . date('Ymd') . '.xlsx', 'Portfolio', self::LOAN_HEADERS, $this->loanRows());
    }

    public function supervisoryXlsx(): void
    {
        Rbac::require('compliance.reports');
        $pkg = ReportService::supervisoryPackage(Auth::institutionId());
        $rows = [];
        foreach ($pkg['portfolio'] as $code => $p) {
            foreach ($p['classes'] as $cls => $c) {
                $rows[] = [$code, $p['name'], $cls, $c['loans'], $c['outstanding'], $c['provisions']];
            }
            $rows[] = [$code, $p['name'], 'NPL RATIO % (>90 DPD)', '', $p['npl_ratio_pct'], $p['provisions_total']];
        }
        Export::xlsx('fncrb-supervisory-' . date('Ymd') . '.xlsx', 'Supervisory', [
            'Institution', 'Name', 'COBACClass', 'Loans', 'OutstandingXAF', 'ProvisionsXAF',
        ], $rows);
    }

    public function incidentsCsv(): void
    {
        Rbac::requireAny(['incident.view.own', 'incident.view.all']);
        $isRegulator = Auth::isNational() && Rbac::can('incident.view.all');
        $sql = "SELECT i.code, b.master_ref, b.full_name, pi.incident_type, pi.instrument_ref,
                       pi.amount_xaf, pi.incident_date, pi.resolved, pi.resolved_at
                FROM payment_incidents pi
                JOIN institutions i ON i.id = pi.institution_id
                JOIN borrowers b ON b.id = pi.borrower_id";
        $params = [];
        if (!$isRegulator) { $sql .= " WHERE pi.institution_id = ?"; $params[] = Auth::institutionId(); }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(fn($r) => [
            $r['code'], $r['master_ref'], $r['full_name'], $r['incident_type'],
            $r['instrument_ref'], (int)$r['amount_xaf'], $r['incident_date'],
            $r['resolved'] ? 'RESOLVED' : 'OPEN', $r['resolved_at'] ?? '',
        ], $stmt->fetchAll());
        Export::csv('fncrb-incidents-' . date('Ymd') . '.csv', $rows, [
            'Institution', 'BorrowerRef', 'Borrower', 'IncidentType', 'Instrument',
            'AmountXAF', 'Date', 'Status', 'ResolvedAt',
        ]);
    }
}
