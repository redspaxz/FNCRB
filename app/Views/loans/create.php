<?php $e = $data['e']; ?>
<h3>Submit loan record</h3>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="<?= App\Core\Rbac::baseUrl() ?>/loans" class="card p-4 bg-white">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label">Borrower *</label>
      <select name="borrower_id" class="form-select" required>
        <option value="">— select —</option>
        <?php foreach ($borrowers as $b): ?>
          <option value="<?= (int)$b['id'] ?>"><?= $e($b['full_name']) ?> [<?= $e($b['master_ref']) ?>]</option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label">Contract reference *</label><input name="contract_ref" class="form-control" maxlength="50" required></div>
    <div class="col-md-4"><label class="form-label">Loan type *</label>
      <select name="loan_type" class="form-select" required>
        <?php foreach (['CONSUMER','MORTGAGE','BUSINESS','MICRO','PROJECT','OVERDRAFT','LEASE'] as $t): ?><option><?= $t ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label">Principal (XAF) *</label><input type="number" name="principal_xaf" class="form-control" min="1" required></div>
    <div class="col-md-3"><label class="form-label">Outstanding (XAF) *</label><input type="number" name="outstanding_xaf" class="form-control" min="0" required></div>
    <div class="col-md-3"><label class="form-label">Monthly payment (XAF)</label><input type="number" name="monthly_payment_xaf" class="form-control" min="0" value="0"></div>
    <div class="col-md-3"><label class="form-label">Interest rate (%)</label><input type="number" step="0.001" name="interest_rate_pct" class="form-control" value="0"></div>
    <div class="col-md-3"><label class="form-label">Start date *</label><input type="date" name="start_date" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label">Maturity date *</label><input type="date" name="maturity_date" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label">Instalments</label><input type="number" name="instalments_total" class="form-control" min="0" value="0"></div>
    <div class="col-md-2"><label class="form-label">Past-due instal.</label><input type="number" name="instalments_past_due" class="form-control" min="0" value="0"></div>
    <div class="col-md-2"><label class="form-label">Days past due</label><input type="number" name="days_past_due" class="form-control" min="0" value="0"></div>
    <div class="col-md-3"><label class="form-label">Status</label>
      <select name="status" class="form-select"><option>ACTIVE</option><option>SETTLED</option><option>WRITTEN_OFF</option><option>RESTRUCTURED</option></select></div>
  </div>
  <p class="text-muted small mt-3">COBAC asset classification and provisioning are computed automatically from arrears.</p>
  <button class="btn btn-primary">Submit</button>
</form>
