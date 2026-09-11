<?php $e = $data['e']; ?>
<div class="d-flex justify-content-between align-items-center">
  <h3>Borrowers</h3>
  <a class="btn btn-primary" href="<?= App\Core\Rbac::baseUrl() ?>/borrowers/create">New borrower</a>
</div>
<form class="row g-2 my-3" method="get">
  <div class="col-md-6"><input class="form-control" name="q" value="<?= $e($q) ?>" placeholder="Search name, CNI, NIU, master ref or cooperative ID…"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Search</button></div>
</form>
<table class="table table-striped table-sm bg-white">
  <thead><tr><th>Master ref</th><th>Name</th><th>Type</th><th>CNI</th><th>NIU</th><th>Coop ID</th><th>Region</th></tr></thead>
  <tbody>
  <?php foreach ($borrowers as $b): ?>
    <tr>
      <td><code><?= $e($b['master_ref']) ?></code></td>
      <td><?= $e($b['full_name']) ?></td>
      <td><?= $e($b['type']) ?></td>
      <td><?= $e($b['cni_number'] ?? '—') ?></td>
      <td><?= $e($b['niu'] ?? '—') ?></td>
      <td><?= $e($b['coop_member_id'] ?? '—') ?></td>
      <td><?= $e($b['region'] ?? '—') ?></td>
    </tr>
  <?php endforeach; if (!$borrowers): ?>
    <tr><td colspan="7" class="text-muted">No borrowers found.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
