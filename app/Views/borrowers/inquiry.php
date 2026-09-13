<?php $e = $data['e'];
/** @var array|null $report */
function xaf($n){ return number_format((int)$n) . ' XAF'; }
?>
<h3>Credit Inquiry <small class="text-muted fs-6">consent-gated · cross-institutional</small></h3>

<div class="alert alert-warning py-2">
  <b>Regulatory notice:</b> an inquiry without recorded borrower consent exposes your institution to COBAC sanctions
  and national data-penalty liability. Consent reference is mandatory below.
</div>

<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= App\Core\Rbac::baseUrl() ?>/inquiry" class="card p-4 bg-white mb-4">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label">Borrower *</label>
      <select name="borrower_id" class="form-select" required>
        <option value="">— select —</option>
        <?php foreach ($borrowers as $b): ?>
          <option value="<?= (int)$b['id'] ?>"><?= $e($b['full_name']) ?> [<?= $e($b['master_ref']) ?>]<?= $b['cni_number'] ? ' · CNI ' . $e($b['cni_number']) : '' ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-2"><label class="form-label">Consent type *</label>
      <select name="consent_type" class="form-select" required><option>DIGITAL</option><option>PHYSICAL</option></select></div>
    <div class="col-md-3"><label class="form-label">Consent reference *</label>
      <input name="consent_ref" class="form-control" maxlength="80" placeholder="e.g. CS-2026-0451 (signed doc ref)" required></div>
    <div class="col-md-3"><label class="form-label">Declared monthly income (XAF)</label>
      <input type="number" name="declared_monthly_income" class="form-control" min="0" value="0"></div>
    <div class="col-12"><label class="form-label">Purpose</label>
      <input name="purpose" class="form-control" maxlength="255" placeholder="Credit underwriting / member loan review / project finance…"></div>
  </div>
  <button class="btn btn-primary mt-3">Run inquiry</button>
</form>

<?php if ($report): ?>
<hr>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <h4>Credit report <small class="text-muted fs-6">generated <?= $e($report['generated_at']) ?></small></h4>
  <button class="btn btn-sm no-print" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="row g-3 my-1">
  <div class="col-md-3"><div class="card p-3 text-center bg-white border">
    <small>Systemic score</small>
    <h1 class="grade-<?= $e($report['score']['risk_grade']) ?>"><?= (int)$report['score']['score'] ?></h1>
    <span class="badge bg-secondary">Grade <?= $e($report['score']['risk_grade']) ?></span>
  </div></div>
  <div class="col-md-3"><div class="card p-3 bg-white border"><small>Institutions exposed</small><h3><?= (int)$report['exposure']['institution_count'] ?></h3></div></div>
  <div class="col-md-3"><div class="card p-3 bg-white border"><small>Total outstanding</small><h5><?= xaf($report['exposure']['total_outstanding_xaf']) ?></h5></div></div>
  <div class="col-md-3"><div class="card p-3 bg-white border"><small>Monthly obligations<?= $report['score']['dti_pct'] !== null ? ' · DTI ' . $e($report['score']['dti_pct']) . '%' : '' ?></small><h5><?= xaf($report['exposure']['total_monthly_payment_xaf']) ?></h5></div></div>
</div>

<h5 class="mt-4">Cross-institutional loan detail</h5>
<table class="table table-sm table-striped bg-white">
  <thead><tr><th>Institution</th><th>Category</th><th>Contract</th><th>Type</th><th>Outstanding</th><th>Days past due</th><th>COBAC class</th><th>Provision</th></tr></thead>
  <tbody>
  <?php foreach ($report['exposure']['loans'] as $l): ?>
    <tr>
      <td><?= $e($l['institution_code']) ?> — <?= $e($l['institution_name']) ?></td>
      <td><?= $e($l['institution_category']) ?></td>
      <td><code><?= $e($l['contract_ref']) ?></code></td>
      <td><?= $e($l['loan_type']) ?></td>
      <td><?= xaf($l['outstanding_xaf']) ?></td>
      <td><?= (int)$l['days_past_due'] ?></td>
      <td class="cls-<?= $e($l['cobac_class']) ?>"><?= $e($l['cobac_class']) ?></td>
      <td><?= xaf($l['provision_xaf']) ?></td>
    </tr>
  <?php endforeach; if (!$report['exposure']['loans']): ?>
    <tr><td colspan="8" class="text-muted">No active registry loans.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<div class="row g-4">
  <div class="col-md-6">
    <h5>Payment incidents (CNEF / CIP)</h5>
    <table class="table table-sm table-striped bg-white">
      <thead><tr><th>Type</th><th>Institution</th><th>Instrument</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($report['incidents'] as $pi): ?>
        <tr class="<?= $pi['resolved'] ? '' : 'table-danger' ?>">
          <td><?= $e($pi['incident_type']) ?></td><td><?= $e($pi['institution_code']) ?></td>
          <td><?= $e($pi['instrument_ref']) ?></td><td><?= xaf($pi['amount_xaf']) ?></td>
          <td><?= $e($pi['incident_date']) ?></td>
          <td><?= $pi['resolved'] ? 'Resolved' : '<b>Open</b>' ?></td>
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
            <td><?= xaf($c['estimated_value_xaf']) ?></td><td><?= $e($c['institution_code']) ?></td></tr>
      <?php endforeach; if (!$report['collateral']): ?>
        <tr><td colspan="5" class="text-muted">No registered collateral.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php if ($report['guarantees']): ?>
    <h5>Guarantor liabilities (cautionnement)</h5>
    <ul class="small">
      <?php foreach ($report['guarantees'] as $g): ?>
        <li><?= xaf($g['guarantee_xaf']) ?> guaranteed for loan <code><?= $e($g['contract_ref']) ?></code> at <?= $e($g['institution_code']) ?><?= $g['solidarity_group'] ? ' (group ' . $e($g['solidarity_group']) . ')' : '' ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<p class="text-muted small mt-2">Score factors: <?= $e(json_encode($report['score']['factors'])) ?></p>
<?php endif; ?>
