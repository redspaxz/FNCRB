<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>New User</h3>

<?php if (!empty($generated)): ?>
<div class="alert alert-success mt-3">
  <b>User created.</b> Share these one-time credentials securely — the password will not be shown again:<br>
  Email: <code><?= $e($generated['email']) ?></code> · Password: <code><?= $e($generated['password']) ?></code>
  <br><small>The user must choose a new password at first sign-in.</small>
</div>
<?php endif; ?>

<?php if (!empty($error)): ?><div class="alert alert-danger mt-3"><?= $e($error) ?></div><?php endif; ?>

<form method="post" action="<?= $e($base) ?>/users" class="card p-4 mt-3" style="max-width:720px">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">Full name *</label>
      <input name="full_name" class="form-control" maxlength="150" required></div>
    <div class="col-md-6"><label class="form-label">Email *</label>
      <input type="email" name="email" class="form-control" maxlength="190" required></div>
    <div class="col-md-4"><label class="form-label">Role *</label>
      <select name="role_code" class="form-select" required>
        <option value="">— select —</option>
        <?php foreach ($roles as $r): ?><option value="<?= $e($r['code']) ?>"><?= $e($r['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php if (!empty($isSuper)): ?>
    <div class="col-md-8"><label class="form-label">Institution <small class="text-muted">(not used for System Administrator / Supervisor roles)</small></label>
      <select name="institution_id" class="form-select">
        <option value="">— national —</option>
        <?php foreach ($institutions as $i): ?><option value="<?= (int)$i['id'] ?>"><?= $e($i['code']) ?> — <?= $e($i['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div class="col-md-4"><label class="form-label">Officer level</label>
      <select name="officer_level" class="form-select"><option value="1">1 — Junior</option><option value="2" selected>2 — Senior</option><option value="3">3 — Manager</option></select></div>
    <div class="col-md-4"><label class="form-label">Branch code</label>
      <input name="branch_code" class="form-control" maxlength="30"></div>
  </div>
  <p class="text-muted small mt-3"><?= !empty($isSuper) ? 'Institution roles require an institution; supervisor and administrator accounts are national.' : 'The user is created in your institution.' ?>
    A policy-compliant one-time password is generated and displayed once; it must be changed at first sign-in.</p>
  <button class="btn btn-primary">Create user</button>
</form>
