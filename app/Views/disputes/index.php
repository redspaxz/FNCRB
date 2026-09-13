<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <h3>Consumer Disputes</h3>
  <a class="btn btn-primary btn-sm" href="<?= $base ?>/disputes/create">File dispute</a>
</div>
<p class="page-sub">Statutory workflow under national privacy/data-protection law — 30-day response window (Law 2010/012).</p>

<?php if (!empty($statusFilter)): ?>
<div class="alert alert-info py-2 d-flex align-items-center gap-2">
  <b>Filter:</b> <span class="badge bg-secondary"><?= $e($statusFilter) ?></span>
  <a class="btn btn-sm ms-auto" href="<?= $base ?>/disputes">Clear</a>
</div>
<?php endif; ?>

<table class="table table-striped mt-2" id="dispute-table">
  <thead><tr><th>Reference</th><th>Borrower</th><th>Type</th><th>Against</th><th>Filed by</th>
    <th>SLA due</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($disputes as $d): ?>
    <?php $overSla = in_array($d['status'], ['OPEN','UNDER_REVIEW'], true) && strtotime($d['sla_due_at']) < time(); ?>
    <tr data-dispute-row="<?= (int)$d['id'] ?>" class="<?= $overSla ? 'table-danger' : '' ?>">
      <td><code><?= $e($d['reference']) ?></code></td>
      <td><?= $e($d['full_name']) ?> <small class="text-muted">[<?= $e($d['master_ref']) ?>]</small></td>
      <td><small><?= $e(str_replace('_', ' ', $d['dispute_type'])) ?></small></td>
      <td><?= $e($d['against'] ?? '—') ?></td>
      <td><?= $e($d['filed_by']) ?></td>
      <td class="<?= $overSla ? 'text-danger fw-bold' : '' ?>"><?= $e(substr($d['sla_due_at'], 0, 10)) ?></td>
      <td><span class="badge <?= ['OPEN'=>'bg-warning text-dark','UNDER_REVIEW'=>'bg-info text-dark','CORRECTED'=>'bg-success','REJECTED'=>'bg-danger','WITHDRAWN'=>'bg-secondary'][$d['status']] ?>"><?= $e($d['status']) ?></span></td>
      <td class="text-end" style="white-space:nowrap;">
        <?php if ($canWork && $d['status'] === 'OPEN'): ?>
          <button class="btn btn-sm" data-dsp-action="UNDER_REVIEW" data-dsp-id="<?= (int)$d['id'] ?>">Review</button>
        <?php elseif ($canWork && $d['status'] === 'UNDER_REVIEW'): ?>
          <button class="btn btn-sm btn-primary" data-dsp-action="CORRECTED" data-dsp-id="<?= (int)$d['id'] ?>">Correct</button>
          <button class="btn btn-sm" data-dsp-action="REJECTED" data-dsp-id="<?= (int)$d['id'] ?>">Reject</button>
        <?php endif; ?>
        <button class="btn btn-sm" data-dsp-details="<?= (int)$d['id'] ?>">Details</button>
      </td>
    </tr>
    <tr class="d-none" data-dsp-detail-row="<?= (int)$d['id'] ?>">
      <td colspan="8" class="bg-light small">
        <b>Details:</b> <?= $e($d['details']) ?><br>
        <?php if ($d['resolution_note']): ?><b>Resolution:</b> <?= $e($d['resolution_note']) ?><?= $d['resolver'] ? ' — ' . $e($d['resolver']) : '' ?><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$disputes): ?>
    <tr><td colspan="8" class="text-muted">No disputes on record.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php $pageScripts = "<script src=\"" . App\Core\Rbac::baseUrl() . "/assets/js/disputes.js?v=1\"></script>"; ?>
