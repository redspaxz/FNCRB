<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl();
/** @var array|null $report */
$xaf = fn($n) => number_format((int)$n) . ' XAF';
$o = fn($k, $d = '') => $e(is_scalar($old[$k] ?? null) ? $old[$k] : $d);
?>
<h3>Credit Inquiry <small class="text-muted fs-6">consent-gated · cross-institutional</small></h3>

<div class="alert alert-warning py-2 no-print">
  <b>Regulatory notice:</b> an inquiry without a borrower-signed consent exposes your institution to COBAC sanctions
  and national data-protection penalties (Law 2010/012). The consent, its evidence and this inquiry are recorded and audited.
</div>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= $e($base) ?>/inquiry" enctype="multipart/form-data" class="card p-4 bg-white mb-4 no-print">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label" for="inq-id">Borrower identifier *</label>
      <input id="inq-id" name="identifier" class="form-control" maxlength="40" required value="<?= $e($identifier) ?>"
             placeholder="CNI, NIU, cooperative ID or FNB… ref"></div>
    <div class="col-md-2"><label class="form-label">Consent type *</label>
      <select name="consent_type" class="form-select" required>
        <?php foreach (['DIGITAL', 'PHYSICAL'] as $v): ?><option<?= ($old['consent_type'] ?? '') === $v ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label">Consent reference *</label>
      <input name="consent_ref" class="form-control" maxlength="80" required value="<?= $o('consent_ref') ?>" placeholder="signed form no. / e-signature id"></div>
    <div class="col-md-3"><label class="form-label">Signed on *</label>
      <input type="date" name="consent_signed_at" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= $o('consent_signed_at', date('Y-m-d')) ?>"></div>
    <div class="col-md-6"><label class="form-label">Signed consent form (PDF/JPG/PNG, ≤ 5 MB) — required for PHYSICAL</label>
      <input type="file" name="consent_evidence" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
    <div class="col-md-3"><label class="form-label">Declared monthly income (XAF)</label>
      <input type="number" name="declared_monthly_income" class="form-control" min="0" value="<?= $o('declared_monthly_income', '0') ?>"></div>
    <div class="col-md-9"><label class="form-label">Purpose *</label>
      <input name="purpose" class="form-control" maxlength="255" required value="<?= $o('purpose') ?>" placeholder="Credit underwriting / member loan review / project finance…"></div>
    <div class="col-12"><div class="form-check">
      <input class="form-check-input" type="checkbox" name="consent_attested" id="attest" value="1" required>
      <label class="form-check-label" for="attest" style="font-size:12.5px;">I attest that the borrower personally signed the consent referenced above for this purpose, and that the original is retained by my institution.</label>
    </div></div>
  </div>
  <button class="btn btn-primary mt-3">Run inquiry</button>
</form>

<?php if ($report): $s = $report['score']; $h = $report['history']; ?>
<hr>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <h4>Credit report — <?= $e($borrower['full_name']) ?> <code><?= $e($borrower['master_ref']) ?></code>
    <small class="text-muted fs-6">generated <?= $e($report['generated_at']) ?> · consent <?= $e($report['consent']['consent_ref']) ?></small></h4>
  <button class="btn btn-sm no-print" data-action="print">Print / Save as PDF</button>
</div>

<div class="row g-3 my-1">
  <div class="col-md-3"><div class="card p-3 text-center bg-white border">
    <small>Systemic score</small>
    <?php if ($s['score'] === null): ?>
      <h1>NR</h1><span class="badge bg-secondary">Not rated — thin file</span>
    <?php else: ?>
      <h1 class="grade-<?= $e($s['risk_grade']) ?>"><?= (int)$s['score'] ?></h1>
      <span class="badge bg-secondary">Grade <?= $e($s['risk_grade']) ?></span>
    <?php endif; ?>
  </div></div>
  <div class="col-md-3"><div class="card p-3 bg-white border"><small>Institutions exposed</small><h3><?= (int)$report['exposure']['institution_count'] ?></h3></div></div>
  <div class="col-md-3"><div class="card p-3 bg-white border"><small>Total outstanding</small><h5><?= $xaf($report['exposure']['total_outstanding_xaf']) ?></h5></div></div>
  <div class="col-md-3"><div class="card p-3 bg-white border"><small>Monthly obligations<?= $s['dti_pct'] !== null ? ' · DTI ' . $e($s['dti_pct']) . '%' : '' ?></small><h5><?= $xaf($report['exposure']['total_monthly_payment_xaf']) ?></h5></div></div>
</div>

<?php if (!empty($s['reasons'])): ?>
<div class="card p-3 bg-white border mb-3">
  <div class="titled">Main factors lowering the score</div>
  <ul class="small mb-0"><?php foreach ($s['reasons'] as $r): ?><li><?= $e($r) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<h5 class="mt-4">Open credit — cross-institutional detail</h5>
