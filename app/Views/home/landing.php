<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); $t = fn($k) => App\Core\Lang::t($k);
$langSwitch = '?lang=' . (App\Core\Lang::lang() === 'fr' ? 'en' : 'fr');
?>
<!DOCTYPE html>
<html lang="<?= App\Core\Lang::lang() ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>FNCRB — <?= $t('app_name') ?></title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css?v=3" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css?v=3" rel="stylesheet">
</head>
<body>
<div class="hero">
  <span class="hero-logo">FN</span>
  <h1><?= $t('app_name') ?></h1>
  <p class="lead-sub"><?= $t('landing_lead') ?></p>
  <p class="reg-note"><?= $t('landing_reg') ?> <b>COBAC · BEAC · CNEF · OHADA</b></p>
  <p><a href="<?= $base ?>/<?= $langSwitch ?>"><?= App\Core\Lang::lang() === 'fr' ? 'English' : 'Français' ?></a></p>
  <div class="row g-3 mt-1">
    <div class="col-md-4"><div class="card p-3 h-100"><b><?= $t('landing_f1') ?></b><small class="d-block mt-1"><?= $t('landing_f1s') ?></small></div></div>
    <div class="col-md-4"><div class="card p-3 h-100"><b><?= $t('landing_f2') ?></b><small class="d-block mt-1"><?= $t('landing_f2s') ?></small></div></div>
    <div class="col-md-4"><div class="card p-3 h-100"><b><?= $t('landing_f3') ?></b><small class="d-block mt-1"><?= $t('landing_f3s') ?></small></div></div>
  </div>
  <a class="btn btn-primary btn-lg mt-4" href="<?= $base ?>/login"><?= $t('landing_cta') ?></a>
</div>
</body>
</html>
