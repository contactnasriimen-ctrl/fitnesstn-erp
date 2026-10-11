<?php
declare(strict_types=1);

const ROLES = [
    'admin' => 'Administrateur',
    'showroom' => 'Responsable showroom',
    'marketing' => 'Marketing & publicité',
];

/**
 * Droits par rôle (l'administrateur a tous les droits).
 * Un responsable showroom ne voit que les ventes, caisses, livraisons et dépenses de SON showroom.
 */
const PERMS = [
    'showroom' => [
        'dashboard', 'pos', 'sales.view', 'sales.pay', 'sales.export', 'products.view', 'products.export',
        'stock.view', 'stock.move', 'stock.export', 'deliveries.view', 'deliveries.edit', 'deliveries.export',
        'deliveries.import', 'carriers.view', 'tasks', 'finance.view', 'expenses.edit', 'expenses.export',
        'expenses.import', 'sessions.export', 'payments.export', 'clients.view', 'clients.edit', 'clients.export', 'clients.import',
        'tasks.export', 'tasks.import', 'campaigns.view', 'io',
    ],
    'marketing' => [
        'dashboard', 'products.view', 'products.export', 'stock.view', 'stock.export', 'tasks', 'tasks.export', 'tasks.import',
        'campaigns.view', 'campaigns.edit', 'campaigns.export', 'campaigns.import', 'marketing.stats',
        'clients.view', 'clients.export', 'io',
    ],
];

function user(): ?array
{
    static $u = false;
    if ($u === false || ($u && (int)$u['id'] !== (int)($_SESSION['uid'] ?? 0))) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = one('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']]);
            if (!$u) unset($_SESSION['uid']);
        }
    }
    return $u;
}

function is_admin(): bool
{
    return (user()['role'] ?? '') === 'admin';
}

function can(string $perm): bool
{
    $u = user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    return in_array($perm, PERMS[$u['role']] ?? [], true);
}

function require_login(): void
{
    if (!user()) {
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) json_out(['ok' => false, 'error' => 'Session expirée, reconnectez-vous.'], 401);
        redirect(url('login', ['next' => $_SERVER['REQUEST_URI'] ?? '']));
    }
}

function require_perm(string $perm): void
{
    require_login();
    if (!can($perm)) {
        http_response_code(403);
        layout_start('Accès refusé', '');
        echo '<div class="empty"><h2>Accès refusé</h2><p>Votre profil (' . e(ROLES[user()['role']] ?? '') . ') n’a pas accès à cette page.</p>'
            . '<p><a class="btn" href="' . e(url('dashboard')) . '">Retour au tableau de bord</a></p></div>';
        layout_end();
        exit;
    }
}

/** Showroom imposé à l'utilisateur (null = tous les sites, pour l'admin et le marketing). */
function scope_loc(): ?int
{
    $u = user();
    if ($u && $u['role'] === 'showroom') return (int)$u['location_id'];
    return null;
}

/** Vérifie qu'une donnée rattachée au site $locId est accessible. */
function can_loc(?int $locId): bool
{
    $s = scope_loc();
    return $s === null || (int)$locId === $s;
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/** Retourne null si OK, sinon un message d'erreur. */
function login_attempt(string $username, string $password): ?string
{
    $ip = client_ip();
    $since = time() - 600;
    $fails = (int)val('SELECT COUNT(*) FROM login_attempts WHERE (username = ? OR ip = ?) AND at > ?', [mb_strtolower($username), $ip, $since]);
    if ($fails >= 8) return 'Trop de tentatives. Réessayez dans 10 minutes.';
    $u = one('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        insert('login_attempts', ['username' => mb_strtolower($username), 'ip' => $ip, 'at' => time()]);
        return 'Identifiant ou mot de passe incorrect.';
    }
    q('DELETE FROM login_attempts WHERE username = ? OR at < ?', [mb_strtolower($username), $since]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    unset($_SESSION['csrf']);
    q('UPDATE users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    audit('connexion', 'user', $u['id']);
    return null;
}

function password_problem(string $pw): ?string
{
    if (mb_strlen($pw) < 8) return 'Le mot de passe doit contenir au moins 8 caractères.';
    if (!preg_match('/\d/', $pw) || !preg_match('/\pL/u', $pw)) return 'Le mot de passe doit contenir des lettres et des chiffres.';
    return null;
}

/* ---------- Tableaux de tâches ---------- */

function board_can_view(array $b): bool
{
    $u = user();
    if ($u['role'] === 'admin') return true;
    if ($b['kind'] === 'marketing') return true;
    if ($b['kind'] === 'showroom') return $u['role'] === 'marketing' || (int)$b['location_id'] === (int)$u['location_id'];
    return false;
}

function board_can_edit(array $b): bool
{
    $u = user();
    if ($u['role'] === 'admin') return true;
    if ($b['kind'] === 'marketing') return $u['role'] === 'marketing';
    if ($b['kind'] === 'showroom') return $u['role'] === 'showroom' && (int)$b['location_id'] === (int)$u['location_id'];
    return false;
}
