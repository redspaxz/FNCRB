<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>Collateral — OHADA Sûretés</h3>

<?php if ($doublePledges): ?>
<div class="alert alert-danger">
  <b>RCCM double-pledging detected:</b>
  <ul class="mb-0">
    <?php foreach ($doublePledges as $dp): ?>
      <li>RCCM ref <code><?= $e($dp['rccm_registration_no']) ?></code> — <?= (int)$dp['pledges'] ?> active pledges by <?= $e($dp['institution_codes']) ?> (collateral ids: <?= $e($dp['collateral_ids']) ?>)</li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<details class="card p-3 mb-3">
  <summary><b>Register a security interest</b></summary>
  <form method="post" action="<?= $e($base) ?>/collateral" class="row g-2 mt-2">
    <?= App\Core\Csrf::field() ?>
    <div class="col-md-3"><label class="form-label">Loan contract ref *</label><input name="contract_ref" class="form-control" maxlength="50" required></div>
    <div class="col-md-3"><label class="form-label">Type *</label>
      <select name="collateral_type" class="form-select" required><?php foreach ($types as $t): ?><option><?= $e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Description *</label><input name="description" class="form-control" maxlength="255" required></div>
    <div class="col-md-3"><label class="form-label">Estimated value (XAF)</label><input type="number" min="0" name="estimated_value_xaf" class="form-control" value="0"></div>
    <div class="col-md-3"><label class="form-label">RCCM registration no.</label><input name="rccm_registration_no" class="form-control" maxlength="50"></div>
    <div class="col-md-3"><label class="form-label">RCCM registered on</label><input type="date" name="rccm_registered_at" class="form-control"></div>
    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100">Register</button></div>
  </form>
  <p class="text-muted small mb-0 mt-2">An RCCM reference already registered as collateral (by any institution) is refused.</p>
</details>
<?php endif; ?>

<table class="table table-striped table-sm bg-white">
  <thead><tr><th>Institution</th><th>Loan</th><th>Borrower</th><th>Type</th><th>Description</th><th>Est. value (XAF)</th><th>RCCM ref</th><th>Registered</th><th>Status</th><th></th></tr></thead>
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
      <td class="text-end" style="white-space:nowrap;">
        <?php if ($canManage && $c['status'] === 'REGISTERED' && (int)$c['institution_id'] === (int)App\Core\Auth::institutionId()): ?>
          <?php foreach (['RELEASED' => 'Release', 'FORECLOSED' => 'Foreclose'] as $st => $label): ?>
          <form method="post" action="<?= $e($base) ?>/collateral/status" class="d-inline" data-confirm="<?= $label ?> this security interest?">
            <?= App\Core\Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>">
            <button class="btn btn-sm"><?= $label ?></button>
          </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$collateral): ?><tr><td colspan="10" class="text-muted">No collateral registered.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>
<p class="text-muted small">Security interests are registered per the OHADA Uniform Act (nantissement, vehicle mortgages, pledges) and cross-referenced with the RCCM to prevent double-pledging.</p>
