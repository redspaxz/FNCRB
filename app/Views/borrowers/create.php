<?php $e = $data['e']; ?>
<h3>Register borrower</h3>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="<?= App\Core\Rbac::baseUrl() ?>/borrowers" class="card p-4 bg-white" style="max-width:720px">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">Full name / Company *</label><input name="full_name" class="form-control" required maxlength="200"></div>
    <div class="col-md-3"><label class="form-label">Type</label>
      <select name="type" class="form-select"><option>INDIVIDUAL</option><option>CORPORATE</option></select></div>
    <div class="col-md-3"><label class="form-label">Gender</label>
      <select name="gender" class="form-select"><option value=""></option><option>M</option><option>F</option></select></div>
    <div class="col-md-4"><label class="form-label">Date of birth</label><input type="date" name="date_of_birth" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">CNI number</label><input name="cni_number" class="form-control" maxlength="30"></div>
    <div class="col-md-4"><label class="form-label">NIU (tax id)</label><input name="niu" class="form-control" maxlength="30"></div>
    <div class="col-md-4"><label class="form-label">Cooperative member ID</label><input name="coop_member_id" class="form-control" maxlength="40"></div>
    <div class="col-md-4"><label class="form-label">Phone</label><input name="phone" class="form-control" maxlength="25"></div>
    <div class="col-md-4"><label class="form-label">Region</label>
      <select name="region" class="form-select">
        <option value=""></option>
        <?php foreach (['Littoral','Centre','Ouest','Sud','Nord','Adamaoua','Est','Nord-Ouest','Sud-Ouest','Extrême-Nord'] as $r): ?>
          <option><?= $r ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <p class="text-muted small mt-3">Identity reconciliation will match this record against CNI, NIU and cooperative IDs to prevent duplicate borrower profiles across institutions.</p>
  <button class="btn btn-primary">Register</button>
</form>
