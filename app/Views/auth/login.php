<?php $e = $data['e']; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — FNCRB</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f4f6f9;display:flex;align-items:center;min-height:100vh}</style>
</head>
<body>
<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-5">
      <div class="card shadow">
        <div class="card-body p-4">
          <h4 class="mb-3"><b>FNCRB</b> Sign in</h4>
          <?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
          <form method="post" action="<?= App\Core\Rbac::baseUrl() ?>/login">
            <?= App\Core\Csrf::field() ?>
            <div class="mb-3">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= $e($email ?? '') ?>" required autofocus>
            </div>
            <div class="mb-3">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" required>
            </div>
            <button class="btn btn-primary w-100">Sign in</button>
          </form>
          <p class="text-muted small mt-3 mb-0">Access restricted to authorized registry participants. All access attempts are logged and audited.</p>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
