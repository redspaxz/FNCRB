<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>FNCRB — First National Credit Registry Bureau</title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css" rel="stylesheet">
<link href="<?= $base ?>/assets/css/applet.css" rel="stylesheet">
</head>
<body>
<div class="applet-window">
  <div class="applet-titlebar">
    <span class="applet-icon"></span> FNCRB — First National Credit Registry Bureau
    <span class="applet-btns"><span>_</span><span>&#9723;</span><span>&times;</span></span>
  </div>
  <div class="applet-menubar"><a href="<?= $base ?>/login">Sign in</a></div>
  <div class="applet-main">
    <div class="titled" style="margin:-4px 0 10px;display:block;padding:4px 8px;font-weight:bold;border-bottom:1px solid #808080;background:linear-gradient(180deg,#e6e3dd,#cfcbc3)">Welcome — Centrale des Risques</div>
    <p>FNCRB mitigates cross-institutional credit risk across <b>Category 1, 2 &amp; 3</b> Microfinance Institutions and commercial banks in Cameroon (CEMAC).</p>
    <p style="font-size:11px;color:#333;">Regulatory framework: <b>COBAC · BEAC · CNEF · OHADA Uniform Act</b></p>
    <div class="row g-3 mt-2">
      <div class="col-md-4"><div class="card p-3 h-100"><b>Cross-Institution Exposure</b><small style="color:#333">Consolidated borrower debt before any new credit is issued.</small></div></div>
      <div class="col-md-4"><div class="card p-3 h-100"><b>Consent-Gated Inquiries</b><small style="color:#333">No credit check without recorded borrower consent.</small></div></div>
      <div class="col-md-4"><div class="card p-3 h-100"><b>Tamper-Proof Audit</b><small style="color:#333">Hash-chained logs of every search, query and write.</small></div></div>
    </div>
    <a class="btn mt-4" href="<?= $base ?>/login">Enter Registry &gt;&gt;</a>
  </div>
  <div class="applet-statusbar">
    <span class="cell grow">Applet ready</span>
    <span class="cell">COBAC/BEAC</span>
  </div>
</div>
</body>
</html>
