<?php $e = $data['e']; /** @var array $stats */ ?>
<h3>Dashboard</h3>
<div class="row g-3 my-1">
  <div class="col-md-4"><div class="card text-bg-primary p-3"><small>Institutions reporting</small><h2><?= (int)$stats['institutions'] ?></h2></div></div>
  <div class="col-md-4"><div class="card text-bg-success p-3"><small>Registered borrowers</small><h2><?= (int)$stats['borrowers'] ?></h2></div></div>
  <div class="col-md-4"><div class="card text-bg-dark p-3"><small>Active loans</small><h2><?= (int)$stats['active_loans'] ?></h2></div></div>
  <div class="col-md-4"><div class="card p-3 border"><small>Outstanding portfolio (XAF)</small><h2><?= number_format((float)$stats['outstanding']) ?></h2></div></div>
  <div class="col-md-4"><div class="card text-bg-danger p-3"><small>NPL loans (Doubtful/Compromised)</small><h2><?= (int)$stats['npl_loans'] ?></h2></div></div>
  <div class="col-md-4"><div class="card text-bg-warning p-3"><small>Open payment incidents (CIP)</small><h2><?= (int)$stats['open_incidents'] ?></h2></div></div>
</div>
<?php if ($isRegulator): ?>
<div class="alert alert-info mt-3">Regulator view (COBAC/BEAC): figures cover all reporting institutions.</div>
<?php endif; ?>
<div class="mt-4">
  <h5>Mandatory workflow reminder</h5>
  <p class="text-muted">Category 1 MFIs must query the registry before approving member credit. Category 2 micro-banks perform real-time API inquiries during underwriting and submit borrower performance and dishonored instruments. Category 3 institutions run comprehensive credit history checks for uncollateralized project finance.</p>
</div>
