<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); $t = fn($k) => App\Core\Lang::t($k);
$langSwitch = '?lang=' . (App\Core\Lang::lang() === 'fr' ? 'en' : 'fr');
?>
<!DOCTYPE html>
<html lang="<?= App\Core\Lang::lang() ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $t('sign_in') ?> — FNCRB</title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css?v=4" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css?v=5" rel="stylesheet">
</head>
<body class="login-page">
<div class="login-card">
  <div class="login-head">
    <div class="login-title"><?= $t('login_title') ?></div>
    <div class="login-sub"><?= $t('login_sub') ?></div>
    <div style="margin-top:6px;"><a href="<?= $base ?>/login<?= $langSwitch ?>" style="color:#fff;font-size:11.5px;opacity:.9;text-decoration:underline;"><?= App\Core\Lang::lang() === 'fr' ? 'English' : 'Français' ?></a></div>
  </div>
  <div class="login-body">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= $base ?>/login">
      <?= App\Core\Csrf::field() ?>
      <div class="mb-2">
        <label class="form-label"><?= $t('email') ?></label>
        <input type="email" name="email" class="form-control" value="<?= $e($email ?? '') ?>" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label"><?= $t('password') ?></label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="terms_accepted" id="termsAccepted" value="1" required>
        <label class="form-check-label" for="termsAccepted" style="font-weight:400;font-size:12.5px;">
          <?= $t('login_terms_label') ?> <a href="<?= $base ?>/terms" target="_blank" rel="noopener"><?= $t('login_terms_link') ?></a> <?= $t('login_terms_suffix') ?>
        </label>
      </div>
      <button class="btn btn-primary w-100" id="loginBtn" disabled><?= $t('sign_in') ?></button>
    </form>
    <p class="text-muted small mt-2 mb-0 login-note"><?= $t('login_note') ?></p>
  </div>
</div>
<script src="<?= $base ?>/assets/js/app.js?v=3"></script>
</body>
</html>
