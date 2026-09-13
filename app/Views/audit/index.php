<?php $e = $data['e']; ?>
<h3>Audit Trail <small class="text-muted fs-6">tamper-proof · hash-chained</small></h3>
<div class="alert <?= $chainOk ? 'alert-success' : 'alert-danger' ?>">
  <?php if ($chainOk): ?>
    <b>Chain integrity verified.</b> All <?= count($logs) ?>+ log entries are linked by SHA-256 hashes — no deletions or mutations detected.
  <?php else: ?>
    <b>CHAIN BROKEN at audit row #<?= (int)$brokenAt ?></b> — entries before this point may have been tampered with. Escalate to COBAC inspectors immediately.
  <?php endif; ?>
</div>
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
      <td><code style="word-break:break-all"><?= $e(mb_substr((string)$a['details'], 0, 120)) ?></code></td>
      <td><code title="<?= $e($a['row_hash']) ?>"><?= $e(mb_substr($a['row_hash'], 0, 10)) ?>…</code></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?= $pager ?? '' ?>

