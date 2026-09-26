<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl();
$isNew = !empty($isNew) || empty($inst['id']);
$v = fn($k) => $e(is_scalar($inst[$k] ?? null) ? $inst[$k] : ''); ?>
<h3><?= $isNew ? 'Onboard institution' : 'Edit institution ' . $v('code') ?></h3>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="<?= $e($base) ?>/institutions<?= $isNew ? '' : '/update' ?>" class="card p-4" style="max-width:760px">
  <?= App\Core\Csrf::field() ?>
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int)$inst['id'] ?>"><?php endif; ?>
  <div class="row g-3">
    <?php if ($isNew): ?>
    <div class="col-md-4"><label class="form-label">Code *</label><input name="code" class="form-control" maxlength="20" required value="<?= $v('code') ?>" placeholder="e.g. MICROBANK-PL"></div>
    <div class="col-md-4"><label class="form-label">Category *</label>
      <select name="category" class="form-select" required><?php foreach ($categories as $c): ?><option<?= ($inst['category'] ?? '') === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
    <?php else: ?>
    <div class="col-md-4"><label class="form-label">Status *</label>
      <select name="status" class="form-select" required><?php foreach (['ACTIVE', 'SUSPENDED', 'REVOKED'] as $s): ?><option<?= ($inst['status'] ?? '') === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
      <div class="form-text">Suspending or revoking ends all user sessions and blocks the API key immediately.</div></div>
    <?php endif; ?>
    <div class="col-md-8"><label class="form-label">Name *</label><input name="name" class="form-control" maxlength="200" required value="<?= $v('name') ?>"></div>
    <div class="col-md-4"><label class="form-label">Net equity (XAF) *</label><input type="number" min="0" name="net_equity_xaf" class="form-control" required value="<?= $v('net_equity_xaf') ?: '0' ?>"></div>
    <div class="col-md-4"><label class="form-label">Legal form</label><input name="legal_form" class="form-control" maxlength="100" value="<?= $v('legal_form') ?>"></div>
    <div class="col-md-4"><label class="form-label">RCCM number</label><input name="rccm_number" class="form-control" maxlength="50" value="<?= $v('rccm_number') ?>"></div>
    <div class="col-md-4"><label class="form-label">NIU</label><input name="niu" class="form-control" maxlength="50" value="<?= $v('niu') ?>"></div>
    <div class="col-md-8"><label class="form-label">Head office</label><input name="head_office" class="form-control" maxlength="200" value="<?= $v('head_office') ?>"></div>
    <div class="col-12"><label class="form-label">Machine-API IP allow-list <small class="text-muted">(comma separated; empty = any IP)</small></label>
      <input name="ip_allowlist" class="form-control" value="<?= $v('ip_allowlist') ?>" placeholder="203.0.113.10, 203.0.113.11"></div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary"><?= $isNew ? 'Onboard' : 'Save' ?></button>
    <a class="btn" href="<?= $e($base) ?>/institutions">Cancel</a>
  </div>
</form>
