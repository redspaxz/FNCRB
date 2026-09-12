<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — FNCRB</title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css" rel="stylesheet">
<style>
  body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
  .login-card { width: 400px; background:#fff; border:1px solid var(--lumo-border); border-radius:14px;
                box-shadow: var(--lumo-shadow-md); overflow:hidden; }
  .login-head { background: linear-gradient(135deg,#1a66d6,#3f8ef7); color:#fff; padding:26px 28px; }
  .login-head .logo { width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.2);
                      display:inline-flex;align-items:center;justify-content:center;font-weight:800; }
</style>
</head>
<body>
<div class="login-card">
  <div class="login-head">
    <span class="logo">FN</span>
    <div style="margin-top:12px;font-weight:700;font-size:17px;">FNCRB</div>
    <div style="font-size:12.5px;opacity:.85;">First National Credit Registry Bureau</div>
  </div>
  <div style="padding:24px 28px 26px;">
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= $base ?>/login">
      <?= App\Core\Csrf::field() ?>
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= $e($email ?? '') ?>" required autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button class="btn btn-primary w-100">Sign in</button>
    </form>
    <p class="text-muted small mt-3 mb-0" style="font-size:11.5px;">Access restricted to authorized registry participants. All access attempts are logged and audited.</p>
  </div>
</div>
</body>
</html>