<table class="table table-sm table-striped bg-white">
  <thead><tr><th>Institution</th><th>Category</th><th>Contract</th><th>Type</th><th>Status</th><th>Outstanding</th><th>Days past due</th><th>COBAC class</th><th>Provision</th></tr></thead>
  <tbody>
  <?php foreach ($report['exposure']['loans'] as $l): ?>
    <tr>
      <td><?= $e($l['institution_code']) ?> — <?= $e($l['institution_name']) ?></td>
      <td><?= $e($l['institution_category']) ?></td>
      <td><code><?= $e($l['contract_ref']) ?></code></td>
      <td><?= $e($l['loan_type']) ?></td>
      <td><?= $e($l['status']) ?></td>
      <td><?= $xaf($l['outstanding_xaf']) ?></td>
      <td><?= (int)$l['days_past_due'] ?></td>
      <td class="cls-<?= $e($l['cobac_class']) ?>"><?= $e($l['cobac_class']) ?></td>
      <td><?= $xaf($l['provision_xaf']) ?></td>
    </tr>
  <?php endforeach; if (!$report['exposure']['loans']): ?>
    <tr><td colspan="9" class="text-muted">No open registry credit.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<p class="small text-muted">History: worst delinquency in 24 months <b><?= $h['worst_dpd_24m'] === null ? '—' : (int)$h['worst_dpd_24m'] . ' days' ?></b> ·
  settled credits <b><?= (int)$h['settled_count'] ?></b> · written off <b><?= (int)$h['written_off_count'] ?></b>.</p>
<?php if ($h['closed_loans']): ?>
<table class="table table-sm bg-white small">
  <thead><tr><th>Closed credit</th><th>Institution</th><th>Type</th><th>Status</th><th>Principal</th><th>Period</th></tr></thead>
  <tbody><?php foreach ($h['closed_loans'] as $c): ?>
    <tr><td><code><?= $e($c['contract_ref']) ?></code></td><td><?= $e($c['institution_code']) ?></td><td><?= $e($c['loan_type']) ?></td>
      <td><?= $e($c['status']) ?></td><td><?= $xaf($c['principal_xaf']) ?></td><td><?= $e($c['start_date']) ?> → <?= $e($c['maturity_date']) ?></td></tr>
  <?php endforeach; ?></tbody>
</table>
<?php endif; ?>

<div class="row g-4">
  <div class="col-md-6">
    <h5>Payment incidents (CNEF / CIP)</h5>
    <table class="table table-sm table-striped bg-white">
      <thead><tr><th>Type</th><th>Institution</th><th>Instrument</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($report['incidents'] as $pi): ?>
        <tr class="<?= $pi['resolved'] ? '' : 'table-danger' ?>">
          <td><?= $e($pi['incident_type']) ?></td><td><?= $e($pi['institution_code']) ?></td>
          <td><?= $e($pi['instrument_ref']) ?></td><td><?= $xaf($pi['amount_xaf']) ?></td>
          <td><?= $e($pi['incident_date']) ?></td>
          <td><?= $pi['resolved'] ? 'Resolved ' . $e($pi['resolved_at'] ?? '') : '<b>Open</b>' ?></td>
        </tr>
      <?php endforeach; if (!$report['incidents']): ?>
        <tr><td colspan="6" class="text-muted">No payment incidents recorded.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="col-md-6">
    <h5>Registered collateral (OHADA sûretés)</h5>
    <table class="table table-sm table-striped bg-white">
      <thead><tr><th>Type</th><th>Description</th><th>RCCM ref</th><th>Value</th><th>Holder</th></tr></thead>
      <tbody>
      <?php foreach ($report['collateral'] as $c): ?>
        <tr><td><?= $e($c['collateral_type']) ?></td><td><?= $e($c['description']) ?></td>
            <td><code><?= $e($c['rccm_registration_no'] ?? '—') ?></code></td>
            <td><?= $xaf($c['estimated_value_xaf']) ?></td><td><?= $e($c['institution_code']) ?></td></tr>
      <?php endforeach; if (!$report['collateral']): ?>
        <tr><td colspan="5" class="text-muted">No registered collateral.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php if ($report['guarantees']): ?>
    <h5>Guarantor liabilities (cautionnement)</h5>
    <ul class="small">
      <?php foreach ($report['guarantees'] as $g): ?>
        <li><?= $xaf($g['guarantee_xaf']) ?> guaranteed for loan <code><?= $e($g['contract_ref']) ?></code> at <?= $e($g['institution_code']) ?><?= $g['solidarity_group'] ? ' (group ' . $e($g['solidarity_group']) . ')' : '' ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<p class="text-muted small mt-2">Score factors: <?= $e(json_encode($s['factors'])) ?></p>
<?php endif; ?>
