<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>Audit Trail <small class="text-muted fs-6">tamper-evident · hash-chained · <?= $national ? 'national' : 'your institution' ?></small></h3>
<div class="alert <?= $chain['ok'] ? 'alert-success' : 'alert-danger' ?> d-flex align-items-center gap-3 flex-wrap">
  <div>
  <?php if ($chain['ok']): ?>
    <b>Chain integrity verified.</b> Every entry recorded since the last checkpoint re-hashes correctly — no deletion, insertion or edit detected.
    <?php if ($checkpoint): ?><br><small>Checkpoint: entry #<?= (int)$checkpoint['last_id'] ?>, verified <?= $e($checkpoint['verified_at']) ?>. Earlier entries are covered by the nightly full verification.</small><?php endif; ?>
  <?php else: ?>
    <b>CHAIN BROKEN at audit entry #<?= (int)$chain['broken_at'] ?></b> (<?= $e($chain['reason']) ?>). Escalate to COBAC inspectors immediately.
  <?php endif; ?>
  </div>
  <?php if ($national): ?>
  <form method="post" action="<?= $e($base) ?>/audit/verify" class="ms-auto">
    <?= App\Core\Csrf::field() ?><button class="btn btn-sm">Run full verification</button>
  </form>
  <?php endif; ?>
</div>
<form class="row g-2 mb-2" method="get">
  <div class="col-md-4"><input class="form-control" name="action" value="<?= $e($action ?? '') ?>" placeholder="Filter by action (e.g. INQUIRY, LOGIN_FAILED)"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button> <a class="btn" href="<?= $e($base) ?>/audit">Clear</a></div>
  <div class="col-auto ms-auto small align-self-center"><?= number_format((int)$total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?></div>
</form>
<div class="table-responsive">
<table class="table table-striped table-sm bg-white" style="font-size:.85rem">
  <thead><tr><th>#</th><th>Time</th><th>User</th><th>Institution</th><th>Action</th><th>Entity</th><th>Details</th><th>Row hash</th></tr></thead>
  <tbody>
  <?php foreach ($logs as $a): ?>
    <tr>
      <td><?= (int)$a['id'] ?></td>
      <td><?= $e($a['created_at']) ?></td>
      <td><?= $e($a['full_name'] ?? '—') ?></td>
      <td><?= $e($a['inst_code'] ?? '—') ?></td>
      <td><span class="badge bg-secondary"><?= $e($a['action']) ?></span></td>
      <td><?= $e($a['entity'] ?? '') ?><?= $a['entity_id'] !== '' && $a['entity_id'] !== null ? ' #' . $e((string)$a['entity_id']) : '' ?></td>
      <td><code style="word-break:break-all"><?= $e(mb_substr((string)$a['details'], 0, 160)) ?></code></td>
      <td><code title="<?= $e($a['row_hash']) ?> (v<?= (int)$a['hash_version'] ?>)"><?= $e(mb_substr($a['row_hash'], 0, 10)) ?>…</code></td>
    </tr>
  <?php endforeach; if (!$logs): ?><tr><td colspan="8" class="text-muted">No entries.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= $pager ?? '' ?>
