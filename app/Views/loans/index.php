<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>Loan Portfolio</h3>
<?php if (!empty($filters) && array_filter($filters)): ?>
<div class="alert alert-info py-2 d-flex align-items-center gap-2">
  <b>Filter:</b>
  <?php foreach (array_filter($filters) as $k => $v): ?>
    <span class="badge bg-secondary"><?= $e(ucfirst($k)) ?> = <?= $e((string)$v) ?></span>
  <?php endforeach; ?>
  <a class="btn btn-sm ms-auto" href="<?= $e($base) ?>/loans">Clear</a>
</div>
<?php endif; ?>
<?php if ($isRegulator): ?><div class="alert alert-info py-2">National view — all reporting institutions.</div><?php endif; ?>
<div class="mb-2 d-flex gap-2 flex-wrap align-items-center">
  <?php if ($canReport): ?><a class="btn btn-primary btn-sm" href="<?= $e($base) ?>/loans/create">Submit / update loan record</a><?php endif; ?>
  <a class="btn btn-sm" href="<?= $e($base) ?>/reports/loans.csv">Export CSV</a>
  <a class="btn btn-sm" href="<?= $e($base) ?>/reports/loans.xlsx">Export XLSX</a>
  <span class="ms-auto small">Status:
    <?php foreach (['' => 'All', 'ACTIVE' => 'Active', 'RESTRUCTURED' => 'Restructured', 'SETTLED' => 'Settled', 'WRITTEN_OFF' => 'Written off'] as $k => $label): ?>
      <a href="<?= $e($base) ?>/loans<?= $k ? '?status=' . $k : '' ?>"<?= ($filters['status'] ?? '') === $k ? ' class="fw-bold"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?></span>
</div>
<table class="table table-striped table-sm bg-white">
  <thead><tr><th>Institution</th><th>Borrower</th><th>Contract</th><th>Type</th><th>Outstanding (XAF)</th><th>Monthly</th><th>DPD</th><th>COBAC class</th><th>Provision</th><th>Status</th><th>Reported</th></tr></thead>
  <tbody>
  <?php foreach ($loans as $l): ?>
    <tr>
      <td><?= $e($l['inst_code']) ?></td>
      <td><?= $e($l['full_name']) ?> <small class="text-muted">[<?= $e($l['master_ref']) ?>]</small></td>
      <td><code><?= $e($l['contract_ref']) ?></code></td>
      <td><?= $e($l['loan_type']) ?></td>
      <td><?= number_format((int)$l['outstanding_xaf']) ?></td>
      <td><?= number_format((int)$l['monthly_payment_xaf']) ?></td>
      <td><?= (int)$l['days_past_due'] ?></td>
      <td class="cls-<?= $e($l['cobac_class']) ?>"><?= $e($l['cobac_class']) ?></td>
      <td><?= number_format((int)$l['provision_xaf']) ?></td>
      <td><?= $e($l['status']) ?></td>
      <td><?= $e($l['reported_at']) ?></td>
    </tr>
  <?php endforeach; if (!$loans): ?><tr><td colspan="11" class="text-muted">No loans.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>
