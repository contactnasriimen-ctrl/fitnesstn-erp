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
        'quote' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/><path d="M8.5 13h7M8.5 16.5h4.5"/><path d="M9 9.5h2"/>',
        'invoice' => '<path d="M6 2.5h9l4 4V21l-2.5-1.5L14 21l-2-1.5L10 21l-2.5-1.5L5 21V3.5a1 1 0 0 1 1-1z"/><path d="M14.5 2.5V7h4.5"/><path d="M8.5 11.5h7M8.5 15h7"/>',
        'send' => '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
        'chat' => '<path d="M4 20l1.4-4.2A8 8 0 1 1 8.6 19z"/><path d="M9 10.5c.5 2 2.3 3.8 4.5 4.5l1.3-1.2 1.7.8"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6 8.5-6"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
        'sidebar' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="M15 10l-2 2 2 2"/>',
        'enter' => '<path d="M20 5v7a3 3 0 0 1-3 3H5"/><path d="M9 11l-4 4 4 4"/>',
        'tool' => '<path d="M14.5 5.5a4 4 0 0 0 4.9 4.9l-9.2 9.2a2.1 2.1 0 0 1-3-3l9.2-9.2a4 4 0 0 0-1.9-1.9z"/><path d="M14.5 5.5l2.6-2.6a4 4 0 0 1 4 4l-2.6 2.6"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4.5V3h6v1.5"/><path d="M9 11h6M9 15h4"/>',
        'up' => '<path d="M6 15l6-6 6 6"/>',
        'down' => '<path d="M6 9l6 6 6-6"/>',
        'spark' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
    ];
    return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function nav_items(): array
{
    return [
        ['dashboard', 'Tableau de bord', 'home', 'dashboard'],
        ['x', 'X — assistant IA', 'spark', 'x'],
        ['-', 'Ventes', '', ''],
        ['pos', 'Caisse', 'pos', 'pos'],
        ['sales', 'Ventes', 'receipt', 'sales.view'],
        ['quotes', 'Devis', 'quote', 'invoices.view'],
        ['invoices', 'Factures', 'invoice', 'invoices.view'],
        ['delivery_notes', 'Bons de livraison', 'clipboard', 'invoices.view'],
        ['clients', 'Clients', 'users', 'clients.view'],
        ['-', 'Catalogue & logistique', '', ''],
        ['products', 'Produits', 'box', 'products.view'],
        ['stock', 'Stock', 'stock', 'stock.view'],
        ['deliveries', 'Livraisons', 'truck', 'deliveries.view'],
        ['repairs', 'Réparations & SAV', 'tool', 'repairs.view'],
        ['-', 'Équipe & marketing', '', ''],
        ['tasks', 'Tâches', 'tasks', 'tasks'],
        ['marketing', 'Marketing', 'megaphone', 'campaigns.view'],
        ['-', 'Gestion', '', ''],
        ['finances', 'Finances', 'wallet', 'finance.view'],
        ['io', 'Import / Export', 'swap', 'io'],
        ['-', 'Administration', '', ''],
        ['users', 'Utilisateurs', 'usercog', 'users'],
        ['settings', 'Paramètres', 'settings', 'settings'],
        ['audit', 'Journal d’activité', 'log', 'audit'],
    ];
}

/** Menu groupé : un intertitre n'apparaît que si au moins une entrée de son groupe est accessible. */
function nav_groups(): array
{
    $groups = [['', []]];
    foreach (nav_items() as [$r, $label, $ic, $perm]) {
        if ($r === '-') {
            $groups[] = [$label, []];
            continue;
        }
        if (can($perm)) $groups[count($groups) - 1][1][] = [$r, $label, $ic];
    }
    return array_values(array_filter($groups, fn($g) => $g[1]));
}

