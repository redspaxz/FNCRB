<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>Consents <small class="text-muted fs-6"><?= $national ? 'national register' : 'your institution' ?></small></h3>
<p class="page-sub">Borrower consents recorded for credit inquiries. A revoked or expired consent can no longer be relied on.</p>
<div class="mb-3 d-flex gap-2">
  <?php foreach (['' => 'All', 'active' => 'Active', 'expired' => 'Expired', 'revoked' => 'Revoked'] as $k => $label): ?>
    <a class="btn btn-sm<?= ($state ?? '') === $k || (!$state && $k === '') ? ' btn-primary' : '' ?>" href="<?= $e($base) ?>/consents<?= $k ? '?state=' . $k : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<table class="table table-striped table-sm bg-white">
  <thead><tr><th>#</th><?php if ($national): ?><th>Institution</th><?php endif; ?><th>Borrower</th><th>Type</th><th>Reference</th><th>Signed</th><th>Valid until</th><th>Evidence</th><th>Captured by</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c):
    $st = $c['revoked_at'] ? 'REVOKED' : (strtotime($c['expires_at']) <= time() ? 'EXPIRED' : 'ACTIVE'); ?>
    <tr>
      <td><?= (int)$c['id'] ?></td>
      <?php if ($national): ?><td><?= $e($c['inst_code']) ?></td><?php endif; ?>
      <td><?= $e($c['full_name']) ?> <small class="text-muted">[<?= $e($c['master_ref']) ?>]</small></td>
      <td><?= $e($c['consent_type']) ?></td>
      <td><code><?= $e($c['consent_ref']) ?></code></td>
      <td><?= $e($c['signed_at'] ?? '—') ?></td>
      <td><?= $e(substr((string)$c['expires_at'], 0, 10)) ?></td>
      <td><?php if ($c['evidence_path']): ?><a href="<?= $e($base) ?>/consents/evidence?id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener">view</a>
          <?php elseif ($c['evidence_sha256']): ?><small title="<?= $e($c['evidence_sha256']) ?>">sha256 <?= $e(substr($c['evidence_sha256'], 0, 8)) ?>…</small>
          <?php else: ?>—<?php endif; ?></td>
      <td><?= $e($c['captured_by_name'] ?? 'API') ?></td>
      <td><span class="badge <?= ['ACTIVE' => 'bg-success', 'EXPIRED' => 'bg-secondary', 'REVOKED' => 'bg-danger'][$st] ?>"><?= $st ?></span>
        <?php if ($c['revoked_at']): ?><br><small class="text-muted"><?= $e($c['revoke_reason'] ?? '') ?></small><?php endif; ?></td>
      <td class="text-end">
        <?php if ($canRevoke && $st === 'ACTIVE'): ?>
        <form method="post" action="<?= $e($base) ?>/consents/revoke" class="d-flex gap-1" data-confirm="Revoke this consent? Further inquiries relying on it will be refused.">
          <?= App\Core\Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <input name="reason" class="form-control form-control-sm" placeholder="Reason" required minlength="3" maxlength="255" style="width:130px">
          <button class="btn btn-sm">Revoke</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="11" class="text-muted">No consents.</td></tr><?php endif; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>
