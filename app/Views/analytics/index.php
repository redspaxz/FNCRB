<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); $t = fn($k) => App\Core\Lang::t($k);
/** @var array $quality $ops $health $disputes */ ?>

<h3>Analytics</h3>
<p class="page-sub">Data provider performance · bureau operations · registry &amp; system performance · consumer disputes</p>

<!-- ============ 1. DATA INGESTION & QUALITY (provider performance) ============ -->
<div class="card p-4 mt-3">
  <div class="titled">1 · Data Ingestion &amp; Quality — provider scorecard</div>
  <table class="table table-striped">
    <thead><tr><th>Institution</th><th>Cat.</th><th>Submissions</th><th>Rejected</th>
      <th>Accuracy %</th><th>Completeness %</th><th>Timeliness %</th><th>Data age (d)</th><th>Quality score</th><th>Grade</th></tr></thead>
    <tbody>
    <?php foreach ($quality['providers'] as $p): ?>
      <tr>
        <td><b><?= $e($p['institution']) ?></b> <small class="text-muted"><?= $e($p['name']) ?></small></td>
        <td><?= $e($p['category']) ?></td>
        <td><?= (int)$p['submissions'] ?></td>
        <td><?= (int)$p['rejected'] ?></td>
        <td><?= $e($p['accuracy_pct'] ?? '—') ?></td>
        <td><?= $e($p['completeness_pct'] ?? '—') ?></td>
        <td><?= $e($p['timeliness_pct'] ?? '—') ?></td>
        <td><?= $p['data_age_days'] === null ? '—' : (int)$p['data_age_days'] ?></td>
        <td><b><?= $e($p['quality_score'] ?? '—') ?></b></td>
        <td><?php if ($p['grade']): ?><span class="badge <?= ['A'=>'bg-success','B'=>'bg-primary','C'=>'bg-warning','D'=>'bg-danger'][$p['grade']] ?>"><?= $e($p['grade']) ?></span><?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="chart-caption">Composite score = accuracy 40% + completeness 30% + timeliness 30%.
    Reconciliation queue: <b><?= (int)$quality['reconciliation_queue'] ?></b> duplicate identit(ies) pending merge.</div>
</div>

<!-- ============ 2. BUREAU OPERATIONS & INQUIRY METRICS ============ -->
<div class="row g-3 mt-1">
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">Total inquiries</small><h2><?= number_format($ops['total_inquiries']) ?></h2>
    <small class="text-muted"><?= (int)$ops['active_subscribers'] ?> active subscriber(s)</small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">Unique borrowers queried</small><h2><?= number_format($ops['unique_borrowers_queried']) ?></h2>
    <small class="text-muted">consumer reach</small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">Consent compliance</small><h2><?= $e($ops['consent_compliance_pct'] ?? '—') ?>%</h2>
    <small class="text-muted"><?= (int)$ops['consent_refusals'] ?> refusal(s) enforced</small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">Channel mix</small>
    <?php foreach ($ops['by_channel'] as $ch => $n): ?>
      <div class="small"><?= $e($ch) ?>: <b><?= (int)$n ?></b></div>
    <?php endforeach; ?></div></div>

  <div class="col-lg-6"><div class="card p-3 h-100">
    <div class="titled">Inquiry demand by institution</div>
    <div class="chart-box" data-chart="ops-institutions"></div></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100">
    <div class="titled">Inquiry volume — last 30 days</div>
    <div class="chart-box" data-chart="ops-trend"></div></div></div>
</div>

<!-- ============ 3. REGISTRY & SYSTEM PERFORMANCE ============ -->
<div class="row g-3 mt-1">
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">API requests (24h)</small><h2><?= number_format($health['api_requests_24h']) ?></h2>
    <small class="text-muted"><?= (int)$health['api_rejections_24h'] ?> rejected (security)</small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center <?= $health['audit_chain_intact'] ? '' : 'border-danger' ?>">
    <small class="text-muted">Audit chain</small>
    <h2><?= $health['audit_chain_intact'] ? '✅' : '⚠️' ?></h2>
    <small class="text-muted"><?= $health['audit_chain_intact'] ? 'intact' : 'BROKEN @ #' . (int)$health['audit_chain_broken_at'] ?></small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">2FA adoption</small><h2><?= $e($health['mfa_adoption_pct'] ?? '—') ?>%</h2>
    <small class="text-muted">of user accounts</small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">Security events (7d)</small><h2><?= (int)$health['failed_logins_7d'] ?></h2>
    <small class="text-muted">failed logins · <?= (int)$health['throttle_events_7d'] ?> throttles</small></div></div>

  <div class="col-lg-6"><div class="card p-3 h-100">
    <div class="titled">API throughput — 24h</div>
    <div class="chart-box" data-chart="sys-api"></div></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100">
    <div class="titled">Audit activity — 14 days (operational heartbeat)</div>
    <div class="chart-box" data-chart="sys-audit"></div></div></div>
</div>

