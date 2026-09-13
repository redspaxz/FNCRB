<?php $e = $data['e']; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <h3>Compliance & Reporting</h3>
  <div class="d-flex gap-2">
    <button id="btn-reclassify" class="btn btn-outline-primary btn-sm">Run classification &amp; provisioning</button>
    <a class="btn btn-outline-secondary btn-sm" href="<?= App\Core\Rbac::baseUrl() ?>/compliance/supervisory-package" target="_blank">Supervisory package (JSON)</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= App\Core\Rbac::baseUrl() ?>/reports/supervisory.xlsx">Supervisory XLSX</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= App\Core\Rbac::baseUrl() ?>/compliance/concentration" target="_blank">Concentration ratios (JSON)</a>
  </div>
</div>

<h5 class="mt-4">Portfolio quality — COBAC asset classification</h5>
<div class="table-responsive">
<table class="table table-bordered table-sm bg-white">
  <thead>
    <tr><th>Institution</th><th>Class</th><th>Loans</th><th>Outstanding (XAF)</th><th>Provisions (XAF)</th></tr>
  </thead>
  <tbody>
  <?php foreach ($portfolio as $code => $p): ?>
    <?php $rowspan = count($p['classes']) ?: 1; $first = true; ?>
    <?php foreach ($p['classes'] ?: ['' => null] as $cls => $v): ?>
    <tr>
      <?php if ($first): ?>
      <td rowspan="<?= $rowspan ?>"><b><?= $e($code) ?></b> — <?= $e($p['name']) ?><br>
        <small class="text-muted">NPL ratio: <?= $e((string)$p['npl_ratio_pct']) ?>% · Provisions: <?= number_format($p['provisions_total']) ?></small></td>
      <?php endif; $first = false; ?>
      <?php if ($v): ?>
      <td class="cls-<?= $e((string)$cls) ?>"><?= $e((string)$cls) ?></td>
      <td><?= (int)$v['loans'] ?></td>
      <td><?= number_format($v['outstanding']) ?></td>
      <td><?= number_format($v['provisions']) ?></td>
      <?php else: ?>
      <td colspan="4" class="text-muted">No loans</td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h5 class="mt-4">Payment incidents summary (CIP)</h5>
<table class="table table-sm table-striped bg-white">
  <thead><tr><th>Institution</th><th>Incident type</th><th>Count</th><th>Amount (XAF)</th></tr></thead>
  <tbody>
  <?php foreach ($cip as $r): ?>
    <tr><td><?= $e($r['code']) ?> — <?= $e($r['name']) ?></td><td><?= $e($r['incident_type']) ?></td>
        <td><?= (int)$r['cnt'] ?></td><td><?= number_format((int)$r['amt']) ?></td></tr>
  <?php endforeach; if (!$cip): ?><tr><td colspan="4" class="text-muted">No incidents.</td></tr><?php endif; ?>
  </tbody>
</table>

<?php // reclassify button behavior lives in assets/js/app.js (CSP-safe, no inline script)
?>
