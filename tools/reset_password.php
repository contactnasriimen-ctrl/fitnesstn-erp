<?php
// Réinitialise le mot de passe d'un compte : php tools/reset_password.php <identifiant> [nouveau_mot_de_passe]
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/bootstrap.php';
$username = $argv[1] ?? '';
$u = $username !== '' ? one('SELECT * FROM users WHERE username = ?', [$username]) : null;
if (!$u) {
    fwrite(STDERR, "Usage : php tools/reset_password.php <identifiant> [mot_de_passe]\nComptes : " . implode(', ', array_column(all('SELECT username FROM users'), 'username')) . "\n");
    exit(1);
}
$pw = $argv[2] ?? ('ftn-' . substr(bin2hex(random_bytes(4)), 0, 6));
q('UPDATE users SET password_hash = ?, initial_password = 1, active = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
q('DELETE FROM login_attempts');
echo "Nouveau mot de passe de « {$u['username']} » : $pw\n";
