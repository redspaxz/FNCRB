<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — FNCRB</title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= $base ?>/assets/css/applet.css" rel="stylesheet">
</head>
<body>
<div class="applet-window" style="max-width:460px;margin-top:10vh">
  <div class="applet-titlebar">
    <span class="applet-icon"></span> FNCRB — Sign in
    <span class="applet-btns"><span>_</span><span>&#9723;</span><span>&times;</span></span>
  </div>
  <div class="applet-menubar"><a href="#">File</a><a href="#">Help</a></div>
  <div class="applet-main">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= $base ?>/login">
      <?= App\Core\Csrf::field() ?>
      <div class="mb-3">
        <label class="form-label">User ID (email)</label>
        <input type="email" name="email" class="form-control" value="<?= $e($email ?? '') ?>" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button class="btn w-100">Sign in</button>
    </form>
  </div>
  <div class="applet-statusbar">
    <span class="cell grow">Access restricted to authorized participants</span>
    <span class="cell">SECURE</span>
  </div>
</div>
</body>
</html>
