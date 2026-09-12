<?php /** @var string $content @var array $u */ $u = App\Core\Auth::user(); $e = $data['e'] ?? fn($s) => htmlspecialchars((string)$s); $base = App\Core\Rbac::baseUrl();
$initials = $u ? mb_strtoupper(mb_substr($u['full_name'],0,1) . (mb_strpos($u['full_name'],' ') ? mb_substr($u['full_name'], mb_strpos($u['full_name'],' ')+1, 1) : '')) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title ?? 'FNCRB') ?> — FNCRB</title>
<meta name="csrf-token" content="<?= App\Core\Csrf::token() ?>">
<meta name="base-url" content="<?= $base ?>">
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css" rel="stylesheet">
</head>
<body>
<div class="vaadin-app">

  <header class="vaadin-appbar">
    <a class="brand" href="<?= $base ?>/dashboard"><span class="logo">FN</span> FNCRB</a>
    <nav class="app-tabs">
      <?php if ($u): ?>
        <a href="<?= $base ?>/dashboard">Dashboard</a>
        <?php if (App\Core\Rbac::can('inquiry.perform')): ?><a href="<?= $base ?>/inquiry">Inquiry</a><?php endif; ?>
        <?php if (App\Core\Rbac::can('borrower.manage')): ?><a href="<?= $base ?>/borrowers">Borrowers</a><?php endif; ?>
        <?php if (App\Core\Rbac::can('loan.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/loans">Portfolio</a><?php endif; ?>
        <?php if (App\Core\Rbac::can('compliance.reports')): ?><a href="<?= $base ?>/compliance">Compliance</a><?php endif; ?>
      <?php else: ?>
        <a href="<?= $base ?>/login">Sign in</a>
      <?php endif; ?>
    </nav>
    <?php if ($u): ?>
    <div class="userchip">
      <div class="meta text-end d-none d-md-block">
        <div class="who"><?= $e($u['full_name']) ?></div>
        <div class="sub"><?= $e($u['role_code']) ?> · <?= $e($u['institution_code'] ?? 'COBAC/BEAC') ?></div>
      </div>
      <span class="avatar"><?= $e($initials) ?></span>
      <a class="btn btn-sm" href="<?= $base ?>/logout">Sign out</a>
    </div>
    <?php endif; ?>
  </header>

  <div class="vaadin-shell">
    <?php if ($u): ?>
    <aside class="vaadin-nav">
      <div class="nav-section">Registry</div>
      <a href="<?= $base ?>/dashboard"><span class="ico">▦</span> Dashboard</a>
      <?php if (App\Core\Rbac::can('inquiry.perform')): ?><a href="<?= $base ?>/inquiry"><span class="ico">⌕</span> Credit Inquiry</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('borrower.manage')): ?><a href="<?= $base ?>/borrowers"><span class="ico">👤</span> Borrowers</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('loan.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/loans"><span class="ico">▤</span> Loan Portfolio</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('collateral.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/collateral"><span class="ico">⛨</span> Collateral</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('incident.view.own') || App\Core\Rbac::can('incident.view.all')): ?><a href="<?= $base ?>/incidents"><span class="ico">⚠</span> Incidents</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('compliance.reports')): ?><a href="<?= $base ?>/compliance"><span class="ico">▣</span> Compliance</a><?php endif; ?>
      <?php if (App\Core\Rbac::can('audit.view')): ?><a href="<?= $base ?>/audit"><span class="ico">☰</span> Audit Trail</a><?php endif; ?>
      <div class="nav-section">Regulatory</div>
      <div class="nav-note">COBAC · BEAC · CNEF · OHADA Uniform Act.<br>All inquiries require recorded borrower consent.</div>
    </aside>
    <?php endif; ?>

    <main class="vaadin-content">
      <?= $content ?>
    </main>
  </div>

</div>
<script src="<?= $base ?>/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="<?= $base ?>/assets/js/app.js"></script>
<?= $pageScripts ?? '' ?>
</body>
</html>
