<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <h3>Consumer Disputes</h3>
  <?php if ($canFile): ?><a class="btn btn-primary btn-sm" href="<?= $e($base) ?>/disputes/create">File dispute</a><?php endif; ?>
</div>
<p class="page-sub">Statutory workflow under national privacy/data-protection law — 30-day response window (Law 2010/012).
  <?= $canWork ? '' : 'You see disputes your institution filed and disputes about data your institution reported.' ?></p>

<div class="mb-2 small">Status:
  <a href="<?= $e($base) ?>/disputes">all</a>
  <?php foreach (App\Services\DisputeService::STATUSES as $s): ?> · <a href="<?= $e($base) ?>/disputes?status=<?= $s ?>"<?= $statusFilter === $s ? ' class="fw-bold"' : '' ?>><?= $e(strtolower(str_replace('_', ' ', $s))) ?></a><?php endforeach; ?>
  · <?= (int)$kpis['over_sla'] ?> overdue
</div>

<table class="table table-striped mt-2" id="dispute-table">
  <thead><tr><th>Reference</th><th>Borrower</th><th>Type</th><th>Against</th><th>Filed by</th>
    <th>SLA due</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($disputes as $d): ?>
    <?php $overSla = in_array($d['status'], ['OPEN','UNDER_REVIEW'], true) && strtotime($d['sla_due_at']) < time();
          $isFurnisher = !$canWork && $myInst !== null && (int)$d['against_inst_id'] === (int)$myInst; ?>
    <tr data-dispute-row="<?= (int)$d['id'] ?>" class="<?= $overSla ? 'table-danger' : '' ?>">
      <td><code><?= $e($d['reference']) ?></code></td>
      <td><?= $e($d['full_name']) ?> <small class="text-muted">[<?= $e($d['master_ref']) ?>]</small></td>
      <td><small><?= $e(str_replace('_', ' ', $d['dispute_type'])) ?></small><?= $d['contract_ref'] ? '<br><code>' . $e($d['contract_ref']) . '</code>' : '' ?></td>
      <td><?= $e($d['against'] ?? '—') ?></td>
      <td><?= $e($d['filed_by']) ?></td>
      <td class="<?= $overSla ? 'text-danger fw-bold' : '' ?>"><?= $e(substr($d['sla_due_at'], 0, 10)) ?></td>
      <td><span class="badge <?= ['OPEN'=>'bg-warning text-dark','UNDER_REVIEW'=>'bg-info text-dark','CORRECTED'=>'bg-success','REJECTED'=>'bg-danger','WITHDRAWN'=>'bg-secondary'][$d['status']] ?>"><?= $e($d['status']) ?></span>
        <?= $d['furnisher_response'] ? '<br><small class="text-muted">furnisher replied</small>' : '' ?></td>
      <td class="text-end" style="white-space:nowrap;">
        <?php if ($canWork && $d['status'] === 'OPEN'): ?>
          <button class="btn btn-sm" data-dsp-action="UNDER_REVIEW" data-dsp-id="<?= (int)$d['id'] ?>">Review</button>
        <?php elseif ($canWork && $d['status'] === 'UNDER_REVIEW'): ?>
          <button class="btn btn-sm btn-primary" data-dsp-action="CORRECTED" data-dsp-id="<?= (int)$d['id'] ?>" data-dsp-loan="<?= (int)$d['loan_id'] ?>">Correct</button>
          <button class="btn btn-sm" data-dsp-action="REJECTED" data-dsp-id="<?= (int)$d['id'] ?>">Reject</button>
        <?php endif; ?>
        <?php if ($canWork): ?><a class="btn btn-sm" href="<?= $e($base) ?>/borrowers/report?ref=<?= $e(rawurlencode($d['master_ref'])) ?>">File</a><?php endif; ?>
        <button class="btn btn-sm" data-dsp-details="<?= (int)$d['id'] ?>">Details</button>
      </td>
    </tr>
    <tr class="d-none" data-dsp-detail-row="<?= (int)$d['id'] ?>">
      <td colspan="8" class="bg-light small">
        <b>Details:</b> <?= $e($d['details']) ?><br>
        <?php if ($d['furnisher_response']): ?><b>Furnisher response</b> (<?= $e($d['furnisher_responded_at']) ?>): <?= $e($d['furnisher_response']) ?><br><?php endif; ?>
        <?php if ($d['resolution_note']): ?><b>Resolution:</b> <?= $e($d['resolution_note']) ?><?= $d['resolver'] ? ' — ' . $e($d['resolver']) : '' ?><br><?php endif; ?>
        <?php if ($isFurnisher && in_array($d['status'], ['OPEN', 'UNDER_REVIEW'], true)): ?>
        <form method="post" action="<?= $e($base) ?>/disputes/respond" class="d-flex gap-2 mt-2">
          <?= App\Core\Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <textarea name="response" class="form-control form-control-sm" rows="2" minlength="5" maxlength="5000" required placeholder="Your institution's position on the disputed data (evidence, corrected figures…)"><?= $e($d['furnisher_response'] ?? '') ?></textarea>
          <button class="btn btn-sm btn-primary">Send response</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$disputes): ?>
    <tr><td colspan="8" class="text-muted">No disputes on record.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>

<?php $pageScripts = '<script src="' . htmlspecialchars($base, ENT_QUOTES) . '/assets/js/disputes.js?v=2"></script>'; ?>
