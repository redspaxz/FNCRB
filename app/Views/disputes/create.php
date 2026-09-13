<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>File Consumer Dispute</h3>
<p class="page-sub">File on behalf of a borrower who contests registry data. Statutory response window: 30 days.</p>

<?php if (!empty($error)): ?><div class="alert alert-danger mt-3"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= $base ?>/disputes" class="card p-4 mt-2" style="max-width:760px">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-5"><label class="form-label">Borrower *</label>
      <select name="borrower_id" class="form-select" required>
        <option value="">— select —</option>
        <?php foreach ($borrowers as $b): ?>
          <option value="<?= (int)$b['id'] ?>"><?= $e($b['full_name']) ?> [<?= $e($b['master_ref']) ?>]</option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label">Dispute type *</label>
      <select name="dispute_type" class="form-select" required>
        <option value="">— select —</option>
        <?php foreach ($types as $ty): ?>
          <option value="<?= $e($ty) ?>"><?= $e(ucwords(strtolower(str_replace('_', ' ', $ty)))) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label">Against institution</label>
      <select name="against_inst_id" class="form-select">
        <option value="">— registry-wide —</option>
        <?php foreach ($institutions as $i): ?>
          <option value="<?= (int)$i['id'] ?>"><?= $e($i['code']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-12"><label class="form-label">Details * <small class="text-muted">(what the consumer contests, min 10 characters)</small></label>
      <textarea name="details" class="form-control" rows="4" required minlength="10"></textarea></div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary">File dispute</button>
    <a class="btn" href="<?= $base ?>/disputes">Cancel</a>
  </div>
</form>
