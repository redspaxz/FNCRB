<?php /** @var string $content @var array $u */ $u = App\Core\Auth::user(); $e = $data['e'] ?? fn($s) => htmlspecialchars((string)$s); $base = App\Core\Rbac::baseUrl(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title ?? 'FNCRB') ?> — FNCRB</title>
<meta name="csrf-token" content="<?= App\Core\Csrf::token() ?>">
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= $base ?>/assets/css/applet.css" rel="stylesheet">
</head>
<body>
<div class="applet-window">
  <div class="applet-titlebar">
    <span class="applet-icon"></span>
    FNCRB — First National Credit Registry Bureau
    <span class="applet-btns"><span>_</span><span>&#9723;</span><span>&times;</span></span>
  </div>

  <div class="applet-menubar">
    <?php if ($u): ?>
      <a href="<?= $base ?>/dashboard">Dashboard</a>
      <?php if (App\Core\Rbac::can('inquiry.perform')): ?><a href="<?= $base ?>/inquiry">Inquiry</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('borrower.manage')): ?><a href="<?= $base ?>/borrowers">Borrowers</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('loan.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/loans">Portfolio</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('collateral.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/collateral">Sûretés</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('incident.view.own') || App\Core\Rbac::can('incident.view.all')): ?><a href="<?= $base ?>/incidents">Incidents</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('compliance.reports')): ?><a href="<?= $base ?>/compliance">Compliance</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('audit.view')): ?><a href="<?= $base ?>/audit">Audit</a><?php endif; ?>
      <a href="<?= $base ?>/logout" style="margin-left:auto">Exit</a>
    <?php else: ?>
      <a href="<?= $base ?>/login">Sign in</a>
    <?php endif; ?>
  </div>

  <?php if ($u): ?>
  <div class="applet-toolbar">
    <span>User: <b><?= $e($u['full_name']) ?></b></span> |
    <span>Role: <b><?= $e($u['role_code']) ?></b></span> |
    <span>Institution: <b><?= $e($u['institution_code'] ?? 'COBAC/BEAC (national)') ?></b></span> |
    <span>Session: <b id="applet-clock"><?= date('H:i:s') ?></b></span>
  </div>
  <?php endif; ?>

  <div class="applet-body">
    <?php if ($u): ?>
    <nav class="applet-side">
      <div class="side-title">Registry Modules</div>
      <a href="<?= $base ?>/dashboard">:: Dashboard</a>
      <?php if (App\Core\Rbac::can('inquiry.perform')): ?><a href="<?= $base ?>/inquiry">:: Credit Inquiry</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('borrower.manage')): ?><a href="<?= $base ?>/borrowers">:: Borrowers</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('loan.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/loans">:: Loan Portfolio</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('collateral.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/collateral">:: Collateral</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('incident.view.own') || App\Core\Rbac::can('incident.view.all')): ?><a href="<?= $base ?>/incidents">:: Incidents (CIP)</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('compliance.reports')): ?><a href="<?= $base ?>/compliance">:: Compliance</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('audit.view')): ?><a href="<?= $base ?>/audit">:: Audit Trail</a><?php endif; ?>
      <div class="side-title" style="margin-top:14px">Regulatory</div>
      <div style="font-size:10px;color:#444;padding:2px 8px;">COBAC · BEAC · CNEF · OHADA Uniform Act</div>
    </nav>
    <?php endif; ?>
    <main class="applet-main">
      <?= $content ?>
    </main>
  </div>

  <div class="applet-statusbar">
    <span class="cell grow">Ready — FNCRB Central Credit Registry</span>
    <span class="cell">Consent required for all inquiries</span>
    <span class="cell"><?= $u ? 'AUTHENTICATED' : 'GUEST' ?></span>
  </div>
</div>
<script src="<?= $base ?>/assets/vendor/bootstrap.bundle.min.js"></script>
<script>
setInterval(function () {
  var c = document.getElementById('applet-clock');
  if (c) c.textContent = new Date().toTimeString().slice(0, 8);
}, 1000);
</script>
<?= $pageScripts ?? '' ?>
</body>
</html>