<!-- ============ 4. CONSUMER RIGHTS & DISPUTE MANAGEMENT ============ -->
<div class="row g-3 mt-1 mb-3">
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">Disputes on record</small><h2><?= (int)$disputes['total'] ?></h2>
    <small class="text-muted">since inception</small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">Resolution rate</small><h2><?= $e($disputes['resolution_rate_pct'] ?? '—') ?>%</h2>
    <small class="text-muted">avg <?= $e($disputes['avg_resolution_days'] ?? '—') ?> day(s)</small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center <?= $disputes['over_sla'] ? 'border-danger' : '' ?>">
    <small class="text-muted">Past statutory SLA</small><h2><?= (int)$disputes['over_sla'] ?></h2>
    <small class="text-muted">30-day response window</small></div></div>
  <div class="col-lg-3"><div class="card p-3 h-100 text-center">
    <small class="text-muted">Data corrections applied</small><h2><?= (int)$disputes['corrections_applied'] ?></h2>
    <small class="text-muted">with evidence trail</small></div></div>

  <div class="col-lg-6"><div class="card p-3 h-100">
    <div class="titled">Disputes by status</div>
    <div class="chart-box" data-chart="dsp-status"></div>
    <a class="btn btn-sm mt-2" href="<?= $base ?>/disputes">Open dispute workflow →</a></div></div>
  <div class="col-lg-6"><div class="card p-3 h-100">
    <div class="titled">Registry data inventory (rows)</div>
    <div class="chart-box" data-chart="sys-inventory"></div></div></div>
</div>

<!-- ============ 5. MACRO-FINANCIAL & CREDIT MARKET (executive, regulator only) ============ -->
<?php if (!empty($macro)): ?>
<div class="card p-4 mt-3" style="border-left:4px solid #1a66d6">
  <div class="titled">5 · Macro-Financial &amp; Credit Market Analytics — strategic executive view (anonymized, system-wide)</div>

  <div class="row g-3">
    <div class="col-lg-3"><div class="card p-3 h-100 text-center <?= ($macro['npl']['national']['npl_ratio_accounts_pct'] ?? 0) > 10 ? 'border-danger' : '' ?>">
      <small class="text-muted">NPL indicator (<?= (int)($macro['npl']['national']['npl_accounts'] ?? 0) ?>/<?= (int)($macro['npl']['national']['total_accounts'] ?? 0) ?> accounts ≥90 DPD)</small>
      <h2><?= $e($macro['npl']['national']['npl_ratio_accounts_pct'] ?? '—') ?>%</h2>
      <small class="text-muted">by value: <?= $e($macro['npl']['national']['npl_ratio_value_pct'] ?? '—') ?>%</small></div></div>
    <div class="col-lg-3"><div class="card p-3 h-100 text-center">
      <small class="text-muted">Credit coverage — individuals</small><h2><?= $e($macro['coverage']['individual_coverage_pct']) ?>%</h2>
      <small class="text-muted"><?= number_format($macro['coverage']['individuals_in_registry']) ?> / <?= number_format($macro['coverage']['adult_population']) ?> adults</small>
      <hr class="my-2"><small class="text-muted">Legal entities: <b><?= $e($macro['coverage']['corporate_coverage_pct']) ?>%</b> (<?= number_format($macro['coverage']['corporates_in_registry']) ?>)</small></div></div>
    <div class="col-lg-3"><div class="card p-3 h-100 text-center">
      <small class="text-muted">Credit growth (<?= $e($macro['credit_growth']['last_period'] ?? '—') ?>)</small>
      <h2><?= $e($macro['credit_growth']['mom_growth_pct'] ?? '—') ?>% <small class="text-muted fs-6">MoM</small></h2>
      <small class="text-muted">YoY: <?= $e(json_encode($macro['credit_growth']['yoy_growth_pct'])) ?></small></div></div>
    <div class="col-lg-3"><div class="card p-3 h-100 text-center <?= ($macro['indebtedness']['over_indebted_pct'] ?? 0) > 25 ? 'border-danger' : '' ?>">
      <small class="text-muted">Indebtedness index</small>
      <h2><?= $e($macro['indebtedness']['avg_accounts_per_borrower'] ?? '—') ?></h2>
      <small class="text-muted">avg accounts/borrower · avg debt <?= $e(number_format((int)($macro['indebtedness']['avg_outstanding_xaf'] ?? 0))) ?> XAF</small>
      <hr class="my-2"><small class="text-muted">over-indebted: <b><?= $e($macro['indebtedness']['over_indebted_pct'] ?? '—') ?>%</b> · multi-institution <?= $e($macro['indebtedness']['multi_institution_pct'] ?? '—') ?>%</small></div></div>

    <div class="col-lg-6"><div class="card p-3 h-100">
      <div class="titled">New credit facilities per month (all sectors)</div>
      <div class="chart-box" data-chart="macro-growth"></div></div></div>
    <div class="col-lg-6"><div class="card p-3 h-100">
      <div class="titled">NPL ratio by sector (accounts ≥90 DPD, %)</div>
      <div class="chart-box" data-chart="macro-npl-sector"></div></div></div>
    <div class="col-lg-6"><div class="card p-3 h-100">
      <div class="titled">Active accounts per borrower — distribution</div>
      <div class="chart-box" data-chart="macro-indebted"></div></div></div>
    <div class="col-lg-6"><div class="card p-3 h-100">
      <div class="titled">MoM credit growth by sector (%)</div>
      <div class="chart-box" data-chart="macro-growth-sector"></div></div></div>
  </div>
  <div class="chart-caption">Population/entity denominators are configurable in <code>config.php → macro</code> — calibrate with BEAC/INS statistics.</div>
</div>
<?php endif; ?>

<?php
$q = json_encode($quality); $o = json_encode($ops); $h = json_encode($health); $dsp = json_encode($disputes); $mc = json_encode($macro);
$pageScripts = <<<HTML
<script>window.FNCRB_ANALYTICS = {quality: $q, ops: $o, health: $h, disputes: $dsp, macro: $mc};</script>
<script src="{$base}/assets/js/analytics-charts.js?v=3"></script>
HTML;
?>
