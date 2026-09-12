<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — FNCRB</title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css?v=3" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css?v=3" rel="stylesheet">
</head>
<body class="login-page">
<div class="login-card">
  <div class="login-head">
    <div class="login-title">First National Credit Registry Bureau</div>
    <div class="login-sub">Central Credit Registry — Cameroon (CEMAC)</div>
  </div>
  <div class="login-body">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= $base ?>/login">
      <?= App\Core\Csrf::field() ?>
      <div class="mb-2">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= $e($email ?? '') ?>" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="terms_accepted" id="termsAccepted" value="1" required>
        <label class="form-check-label" for="termsAccepted" style="font-weight:400;font-size:12.5px;">
          I have read and accept the <a href="<?= $base ?>/terms" target="_blank" rel="noopener">Terms &amp; Conditions</a> of the FNCRB Central Credit Registry.
        </label>
      </div>
      <button class="btn btn-primary w-100" id="loginBtn" disabled>Sign in</button>
    </form>
    <p class="text-muted small mt-2 mb-0 login-note">Access restricted to authorized registry participants. All access attempts are logged and audited.</p>
  </div>
</div>
<script src="<?= $base ?>/assets/js/app.js?v=3"></script>
</body>
</html>
