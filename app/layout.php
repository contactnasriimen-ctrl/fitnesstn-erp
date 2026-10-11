<?php
declare(strict_types=1);

function icon(string $name, string $cls = 'ic'): string
{
    static $p = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'pos' => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V5h10v6"/><path d="M7 15h2M11 15h2M15 15h2M7 18h10"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c2 .7 3.2 2.4 3.5 5.2"/>',
        'box' => '<path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
        'stock' => '<path d="M3 21V9l9-6 9 6v12"/><path d="M7 21v-8h10v8"/><path d="M7 17h10"/>',
        'truck' => '<path d="M2 6h12v10H2z"/><path d="M14 9h4l3 3.5V16h-7"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'tasks' => '<rect x="3" y="4" width="5" height="15" rx="1.2"/><rect x="10" y="4" width="5" height="10" rx="1.2"/><rect x="17" y="4" width="4" height="12" rx="1.2"/>',
        'megaphone' => '<path d="M3 10v4h3l8 5V5l-8 5z"/><path d="M18 8.5a5 5 0 0 1 0 7"/><path d="M6.5 14l1.5 5h2.5l-1-4.4"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M15.5 15h2.5"/><path d="M6 6l9-3 1 3"/>',
        'swap' => '<path d="M7 20V4M3 8l4-4 4 4"/><path d="M17 4v16M13 16l4 4 4-4"/>',
        'usercog' => '<circle cx="10" cy="8" r="4"/><path d="M3 20c.8-3.8 3.6-6 7-6 1.3 0 2.5.3 3.5.9"/><circle cx="18" cy="17" r="2.5"/><path d="M18 12.8v1.7M18 19.5v1.7M13.8 17h1.7M20.5 17h1.7"/>',
        'settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
        'log' => '<path d="M5 3h11l3 3v15H5z"/><path d="M9 9h6M9 13h6M9 17h3"/>',
        'logout' => '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l5 5"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
        'print' => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 20h16"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 20h16"/>',
        'alert' => '<path d="M12 3.5l9.5 17h-19z"/><path d="M12 10v5M12 17.5v.5"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'left' => '<path d="M15 6l-6 6 6 6"/>',
        'right' => '<path d="M9 6l6 6-6 6"/>',
        'cash' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.8"/><path d="M6 9.5v5M18 9.5v5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'transfer' => '<path d="M4 8h14l-4-4M20 16H6l4 4"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
    ];
    return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function nav_items(): array
{
    return [
        ['dashboard', 'Tableau de bord', 'home', 'dashboard'],
        ['pos', 'Caisse', 'pos', 'pos'],
        ['sales', 'Ventes', 'receipt', 'sales.view'],
        ['clients', 'Clients', 'users', 'clients.view'],
        ['products', 'Produits', 'box', 'products.view'],
        ['stock', 'Stock', 'stock', 'stock.view'],
        ['deliveries', 'Livraisons', 'truck', 'deliveries.view'],
        ['tasks', 'Tâches', 'tasks', 'tasks'],
        ['marketing', 'Marketing', 'megaphone', 'campaigns.view'],
        ['finances', 'Finances', 'wallet', 'finance.view'],
        ['io', 'Import / Export CSV', 'swap', 'io'],
        ['-', 'Administration', '', 'users'],
        ['users', 'Utilisateurs', 'usercog', 'users'],
        ['settings', 'Paramètres', 'settings', 'settings'],
        ['audit', 'Journal d’activité', 'log', 'audit'],
    ];
}

