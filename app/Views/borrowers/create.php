<?php $e = $data['e']; $o = fn($k) => $e(is_scalar($old[$k] ?? null) ? $old[$k] : ''); ?>
<h3>Register borrower</h3>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="<?= $e(App\Core\Rbac::baseUrl()) ?>/borrowers" class="card p-4 bg-white" style="max-width:720px">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">Full name / Company *</label><input name="full_name" class="form-control" required maxlength="200" value="<?= $o('full_name') ?>"></div>
    <div class="col-md-3"><label class="form-label">Type</label>
      <select name="type" class="form-select"><?php foreach (['INDIVIDUAL','CORPORATE'] as $v): ?><option<?= ($old['type'] ?? '') === $v ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Gender</label>
      <select name="gender" class="form-select"><option value=""></option><?php foreach (['M','F'] as $v): ?><option<?= ($old['gender'] ?? '') === $v ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Date of birth</label><input type="date" name="date_of_birth" class="form-control" value="<?= $o('date_of_birth') ?>"></div>
    <div class="col-md-4"><label class="form-label">CNI number</label><input name="cni_number" class="form-control" maxlength="30" value="<?= $o('cni_number') ?>"></div>
    <div class="col-md-4"><label class="form-label">NIU (tax id)</label><input name="niu" class="form-control" maxlength="30" value="<?= $o('niu') ?>"></div>
    <div class="col-md-4"><label class="form-label">Cooperative member ID</label><input name="coop_member_id" class="form-control" maxlength="40" value="<?= $o('coop_member_id') ?>"></div>
    <div class="col-md-4"><label class="form-label">Phone</label><input name="phone" class="form-control" maxlength="25" value="<?= $o('phone') ?>"></div>
    <div class="col-md-4"><label class="form-label">Region</label>
      <select name="region" class="form-select">
        <option value=""></option>
        <?php foreach (['Littoral','Centre','Ouest','Sud','Nord','Adamaoua','Est','Nord-Ouest','Sud-Ouest','Extrême-Nord'] as $r): ?>
          <option<?= ($old['region'] ?? '') === $r ? ' selected' : '' ?>><?= $r ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <p class="text-muted small mt-3">At least one identifier (CNI, NIU or cooperative ID) is required. Identity reconciliation matches it against the registry to prevent duplicate borrower profiles across institutions.</p>
  <button class="btn btn-primary">Register</button>
</form>
