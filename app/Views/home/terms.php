<?php $e = fn($s) => htmlspecialchars((string)$s); $base = App\Core\Rbac::baseUrl(); $t = fn($k) => App\Core\Lang::t($k); $L = App\Core\Lang::lang();
$clauses = [
  'en' => [
    ['Authorized use.', 'Access to this registry is restricted to institutions supervised under CEMAC regulations — Category 1, 2 and 3 Microfinance Institutions, commercial banks, and COBAC/BEAC supervisory staff. Credentials are personal and must not be shared.'],
    ['Mandatory consent.', 'Every credit inquiry must be preceded by a valid, recorded borrower consent (digital or physical). Consulting the registry without recorded consent exposes your institution to sanctions under COBAC rules and national data-protection law. The system refuses and audits consent-less inquiries.'],
    ['Data accuracy and reporting.', 'Participating institutions must report credit portfolios, repayment performance, payment incidents and collateral registrations completely and on schedule, in COBAC-standardized formats. Submitting knowingly false information is a regulatory offense.'],
    ['Confidentiality.', 'Registry data (borrower identities, exposures, scores, incidents) is confidential and may be used solely for credit risk assessment and supervisory purposes. Redistribution, resale or commercial exploitation is prohibited.'],
    ['Audit and accountability.', 'All searches, credit queries and data writes are recorded in a tamper-proof, hash-chained audit trail attributable to your account. You are responsible for all actions performed under your credentials.'],
    ['OHADA securities.', 'Collateral registrations must reflect genuine security interests under the OHADA Uniform Act. Attempts to register collateral already pledged elsewhere (double-pledging) are blocked and reported.'],
    ['Session security.', 'Sessions expire after inactivity. Report any suspected credential compromise to your administrator and to COBAC without delay.'],
    ['Amendments.', 'These terms may be updated to reflect new COBAC/BEAC/CNEF directives. Continued access after notice of amendment constitutes acceptance.'],
  ],
  'fr' => [
    ['Usage autorisé.', 'L’accès à ce registre est réservé aux institutions soumises à la réglementation CEMAC — institutions de microfinance de Catégories 1, 2 et 3, banques commerciales et personnel de supervision COBAC/BEAC. Les identifiants sont personnels et ne doivent pas être partagés.'],
    ['Consentement obligatoire.', 'Toute interrogation de crédit doit être précédée d’un consentement valide et enregistré de l’emprunteur (numérique ou physique). Consulter le registre sans consentement enregistré expose votre institution à des sanctions au titre des règles COBAC et de la loi nationale sur la protection des données. Le système refuse et audite les interrogations sans consentement.'],
    ['Exactitude des données et déclaration.', 'Les institutions participantes doivent déclarer intégralement et dans les délais les portefeuilles de crédit, la performance des remboursements, les incidents de paiement et les inscriptions de sûretés, selon les formats normalisés COBAC. La soumission sciemment de fausses informations constitue une infraction réglementaire.'],
    ['Confidentialité.', 'Les données du registre (identités des emprunteurs, expositions, scores, incidents) sont confidentielles et ne peuvent être utilisées qu’à des fins d’évaluation du risque de crédit et de supervision. La rediffusion, la revente ou l’exploitation commerciale sont interdites.'],
    ['Audit et responsabilité.', 'Toutes les recherches, interrogations de crédit et écritures sont consignées dans une piste d’audit infalsifiable, chaînée par hachage et attribuable à votre compte. Vous êtes responsable de toutes les actions effectuées avec vos identifiants.'],
    ['Sûretés OHADA.', 'Les inscriptions de sûretés doivent refléter de véritables garanties au sens de l’Acte Uniforme OHADA. Les tentatives d’inscrire un collatéral déjà gagé ailleurs (double nantissement) sont bloquées et signalées.'],
    ['Sécurité des sessions.', 'Les sessions expirent après une période d’inactivité. Signalez sans délai toute compromission suspecte de vos identifiants à votre administrateur et à la COBAC.'],
    ['Modifications.', 'Les présentes conditions peuvent être mises à jour pour refléter les nouvelles directives COBAC/BEAC/CNEF. La poursuite de l’accès après notification d’une modification vaut acceptation.'],
  ],
];
?>
<!DOCTYPE html>
<html lang="<?= $L ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $t('terms_title') ?> — FNCRB</title>
<link href="<?= $base ?>/assets/vendor/bootstrap.min.css?v=3" rel="stylesheet">
<link href="<?= $base ?>/assets/css/lumo.css?v=3" rel="stylesheet">
</head>
<body>
<div class="hero" style="max-width:760px;">
  <span class="hero-logo">FN</span>
  <h1 style="font-size:22px;"><?= $t('terms_title') ?></h1>
  <p class="reg-note"><?= $t('terms_version') ?></p>

  <div class="card p-4 mt-3" style="font-size:13px;line-height:1.65;">
    <?php foreach ($clauses[$L] as $i => [$head, $body]): ?>
      <p class="<?= $i === count($clauses[$L]) - 1 ? 'mb-0' : 'mb-3' ?>"><b><?= $i + 1 ?>. <?= $e($head) ?></b> <?= $e($body) ?></p>
    <?php endforeach; ?>
  </div>

  <div class="mt-4 d-flex gap-2">
    <a class="btn btn-primary" href="<?= $base ?>/login"><?= $t('back_login') ?></a>
    <button class="btn" onclick="window.close()"><?= $t('close_window') ?></button>
  </div>
</div>
</body>
</html>
