<?php $e = $data['e']; $base = App\Core\Rbac::baseUrl(); ?>
<h3>My Account</h3>
<p class="page-sub"><?= $e($me['email']) ?> · <b><?= $me['totp_secret'] ? '2FA active' : '2FA not enabled' ?></b></p>

<?php if (!empty($mustChange)): ?>
<div class="alert alert-warning"><b>Password change required.</b> Your account was issued a one-time password. Choose a new password to continue using the registry.</div>
<?php endif; ?>

<div class="row g-4 mt-1">
  <div class="col-md-5">
    <div class="card p-4">
      <div class="titled">Change password</div>
      <?php if (!empty($msg)): ?><div class="alert alert-success"><?= $e($msg) ?></div><?php endif; ?>
      <?php if (!empty($error)): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= $e($base) ?>/account/password">
        <?= App\Core\Csrf::field() ?>
        <div class="mb-2"><label class="form-label" for="cur-pw">Current password</label>
          <input id="cur-pw" type="password" name="current_password" class="form-control" autocomplete="current-password" required></div>
        <div class="mb-2"><label class="form-label" for="new-pw">New password</label>
          <input id="new-pw" type="password" name="new_password" class="form-control" autocomplete="new-password" required minlength="10"></div>
        <div class="mb-3"><label class="form-label" for="conf-pw">Confirm new password</label>
          <input id="conf-pw" type="password" name="confirm_password" class="form-control" autocomplete="new-password" required></div>
        <p class="text-muted" style="font-size:11.5px;">Minimum 10 characters, with upper case, lower case and a digit. Changing it signs out your other sessions.</p>
        <button class="btn btn-primary">Update password</button>
      </form>
    </div>
  </div>

  <div class="col-md-7">
    <div class="card p-4">
      <div class="titled">Two-factor authentication (TOTP)</div>
      <?php if (!empty($msg2)): ?><div class="alert alert-success"><?= $e($msg2) ?></div><?php endif; ?>
      <?php if (!empty($error2)): ?><div class="alert alert-danger"><?= $e($error2) ?></div><?php endif; ?>

      <?php if ($me['totp_secret']): ?>
        <p style="font-size:13px;">Your account requires a one-time code from your authenticator app at every sign-in.</p>
        <form method="post" action="<?= $e($base) ?>/account/2fa/disable" class="row g-2" style="max-width:460px;">
          <?= App\Core\Csrf::field() ?>
          <div class="col-6"><input type="password" name="current_password" class="form-control" placeholder="Current password" autocomplete="current-password" required></div>
          <div class="col-4"><input type="text" name="otp" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="Code" autocomplete="one-time-code" required></div>
          <div class="col-2"><button class="btn">Disable</button></div>
          <div class="col-12 text-muted" style="font-size:11.5px;">Re-authentication is required to remove the second factor.</div>
        </form>
      <?php elseif ($pendingSecret): ?>
        <p style="font-size:13px;"><b>Step 1.</b> Add this secret to your authenticator app (Google Authenticator, Authy, FreeOTP…), or open the otpauth link on this device:</p>
        <p><code style="font-size:13px;word-break:break-all;"><?= $e($pendingSecret) ?></code></p>
        <p style="font-size:12px;word-break:break-all;"><a href="<?= $e($otpauthUri) ?>"><?= $e($otpauthUri) ?></a></p>
        <p style="font-size:13px;"><b>Step 2.</b> Enter the current 6-digit code to activate:</p>
        <form method="post" action="<?= $e($base) ?>/account/2fa/confirm" class="d-flex gap-2" style="max-width:340px;">
          <?= App\Core\Csrf::field() ?>
          <input type="text" name="otp" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" autocomplete="one-time-code" required>
          <button class="btn btn-primary">Activate</button>
        </form>
      <?php else: ?>
        <p style="font-size:13px;">Protect your account with a time-based one-time code (TOTP). Required for production use under COBAC security expectations.</p>
        <form method="post" action="<?= $e($base) ?>/account/2fa/start">
          <?= App\Core\Csrf::field() ?>
          <button class="btn btn-primary">Enable 2FA</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
