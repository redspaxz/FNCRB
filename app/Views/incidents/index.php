<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h3>Payment Incidents <small class="text-muted fs-6">Centrale des Incidents de Paiement (CNEF/CIP)</small></h3><a class="btn btn-sm" href="<?= $e($base) ?>/reports/incidents.csv">Export CSV</a></div>
<?php if (!empty($typeFilter) || !empty($state)): ?>
<div class="alert alert-info py-2 d-flex align-items-center gap-2">
  <b>Filter:</b> <?php if ($typeFilter): ?><span class="badge bg-secondary"><?= $e($typeFilter) ?></span><?php endif; ?>
  <?php if ($state): ?><span class="badge bg-secondary"><?= $e($state) ?></span><?php endif; ?>
  <a class="btn btn-sm ms-auto" href="<?= $e($base) ?>/incidents">Clear</a>
</div>
<?php endif; ?>
<?php if ($isRegulator): ?><div class="alert alert-info py-2">National view.</div><?php endif; ?>

<?php if ($canReport): ?>
<details class="card p-3 mb-3">
  <summary><b>Report a payment incident</b></summary>
  <form method="post" action="<?= $e($base) ?>/incidents" class="row g-2 mt-2">
    <?= App\Core\Csrf::field() ?>
    <div class="col-md-3"><label class="form-label">Borrower identifier *</label><input name="borrower_identifier" class="form-control" maxlength="40" required placeholder="CNI, NIU, coop ID or FNB… ref"></div>
    <div class="col-md-3"><label class="form-label">Type *</label>
      <select name="incident_type" class="form-select" required><?php foreach ($types as $t): ?><option><?= $e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Instrument ref *</label><input name="instrument_ref" class="form-control" maxlength="60" required></div>
    <div class="col-md-2"><label class="form-label">Amount (XAF) *</label><input type="number" min="1" name="amount_xaf" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label">Date *</label><input type="date" name="incident_date" class="form-control" max="<?= date('Y-m-d') ?>" required></div>
    <div class="col-12"><button class="btn btn-primary">Report to CIP</button></div>
  </form>
</details>
<?php endif; ?>

<div class="mb-2 small">Show:
  <a href="<?= $e($base) ?>/incidents">all</a> · <a href="<?= $e($base) ?>/incidents?state=open">open</a> · <a href="<?= $e($base) ?>/incidents?state=resolved">resolved</a>
</div>
<table class="table table-striped table-sm bg-white">
  <thead><tr><th>Institution</th><th>Borrower</th><th>Type</th><th>Instrument</th><th>Amount (XAF)</th><th>Date</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($incidents as $pi): ?>
    <tr class="<?= $pi['resolved'] ? '' : 'table-danger' ?>">
      <td><?= $e($pi['inst_code']) ?></td>
      <td><?= $e($pi['full_name']) ?> <small class="text-muted">[<?= $e($pi['master_ref']) ?>]</small></td>
      <td><?= $e($pi['incident_type']) ?></td>
      <td><code><?= $e($pi['instrument_ref']) ?></code></td>
      <td><?= number_format((int)$pi['amount_xaf']) ?></td>
      <td><?= $e($pi['incident_date']) ?></td>
      <td><?= $pi['resolved'] ? 'Resolved ' . $e($pi['resolved_at'] ?? '') . ($pi['resolution_note'] ? '<br><small class="text-muted">' . $e($pi['resolution_note']) . '</small>' : '') : '<b>Open</b>' ?></td>
      <td class="text-end">
        <?php if ($canReport && !$pi['resolved'] && (int)$pi['institution_id'] === (int)$myInst): ?>
        <form method="post" action="<?= $e($base) ?>/incidents/resolve" class="d-flex gap-1" data-confirm="Mark this incident as regularized?">
          <?= App\Core\Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$pi['id'] ?>">
          <input type="date" name="resolved_at" class="form-control form-control-sm" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" style="width:140px">
          <input name="note" class="form-control form-control-sm" placeholder="Regularization note" required minlength="3" maxlength="255" style="width:170px">
          <button class="btn btn-sm">Resolve</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$incidents): ?><tr><td colspan="8" class="text-muted">No incidents.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>
