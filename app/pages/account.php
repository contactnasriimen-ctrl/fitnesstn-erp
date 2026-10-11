<?php
declare(strict_types=1);

$u = user();
if (is_post()) {
    csrf_check();
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $rep = (string)($_POST['repeat'] ?? '');
    if (!password_verify($cur, $u['password_hash'])) {
        flash('err', 'Le mot de passe actuel est incorrect.');
    } elseif ($new !== $rep) {
        flash('err', 'Les deux nouveaux mots de passe ne sont pas identiques.');
    } elseif ($p = password_problem($new)) {
        flash('err', $p);
    } else {
        q('UPDATE users SET password_hash = ?, initial_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        audit('mot de passe modifié', 'user', $u['id']);
        flash('ok', 'Mot de passe modifié.');
    }
    redirect(url('account'));
}

$loc = $u['location_id'] ? location((int)$u['location_id']) : null;
layout_start('Mon compte', 'account');
?>
<div class="grid2">
  <section class="card">
    <h2>Profil</h2>
    <dl class="kv">
      <dt>Nom</dt><dd><?= e($u['name']) ?></dd>
      <dt>Identifiant</dt><dd><?= e($u['username']) ?></dd>
      <dt>Rôle</dt><dd><?= e(ROLES[$u['role']] ?? $u['role']) ?></dd>
      <dt>Showroom</dt><dd><?= $loc ? e($loc['name']) : 'Tous les sites' ?></dd>
      <dt>Dernière connexion</dt><dd><?= fdate($u['last_login'], true) ?></dd>
    </dl>
  </section>
  <form class="card" method="post">
    <?= csrf_field() ?>
    <h2>Changer mon mot de passe</h2>
    <label>Mot de passe actuel<input type="password" name="current" autocomplete="current-password" required></label>
    <label>Nouveau mot de passe<input type="password" name="new" autocomplete="new-password" minlength="8" required></label>
    <label>Répéter le nouveau mot de passe<input type="password" name="repeat" autocomplete="new-password" minlength="8" required></label>
    <p class="hint">8 caractères minimum, avec des lettres et des chiffres.</p>
    <button class="btn primary"><?= icon('check') ?>Enregistrer</button>
  </form>
</div>
<?php
layout_end();
