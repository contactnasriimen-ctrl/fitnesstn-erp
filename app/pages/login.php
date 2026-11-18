<?php
declare(strict_types=1);

if (user()) redirect(url(home_route()));

$error = null;
$username = '';
if (is_post()) {
    csrf_check();
    $username = (string)post('username');
    $error = login_attempt($username, (string)($_POST['password'] ?? ''));
    if (!$error) {
        $next = (string)($_GET['next'] ?? '');
        // Retour à la page demandée, même si l'ERP est installé dans un sous-dossier.
        $back = preg_match('#(?:^|/)index\.php\?(r=[a-z_]+[^\s"<>]*)$#', $next, $m) ? 'index.php?' . $m[1] : url(home_route());
        redirect($back);
    }
}

layout_start('Connexion', 'login', ['body' => 'login-page']);
?>
<div class="login-wrap">
  <div class="login-art" aria-hidden="true">
    <img class="login-logo" src="assets/img/logo-fitnesstn-white.svg" alt="">
    <h2>ERP · gestion</h2>
    <p>Caisse · Devis & factures · Stock · Livraisons · Tâches · Finances</p>
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
    <label>Mot de passe<span class="pw-field"><input type="password" name="password" autocomplete="current-password" required><button type="button" class="icon-btn xs" data-pw-toggle title="Afficher le mot de passe" aria-label="Afficher le mot de passe" aria-pressed="false"><?= icon('eye') ?></button></span></label>
    <button class="btn primary block" type="submit"><?= icon('lock') ?>Se connecter</button>
    <p class="hint">Mot de passe oublié ? Demandez à l’administrateur de le réinitialiser.</p>
  </form>
</div>
<?php
layout_end();
