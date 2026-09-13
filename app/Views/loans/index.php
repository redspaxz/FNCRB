<?php $e = $data['e']; ?>
<h3>Loan Portfolio</h3>
<?php if ($isRegulator): ?><div class="alert alert-info py-2">National view — all reporting institutions.</div><?php endif; ?>
<div class="mb-2 d-flex gap-2"><a class="btn btn-primary btn-sm" href="<?= App\Core\Rbac::baseUrl() ?>/loans/create">Submit loan record</a><a class="btn btn-sm" href="<?= App\Core\Rbac::baseUrl() ?>/reports/loans.csv">Export CSV</a><a class="btn btn-sm" href="<?= App\Core\Rbac::baseUrl() ?>/reports/loans.xlsx">Export XLSX</a></div>
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
