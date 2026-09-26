<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<div class="d-flex justify-content-between align-items-center">
  <h3>Institutions</h3>
  <a class="btn btn-primary btn-sm" href="<?= $e($base) ?>/institutions/create">Onboard institution</a>
</div>

<?php if (!empty($newKey)): ?>
<div class="alert alert-warning mt-3">
  <b>New API key for <?= $e($newKey['code']) ?></b> — shown once; the previous key no longer works. Transmit it through a secure channel:<br>
  <code style="font-size:13px;word-break:break-all;"><?= $e($newKey['key']) ?></code>
</div>
<?php endif; ?>

<table class="table table-striped mt-3">
  <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Net equity (XAF)</th><th>Users</th><th>Loans</th><th>API</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $i): ?>
    <tr>
      <td><code><?= $e($i['code']) ?></code></td>
      <td><?= $e($i['name']) ?></td>
      <td><?= $e($i['category']) ?></td>
      <td><?= number_format((int)$i['net_equity_xaf']) ?></td>
      <td><?= (int)$i['users'] ?></td>
      <td><?= (int)$i['loans'] ?></td>
      <td><?= $i['api_key_hash'] ? 'key issued' : '—' ?><?= $i['ip_allowlist'] ? '<br><small class="text-muted">IP-restricted</small>' : '' ?></td>
      <td><span class="badge <?= ['ACTIVE' => 'bg-success', 'SUSPENDED' => 'bg-warning text-dark', 'REVOKED' => 'bg-danger'][$i['status']] ?>"><?= $e($i['status']) ?></span></td>
      <td class="text-end" style="white-space:nowrap;">
        <?php if ($i['category'] !== 'REGULATOR'): ?>
        <a class="btn btn-sm" href="<?= $e($base) ?>/institutions/edit?id=<?= (int)$i['id'] ?>">Edit</a>
        <form method="post" action="<?= $e($base) ?>/institutions/rotate-key" class="d-inline" data-confirm="Issue a new API key for <?= $e($i['code']) ?>? The current key stops working immediately.">
          <?= App\Core\Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
          <button class="btn btn-sm"><?= $i['api_key_hash'] ? 'Rotate API key' : 'Issue API key' ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
