<?php /** @var string $content @var array $u */ $u = App\Core\Auth::user(); $e = $data['e'] ?? fn($s) => htmlspecialchars((string)$s); $base = App\Core\Rbac::baseUrl(); $t = fn($k) => App\Core\Lang::t($k);
$initials = $u ? mb_strtoupper(mb_substr($u['full_name'],0,1) . (mb_strpos($u['full_name'],' ') ? mb_substr($u['full_name'], mb_strpos($u['full_name'],' ')+1, 1) : '')) : '';
$otherLang = App\Core\Lang::lang() === 'fr' ? 'en' : 'fr';
$langSwitch = preg_replace('/([?&])lang=[^&]*/', '', $_SERVER['REQUEST_URI'] ?? '/');
$langSwitch .= (str_contains($langSwitch, '?') ? '&' : '?') . 'lang=' . $otherLang;
?>
<!DOCTYPE html>
<html lang="<?= App\Core\Lang::lang() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title ?? 'FNCRB') ?> — FNCRB</title>
<meta name="csrf-token" content="<?= App\Core\Csrf::token() ?>">
<meta name="base-url" content="<?= $base ?>">
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css?v=4" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css?v=5" rel="stylesheet">
</head>
<body>
<div class="vaadin-app">

  <header class="vaadin-appbar">
    <a class="brand" href="<?= $base ?>/dashboard"><span class="logo">FINACREB</span></a>
    <nav class="app-tabs">
      <?php if ($u): ?>
        <a href="<?= $base ?>/dashboard"><?= $t('nav_dashboard') ?></a>
        <?php if (App\Core\Rbac::can('inquiry.perform')): ?><a href="<?= $base ?>/inquiry"><?= $t('nav_inquiry') ?></a><?php endif; ?>
        <?php if (App\Core\Rbac::can('borrower.manage')): ?><a href="<?= $base ?>/borrowers"><?= $t('nav_borrowers') ?></a><?php endif; ?>
        <?php if (App\Core\Rbac::can('loan.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/loans"><?= $t('nav_loans') ?></a><?php endif; ?>
        <?php if (App\Core\Rbac::can('compliance.reports')): ?><a href="<?= $base ?>/compliance"><?= $t('nav_compliance') ?></a><?php endif; ?>
      <?php else: ?>
        <a href="<?= $base ?>/login"><?= $t('sign_in') ?></a>
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
      <a class="btn btn-sm" href="<?= $base ?>/logout"><?= $t('sign_out') ?></a>
    </div>
    <?php endif; ?>
  </header>

  <div class="vaadin-shell">
    <?php if ($u): ?>
    <aside class="vaadin-nav">
      <div class="nav-section"><?= $t('nav_section_registry') ?></div>
      <a href="<?= $base ?>/dashboard"><span class="ico">▦</span> <?= $t('nav_dashboard') ?></a>
      <?php if (App\Core\Rbac::can('inquiry.perform')): ?><a href="<?= $base ?>/inquiry"><span class="ico">⌕</span> <?= $t('nav_inquiry') ?></a><?php endif; ?>
      <?php if (App\Core\Rbac::can('borrower.manage')): ?><a href="<?= $base ?>/borrowers"><span class="ico">👤</span> <?= $t('nav_borrowers') ?></a><?php endif; ?>
      <?php if (App\Core\Rbac::can('loan.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/loans"><span class="ico">▤</span> <?= $t('nav_loans') ?></a><?php endif; ?>
      <?php if (App\Core\Rbac::can('collateral.view.own') || App\Core\Rbac::can('loan.view.all')): ?><a href="<?= $base ?>/collateral"><span class="ico">⛨</span> <?= $t('nav_collateral') ?></a><?php endif; ?>
      <?php if (App\Core\Rbac::can('incident.view.own') || App\Core\Rbac::can('incident.view.all')): ?><a href="<?= $base ?>/incidents"><span class="ico">⚠</span> <?= $t('nav_incidents') ?></a><?php endif; ?>
      <?php if (App\Core\Rbac::can('compliance.reports')): ?><a href="<?= $base ?>/compliance"><span class="ico">▣</span> <?= $t('nav_compliance') ?></a><?php endif; ?>
      <?php if (App\Core\Rbac::can('audit.view')): ?><a href="<?= $base ?>/audit"><span class="ico">☰</span> <?= $t('nav_audit') ?></a><?php endif; ?>
      <div class="nav-section"><?= $t('nav_section_regulatory') ?></div>
      <div class="nav-note">COBAC · BEAC · CNEF · OHADA. <?= $t('status_consent') ?>.</div>
    </aside>
    <?php endif; ?>

    <main class="vaadin-content">
      <?= $content ?>
    </main>
  </div>

</div>
<script src="<?= $base ?>/assets/vendor/bootstrap.bundle.min.js?v=3"></script>
<script src="<?= $base ?>/assets/js/app.js?v=3"></script>
<?= $pageScripts ?? '' ?>
</body>
</html>
