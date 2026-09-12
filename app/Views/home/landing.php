<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>FNCRB — First National Credit Registry Bureau</title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css" rel="stylesheet">
<style>
  .hero { max-width: 860px; margin: 7vh auto; padding: 0 20px; }
  .hero-logo { width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#1a66d6,#37a3f5);
               color:#fff;font-weight:800;font-size:20px;display:inline-flex;align-items:center;justify-content:center;
               box-shadow: var(--lumo-shadow-md); }
</style>
</head>
<body>
<div class="hero">
  <span class="hero-logo">FN</span>
  <h1 style="font-size:28px;font-weight:800;margin-top:16px;">First National Credit Registry Bureau</h1>
  <p class="page-sub" style="font-size:15px;">Central Credit Registry for Cameroon (CEMAC) — mitigating cross-institutional credit risk across Category 1, 2 &amp; 3 Microfinance Institutions and commercial banks.</p>
  <p style="font-size:12.5px;color:var(--lumo-secondary);">Regulatory framework: <b style="color:var(--lumo-text)">COBAC · BEAC · CNEF · OHADA Uniform Act</b></p>
  <div class="row g-3 mt-3">
    <div class="col-md-4"><div class="card p-3 h-100"><b>Cross-Institution Exposure</b><small class="d-block mt-1">Consolidated borrower debt across all reporting institutions.</small></div></div>
    <div class="col-md-4"><div class="card p-3 h-100"><b>Consent-Gated Inquiries</b><small class="d-block mt-1">No credit check without recorded borrower consent.</small></div></div>
    <div class="col-md-4"><div class="card p-3 h-100"><b>Tamper-Proof Audit</b><small class="d-block mt-1">Hash-chained logs of every search, query and write.</small></div></div>
  </div>
  <a class="btn btn-primary btn-lg mt-4" href="<?= $base ?>/login">Sign in to the registry</a>
</div>
</body>
</html>
