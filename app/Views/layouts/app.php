<?php /** @var string $content @var array $u */ $u = App\Core\Auth::user(); $e = $data['e'] ?? fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); $base = App\Core\Rbac::baseUrl(); $t = fn($k) => App\Core\Lang::t($k);
$can = fn($p) => App\Core\Rbac::can($p);
$initials = $u ? mb_strtoupper(mb_substr($u['full_name'],0,1) . (mb_strpos($u['full_name'],' ') ? mb_substr($u['full_name'], mb_strpos($u['full_name'],' ')+1, 1) : '')) : '';
$otherLang = App\Core\Lang::lang() === 'fr' ? 'en' : 'fr';
$langSwitch = preg_replace('/([?&])lang=[^&]*/', '', $_SERVER['REQUEST_URI'] ?? '/');
$langSwitch .= (str_contains($langSwitch, '?') ? '&' : '?') . 'lang=' . $otherLang;
$flashes = $u ? App\Core\Flash::take() : [];
?>
<!DOCTYPE html>
<html lang="<?= App\Core\Lang::lang() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title ?? 'FNCRB') ?> — FNCRB</title>
<meta name="csrf-token" content="<?= App\Core\Csrf::token() ?>">
<meta name="base-url" content="<?= $e($base) ?>">
<link href="<?= $e($base) ?>/assets/vendor/bootstrap.min.css?v=4" rel="stylesheet">
<link href="<?= $e($base) ?>/assets/css/lumo.css?v=9" rel="stylesheet">
</head>
<body>
<div class="vaadin-app">

  <header class="vaadin-appbar">
    <a class="brand" href="<?= $e($base) ?>/dashboard"><span class="logo">FINACREB</span></a>
    <nav class="app-tabs">
      <?php if ($u): ?>
        <a href="<?= $e($base) ?>/dashboard"><?= $t('nav_dashboard') ?></a>
        <?php if ($can('inquiry.perform')): ?><a href="<?= $e($base) ?>/inquiry"><?= $t('nav_inquiry') ?></a><?php endif; ?>
        <?php if ($can('borrower.manage')): ?><a href="<?= $e($base) ?>/borrowers"><?= $t('nav_borrowers') ?></a><?php endif; ?>
        <?php if ($can('loan.view.own') || $can('loan.view.all')): ?><a href="<?= $e($base) ?>/loans"><?= $t('nav_loans') ?></a><?php endif; ?>
        <?php if ($can('compliance.reports')): ?><a href="<?= $e($base) ?>/compliance"><?= $t('nav_compliance') ?></a><?php endif; ?>
      <?php else: ?>
        <a href="<?= $e($base) ?>/login"><?= $t('sign_in') ?></a>
      <?php endif; ?>
    </nav>
    <a class="lang-switch" href="<?= $e($langSwitch) ?>" title="<?= $t('language') ?>"><?= strtoupper($otherLang) === 'EN' ? '🇬🇧 EN' : '🇫🇷 FR' ?></a>
    <?php if ($u): ?>
    <div class="userchip">
      <div class="meta text-end d-none d-md-block">
        <div class="who"><?= $e($u['full_name']) ?></div>
        <div class="sub"><?= $e($u['role_code']) ?> · <?= $e($u['institution_code'] ?? 'COBAC/BEAC') ?></div>
      </div>
      <span class="avatar"><?= $e($initials) ?></span>
      <form method="post" action="<?= $e($base) ?>/logout" class="d-inline"><?= App\Core\Csrf::field() ?><button class="btn btn-sm"><?= $t('sign_out') ?></button></form>
    </div>
    <?php endif; ?>
  </header>

  <div class="vaadin-shell">
    <?php if ($u): ?>
    <aside class="vaadin-nav">
      <div class="nav-section"><?= $t('nav_section_registry') ?></div>
      <a href="<?= $e($base) ?>/dashboard"><span class="ico">▦</span> <?= $t('nav_dashboard') ?></a>
      <?php if ($can('inquiry.perform')): ?><a href="<?= $e($base) ?>/inquiry"><span class="ico">⌕</span> <?= $t('nav_inquiry') ?></a><?php endif; ?>
      <?php if ($can('inquiry.view.own') || $can('inquiry.view.all')): ?><a href="<?= $e($base) ?>/inquiries"><span class="ico">≡</span> <?= $t('nav_inquiries') ?></a><?php endif; ?>
      <?php if ($can('inquiry.perform') || $can('inquiry.view.own') || $can('inquiry.view.all')): ?><a href="<?= $e($base) ?>/consents"><span class="ico">✎</span> <?= $t('nav_consents') ?></a><?php endif; ?>
      <?php if ($can('borrower.manage') || $can('disputes.work')): ?><a href="<?= $e($base) ?>/<?= $can('borrower.manage') ? 'borrowers' : 'borrowers/report' ?>"><span class="ico">👤</span> <?= $t('nav_borrowers') ?></a><?php endif; ?>
      <?php if ($can('loan.view.own') || $can('loan.view.all')): ?><a href="<?= $e($base) ?>/loans"><span class="ico">▤</span> <?= $t('nav_loans') ?></a><?php endif; ?>
      <?php if ($can('collateral.view.own') || $can('loan.view.all')): ?><a href="<?= $e($base) ?>/collateral"><span class="ico">⛨</span> <?= $t('nav_collateral') ?></a><?php endif; ?>
      <?php if ($can('incident.view.own') || $can('incident.view.all')): ?><a href="<?= $e($base) ?>/incidents"><span class="ico">⚠</span> <?= $t('nav_incidents') ?></a><?php endif; ?>
      <?php if ($can('compliance.reports')): ?><a href="<?= $e($base) ?>/compliance"><span class="ico">▣</span> <?= $t('nav_compliance') ?></a><?php endif; ?>
      <?php if ($can('audit.view')): ?><a href="<?= $e($base) ?>/audit"><span class="ico">☰</span> <?= $t('nav_audit') ?></a><?php endif; ?>
      <?php if ($can('analytics.view')): ?><a href="<?= $e($base) ?>/analytics"><span class="ico">📈</span> <?= $t('nav_analytics') ?></a><?php endif; ?>
      <?php if ($can('disputes.file') || $can('disputes.work')): ?><a href="<?= $e($base) ?>/disputes"><span class="ico">⚖</span> <?= $t('nav_disputes') ?></a><?php endif; ?>
      <?php if ($can('users.manage') || $can('institutions.manage') || $can('borrower.reconcile')): ?>
      <div class="nav-section"><?= $t('nav_section_admin') ?></div>
      <?php if ($can('users.manage')): ?><a href="<?= $e($base) ?>/users"><span class="ico">🛂</span> <?= $t('nav_users') ?></a><?php endif; ?>
      <?php if ($can('institutions.manage')): ?><a href="<?= $e($base) ?>/institutions"><span class="ico">🏛</span> <?= $t('nav_institutions') ?></a><?php endif; ?>
      <?php if ($can('borrower.reconcile')): ?><a href="<?= $e($base) ?>/reconciliation"><span class="ico">⇄</span> <?= $t('nav_reconciliation') ?></a><?php endif; ?>
      <?php endif; ?>
      <div class="nav-section"><?= $t('nav_account') ?></div>
      <a href="<?= $e($base) ?>/account"><span class="ico">🔑</span> <?= $t('nav_account') ?></a>
      <div class="nav-section"><?= $t('nav_section_regulatory') ?></div>
      <div class="nav-note">COBAC · BEAC · CNEF · OHADA. <?= $t('status_consent') ?>.</div>
    </aside>
    <?php endif; ?>

    <main class="vaadin-content">
      <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= $e($f['type']) ?>"><?= $e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>

</div>
<script src="<?= $e($base) ?>/assets/vendor/bootstrap.bundle.min.js?v=3"></script>
<script src="<?= $e($base) ?>/assets/js/app.js?v=4"></script>
<script src="<?= $e($base) ?>/assets/js/charts.js?v=5"></script>
<?= $pageScripts ?? '' ?>
</body>
</html>
