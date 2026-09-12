<?php $e = fn($s) => htmlspecialchars((string)$s); $base = App\Core\Rbac::baseUrl(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terms & Conditions — FNCRB</title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css?v=3" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css?v=3" rel="stylesheet">
</head>
<body>
<div class="hero" style="max-width:760px;">
  <span class="hero-logo">FN</span>
  <h1 style="font-size:22px;">Terms &amp; Conditions of Access</h1>
  <p class="reg-note">First National Credit Registry Bureau (FNCRB) — Central Credit Registry, Cameroon (CEMAC). Version T&amp;C-2026-09.</p>

  <div class="card p-4 mt-3" style="font-size:13px;line-height:1.65;">
    <p class="mb-3"><b>1. Authorized use.</b> Access to this registry is restricted to institutions supervised under CEMAC regulations — Category 1, 2 and 3 Microfinance Institutions, commercial banks, and COBAC/BEAC supervisory staff. Credentials are personal and must not be shared.</p>

    <p class="mb-3"><b>2. Mandatory consent.</b> Every credit inquiry must be preceded by a valid, recorded borrower consent (digital or physical). Consulting the registry without recorded consent exposes your institution to sanctions under COBAC rules and national data-protection law. The system refuses and audits consent-less inquiries.</p>

    <p class="mb-3"><b>3. Data accuracy and reporting.</b> Participating institutions must report credit portfolios, repayment performance, payment incidents and collateral registrations completely and on schedule, in COBAC-standardized formats. Submitting knowingly false information is a regulatory offense.</p>

    <p class="mb-3"><b>4. Confidentiality.</b> Registry data (borrower identities, exposures, scores, incidents) is confidential and may be used solely for credit risk assessment and supervisory purposes. Redistribution, resale or commercial exploitation is prohibited.</p>

    <p class="mb-3"><b>5. Audit and accountability.</b> All searches, credit queries and data writes are recorded in a tamper-proof, hash-chained audit trail attributable to your account. You are responsible for all actions performed under your credentials.</p>

    <p class="mb-3"><b>6. OHADA securities.</b> Collateral registrations must reflect genuine security interests under the OHADA Uniform Act. Attempts to register collateral already pledged elsewhere (double-pledging) are blocked and reported.</p>

    <p class="mb-3"><b>7. Session security.</b> Sessions expire after inactivity. Report any suspected credential compromise to your administrator and to COBAC without delay.</p>

    <p class="mb-0"><b>8. Amendments.</b> These terms may be updated to reflect new COBAC/BEAC/CNEF directives. Continued access after notice of amendment constitutes acceptance.</p>
  </div>

  <div class="mt-4 d-flex gap-2">
    <a class="btn btn-primary" href="<?= $base ?>/login">Back to sign in</a>
    <button class="btn" onclick="window.close()">Close window</button>
  </div>
</div>
</body>
</html>
