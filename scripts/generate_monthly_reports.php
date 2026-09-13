<?php
declare(strict_types=1);
/**
 * CLI: monthly per-institution report generator.
 * Writes audited XLSX portfolio returns into storage/reports/ for pickup or
 * e-mail distribution (cron: 5 0 1 * * php scripts/generate_monthly_reports.php).
 *
 * Usage: php scripts/generate_monthly_reports.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$dir = dirname(__DIR__) . '/storage/reports';
if (!is_dir($dir)) mkdir($dir, 0770, true);

$pdo = \App\Core\Database::pdo();
$insts = $pdo->query(
    "SELECT id, code FROM institutions WHERE status='ACTIVE' AND category != 'REGULATOR'"
)->fetchAll();

$period = date('Ym');
$written = [];
foreach ($insts as $inst) {
    $stmt = $pdo->prepare(
        "SELECT b.master_ref, b.full_name, l.contract_ref, l.loan_type, l.principal_xaf,
                l.outstanding_xaf, l.days_past_due, l.cobac_class, l.provision_xaf, l.status, l.reported_at
         FROM loans l JOIN borrowers b ON b.id = l.borrower_id
         WHERE l.institution_id = ? ORDER BY l.reported_at DESC"
    );
    $stmt->execute([$inst['id']]);
    $rows = array_map(fn($l) => [
        $l['master_ref'], $l['full_name'], $l['contract_ref'], $l['loan_type'],
        (int)$l['principal_xaf'], (int)$l['outstanding_xaf'], (int)$l['days_past_due'],
        $l['cobac_class'], (int)$l['provision_xaf'], $l['status'], $l['reported_at'],
    ], $stmt->fetchAll());

    $file = "fncrb-return-{$inst['code']}-{$period}.xlsx";
    $bytes = \App\Core\Export::buildXlsx('MonthlyReturn', [
        'BorrowerRef', 'Borrower', 'Contract', 'LoanType', 'PrincipalXAF',
        'OutstandingXAF', 'DaysPastDue', 'COBACClass', 'ProvisionXAF', 'Status', 'ReportedAt',
    ], $rows);
    file_put_contents("$dir/$file", $bytes);
    $written[] = ['institution' => $inst['code'], 'file' => $file, 'rows' => count($rows), 'sha256' => hash('sha256', $bytes)];
}

\App\Core\Audit::log('MONTHLY_REPORTS_GENERATED', null, ['period' => $period, 'files' => $written]);
echo json_encode(['period' => $period, 'files' => $written], JSON_PRETTY_PRINT), "\n";
