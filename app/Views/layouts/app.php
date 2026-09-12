<?php /** @var string $content @var array $u */ $u = App\Core\Auth::user(); $e = $data['e'] ?? fn($s) => htmlspecialchars((string)$s); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title ?? 'FNCRB') ?> — FNCRB</title>
<meta name="csrf-token" content="<?= App\Core\Csrf::token() ?>">
<link href="<?= App\Core\Rbac::baseUrl() ?>/assets/vendor/bootstrap.min.css" rel="stylesheet">
<style>
  body { background:#f4f6f9; }
  .navbar-brand b { color:#0d6efd; }
  .sidebar { min-height: calc(100vh - 56px); background:#1b2838; }
  .sidebar .nav-link { color:#c8d3e0; border-radius:.375rem; margin:.1rem .5rem; }
  .sidebar .nav-link.active, .sidebar .nav-link:hover { color:#fff; background:#2c3e50; }
  footer { font-size:.8rem; color:#888; }
  .grade-A{color:#198754}.grade-B{color:#6c757d}.grade-C{color:#adb5bd}.grade-D{color:#fd7e14}.grade-E{color:#dc3545}
  .cls-HEALTHY{color:#198754}.cls-WATCH{color:#0dcaf0}.cls-UNCERTAIN{color:#fd7e14}.cls-DOUBTFUL{color:#dc3545}.cls-COMPROMISED{color:#8b0000;font-weight:700}
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark px-3">
  <a class="navbar-brand" href="<?= App\Core\Rbac::baseUrl() ?>/dashboard"><b>FNCRB</b> · First National Credit Registry Bureau</a>
  <?php if ($u): ?>
  <div class="d-flex text-light small align-items-center gap-3">
    <span><?= $e($u['full_name']) ?> · <?= $e($u['role_code']) ?><?= $u['institution_code'] ? ' · ' . $e($u['institution_code']) : '' ?></span>
    <a class="btn btn-sm btn-outline-light" href="<?= App\Core\Rbac::baseUrl() ?>/logout">Sign out</a>
  </div>
  <?php endif; ?>
</nav>
<div class="container-fluid">
<div class="row">
  <?php if ($u): ?>
  <nav class="col-md-2 d-none d-md-block sidebar py-3">
    <ul class="nav flex-column">
      <li class="nav-item"><a class="nav-link" href="<?= App\Core\Rbac::baseUrl() ?>/dashboard">Dashboard</a></li>
      <?php if (App\Core\Rbac::can('inquiry.perform')): ?>
      <li class="nav-item"><a class="nav-link" href="<?= App\Core\Rbac::baseUrl() ?>/inquiry">Credit Inquiry</a></li>
      <?php endif; ?>
      <?php if (App\Core\Rbac::can('borrower.manage')): ?>
      <li class="nav-item"><a class="nav-link" href="<?= App\Core\Rbac::baseUrl() ?>/borrowers">Borrowers</a></li>
      <?php endif; ?>
      <?php if (App\Core\Rbac::can('loan.view.own') || App\Core\Rbac::can('loan.view.all')): ?>
      <li class="nav-item"><a class="nav-link" href="<?= App\Core\Rbac::baseUrl() ?>/loans">Loan Portfolio</a></li>
      <?php endif; ?>
      <?php if (App\Core\Rbac::can('collateral.view.own')): ?>
      <li class="nav-item"><a class="nav-link" href="<?= App\Core\Rbac::baseUrl() ?>/collateral">Collateral (Sûretés)</a></li>
      <?php endif; ?>
      <?php if (App\Core\Rbac::can('incident.view.own') || App\Core\Rbac::can('incident.view.all')): ?>
      <li class="nav-item"><a class="nav-link" href="<?= App\Core\Rbac::baseUrl() ?>/incidents">Payment Incidents</a></li>
      <?php endif; ?>
      <?php if (App\Core\Rbac::can('compliance.reports')): ?>
      <li class="nav-item"><a class="nav-link" href="<?= App\Core\Rbac::baseUrl() ?>/compliance">Compliance & Reporting</a></li>
      <?php endif; ?>
      <?php if (App\Core\Rbac::can('audit.view')): ?>
      <li class="nav-item"><a class="nav-link" href="<?= App\Core\Rbac::baseUrl() ?>/audit">Audit Trail</a></li>
      <?php endif; ?>
    </ul>
  </nav>
  <?php endif; ?>
  <main class="col-md-10 col-lg-10 ms-sm-auto px-md-4 py-3">
    <?= $content ?>
    <footer class="mt-5 mb-3">
      FNCRB — Central Credit Registry (Cameroon / CEMAC) · Regulatory framework: COBAC · BEAC · CNEF · OHADA Uniform Act.
      Every credit inquiry requires recorded borrower consent.
    </footer>
  </main>
</div>
</div>
<script src="<?= App\Core\Rbac::baseUrl() ?>/assets/vendor/bootstrap.bundle.min.js"></script>
<?= $pageScripts ?? '' ?>
</body>
</html>
