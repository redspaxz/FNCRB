<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<div class="d-flex justify-content-between align-items-center">
  <h3>User Management</h3>
  <a class="btn btn-primary btn-sm" href="<?= $e($base) ?>/users/create">New user</a>
</div>
<table class="table table-striped mt-3" data-users>
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Institution</th><th>Branch</th><th>2FA</th><th>Status</th><th>Last login</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$selfId; ?>
    <tr data-user-row data-id="<?= (int)$u['id'] ?>">
      <td><?= $e($u['full_name']) ?><?= $self ? ' <small class="text-muted">(you)</small>' : '' ?></td>
      <td><?= $e($u['email']) ?></td>
      <td><span class="badge bg-secondary"><?= $e($u['role_code']) ?></span></td>
      <td><?= $e($u['inst_code'] ?? 'national') ?></td>
      <td><?= $e($u['branch_code'] ?? '—') ?></td>
      <td data-mfa><?= $u['totp_secret'] ? '✅' : '—' ?></td>
      <td data-status><?= $e($u['status']) ?><?= $u['must_change_password'] ? ' <small class="text-muted">(pw change due)</small>' : '' ?></td>
      <td><?= $e($u['last_login_at'] ?? 'never') ?></td>
      <td class="text-end" style="white-space:nowrap;">
        <?php if (!$self): ?>
        <button class="btn btn-sm" data-toggle-user="<?= (int)$u['id'] ?>"><?= $u['status'] === 'ACTIVE' ? 'Lock' : 'Unlock' ?></button>
        <button class="btn btn-sm" data-reset-user="<?= (int)$u['id'] ?>">Reset password</button>
        <?php if ($u['totp_secret']): ?><button class="btn btn-sm" data-reset-mfa="<?= (int)$u['id'] ?>">Reset 2FA</button><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>

<?php
$pageScripts = '<script src="' . htmlspecialchars($base, ENT_QUOTES) . '/assets/js/users.js?v=2"></script>';
?>
