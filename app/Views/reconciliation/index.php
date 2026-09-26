<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>Identity Reconciliation</h3>
<p class="page-sub">Submissions whose identifiers matched a registry borrower with a different name or date of birth, or matched two different borrowers.
  They are parked here instead of being attached to someone else's credit file.</p>

<div class="mb-2 small">Show:
  <?php foreach (['OPEN', 'ACCEPTED', 'REJECTED'] as $s): ?><a href="<?= $e($base) ?>/reconciliation?status=<?= $s ?>"<?= $status === $s ? ' class="fw-bold"' : '' ?>><?= strtolower($s) ?></a> <?php endforeach; ?>
</div>

<table class="table table-striped table-sm bg-white">
  <thead><tr><th>#</th><th>Received</th><th>Institution</th><th>Conflict</th><th>Submitted identity</th><th>Registry match</th><th>Record</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): $sub = json_decode((string)$c['submitted'], true) ?: []; $ctx = json_decode((string)$c['context'], true) ?: []; ?>
    <tr>
      <td><?= (int)$c['id'] ?></td>
      <td><small><?= $e($c['created_at']) ?></small></td>
      <td><?= $e($c['inst_code'] ?? '—') ?> <small class="text-muted"><?= $e($c['source']) ?></small></td>
      <td><span class="badge bg-warning text-dark"><?= $e($c['conflict_type']) ?></span><br><small><?= $e($c['message']) ?></small></td>
      <td><small><?= $e($sub['full_name'] ?? '') ?><br>CNI <?= $e($sub['cni_number'] ?? '—') ?> · NIU <?= $e($sub['niu'] ?? '—') ?> · DOB <?= $e($sub['date_of_birth'] ?? '—') ?></small></td>
      <td><small><?= $e($c['matched_name'] ?? '—') ?> <code><?= $e($c['master_ref'] ?? '') ?></code><br>CNI <?= $e($c['matched_cni'] ?? '—') ?> · NIU <?= $e($c['matched_niu'] ?? '—') ?> · DOB <?= $e($c['matched_dob'] ?? '—') ?></small></td>
      <td><small><?= $e($ctx['kind'] ?? '') ?> <code><?= $e($ctx['contract_ref'] ?? ($ctx['instrument_ref'] ?? '')) ?></code></small></td>
      <td>
        <?php if ($c['status'] === 'OPEN'): ?>
        <form method="post" action="<?= $e($base) ?>/reconciliation/resolve" class="d-flex flex-column gap-1" style="min-width:230px">
          <?= App\Core\Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <input name="note" class="form-control form-control-sm" placeholder="Decision note (evidence checked)" required minlength="3" maxlength="255">
          <input name="borrower_ref" class="form-control form-control-sm" placeholder="Attach to ref (default: match)" maxlength="40">
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-primary" name="decision" value="ACCEPTED">Same person — accept</button>
            <button class="btn btn-sm" name="decision" value="REJECTED">Reject</button>
          </div>
        </form>
        <?php else: ?><small><?= $e($c['status']) ?> <?= $e($c['decided_at'] ?? '') ?><br><?= $e($c['decision_note'] ?? '') ?></small><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-muted">Queue empty.</td></tr><?php endif; ?>
  </tbody>
</table>

<div class="card p-3 mt-4" style="max-width:760px">
  <div class="titled">Merge duplicate borrower records</div>
  <form method="post" action="<?= $e($base) ?>/reconciliation/merge" class="row g-2" data-confirm="Merge these records? All credit data of the duplicate moves to the master record.">
    <?= App\Core\Csrf::field() ?>
    <div class="col-md-3"><input name="duplicate_ref" class="form-control" placeholder="Duplicate ref" required maxlength="40"></div>
    <div class="col-md-3"><input name="master_ref" class="form-control" placeholder="Surviving ref" required maxlength="40"></div>
    <div class="col-md-4"><input name="reason" class="form-control" placeholder="Justification" required minlength="5" maxlength="255"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Merge</button></div>
  </form>
  <p class="text-muted small mb-0 mt-2">Loans, incidents, consents, inquiries, guarantees, disputes and scores are repointed; the duplicate record is kept as a pointer for traceability.</p>
</div>
