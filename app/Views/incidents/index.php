<?php $e = $data['e']; ?>
<h3>Payment Incidents <small class="text-muted fs-6">Centrale des Incidents de Paiement (CNEF/CIP)</small></h3>
<?php if ($isRegulator): ?><div class="alert alert-info py-2">National view.</div><?php endif; ?>
<table class="table table-striped table-sm bg-white">
  <thead><tr><th>Institution</th><th>Borrower</th><th>Type</th><th>Instrument</th><th>Amount (XAF)</th><th>Date</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($incidents as $pi): ?>
    <tr class="<?= $pi['resolved'] ? '' : 'table-danger' ?>">
      <td><?= $e($pi['inst_code']) ?></td>
      <td><?= $e($pi['full_name']) ?> <small class="text-muted">[<?= $e($pi['master_ref']) ?>]</small></td>
      <td><?= $e($pi['incident_type']) ?></td>
      <td><code><?= $e($pi['instrument_ref']) ?></code></td>
      <td><?= number_format((int)$pi['amount_xaf']) ?></td>
      <td><?= $e($pi['incident_date']) ?></td>
      <td><?= $pi['resolved'] ? 'Resolved' : '<b>Open</b>' ?></td>
    </tr>
  <?php endforeach; if (!$incidents): ?><tr><td colspan="7" class="text-muted">No incidents.</td></tr><?php endif; ?>
  </tbody>
</table>
