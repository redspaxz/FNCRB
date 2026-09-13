<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<div class="d-flex justify-content-between align-items-center">
  <h3>User Management</h3>
  <a class="btn btn-primary btn-sm" href="<?= $base ?>/users/create">New user</a>
</div>
<table class="table table-striped mt-3" data-users>
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Institution</th><th>Branch</th><th>2FA</th><th>Status</th><th>Last login</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr data-user-row data-id="<?= (int)$u['id'] ?>">
      <td><?= $e($u['full_name']) ?></td>
      <td><?= $e($u['email']) ?></td>
      <td><span class="badge bg-secondary"><?= $e($u['role_code']) ?></span></td>
      <td><?= $e($u['inst_code'] ?? '—') ?></td>
      <td><?= $e($u['branch_code'] ?? '—') ?></td>
      <td><?= $u['totp_secret'] ? '✅' : '—' ?></td>
      <td data-status><?= $e($u['status']) ?></td>
      <td><?= $e($u['last_login_at'] ?? 'never') ?></td>
      <td class="text-end" style="white-space:nowrap;">
        <button class="btn btn-sm" data-toggle-user="<?= (int)$u['id'] ?>"><?= $u['status'] === 'ACTIVE' ? 'Lock' : 'Unlock' ?></button>
        <button class="btn btn-sm" data-reset-user="<?= (int)$u['id'] ?>">Reset password</button>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>

<?php
$csrf = App\Core\Csrf::token();
$pageScripts = <<<JS
<script src="{$base}/assets/js/users.js?v=1"></script>
JS;
?>
