<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<div class="d-flex justify-content-between align-items-center">
  <h3>Borrowers</h3>
  <a class="btn btn-primary" href="<?= $e($base) ?>/borrowers/create">New borrower</a>
</div>
<p class="page-sub"><?= $national
  ? 'National registry view.'
  : 'Borrowers your institution reports on, registered, or holds an active consent for. Anyone else can only be located by an exact identifier, and their file is only disclosed through a consent-gated inquiry.' ?></p>
<form class="row g-2 my-3" method="get">
  <div class="col-md-6"><input class="form-control" name="q" value="<?= $e($q) ?>" maxlength="80" placeholder="Search name, CNI, NIU, registry ref or cooperative ID…"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Search</button></div>
</form>

<?php if (!empty($registryMatch)): ?>
<div class="alert alert-info d-flex align-items-center gap-3 flex-wrap">
  <div><b>Registry match (exact identifier):</b> <?= $e($registryMatch['full_name']) ?> <code><?= $e($registryMatch['master_ref']) ?></code> · <?= $e($registryMatch['type']) ?></div>
  <?php if ($canInquire): ?><a class="btn btn-sm btn-primary ms-auto" href="<?= $e($base) ?>/inquiry?identifier=<?= $e(rawurlencode($registryMatch['master_ref'])) ?>">Run consent-gated inquiry</a><?php endif; ?>
</div>
<?php endif; ?>

<table class="table table-striped table-sm bg-white">
  <thead><tr><th>Registry ref</th><th>Name</th><th>Type</th><th>CNI</th><th>NIU</th><th>Coop ID</th><th>Region</th><th></th></tr></thead>
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
      <td class="text-end" style="white-space:nowrap;">
        <?php if ($canInquire): ?><a class="btn btn-sm" href="<?= $e($base) ?>/inquiry?identifier=<?= $e(rawurlencode($b['master_ref'])) ?>">Inquiry</a><?php endif; ?>
        <?php if ($canReport): ?><a class="btn btn-sm" href="<?= $e($base) ?>/borrowers/report?ref=<?= $e(rawurlencode($b['master_ref'])) ?>">Consumer file</a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$borrowers): ?>
    <tr><td colspan="8" class="text-muted">No borrowers found.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>
