<?php
declare(strict_types=1);

if (user()) redirect(url('dashboard'));

$error = null;
$username = '';
if (is_post()) {
    csrf_check();
    $username = (string)post('username');
    $error = login_attempt($username, (string)($_POST['password'] ?? ''));
    if (!$error) {
        $next = (string)($_GET['next'] ?? '');
        // Retour à la page demandée, même si l'ERP est installé dans un sous-dossier.
        $back = preg_match('#(?:^|/)index\.php\?(r=[a-z_]+[^\s"<>]*)$#', $next, $m) ? 'index.php?' . $m[1] : url('dashboard');
        redirect($back);
    }
}

layout_start('Connexion', 'login', ['body' => 'login-page']);
?>
<div class="login-wrap">
  <div class="login-art" aria-hidden="true">
    <img src="assets/img/logo.svg" alt="">
    <h2><?= e(setting('company_name', 'FitnessTN')) ?></h2>
    <p>Caisse · Stock · Livraisons · Tâches · Marketing · Finances</p>
    <ul>
      <?php foreach (locations(true, 'showroom') as $l): ?>
        <li><?= icon('stock') ?><?= e($l['name']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <form class="login-card" method="post" autocomplete="on">
    <?= csrf_field() ?>
    <h1>Connexion</h1>
    <p class="muted">Accès réservé à l’équipe <?= e(setting('company_name', 'FitnessTN')) ?>.</p>
    <?php if ($error): ?><div class="flash err"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
    <label>Identifiant<input name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus></label>
    <label>Mot de passe<input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn primary block" type="submit"><?= icon('lock') ?>Se connecter</button>
    <p class="hint">Mot de passe oublié ? Demandez à l’administrateur de le réinitialiser.</p>
  </form>
</div>
<?php
layout_end();