/** Créations rapides du bouton « Nouveau » et de la recherche (Ctrl+K). */
function quick_actions(): array
{
    $a = [];
    if (can('pos')) $a[] = ['Vente en caisse', url('pos'), 'pos'];
    if (can('invoices.edit')) {
        $a[] = ['Devis', url('quotes', ['a' => 'edit']), 'quote'];
        $a[] = ['Facture', url('invoices', ['a' => 'edit']), 'invoice'];
        $a[] = ['Bon de livraison', url('delivery_notes', ['a' => 'edit']), 'clipboard'];
    }
    if (can('clients.edit')) $a[] = ['Client', url('clients', ['a' => 'edit']), 'users'];
    if (can('repairs.edit')) $a[] = ['Réparation', url('repairs', ['a' => 'edit']), 'tool'];
    if (can('deliveries.edit')) $a[] = ['Livraison', url('deliveries', ['a' => 'edit']), 'truck'];
    if (can('expenses.edit')) $a[] = ['Dépense', url('finances', ['a' => 'expenses']), 'wallet'];
    if (can('tasks')) $a[] = ['Tâche', url('tasks'), 'tasks'];
    return $a;
}

function layout_start(string $title, string $active, array $opts = []): void
{
    $u = user();
    $GLOBALS['ftn_active'] = $active;
    $company = setting('company_name', 'FitnessTN');
    $bodyClass = $opts['body'] ?? '';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="theme-color" content="#0a1f4d">'
        . '<title>' . e($title) . ' · ERP ' . e($company) . '</title>'
        . '<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">'
        . '<link rel="stylesheet" href="assets/css/app.css?v=' . APP_VERSION . filemtime(APP_ROOT . '/assets/css/app.css') . '">'
        . '<link rel="stylesheet" href="assets/css/v13.css?v=' . APP_VERSION . filemtime(APP_ROOT . '/assets/css/v13.css') . '">'
        . '<meta name="csrf" content="' . e(csrf_token()) . '">'
        // Menu replié : appliqué avant l'affichage pour éviter un saut de mise en page.
        . '<script>try{if(localStorage.getItem("ftn-side")==="mini")document.documentElement.classList.add("side-mini");if(localStorage.getItem("ftn-mascot")==="off")document.documentElement.classList.add("mascot-off")}catch(e){}</script>'
        . '</head><body class="' . e($bodyClass) . '">';
    if (!$u) {
        echo '<main class="guest">';
        return;
    }
    $groups = nav_groups();
    echo '<div class="shell"><aside class="side" id="side"><div class="side-head"><a class="brand" href="' . e(url(home_route())) . '">'
        . '<img class="brand-full" src="assets/img/logo-fitnesstn-white.svg" alt="' . e($company) . '"><img class="brand-mini" src="assets/img/runner-white.svg" alt="' . e($company) . '"><small class="brand-txt">ERP · gestion</small></a>'
        . '<button class="icon-btn side-collapse" type="button" data-side-mini title="Replier le menu" aria-label="Replier le menu">' . icon('sidebar') . '</button></div><nav>';
    foreach ($groups as [$glabel, $links]) {
        if ($glabel !== '') echo '<div class="nav-sep"><span>' . e($glabel) . '</span></div>';
        foreach ($links as [$r, $label, $ic]) {
            echo '<a class="' . ($active === $r ? 'on' : '') . '" href="' . e(url($r)) . '" title="' . e($label) . '"' . ($active === $r ? ' aria-current="page"' : '') . '>' . icon($ic) . '<span>' . e($label) . '</span></a>';
        }
    }
    $loc = $u['location_id'] ? location((int)$u['location_id']) : null;
    echo '</nav><div class="me"><a class="me-link" href="' . e(url('account')) . '" title="Mon compte"><span class="avatar">' . e(initials($u['name'])) . '</span>'
        . '<span class="me-txt"><b>' . e($u['name']) . '</b><small>' . e(ROLES[$u['role']] ?? $u['role']) . ($loc ? ' · ' . e($loc['name']) : '') . '</small></span></a>'
        . '<form method="post" action="' . e(url('logout')) . '">' . csrf_field() . '<button class="icon-btn" title="Se déconnecter" aria-label="Se déconnecter">' . icon('logout') . '</button></form></div></aside>';

    // Barre du haut : titre, recherche globale, création rapide, actions de la page
    $quick = quick_actions();
    $palette = [];
    foreach ($groups as [$glabel, $links]) foreach ($links as [$r, $label, $ic]) $palette[] = ['t' => $label, 'h' => url($r), 'i' => $ic, 'g' => 'Aller à'];
    foreach ($quick as [$label, $href, $ic]) $palette[] = ['t' => 'Nouveau : ' . mb_strtolower($label), 'h' => $href, 'i' => 'plus', 'g' => 'Créer'];
    echo '<div class="main"><header class="top"><button class="icon-btn burger" type="button" data-toggle-side aria-label="Menu">' . icon('menu') . '</button>'
        . '<h1>' . e($title) . '</h1>'
        . '<button class="search-btn" type="button" data-palette-open aria-label="Rechercher">' . icon('search') . '<span>Rechercher…</span><kbd>Ctrl K</kbd></button>';
    if ($quick) {
        echo '<details class="menu new-menu"><summary class="btn sm">' . icon('plus') . '<span>Nouveau</span></summary><div class="menu-pop">';
        foreach ($quick as [$label, $href, $ic]) echo '<a href="' . e($href) . '">' . icon($ic) . e($label) . '</a>';
        echo '</div></details>';
    }
    echo '<div class="top-actions">' . ($opts['actions'] ?? '') . '</div>';
    // Menu du compte toujours visible en haut à droite (ordinateur et téléphone) : compte + déconnexion
    echo '<details class="menu user-menu"><summary class="user-btn" title="Mon compte et déconnexion" aria-label="Mon compte et déconnexion"><span class="avatar">' . e(initials($u['name'])) . '</span></summary>'
        . '<div class="menu-pop"><div class="um-head"><b>' . e($u['name']) . '</b><small>' . e(ROLES[$u['role']] ?? $u['role']) . ($loc ? ' · ' . e($loc['name']) : '') . '</small></div>'
        . '<a href="' . e(url('account')) . '">' . icon('user') . 'Mon compte</a>'
        . '<form method="post" action="' . e(url('logout')) . '">' . csrf_field() . '<button type="submit" class="um-out">' . icon('logout') . 'Se déconnecter</button></form></div></details>';
    echo '</header>';
    echo '<dialog class="palette" id="palette" aria-label="Recherche"><div class="pal-box"><div class="pal-in">' . icon('search') . '<input type="search" placeholder="Rechercher un client, un produit, un devis, une facture, une page…" autocomplete="off" data-palette-q><kbd>Échap</kbd></div>'
        . '<div class="pal-list" data-palette-list role="listbox"></div><div class="pal-foot"><span><kbd>↑</kbd><kbd>↓</kbd> naviguer</span><span><kbd>Entrée</kbd> ouvrir</span></div></div>'
        . '<div hidden>' . implode('', array_map(fn($i) => '<span data-icon-src="' . $i . '">' . icon($i) . '</span>',
            array_unique(array_merge(array_column($palette, 'i'), ['users', 'quote', 'invoice', 'receipt', 'box', 'truck', 'tool', 'spark'])))) . '</div>'
        . '<script type="application/json" data-palette-json>' . json_encode(['items' => $palette, 'search' => url('search')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script></dialog>';
    if (!empty($u['initial_password']) && $active !== 'account') {
        echo '<div class="banner">' . icon('lock') . '<span>Vous utilisez encore le mot de passe initial. <a href="' . e(url('account')) . '">Choisissez votre propre mot de passe</a>.</span></div>';
    }
    echo '<div class="content' . (!empty($opts['wide']) ? ' wide' : '') . '">';
    $fl = flashes();
    if ($fl) {
        echo '<div class="toasts" aria-live="polite">';
        foreach ($fl as [$type, $msg]) {
            echo '<div class="flash ' . e($type) . '" role="' . ($type === 'err' ? 'alert' : 'status') . '">' . icon($type === 'ok' ? 'check' : 'alert') . '<span>' . e($msg) . '</span>'
                . '<button type="button" class="icon-btn xs" data-dismiss aria-label="Fermer">' . icon('x') . '</button></div>';
        }
        echo '</div>';
    }
}

/** Barre d'onglets en bas de l'écran sur mobile. */
function mobile_nav(string $active): string
{
    $tabs = array_filter([
        ['dashboard', 'Accueil', 'home', 'dashboard'], ['pos', 'Caisse', 'pos', 'pos'], ['invoices', 'Factures', 'invoice', 'invoices.view'],
        ['sales', 'Ventes', 'receipt', 'sales.view'], ['tasks', 'Tâches', 'tasks', 'tasks'], ['deliveries', 'Livraisons', 'truck', 'deliveries.view'],
    ], fn($t) => can($t[3]));
    $tabs = array_slice(array_values($tabs), 0, 4);
    $h = '<nav class="mnav" aria-label="Navigation rapide">';
    foreach ($tabs as [$r, $label, $ic]) $h .= '<a class="' . ($active === $r ? 'on' : '') . '" href="' . e(url($r)) . '">' . icon($ic) . '<span>' . e($label) . '</span></a>';
    return $h . '<button type="button" data-toggle-side>' . icon('menu') . '<span>Menu</span></button></nav>';
}

/** Petit sportif animé en bas de l'écran (dessin original, SVG + CSS). Clic : il saute et encourage ; × : le masquer. */
function mascot_html(): string
{
    return '<div class="mascot" data-mascot>'
        . '<div class="mascot-bubble" data-mascot-bubble aria-live="polite"></div>'
        . '<button type="button" class="mascot-x" data-mascot-hide title="Masquer le petit sportif (Mon compte pour le réafficher)" aria-label="Masquer le petit sportif">×</button>'
        . '<svg viewBox="0 0 120 120" role="button" tabindex="0" aria-label="Petit sportif qui court sur un tapis : cliquez pour un encouragement" data-mascot-svg>'
        // Ombre et tapis de course
        . '<ellipse cx="56" cy="110" rx="44" ry="5" fill="rgba(10,31,77,.16)"/>'
        . '<rect x="12" y="96" width="86" height="11" rx="5.5" fill="#1e293b"/>'
        . '<rect x="16" y="92.5" width="78" height="6.5" rx="3.2" fill="#334155"/>'
        . '<line class="m-belt" x1="19" y1="95.8" x2="91" y2="95.8" stroke="#94a3b8" stroke-width="1.6" stroke-linecap="round" stroke-dasharray="5 7"/>'
        . '<circle cx="17" cy="101.5" r="3" fill="#475569"/><circle cx="93" cy="101.5" r="3" fill="#475569"/>'
        . '<path d="M92 95 L101 58" stroke="#475569" stroke-width="4.5" stroke-linecap="round"/>'
        . '<path d="M100 63 L83 66.5" stroke="#64748b" stroke-width="3.4" stroke-linecap="round"/>'
        . '<g transform="rotate(-12 103 53)"><rect x="93" y="46.5" width="20" height="13" rx="3.2" fill="#0f172a"/><rect x="95.5" y="49" width="15" height="8" rx="1.8" fill="#0b3fb3"/></g>'
        . '<path class="m-heart" d="M103 55.6c-2.6-1.8-4.1-3.2-4.1-4.7 0-1.1.9-1.9 1.9-1.9.9 0 1.6.5 2.2 1.2.6-.7 1.3-1.2 2.2-1.2 1 0 1.9.8 1.9 1.9 0 1.5-1.5 2.9-4.1 4.7z" fill="#ff4d6d"/>'
        // Coureur
        . '<g class="m-runner">'
        . '<g class="m-leg-b"><path d="M50 72 V89.5" stroke="#d99b74" stroke-width="5" stroke-linecap="round"/><path d="M47 89 h7.5 a3 3 0 0 1 0 5.2 h-7.5 z" fill="#cbd5e1"/></g>'
        . '<g class="m-arm-b"><path d="M50 54 V66" stroke="#d99b74" stroke-width="4.4" stroke-linecap="round"/><circle cx="50" cy="67.2" r="2.6" fill="#d99b74"/></g>'
        . '<path d="M42 65.5 h17 l1.2 9 h-8 l-1.7-3 -1.7 3 h-8 z" fill="#0a1f4d"/>'
        . '<rect x="41" y="49.5" width="19" height="19" rx="8" fill="#1f7bff"/>'
        . '<path d="M44.5 57.5 h12" stroke="#fff" stroke-width="2" stroke-linecap="round" opacity=".85"/>'
        . '<g class="m-leg-a"><path d="M50 72 V89.5" stroke="#f2b896" stroke-width="5" stroke-linecap="round"/><path d="M47 89 h7.5 a3 3 0 0 1 0 5.2 h-7.5 z" fill="#fff" stroke="#1f7bff" stroke-width="1.4"/></g>'
        . '<rect x="48" y="46" width="6" height="5" rx="2" fill="#f2b896"/>'
        . '<circle cx="51" cy="35" r="15" fill="#f6c7a1"/>'
        . '<path d="M36.3 33.5c.6-8.3 7-14 14.7-14 7.9 0 14.3 5.8 14.7 13.6-3.9-3.8-9.6-5.8-15-5.4-5.3.3-10.4 2.4-14.4 5.8z" fill="#2b1d16"/>'
        . '<path d="M36.6 31 Q51 23.5 65.4 31" stroke="#1f7bff" stroke-width="4" fill="none" stroke-linecap="round"/>'
        . '<path class="m-band" d="M37.2 30.6 l-7.5 -3.4 m7.5 3.4 l-6.6 3.6" stroke="#1f7bff" stroke-width="2.6" stroke-linecap="round" fill="none"/>'
        . '<g class="m-eyes"><ellipse cx="46" cy="37.5" rx="1.9" ry="2.5" fill="#1e293b"/><ellipse cx="56.5" cy="37.5" rx="1.9" ry="2.5" fill="#1e293b"/>'
        . '<circle cx="46.6" cy="36.6" r=".7" fill="#fff"/><circle cx="57.1" cy="36.6" r=".7" fill="#fff"/></g>'
        . '<circle cx="42.6" cy="42.6" r="2.5" fill="#ff8fa3" opacity=".55"/><circle cx="60" cy="42.6" r="2.5" fill="#ff8fa3" opacity=".55"/>'
        . '<path d="M48.3 43.2 q3 3.4 6 0" stroke="#7c2d12" stroke-width="1.6" fill="none" stroke-linecap="round"/>'
        . '<g class="m-arm-a"><path d="M50 54 L50 61.5 L55.5 64.5" stroke="#f2b896" stroke-width="4.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/><circle cx="56.3" cy="64.9" r="2.6" fill="#f2b896"/></g>'
        . '<path class="m-sweat" d="M68 27.5 q2.4 3.6 0 5.6 q-2.4-2 0-5.6z" fill="#7dd3fc"/>'
        . '</g></svg></div>';
}

function layout_end(array $scripts = []): void
{
    $active = (string)($GLOBALS['ftn_active'] ?? '');
    // Pas sur la caisse ni sur X : leurs boutons importants sont en bas à droite
    $mascot = in_array($active, ['pos', 'x'], true) ? '' : mascot_html();
    if (user()) echo '</div>' . mobile_nav($active) . '</div></div>' . $mascot;
    else echo '</main>' . $mascot;
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
