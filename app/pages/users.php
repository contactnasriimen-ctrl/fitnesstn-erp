<?php
declare(strict_types=1);

require_perm('users');
$a = (string)get('a', 'list');
$me = user();

if (is_post()) {
    csrf_check();
    $id = (int)post('id');
    try {
        if ($a === 'reset') {
            $u = one('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$u) throw new RuntimeException('Utilisateur introuvable.');
            $pw = (string)($_POST['password'] ?? '');
            $generated = false;
            if ($pw === '') { $pw = 'ftn-' . substr(bin2hex(random_bytes(4)), 0, 6); $generated = true; }
            elseif ($p = password_problem($pw)) throw new RuntimeException($p);
            q('UPDATE users SET password_hash = ?, initial_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            q('DELETE FROM login_attempts WHERE username = ?', [mb_strtolower($u['username'])]);
            audit('mot de passe réinitialisé', 'user', $id, $u['username']);
            flash('ok', 'Nouveau mot de passe de « ' . $u['username'] . ' » : ' . ($generated ? $pw . ' (notez-le, il ne sera plus affiché)' : 'enregistré') . '.');
            redirect(url('users'));
        }
        $username = mb_strtolower((string)post('username'));
        $name = (string)post('name');
        $role = (string)post('role');
        $loc = (int)post('location_id') ?: null;
        if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) throw new RuntimeException('Identifiant : 3 à 30 caractères (lettres minuscules, chiffres, . _ -).');
        if ($name === '') throw new RuntimeException('Le nom est obligatoire.');
        if (!isset(ROLES[$role])) throw new RuntimeException('Rôle invalide.');
        if ($role === 'showroom' && (!$loc || (location($loc)['type'] ?? '') !== 'showroom')) throw new RuntimeException('Choisissez le showroom de ce responsable.');
        if ($role !== 'showroom') $loc = null;
        if (val('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])) throw new RuntimeException('Cet identifiant est déjà utilisé.');
        $active = post('active') ? 1 : 0;
        if ($id === (int)$me['id']) {
            if ($role !== 'admin' || !$active) throw new RuntimeException('Vous ne pouvez pas retirer vos propres droits d’administrateur.');
        }
        $data = ['username' => $username, 'name' => $name, 'role' => $role, 'location_id' => $loc, 'active' => $active];
        if ($id) {
            update('users', $data, 'id = ?', [$id]);
            audit('utilisateur modifié', 'user', $id, $username . ' · ' . ROLES[$role]);
            flash('ok', 'Utilisateur enregistré.');
        } else {
            $pw = (string)($_POST['password'] ?? '');
            if ($p = password_problem($pw)) throw new RuntimeException($p);
            $id = insert('users', $data + ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'initial_password' => 1, 'created_at' => now()]);
            audit('utilisateur créé', 'user', $id, $username . ' · ' . ROLES[$role]);
            flash('ok', 'Utilisateur « ' . $username . ' » créé. Communiquez-lui son mot de passe : il pourra le changer dans « Mon compte ».');
        }
        redirect(url('users'));
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        back(url('users'));
    }
}

if ($a === 'edit') {
    $u = (int)get('id') ? one('SELECT * FROM users WHERE id = ?', [(int)get('id')]) : ['id' => 0, 'username' => '', 'name' => '', 'role' => 'showroom', 'location_id' => null, 'active' => 1];
    if (!$u) redirect(url('users'));
    layout_start($u['id'] ? 'Modifier · ' . $u['name'] : 'Nouvel utilisateur', 'users');
    ?>
    <p><a class="back" href="<?= e(url('users')) ?>"><?= icon('left') ?>Utilisateurs</a></p>
    <div class="grid2">
      <form method="post" class="card" data-role-form>
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <label>Nom affiché<input name="name" value="<?= e($u['name']) ?>" required maxlength="80"></label>
        <label>Identifiant de connexion<input name="username" value="<?= e($u['username']) ?>" required pattern="[a-z0-9._\-]{3,30}" autocomplete="off"></label>
        <label>Rôle<select name="role" data-role><?= opt(ROLES, $u['role']) ?></select></label>
        <label data-loc>Showroom<select name="location_id"><?= opt(location_options(true, 'showroom'), $u['location_id'], true) ?></select></label>
        <?php if (!$u['id']): ?><label>Mot de passe initial<input type="text" name="password" required minlength="8" autocomplete="off" value="<?= e('ftn-' . substr(bin2hex(random_bytes(4)), 0, 6)) ?>"></label><?php endif; ?>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>> Compte actif</label>
        <button class="btn primary"><?= icon('check') ?>Enregistrer</button>
      </form>
      <section class="card">
        <h2>Ce que voit chaque rôle</h2>
        <ul class="role-help">
          <li><b>Administrateur</b> — tout : les deux showrooms, finances et marges, prix d’achat, utilisateurs, paramètres, annulation de ventes, import de catalogue.</li>
          <li><b>Responsable showroom</b> — la caisse, les ventes, clôtures, dépenses et livraisons de <em>son</em> showroom ; le stock de tous les sites (mouvements pour le sien) ; les clients ; le tableau de tâches de son showroom (et celui du marketing en lecture).</li>
          <li><b>Marketing & publicité</b> — les campagnes (budget, leads, ventes générées), les statistiques d’origine des ventes, le tableau de tâches marketing (et ceux des showrooms en lecture), le catalogue et les clients en lecture.</li>
        </ul>
      </section>
    </div>
    <?php if ($u['id']): ?>
      <form method="post" action="<?= e(url('users', ['a' => 'reset'])) ?>" class="card inline-form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
        <b>Réinitialiser le mot de passe</b>
        <input type="text" name="password" placeholder="Laisser vide pour en générer un" autocomplete="off">
        <button class="btn sm" onclick="return confirm('Réinitialiser le mot de passe de <?= e($u['username']) ?> ?')"><?= icon('lock') ?>Réinitialiser</button>
      </form>
    <?php endif;
    layout_end();
    exit;
}

$rows = all('SELECT u.*, l.name loc FROM users u LEFT JOIN locations l ON l.id = u.location_id ORDER BY u.active DESC, u.role, u.name');
layout_start('Utilisateurs', 'users', ['actions' => '<a class="btn sm primary" href="' . e(url('users', ['a' => 'edit'])) . '">' . icon('plus') . 'Nouvel utilisateur</a>']);
?>
<div class="card flush">
  <table class="tbl hover">
    <thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Showroom</th><th>Dernière connexion</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['active'] ? '' : 'dim' ?>" data-href="<?= e(url('users', ['a' => 'edit', 'id' => $r['id']])) ?>">
        <td><span class="avatar sm"><?= e(initials($r['name'])) ?></span> <b><?= e($r['name']) ?></b><?= $r['active'] ? '' : ' ' . badge('Désactivé', 'err') ?></td>
        <td><code><?= e($r['username']) ?></code><?= $r['initial_password'] ? ' ' . badge('mot de passe initial', 'warn') : '' ?></td>
        <td><?= e(ROLES[$r['role']] ?? $r['role']) ?></td><td><?= e($r['loc'] ?: 'Tous') ?></td>
        <td><?= $r['last_login'] ? fdate($r['last_login'], true) : '<span class="muted">jamais</span>' ?></td>
        <td class="num"><a class="icon-btn xs" href="<?= e(url('users', ['a' => 'edit', 'id' => $r['id']])) ?>" title="Modifier"><?= icon('edit') ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
layout_end();
