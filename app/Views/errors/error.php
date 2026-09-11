<?php $e = $data['e']; ?>
<div class="text-center" style="margin-top:10vh">
  <h1 class="display-1"><?= (int)$code ?></h1>
  <p class="lead"><?= $e($message) ?></p>
  <a class="btn btn-outline-primary" href="<?= App\Core\Rbac::baseUrl() ?>/dashboard">Back to dashboard</a>
</div>
