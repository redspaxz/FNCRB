<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); $o = fn($k) => $e(is_scalar($old[$k] ?? null) ? $old[$k] : ''); ?>
<h3>File Consumer Dispute</h3>
<p class="page-sub">File on behalf of a borrower who contests registry data. Statutory response window: 30 days.</p>

<?php if (!empty($error)): ?><div class="alert alert-danger mt-3"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= $e($base) ?>/disputes" class="card p-4 mt-2" style="max-width:760px">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-5"><label class="form-label">Borrower identifier *</label>
      <input name="borrower_identifier" class="form-control" maxlength="40" required value="<?= $o('borrower_identifier') ?>" placeholder="CNI, NIU, coop ID or FNB… ref"></div>
    <div class="col-md-4"><label class="form-label">Dispute type *</label>
      <select name="dispute_type" class="form-select" required>
        <option value="">— select —</option>
        <?php foreach ($types as $ty): ?>
          <option value="<?= $e($ty) ?>"<?= ($old['dispute_type'] ?? '') === $ty ? ' selected' : '' ?>><?= $e(ucwords(strtolower(str_replace('_', ' ', $ty)))) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label">Against institution</label>
      <select name="against_inst_id" class="form-select">
        <option value="">— registry-wide —</option>
        <?php foreach ($institutions as $i): ?>
          <option value="<?= (int)$i['id'] ?>"<?= (string)($old['against_inst_id'] ?? '') === (string)$i['id'] ? ' selected' : '' ?>><?= $e($i['code']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-5"><label class="form-label">Disputed contract ref <small class="text-muted">(optional)</small></label>
      <input name="contract_ref" class="form-control" maxlength="50" value="<?= $o('contract_ref') ?>"></div>
    <div class="col-12"><label class="form-label">Details * <small class="text-muted">(what the consumer contests, 10–5000 characters)</small></label>
      <textarea name="details" class="form-control" rows="4" required minlength="10" maxlength="5000"><?= $o('details') ?></textarea></div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary">File dispute</button>
    <a class="btn" href="<?= $e($base) ?>/disputes">Cancel</a>
  </div>
</form>
