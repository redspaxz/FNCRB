<?php $e = $data['e']; /** @var array $stats */ $t = fn($k) => App\Core\Lang::t($k); $base = App\Core\Rbac::baseUrl(); ?>
<h3><?= $t('dash_title') ?></h3>
<div class="row g-3 my-1">
  <a class="col-md-4 text-decoration-none" href="<?= $base ?>/loans">
    <div class="card text-bg-primary p-3 h-100 stat-card"><small><?= $t('dash_institutions') ?></small><h2><?= (int)$stats['institutions'] ?></h2></div>
  </a>
  <?php if (App\Core\Rbac::can('borrower.manage')): ?>
  <a class="col-md-4 text-decoration-none" href="<?= $base ?>/borrowers">
    <div class="card text-bg-success p-3 h-100 stat-card"><small><?= $t('dash_borrowers') ?></small><h2><?= (int)$stats['borrowers'] ?></h2></div>
  </a>
  <?php else: ?>
  <div class="col-md-4"><div class="card text-bg-success p-3 h-100"><small><?= $t('dash_borrowers') ?></small><h2><?= (int)$stats['borrowers'] ?></h2></div></div>
  <?php endif; ?>
  <a class="col-md-4 text-decoration-none" href="<?= $base ?>/loans">
    <div class="card text-bg-dark p-3 h-100 stat-card"><small><?= $t('dash_active_loans') ?></small><h2><?= (int)$stats['active_loans'] ?></h2></div>
  </a>
  <a class="col-md-4 text-decoration-none" href="<?= $base ?>/loans">
    <div class="card p-3 h-100 stat-card" style="border:1px solid var(--lumo-border);"><small><?= $t('dash_outstanding') ?></small><h2><?= number_format((float)$stats['outstanding']) ?></h2></div>
  </a>
  <a class="col-md-4 text-decoration-none" href="<?= $base ?>/loans">
    <div class="card text-bg-danger p-3 h-100 stat-card"><small><?= $t('dash_npl') ?></small><h2><?= (int)$stats['npl_loans'] ?></h2></div>
  </a>
  <a class="col-md-4 text-decoration-none" href="<?= $base ?>/incidents">
    <div class="card text-bg-warning p-3 h-100 stat-card"><small><?= $t('dash_incidents') ?></small><h2><?= (int)$stats['open_incidents'] ?></h2></div>
  </a>
</div>
<?php if ($isRegulator): ?>
<div class="alert alert-info mt-3"><?= $t('dash_regulator_view') ?></div>
<?php endif; ?>
<div class="mt-4">
  <h5><?= $t('dash_workflow') ?></h5>
  <p class="text-muted" style="font-size:13px;"><?= $t('dash_workflow_body') ?></p>
</div>
