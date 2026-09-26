<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); $xaf = fn($n) => number_format((int)$n); ?>
<h3>Consumer credit file <small class="text-muted fs-6">subject access · dispute investigation</small></h3>
<form class="row g-2 my-3 no-print" method="get" action="<?= $e($base) ?>/borrowers/report">
  <div class="col-md-5"><input class="form-control" name="ref" value="<?= $e($ref) ?>" maxlength="40" placeholder="Registry ref, CNI, NIU or cooperative ID" required></div>
  <div class="col-auto"><button class="btn btn-primary">Open file</button></div>
</form>
<?php if (!empty($notFound)): ?><div class="alert alert-warning">No borrower matches this identifier.</div><?php endif; ?>

<?php if ($file): $b = $file['borrower']; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <h4><?= $e($b['full_name']) ?> <code><?= $e($b['master_ref']) ?></code></h4>
  <button class="btn btn-sm no-print" data-action="print">Print / Save as PDF</button>
</div>
<p class="small">Type <?= $e($b['type']) ?> · CNI <?= $e($b['cni_number'] ?? '—') ?> · NIU <?= $e($b['niu'] ?? '—') ?> · Coop <?= $e($b['coop_member_id'] ?? '—') ?>
  · Born <?= $e($b['date_of_birth'] ?? '—') ?> · Region <?= $e($b['region'] ?? '—') ?> · Phone <?= $e($b['phone'] ?? '—') ?></p>

<h5 class="mt-3">Credit accounts</h5>
<table class="table table-sm table-striped bg-white">
  <thead><tr><th>Institution</th><th>Contract</th><th>Type</th><th>Status</th><th>Principal</th><th>Outstanding</th><th>DPD</th><th>Class</th><th>Reported</th></tr></thead>
  <tbody><?php foreach ($file['loans'] as $l): ?>
    <tr><td><?= $e($l['inst_code']) ?></td><td><code><?= $e($l['contract_ref']) ?></code></td><td><?= $e($l['loan_type']) ?></td>
      <td><?= $e($l['status']) ?></td><td><?= $xaf($l['principal_xaf']) ?></td><td><?= $xaf($l['outstanding_xaf']) ?></td>
      <td><?= (int)$l['days_past_due'] ?></td><td class="cls-<?= $e($l['cobac_class']) ?>"><?= $e($l['cobac_class']) ?></td><td><?= $e($l['reported_at']) ?></td></tr>
  <?php endforeach; if (!$file['loans']): ?><tr><td colspan="9" class="text-muted">None.</td></tr><?php endif; ?></tbody>
</table>

<?php if ($file['history']): ?>
<h5>Reporting history</h5>
<table class="table table-sm bg-white small">
  <thead><tr><th>Contract</th><th>Period</th><th>Outstanding</th><th>DPD</th><th>Class</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($file['history'] as $h): ?>
    <tr><td><code><?= $e($h['contract_ref']) ?></code></td><td><?= $e($h['reported_at']) ?></td><td><?= $xaf($h['outstanding_xaf']) ?></td>
      <td><?= (int)$h['days_past_due'] ?></td><td><?= $e($h['cobac_class']) ?></td><td><?= $e($h['status']) ?></td></tr>
  <?php endforeach; ?></tbody>
</table>
<?php endif; ?>

<div class="row g-4">
  <div class="col-md-6">
    <h5>Payment incidents</h5>
    <table class="table table-sm bg-white small">
      <thead><tr><th>Institution</th><th>Type</th><th>Instrument</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($file['incidents'] as $pi): ?>
        <tr><td><?= $e($pi['inst_code']) ?></td><td><?= $e($pi['incident_type']) ?></td><td><?= $e($pi['instrument_ref']) ?></td>
          <td><?= $xaf($pi['amount_xaf']) ?></td><td><?= $e($pi['incident_date']) ?></td><td><?= $pi['resolved'] ? 'Resolved ' . $e($pi['resolved_at']) : 'Open' ?></td></tr>
      <?php endforeach; if (!$file['incidents']): ?><tr><td colspan="6" class="text-muted">None.</td></tr><?php endif; ?></tbody>
    </table>
  </div>
  <div class="col-md-6">
    <h5>Collateral &amp; guarantees</h5>
    <ul class="small">
      <?php foreach ($file['collateral'] as $c): ?><li><?= $e($c['collateral_type']) ?> — <?= $e($c['description']) ?> (<?= $e($c['inst_code']) ?>, <?= $e($c['status']) ?>)</li><?php endforeach; ?>
      <?php foreach ($file['guarantees'] as $g): ?><li>Guarantor for <code><?= $e($g['contract_ref']) ?></code> at <?= $e($g['institution_code']) ?>: <?= $xaf($g['guarantee_xaf']) ?> XAF</li><?php endforeach; ?>
      <?php if (!$file['collateral'] && !$file['guarantees']): ?><li class="text-muted">None.</li><?php endif; ?>
    </ul>
  </div>
</div>

<h5>Who consulted this file</h5>
<table class="table table-sm bg-white small">
  <thead><tr><th>Date</th><th>Institution</th><th>Channel</th><th>Purpose</th><th>Consent</th></tr></thead>
  <tbody><?php foreach ($file['inquiries'] as $q): ?>
    <tr><td><?= $e($q['created_at']) ?></td><td><?= $e($q['inst_code']) ?></td><td><?= $e($q['channel']) ?></td><td><?= $e($q['purpose']) ?></td>
      <td><?= $e(($q['consent_type'] ?? '') . ' ' . ($q['consent_ref'] ?? '—')) ?></td></tr>
  <?php endforeach; if (!$file['inquiries']): ?><tr><td colspan="5" class="text-muted">No inquiries.</td></tr><?php endif; ?></tbody>
</table>

<h5>Consents on record</h5>
<ul class="small">
  <?php foreach ($file['consents'] as $c): ?>
    <li><?= $e($c['inst_code']) ?> · <?= $e($c['consent_type']) ?> <code><?= $e($c['consent_ref']) ?></code> signed <?= $e($c['signed_at'] ?? '—') ?>,
      valid to <?= $e($c['expires_at']) ?><?= $c['revoked_at'] ? ' · <b>revoked ' . $e($c['revoked_at']) . '</b>' : '' ?></li>
  <?php endforeach; if (!$file['consents']): ?><li class="text-muted">None.</li><?php endif; ?>
</ul>

<h5>Disputes</h5>
<ul class="small">
  <?php foreach ($file['disputes'] as $d): ?><li><code><?= $e($d['reference']) ?></code> <?= $e($d['dispute_type']) ?> — <?= $e($d['status']) ?> (filed <?= $e($d['created_at']) ?>)</li><?php endforeach; ?>
  <?php if (!$file['disputes']): ?><li class="text-muted">None.</li><?php endif; ?>
</ul>
<p class="text-muted small">Access to this file is recorded in the audit trail.</p>
<?php endif; ?>
