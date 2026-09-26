<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>Inquiry Log <small class="text-muted fs-6"><?= $national ? 'national' : 'your institution' ?></small></h3>
<p class="page-sub">Every consultation of a credit file, with the consent it relied on.</p>
<form class="row g-2 my-3" method="get">
  <div class="col-md-3"><input class="form-control" name="borrower" value="<?= $e($filters['borrower'] ?? '') ?>" placeholder="Registry ref (FNB…)"></div>
  <div class="col-md-2"><select name="channel" class="form-select"><option value="">All channels</option>
    <?php foreach (['WEB', 'API'] as $c): ?><option<?= ($filters['channel'] ?? '') === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><input type="date" class="form-control" name="from" value="<?= $e($filters['from'] ?? '') ?>" title="From"></div>
  <div class="col-md-2"><input type="date" class="form-control" name="to" value="<?= $e($filters['to'] ?? '') ?>" title="To"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button> <a class="btn" href="<?= $e($base) ?>/inquiries">Clear</a></div>
</form>
<table class="table table-striped table-sm bg-white">
  <thead><tr><th>#</th><th>Date</th><?php if ($national): ?><th>Institution</th><?php endif; ?><th>User</th><th>Borrower</th><th>Channel</th><th>Purpose</th><th>Consent</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= $e($r['created_at']) ?></td>
      <?php if ($national): ?><td><?= $e($r['inst_code']) ?></td><?php endif; ?>
      <td><?= $e($r['user_name'] ?? 'API client') ?></td>
      <td><?= $e($r['full_name']) ?> <small class="text-muted">[<?= $e($r['master_ref']) ?>]</small></td>
      <td><?= $e($r['channel']) ?></td>
      <td><small><?= $e($r['purpose'] ?? '') ?></small></td>
      <td><small><?= $e(($r['consent_type'] ?? '') . ' ' . ($r['consent_ref'] ?? '—')) ?><?= $r['revoked_at'] ? ' <span class="badge bg-secondary">revoked</span>' : '' ?></small></td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-muted">No inquiries.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>