function layout_start(string $title, string $active, array $opts = []): void
{
    $u = user();
    $company = setting('company_name', 'FitnessTN');
    $bodyClass = $opts['body'] ?? '';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' · ERP ' . e($company) . '</title>'
        . '<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">'
        . '<link rel="stylesheet" href="assets/css/app.css?v=' . APP_VERSION . filemtime(APP_ROOT . '/assets/css/app.css') . '">'
        . '<meta name="csrf" content="' . e(csrf_token()) . '">'
        . '</head><body class="' . e($bodyClass) . '">';
    if (!$u) {
        echo '<main class="guest">';
        return;
    }
    echo '<div class="shell"><aside class="side" id="side"><a class="brand" href="' . e(url('dashboard')) . '">'
        . '<span class="brand-mark"><img src="assets/img/logo.svg" alt=""></span><span><b>' . e($company) . '</b><small>ERP · gestion</small></span></a><nav>';
    foreach (nav_items() as [$r, $label, $ic, $perm]) {
        if (!can($perm)) continue;
        if ($r === '-') {
            echo '<div class="nav-sep">' . e($label) . '</div>';
            continue;
        }
        echo '<a class="' . ($active === $r ? 'on' : '') . '" href="' . e(url($r)) . '">' . icon($ic) . '<span>' . e($label) . '</span></a>';
    }
    $loc = $u['location_id'] ? location((int)$u['location_id']) : null;
    echo '</nav><div class="me"><a class="me-link" href="' . e(url('account')) . '"><span class="avatar">' . e(initials($u['name'])) . '</span>'
        . '<span><b>' . e($u['name']) . '</b><small>' . e(ROLES[$u['role']] ?? $u['role']) . ($loc ? ' · ' . e($loc['name']) : '') . '</small></span></a>'
        . '<form method="post" action="' . e(url('logout')) . '">' . csrf_field() . '<button class="icon-btn" title="Se déconnecter">' . icon('logout') . '</button></form></div></aside>'
        . '<div class="main"><header class="top"><button class="icon-btn burger" type="button" data-toggle-side aria-label="Menu">' . icon('menu') . '</button>'
        . '<h1>' . e($title) . '</h1><div class="top-actions">' . ($opts['actions'] ?? '') . '</div></header>';
    if (!empty($u['initial_password']) && $active !== 'account') {
        echo '<div class="banner">' . icon('lock') . '<span>Vous utilisez encore le mot de passe initial. <a href="' . e(url('account')) . '">Choisissez votre propre mot de passe</a>.</span></div>';
    }
    echo '<div class="content' . (!empty($opts['wide']) ? ' wide' : '') . '">';
    foreach (flashes() as [$type, $msg]) {
        echo '<div class="flash ' . e($type) . '">' . icon($type === 'ok' ? 'check' : 'alert') . '<span>' . e($msg) . '</span></div>';
    }
}

function layout_end(array $scripts = []): void
{
    if (user()) echo '</div></div></div>';
    else echo '</main>';
    $v = APP_VERSION . filemtime(APP_ROOT . '/assets/js/app.js');
    echo '<script src="assets/js/app.js?v=' . $v . '"></script>';
    foreach ($scripts as $s) echo '<script src="assets/js/' . e($s) . '?v=' . APP_VERSION . filemtime(APP_ROOT . '/assets/js/' . $s) . '"></script>';
    echo '</body></html>';
}

/** Petit composant : onglets. */
function tabs(array $items, string $current): string
{
    $h = '<div class="tabs">';
    foreach ($items as $key => [$label, $href]) {
        $h .= '<a class="' . ($key === $current ? 'on' : '') . '" href="' . e($href) . '">' . e($label) . '</a>';
    }
    return $h . '</div>';
}

function badge(string $text, string $tone = ''): string
{
    return '<span class="badge ' . e($tone) . '">' . e($text) . '</span>';
}

function ship_tone(string $status): string
{
    return ['a_preparer' => '', 'en_cours' => 'info', 'livree' => 'ok', 'retour' => 'warn', 'annulee' => 'err'][$status] ?? '';
}

function pager(int $total, int $page, int $per, array $params, string $route): string
{
    $pages = (int)ceil($total / max(1, $per));
    if ($pages <= 1) return '';
    $h = '<div class="pager"><span>' . $total . ' résultats</span>';
    if ($page > 1) $h .= '<a class="btn sm ghost" href="' . e(url($route, array_merge($params, ['page' => $page - 1]))) . '">' . icon('left') . 'Précédent</a>';
    $h .= '<span>Page ' . $page . ' / ' . $pages . '</span>';
    if ($page < $pages) $h .= '<a class="btn sm ghost" href="' . e(url($route, array_merge($params, ['page' => $page + 1]))) . '">Suivant' . icon('right') . '</a>';
    return $h . '</div>';
}

function product_thumb(array $p, string $cls = 'thumb'): string
{
    $src = product_image($p);
    $fam = slug_key($p['family'] ?? 'autres');
    if ($src === '') return '<span class="' . e($cls) . ' ph fam-' . e($fam) . '">' . e(initials($p['name'])) . '</span>';
    return '<span class="' . e($cls) . '"><img loading="lazy" src="' . e($src) . '" alt="" onerror="this.parentNode.classList.add(\'ph\');this.remove()"></span>';
}
