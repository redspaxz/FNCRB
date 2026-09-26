<?php $e = $data['e']; $o = fn($k, $d = '') => $e(is_scalar($old[$k] ?? null) && $old[$k] !== '' ? $old[$k] : $d); ?>
<h3>Submit / update loan record</h3>
<p class="page-sub">Re-submitting an existing contract reference updates it; the previous reporting period is kept as payment history.</p>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="<?= $e(App\Core\Rbac::baseUrl()) ?>/loans" class="card p-4 bg-white">
  <?= App\Core\Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-4"><label class="form-label">Borrower identifier *</label>
      <input name="borrower_identifier" class="form-control" maxlength="40" required value="<?= $o('borrower_identifier') ?>" placeholder="CNI, NIU, coop ID or FNB… ref"></div>
    <div class="col-md-4"><label class="form-label">Contract reference *</label><input name="contract_ref" class="form-control" maxlength="50" required value="<?= $o('contract_ref') ?>"></div>
    <div class="col-md-4"><label class="form-label">Loan type *</label>
      <select name="loan_type" class="form-select" required>
        <?php foreach (App\Services\IngestionService::LOAN_TYPES as $t): ?><option<?= ($old['loan_type'] ?? '') === $t ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label">Principal (XAF) *</label><input type="number" name="principal_xaf" class="form-control" min="1" required value="<?= $o('principal_xaf') ?>"></div>
    <div class="col-md-3"><label class="form-label">Outstanding (XAF) *</label><input type="number" name="outstanding_xaf" class="form-control" min="0" required value="<?= $o('outstanding_xaf') ?>"></div>
    <div class="col-md-3"><label class="form-label">Monthly payment (XAF)</label><input type="number" name="monthly_payment_xaf" class="form-control" min="0" value="<?= $o('monthly_payment_xaf', '0') ?>"></div>
    <div class="col-md-3"><label class="form-label">Interest rate (%)</label><input type="number" step="0.001" min="0" max="999.999" name="interest_rate_pct" class="form-control" value="<?= $o('interest_rate_pct', '0') ?>"></div>
    <div class="col-md-3"><label class="form-label">Start date *</label><input type="date" name="start_date" class="form-control" required value="<?= $o('start_date') ?>"></div>
    <div class="col-md-3"><label class="form-label">Maturity date *</label><input type="date" name="maturity_date" class="form-control" required value="<?= $o('maturity_date') ?>"></div>
    <div class="col-md-3"><label class="form-label">Reporting period date *</label><input type="date" name="reported_at" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= $o('reported_at', date('Y-m-d')) ?>"></div>
    <div class="col-md-3"><label class="form-label">Status</label>
      <select name="status" class="form-select"><?php foreach (App\Services\IngestionService::LOAN_STATUS as $s): ?><option<?= ($old['status'] ?? '') === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Instalments</label><input type="number" name="instalments_total" class="form-control" min="0" value="<?= $o('instalments_total', '0') ?>"></div>
    <div class="col-md-2"><label class="form-label">Past-due instal.</label><input type="number" name="instalments_past_due" class="form-control" min="0" value="<?= $o('instalments_past_due', '0') ?>"></div>
    <div class="col-md-2"><label class="form-label">Days past due</label><input type="number" name="days_past_due" class="form-control" min="0" value="<?= $o('days_past_due', '0') ?>"></div>
  </div>
  <p class="text-muted small mt-3">COBAC asset classification and provisioning are computed automatically from arrears. A SETTLED loan must have outstanding 0.</p>
  <button class="btn btn-primary">Submit</button>
</form>
