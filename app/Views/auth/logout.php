<?php $base = App\Core\Rbac::baseUrl(); ?>
<div class="card p-4" style="max-width:420px">
  <div class="titled">Sign out</div>
  <p style="font-size:13px;">End your registry session on this device?</p>
  <form method="post" action="<?= htmlspecialchars($base, ENT_QUOTES) ?>/logout" class="d-flex gap-2">
    <?= App\Core\Csrf::field() ?>
    <button class="btn btn-primary"><?= App\Core\Lang::t('sign_out') ?></button>
    <a class="btn" href="<?= htmlspecialchars($base, ENT_QUOTES) ?>/dashboard">Cancel</a>
  </form>
</div>
