<?php $e = $data['e']; ?>
<h3>Collateral — OHADA Sûretés</h3>

<?php if ($doublePledges): ?>
<div class="alert alert-danger">
  <b>RCCM double-pledging detected:</b>
  <ul class="mb-0">
    <?php foreach ($doublePledges as $dp): ?>
      <li>RCCM ref <code><?= $e($dp['rccm_registration_no']) ?></code> pledged by <?= $e($dp['institution_codes']) ?> (collateral ids: <?= $e($dp['collateral_ids']) ?>)</li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<table class="table table-striped table-sm bg-white">
  <thead><tr><th>Institution</th><th>Loan</th><th>Borrower</th><th>Type</th><th>Description</th><th>Est. value (XAF)</th><th>RCCM ref</th><th>Registered</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($collateral as $c): ?>
    <tr>
      <td><?= $e($c['inst_code']) ?></td>
      <td><code><?= $e($c['contract_ref']) ?></code></td>
      <td><?= $e($c['full_name']) ?></td>
      <td><?= $e($c['collateral_type']) ?></td>
      <td><?= $e($c['description']) ?></td>
      <td><?= number_format((int)$c['estimated_value_xaf']) ?></td>
      <td><code><?= $e($c['rccm_registration_no'] ?? '—') ?></code></td>
      <td><?= $e($c['rccm_registered_at'] ?? '—') ?></td>
      <td><?= $e($c['status']) ?></td>
    </tr>
  <?php endforeach; if (!$collateral): ?><tr><td colspan="9" class="text-muted">No collateral registered.</td></tr><?php endif; ?>
  </tbody>
</table>
<p class="text-muted small">Security interests are registered per the OHADA Uniform Act (nantissement, vehicle mortgages, pledges) and cross-referenced with the RCCM to prevent double-pledging. Registration of the same RCCM reference by two institutions is blocked at entry and flagged above.</p>
