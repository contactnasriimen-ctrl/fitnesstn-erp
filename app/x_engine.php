<?php
declare(strict_types=1);

/*
 * X — l'assistant de la direction, calculé entièrement dans l'ERP (aucun service extérieur, aucune clé).
 * Une question en français (quelques mots de tunisien / d'arabe sont compris) est analysée :
 *   intention (CA, produits, transporteurs, livraisons, retours, réparations, clients, gouvernorats, stock,
 *   dépenses, paiements, créances, bilan), période (« octobre », « mois dernier », « depuis mars »…) et
 *   éléments cités (produit, transporteur, technicien, gouvernorat, téléphone, showroom).
 * La réponse est calculée par des requêtes SQL fixes, en lecture seule. La question précédente sert de contexte
 * (« et en septembre ? », « et chez TPS ? »).
 */

require_once __DIR__ . '/excel_import.php'; // XL_PRODUCTS, xl_upper() : reconnaissance des machines

const X_MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/* ---------- Compréhension de la question ---------- */

/** Minuscules sans accents ni ponctuation, mots tunisiens / arabes courants traduits. Entouré d'espaces. */
function x_norm(string $q): string
{
    $s = mb_strtolower(trim($q));
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', '’' => "'", '‘' => "'"]);
    $ar = ['قداش' => 'combien', 'كم' => 'combien', 'مبيعات' => 'ventes', 'بعنا' => 'vendu', 'بيع' => 'ventes', 'اليوم' => 'aujourdhui',
        'البارح' => 'hier', 'الشهر' => 'mois', 'شهر' => 'mois', 'العام' => 'annee', 'فلوس' => 'argent', 'تصليح' => 'reparation',
        'توصيل' => 'livraison', 'سلعة' => 'marchandise', 'حرفاء' => 'clients', 'حريف' => 'client', 'مصاريف' => 'depenses', 'مخزون' => 'stock'];
    $s = strtr($s, $ar);
    $s = ' ' . preg_replace('/[^a-z0-9+\s]+/u', ' ', $s) . ' ';
    $tn = [
        '/\b(9add?e?(ch|h|sh)|qadd?e?(ch|sh)|kadd?e?(ch|sh)|9ad|chhal|ch7al)\b/' => 'combien', '/\b(bi3na|ba3na|bi3|beyaa|bay3)\b/' => 'vendu',
        '/\bflous\b/' => 'argent', '/\b(lyoum|lyouma|elyoum)\b/' => 'aujourdhui', '/\b(lbera7|lbare7|el bera7)\b/' => 'hier',
        '/\b(chhar|ch har|ech chhar)\b/' => 'mois', '/\b(el 3am|l3am|3am)\b/' => 'annee', '/\b(sal3a|sel3a|essal3a)\b/' => 'marchandise',
        '/\b(7rif|hrif|7arfa|hrafa)\b/' => 'client', '/\b(tasli7|sla7|tsali7)\b/' => 'reparation', '/\b(tawsil|twsil)\b/' => 'livraison',
        '/\baujourd hui\b/' => 'aujourdhui', '/\bchiffre d affaire(s)?\b/' => 'ca', '/\bchiffre d affaires?\b/' => 'ca',
    ];
    $s = preg_replace(array_keys($tn), array_values($tn), $s);
    return preg_replace('/\s+/', ' ', $s);
}

function x_has(string $s, string $re): bool
{
    return (bool)preg_match('/' . $re . '/', $s);
}

/** Période citée : ['from', 'to', 'label'] ou null. */
function x_period(string $s): ?array
{
    $today = today();
    $y = (int)date('Y');
    $mk = fn(string $f, string $t, string $l) => ['from' => $f, 'to' => min($t, $today) ?: $t, 'label' => $l];
    if (x_has($s, '\b(aujourdhui|today|ce jour)\b')) return $mk($today, $today, 'aujourd’hui');
    if (x_has($s, '\bhier\b')) { $d = date('Y-m-d', strtotime('-1 day')); return $mk($d, $d, 'hier'); }
    if (preg_match('/\b(\d{1,3}) derniers jours\b/', $s, $m)) return $mk(date('Y-m-d', strtotime('-' . ((int)$m[1] - 1) . ' days')), $today, 'les ' . (int)$m[1] . ' derniers jours');
    if (x_has($s, '\bsemaine (derniere|passee|precedente)\b')) {
        $mon = date('Y-m-d', strtotime('monday last week'));
        return $mk($mon, date('Y-m-d', strtotime("$mon +6 days")), 'la semaine dernière');
    }
    if (x_has($s, '\b(cette semaine|la semaine)\b')) return $mk(date('Y-m-d', strtotime('monday this week')), $today, 'cette semaine');
    if (x_has($s, '\bmois (dernier|passe|precedent)\b')) {
        $f = date('Y-m-01', strtotime('first day of last month'));
        return $mk($f, date('Y-m-t', strtotime($f)), 'le mois dernier (' . X_MONTHS[(int)substr($f, 5, 2) - 1] . ')');
    }
    $mre = '\b(janvier|janv|jan|fevrier|fevr|fev|mars|avril|avr|mai|juin|juillet|juil|aout|septembre|sept|sep|octobre|oct|novembre|nov|decembre|dec)\b(?: (20\d\d))?';
    if (preg_match_all('/' . $mre . '/', $s, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        $num = ['jan' => 1, 'fev' => 2, 'mar' => 3, 'avr' => 4, 'mai' => 5, 'jui' => 6, 'aou' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $months = [];
        foreach ($mm as $m) {
            $w = $m[1][0];
            $n = str_starts_with($w, 'juil') ? 7 : ($w === 'juin' ? 6 : $num[substr($w, 0, 3)]);
            $yr = isset($m[2]) && $m[2][0] !== '' ? (int)$m[2][0] : ($n > (int)date('n') ? $y - 1 : $y);
            $months[] = [$yr, $n, $m[0][1]];
        }
        $first = $months[0];
        $start = sprintf('%04d-%02d-01', $first[0], $first[1]);
        $before = substr($s, 0, $first[2]);
        if (preg_match('/\b(depuis|a partir de|a partir d)\s*$/', $before) || preg_match('/\bdepuis\b[^0-9]{0,12}$/', $before)) {
            return $mk($start, $today, 'depuis ' . X_MONTHS[$first[1] - 1] . ' ' . $first[0]);
        }
        if (count($months) >= 2 && x_has($s, '\b(entre|de|du)\b')) {
            $last = end($months);
            $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $last[0], $last[1])));
            return $mk($start, $end, 'de ' . X_MONTHS[$first[1] - 1] . ' à ' . X_MONTHS[$last[1] - 1] . ' ' . $last[0]);
        }
        return $mk($start, date('Y-m-t', strtotime($start)), X_MONTHS[$first[1] - 1] . ' ' . $first[0]);
    }
    // « mois 10 », « chhar 10 », « 10/2026 »
    if (preg_match('/\bmois (\d{1,2})\b(?: (20\d\d))?|\b(\d{1,2}) (20\d\d)\b/', $s, $m)) {
        $n = (int)($m[1] !== '' ? $m[1] : $m[3]);
        $yr = (int)(($m[2] ?? '') !== '' ? $m[2] : ($m[4] ?? '')) ?: ($n > (int)date('n') ? $y - 1 : $y);
        if ($n >= 1 && $n <= 12) {
            $f = sprintf('%04d-%02d-01', $yr, $n);
            return $mk($f, date('Y-m-t', strtotime($f)), X_MONTHS[$n - 1] . ' ' . $yr);
        }
    }
    if (x_has($s, '\b(ce mois|du mois|mois ci|mois en cours|le mois)\b')) return $mk(date('Y-m-01'), $today, 'ce mois-ci (' . X_MONTHS[(int)date('n') - 1] . ')');
    if (x_has($s, '\b(annee derniere|an dernier|annee passee)\b')) return $mk(($y - 1) . '-01-01', ($y - 1) . '-12-31', 'l’année ' . ($y - 1));
    if (preg_match('/\b(20\d\d)\b/', $s, $m)) return $mk($m[1] . '-01-01', $m[1] . '-12-31', 'l’année ' . $m[1]);
    if (x_has($s, '\b(cette annee|annee|depuis janvier|depuis le debut de l annee)\b')) return $mk("$y-01-01", $today, 'cette année');
    if (x_has($s, '\b(tout|toujours|depuis le debut|depuis toujours|total general|au total|global)\b')) return $mk('2000-01-01', $today, 'depuis le début');
    return null;
}

function x_default_period(string $kind): array
{
    $y = date('Y');
    return match ($kind) {
        'month' => ['from' => date('Y-m-01'), 'to' => today(), 'label' => 'ce mois-ci (' . X_MONTHS[(int)date('n') - 1] . ')'],
        'all' => ['from' => '2000-01-01', 'to' => today(), 'label' => 'depuis le début'],
        default => ['from' => "$y-01-01", 'to' => today(), 'label' => 'cette année'],
    };
}

/** Transporteurs cités (id => nom). Les prénoms des techniciens seuls ne comptent pas. */
function x_find_carriers(string $s, array $techs): array
{
    $out = [];
    foreach (all('SELECT id, name FROM carriers') as $c) {
        $words = array_filter(explode(' ', trim(x_norm(preg_replace('/\(.*?\)/', '', $c['name'])))), fn($w) => strlen($w) >= 3
            && !in_array($w, ['transport', 'delivery', 'livraison', 'interne', 'fitnesstn', 'camion', 'poste'], true));
        $words = array_values($words);
        if (!$words) continue;
        // « Omar Kouki » : « kouki » suffit ; « omar » seul désigne le technicien
        if (count($words) > 1 && in_array($words[0], $techs, true)) $words = array_slice($words, 1);
        if (count($words) === 1 && in_array($words[0], $techs, true) && !x_has($s, '\b(transporteur|camion|livreur)\b')) continue;
        foreach ($words as $w) if (x_has($s, '\b' . preg_quote($w, '/') . '\b')) { $out[(int)$c['id']] = $c['name']; break; }
    }
    return $out;
}

function x_techs(): array
{
    $t = [];
    foreach (all("SELECT technician FROM repairs WHERE technician <> '' UNION SELECT technician FROM shipments WHERE technician <> ''") as $r) {
        $w = trim(x_norm($r['technician']));
        if ($w !== '' && !str_contains($w, ' ')) $t[$w] = $r['technician'];
    }
    return $t;
}

function x_find_gouv(string $s): ?string
{
    foreach (GOUVERNORATS as $g) {
        $k = trim(x_norm($g));
        $alt = preg_replace('/^(le|la) /', '', $k);
        if (x_has($s, '\b(' . preg_quote($k, '/') . '|' . preg_quote($alt, '/') . ')\b')) return $g;
    }
    // Quelques villes courantes
    foreach (['djerba' => 'Médenine', 'zarzis' => 'Médenine', 'hammamet' => 'Nabeul', 'kelibia' => 'Nabeul', 'marsa' => 'Tunis', 'mourouj' => 'Ben Arous', 'soukra' => 'Ariana', 'menzah' => 'Ariana'] as $w => $g) {
        if (x_has($s, '\b' . $w . '\b')) return $g;
    }
    return null;
}

/**
 * Produits cités : ['label' => texte, 'ids' => [ids catalogue], 'like' => [motifs sur les libellés de vente], 'family' => famille|null].
 */
function x_find_products(string $raw, string $s): ?array
{
    $u = xl_upper($raw);
    $names = [];
    foreach (XL_PRODUCTS as $re => $name) if (preg_match($re, $u)) $names[$name] = true;
    if ($names) {
        $ids = [];
        foreach (array_keys($names) as $n) foreach (all('SELECT id FROM products WHERE name = ? COLLATE NOCASE', [$n]) as $p) $ids[] = (int)$p['id'];
        $like = [];
        if (preg_match('/\b(BT ?\d{3}|\d{3,4} ?DS|8091|A ?30|B[1-4]|E ?20)\b/', $u, $m)) $like[] = str_replace(' ', '%', trim($m[1]));
        if (preg_match('/(?<![0-9])(\d{3}) ?\+/', $u, $m)) $like[] = $m[1] . '%+';
        return ['label' => implode(', ', array_keys($names)), 'ids' => $ids, 'like' => $like, 'family' => null];
    }
    $fam = [
        '\b(tapis|tapis roulants?|treadmill)\b' => ['Tapis de course', ['tapis', 'BT %', 'treadmill']], '\b(elliptiques?)\b' => ['Elliptiques & cardio', 'ellipti'],
        '\b(velos?|spinning|bike|biking)\b' => ['Vélos', 'velo'], '\b(rameurs?)\b' => ['Rameurs', 'rameur'],
        '\b(musculation|multigym|banc|bench|halteres?)\b' => ['Musculation', 'multigym'], '\b(accessoires?)\b' => ['Accessoires', 'accessoire'],
        '\b(pilates|yoga)\b' => ['Pilates & yoga', 'pilates'], '\b(padel|raquettes?)\b' => ['Padel & raquettes', 'padel'],
    ];
    // Mot distinctif d'un produit du catalogue (« huile », « multigym », « trampoline »…)
    $generic = ['tapis', 'roulant', 'machine', 'velo', 'sport', 'fitness', 'elastique', 'resistance', 'black', 'with', 'pump', 'professionnel', 'musculation',
        'support', 'elastic', 'exercise', 'gloves', 'leather', 'smart', 'speed', 'inclinaison', 'automatique', 'semi', 'programmes', 'outdoor', 'table'];
    $hits = [];
    foreach (all('SELECT id, name FROM products WHERE active = 1') as $p) {
        foreach (explode(' ', trim(x_norm($p['name']))) as $w) {
            if (strlen($w) >= 5 && !ctype_digit($w) && !in_array($w, $generic, true) && x_has($s, '\b' . preg_quote($w, '/') . 's?\b')) { $hits[$w][] = (int)$p['id']; }
        }
    }
    if ($hits) {
        uksort($hits, fn($a, $b) => count($hits[$a]) <=> count($hits[$b]));
        $w = array_key_first($hits);
        return ['label' => $w, 'ids' => $hits[$w], 'like' => [$w], 'family' => null];
    }
    foreach ($fam as $re => [$family, $like]) if (x_has($s, $re)) return ['label' => mb_strtolower($family), 'ids' => [], 'like' => (array)$like, 'family' => $family];
    return null;
}

/** Condition SQL sur sale_items (alias si, produits p) pour un produit trouvé. */
function x_product_sql(array $prod): array
{
    $or = [];
    $p = [];
    if ($prod['ids']) $or[] = 'si.product_id IN (' . implode(',', array_map('intval', $prod['ids'])) . ')';
    if ($prod['family']) { $or[] = 'si.product_id IN (SELECT id FROM products WHERE family = ?)'; $p[] = $prod['family']; }
    foreach ($prod['like'] as $l) { $or[] = '(si.product_id IS NULL AND si.label LIKE ?)'; $p[] = '%' . $l . '%'; }
    return ['(' . implode(' OR ', $or ?: ['0']) . ')', $p];
}

/* ---------- Mise en forme ---------- */

function x_month_label(string $ym): string
{
    return ucfirst(X_MONTHS[(int)substr($ym, 5, 2) - 1]) . ' ' . substr($ym, 0, 4);
}

function x_table(array $head, array $rows, array $num = []): string
{
    if (!$rows) return '';
    $h = '<div class="x-table"><table class="tbl"><thead><tr>';
    foreach ($head as $i => $c) $h .= '<th' . (in_array($i, $num, true) ? ' class="num"' : '') . '>' . e($c) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach (array_values($r) as $i => $v) $h .= '<td' . (in_array($i, $num, true) ? ' class="num"' : '') . '>' . (is_array($v) ? $v[0] : e((string)$v)) . '</td>';
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/** Cellule déjà en HTML (lien…). */
function x_raw(string $html): array
{
    return [$html];
}

function x_btn(string $label, string $href): string
{
    return '<a class="btn xs ghost" href="' . e($href) . '">' . e($label) . '</a>';
}

function x_p(string $html, string $cls = ''): string
{
    return '<p' . ($cls ? ' class="' . $cls . '"' : '') . '>' . $html . '</p>';
}

function x_period_note(array $per, bool $assumed): string
{
    return '<p class="x-period">' . icon('calendar') . 'Période : ' . e($per['label']) . ($per['from'] !== '2000-01-01' ? ' (' . fdate($per['from']) . ' → ' . fdate($per['to']) . ')' : '')
        . ($assumed ? ' <small>— précisez « en octobre », « ce mois », « depuis mars »… pour changer</small>' : '') . '</p>';
}

function x_pct(int $a, int $b): string
{
    return $b > 0 ? pct($a / $b * 100) : '—';
}

/* ---------- Réponses ---------- */

/** Ventes + factures hors caisse, filtres facultatifs (showroom, gouvernorat). */
function x_ca(string $from, string $to, ?int $loc = null, ?string $gouv = null): array
{
    $w = "s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ?";
    $p = [$from, $to];
    if ($loc) { $w .= ' AND s.location_id = ?'; $p[] = $loc; }
    if ($gouv) { $w .= " AND COALESCE(NULLIF((SELECT sh.gouvernorat FROM shipments sh WHERE sh.sale_id = s.id AND sh.gouvernorat <> '' LIMIT 1), ''), c.gouvernorat) = ?"; $p[] = $gouv; }
    $r = one("SELECT COUNT(*) n, COALESCE(SUM(s.total),0) t FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE $w", $p);
    $inv = $gouv ? 0 : (int)val("SELECT COALESCE(SUM(total),0) FROM documents WHERE type = 'facture' AND sale_id IS NULL AND status <> 'annulee' AND date BETWEEN ? AND ?" . ($loc ? ' AND location_id = ' . (int)$loc : ''), [$from, $to]);
    return ['n' => (int)$r['n'], 't' => (int)$r['t'] + $inv, 'inv' => $inv, 'w' => $w, 'p' => $p];
}

function x_answer_ca(array $c): string
{
    $per = $c['period'];
    $r = x_ca($per['from'], $per['to'], $c['loc'], $c['gouv']);
    // Showroom sans vente sur la période : « ventes à Nabeul » = sans doute les clients du gouvernorat
    if ($c['loc'] && !$r['n']) {
        $city = trim(str_replace(['Showroom ', 'showroom '], '', location($c['loc'])['name']));
        $g = in_array($city, GOUVERNORATS, true) ? x_ca($per['from'], $per['to'], null, $city) : null;
        if ($g && $g['n']) {
            return x_p('Le ' . e(location($c['loc'])['name']) . ' n’a pas de vente enregistrée sur la période. Ventes à des clients du gouvernorat de ' . e($city)
                . ' : <b>' . $g['n'] . ' vente(s)</b>, <b>' . money0($g['t']) . '</b>.') . x_period_note($per, $c['assumed']);
        }
    }
    $where = ($c['loc'] ? ' au ' . location($c['loc'])['name'] : '') . ($c['gouv'] ? ' pour des clients de ' . $c['gouv'] : '');
    $countFocus = x_has($c['s'], '\b(combien de ventes|nombre de ventes|nombre)\b');
    $h = x_p($countFocus
        ? '<b>' . $r['n'] . ' vente' . ($r['n'] > 1 ? 's' : '') . '</b>' . e($where) . ' — chiffre d’affaires <b>' . money0($r['t']) . '</b>.'
        : 'Chiffre d’affaires' . e($where) . ' : <b>' . money0($r['t']) . '</b> (' . $r['n'] . ' vente' . ($r['n'] > 1 ? 's' : '') . ($r['n'] ? ', panier moyen ' . money0(intdiv($r['t'] - $r['inv'], max(1, $r['n']))) : '') . ($r['inv'] ? ', dont factures ' . money0($r['inv']) : '') . ').');
    $h .= x_period_note($per, $c['assumed']);
    $days = (strtotime($per['to']) - strtotime($per['from'])) / 86400;
    if ($r['n'] && ($days > 40 || x_has($c['s'], '\bpar mois\b')) && !x_has($c['s'], '\bpar jour\b')) {
        $rows = all("SELECT strftime('%Y-%m', s.created_at) m, COUNT(*) n, SUM(s.total) t FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE {$r['w']} GROUP BY m ORDER BY m", $r['p']);
        $best = $rows ? array_reduce($rows, fn($a, $b) => $a && $a['t'] >= $b['t'] ? $a : $b) : null;
        $h .= x_table(['Mois', 'Ventes', 'CA', 'Panier moyen'], array_map(fn($x) => [x_month_label($x['m']), $x['n'], money0($x['t']), money0(intdiv((int)$x['t'], max(1, (int)$x['n'])))], $rows), [1, 2, 3]);
        if ($best && count($rows) > 1) $h .= x_p('Meilleur mois : <b>' . e(x_month_label($best['m'])) . '</b> avec ' . money0($best['t']) . '.');
    } elseif ($r['n'] && ($days >= 1 && $days <= 40 || x_has($c['s'], '\bpar jour\b'))) {
        $rows = all("SELECT date(s.created_at) d, COUNT(*) n, SUM(s.total) t FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE {$r['w']} GROUP BY d ORDER BY t DESC LIMIT 5", $r['p']);
        if (count($rows) > 1) $h .= x_p('Meilleurs jours : ' . implode(' · ', array_map(fn($x) => fdate($x['d']) . ' (' . money0($x['t']) . ')', $rows)) . '.');
    }
    if (!$c['loc'] && $r['n'] && count(locations(true, 'showroom')) > 1 && !$c['gouv']) {
        $parts = [];
        foreach (locations(true, 'showroom') as $l) { $x = x_ca($per['from'], $per['to'], (int)$l['id']); if ($x['t']) $parts[] = e($l['name']) . ' ' . money0($x['t']); }
        if (count($parts) > 1) $h .= x_p('Par showroom : ' . implode(' · ', $parts) . '.');
    }
    return $h . x_p(x_btn('Voir les ventes', url('sales', ['from' => $per['from'], 'to' => $per['to']])), 'x-actions');
}

function x_answer_top_products(array $c): string
{
    $per = $c['period'];
    $n = preg_match('/\btop ?(\d{1,2})\b|\b(\d{1,2}) (premiers|meilleurs|produits|plus)\b/', $c['s'], $m) ? max(3, min(30, (int)($m[1] ?: $m[2]))) : 10;
    $byQty = x_has($c['s'], '\b(quantite|nombre|combien|unites)\b');
    $rows = all("SELECT COALESCE(p.name, si.label) name, SUM(si.qty) q, SUM(si.total) t FROM sale_items si JOIN sales s ON s.id = si.sale_id
        LEFT JOIN products p ON p.id = si.product_id WHERE s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ?" . ($c['loc'] ? ' AND s.location_id = ' . (int)$c['loc'] : '') . "
        GROUP BY COALESCE(CAST(si.product_id AS TEXT), UPPER(si.label)) ORDER BY " . ($byQty ? 'q DESC, t DESC' : 't DESC') . " LIMIT $n", [$per['from'], $per['to']]);
    if (!$rows) return x_p('Aucune vente sur cette période.') . x_period_note($per, $c['assumed']);
    $tot = (int)val("SELECT COALESCE(SUM(si.total),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ?", [$per['from'], $per['to']]);
    $h = x_p('Le produit le plus vendu' . ($byQty ? ' (en quantité)' : ' (en chiffre d’affaires)') . ' est <b>' . e($rows[0]['name']) . '</b> : ' . (int)$rows[0]['q'] . ' vendu(s), ' . money0($rows[0]['t']) . '.');
    $h .= x_period_note($per, $c['assumed']);
    $i = 0;
    $h .= x_table(['#', 'Produit', 'Quantité', 'CA', 'Part du CA'], array_map(function ($x) use (&$i, $tot) { $i++; return [$i, $x['name'], (int)$x['q'], money0($x['t']), x_pct((int)$x['t'], $tot)]; }, $rows), [0, 2, 3, 4]);
    return $h;
}

function x_answer_product(array $c): string
{
    $per = $c['period'];
    [$cond, $p] = x_product_sql($c['product']);
    $base = "FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN products p ON p.id = si.product_id WHERE s.status <> 'annulee' AND $cond AND date(s.created_at) BETWEEN ? AND ?" . ($c['loc'] ? ' AND s.location_id = ' . (int)$c['loc'] : '');
    $pp = array_merge($p, [$per['from'], $per['to']]);
    $r = one("SELECT COALESCE(SUM(si.qty),0) q, COALESCE(SUM(si.total),0) t, COUNT(DISTINCT s.id) n $base", $pp);
    $label = $c['product']['label'];
    $h = x_p('<b>' . (int)$r['q'] . '</b> ' . e($label) . ' vendu(s) — <b>' . money0($r['t']) . '</b>' . ((int)$r['q'] ? ', prix moyen ' . money0(intdiv((int)$r['t'], max(1, (int)$r['q']))) : '') . '.');
    $h .= x_period_note($per, $c['assumed']);
    if ((int)$r['q']) {
        $days = (strtotime($per['to']) - strtotime($per['from'])) / 86400;
        if ($days > 40) {
            $rows = all("SELECT strftime('%Y-%m', s.created_at) m, SUM(si.qty) q, SUM(si.total) t $base GROUP BY m ORDER BY m", $pp);
            $h .= x_table(['Mois', 'Quantité', 'CA'], array_map(fn($x) => [x_month_label($x['m']), (int)$x['q'], money0($x['t'])], $rows), [1, 2]);
        }
        $det = all("SELECT COALESCE(p.name, si.label) name, SUM(si.qty) q, SUM(si.total) t $base GROUP BY name ORDER BY q DESC LIMIT 8", $pp);
        if (count($det) > 1) $h .= x_p('Détail : ' . implode(' · ', array_map(fn($x) => e($x['name']) . ' ×' . (int)$x['q'], $det)) . '.');
        $g = all("SELECT COALESCE(NULLIF((SELECT sh.gouvernorat FROM shipments sh WHERE sh.sale_id = s.id AND sh.gouvernorat <> '' LIMIT 1), ''), NULLIF(c.gouvernorat, ''), 'non précisé') g, SUM(si.qty) q
            FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN clients c ON c.id = s.client_id WHERE s.status <> 'annulee' AND $cond AND date(s.created_at) BETWEEN ? AND ? GROUP BY g ORDER BY q DESC LIMIT 4", $pp);
        if ($g) $h .= x_p('Surtout vendu à : ' . implode(' · ', array_map(fn($x) => e($x['g']) . ' (' . (int)$x['q'] . ')', $g)) . '.');
    }
    if ($c['product']['ids']) {
        $st = (int)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE product_id IN (' . implode(',', $c['product']['ids']) . ')');
        $h .= x_p('Stock enregistré actuellement : <b>' . $st . '</b>' . ($st <= 0 ? ' (l’inventaire n’est peut-être pas encore saisi)' : '') . '.', 'muted small');
    }
    return $h;
}

function x_answer_carriers_owed(array $c): string
{
    $f = $c['carriers'] ? ' AND sh.carrier_id IN (' . implode(',', array_keys($c['carriers'])) . ')' : '';
    $rows = all("SELECT ca.name, COUNT(*) n, SUM(sh.cod_amount) t, MIN(date(COALESCE(sh.delivered_at, sh.planned_date, sh.created_at))) since FROM shipments sh JOIN carriers ca ON ca.id = sh.carrier_id
        WHERE sh.status = 'livree' AND sh.cod_collected = 0 AND sh.cod_amount > 0$f GROUP BY ca.id ORDER BY t DESC");
    $transit = all("SELECT ca.name, COUNT(*) n, SUM(sh.cod_amount) t FROM shipments sh JOIN carriers ca ON ca.id = sh.carrier_id
        WHERE sh.status = 'en_cours' AND sh.cod_collected = 0 AND sh.cod_amount > 0$f GROUP BY ca.id ORDER BY t DESC");
    $sum = array_sum(array_column($rows, 't'));
    $h = $rows
        ? x_p('Les transporteurs doivent encore reverser <b>' . money0($sum) . '</b> (livraisons faites, argent encaissé chez les clients) : ' . e($rows[0]['name']) . ' en tête avec ' . money0($rows[0]['t']) . '.')
            . x_table(['Transporteur', 'Livraisons', 'À récupérer', 'Depuis le'], array_map(fn($x) => [$x['name'], (int)$x['n'], money0($x['t']), fdate($x['since'])], $rows), [1, 2])
        : x_p('Aucun transporteur ne vous doit d’argent pour des livraisons terminées' . ($c['carriers'] ? ' (' . e(implode(', ', $c['carriers'])) . ')' : '') . '.');
    if ($transit) {
        $h .= x_p('En plus, <b>' . money0(array_sum(array_column($transit, 't'))) . '</b> sont encore en route (colis pas encore livrés, paiement à la livraison) : '
            . implode(' · ', array_map(fn($x) => e($x['name']) . ' ' . money0($x['t']) . ' (' . (int)$x['n'] . ')', $transit)) . '.');
    }
    return $h . x_p(x_btn('Argent à récupérer', url('deliveries', ['status' => 'a_recuperer'])) . ' ' . x_btn('Transporteurs', url('deliveries', ['a' => 'carriers'])), 'x-actions');
}

function x_answer_carriers(array $c): string
{
    $per = $c['period'];
    $f = $c['carriers'] ? ' AND sh.carrier_id IN (' . implode(',', array_keys($c['carriers'])) . ')' : '';
    $rows = all("SELECT ca.name, COUNT(*) n, SUM(sh.status = 'livree') ok, SUM(sh.status = 'retour') ret, SUM(sh.fee) fee, SUM(sh.client_fee) cfee,
            SUM(CASE WHEN sh.status = 'livree' AND sh.cod_collected = 0 THEN sh.cod_amount ELSE 0 END) owed
        FROM shipments sh JOIN carriers ca ON ca.id = sh.carrier_id WHERE sh.status <> 'annulee' AND date(COALESCE(sh.planned_date, sh.created_at)) BETWEEN ? AND ?$f
        GROUP BY ca.id ORDER BY n DESC", [$per['from'], $per['to']]);
    if (!$rows) return x_p('Aucune livraison confiée à un transporteur sur cette période.') . x_period_note($per, $c['assumed']);
    $top = $rows[0];
    $h = x_p(count($rows) === 1
        ? '<b>' . e($top['name']) . '</b> : ' . (int)$top['n'] . ' livraison(s), ' . (int)$top['ok'] . ' livrée(s), ' . (int)$top['ret'] . ' retour(s) (' . x_pct((int)$top['ret'], (int)$top['n']) . '), frais payés ' . money0($top['fee']) . '.'
        : 'Transporteur le plus utilisé : <b>' . e($top['name']) . '</b> (' . (int)$top['n'] . ' livraisons). ' . count($rows) . ' transporteurs au total.');
    $h .= x_period_note($per, $c['assumed']);
    $h .= x_table(['Transporteur', 'Livraisons', 'Livrées', 'Retours', 'Taux de retour', 'Frais payés', 'Payé par clients', 'À récupérer'],
        array_map(fn($x) => [$x['name'], (int)$x['n'], (int)$x['ok'], (int)$x['ret'], x_pct((int)$x['ret'], (int)$x['n']), money0($x['fee']), money0($x['cfee']), (int)$x['owed'] ? money0($x['owed']) : '—'], $rows), [1, 2, 3, 4, 5, 6, 7]);
    $worst = array_values(array_filter($rows, fn($x) => (int)$x['n'] >= 5));
    usort($worst, fn($a, $b) => ((int)$b['ret'] / max(1, (int)$b['n'])) <=> ((int)$a['ret'] / max(1, (int)$a['n'])));
    if ($worst && (int)$worst[0]['ret'] > 0 && count($rows) > 1) $h .= x_p('À surveiller : <b>' . e($worst[0]['name']) . '</b> a le plus fort taux de retour (' . x_pct((int)$worst[0]['ret'], (int)$worst[0]['n']) . ').');
    return $h . x_p(x_btn('Transporteurs', url('deliveries', ['a' => 'carriers'])), 'x-actions');
}

function x_answer_shipments_open(array $c): string
{
    $w = ["sh.status IN ('a_preparer','en_cours')"];
    $p = [];
    if (x_has($c['s'], '\b(retard|en retard)\b')) { $w[] = 'sh.planned_date < ?'; $p[] = today(); }
    if ($c['carriers']) $w[] = 'sh.carrier_id IN (' . implode(',', array_keys($c['carriers'])) . ')';
    if ($c['gouv']) { $w[] = 'sh.gouvernorat = ?'; $p[] = $c['gouv']; }
    if (x_has($c['s'], '\b(chez le transporteur|chez les transporteurs)\b')) $w[] = "sh.holder = 'transporteur'";
    $rows = all('SELECT sh.*, ca.name carrier FROM shipments sh LEFT JOIN carriers ca ON ca.id = sh.carrier_id WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(sh.planned_date, sh.created_at) LIMIT 40', $p);
    $back = (int)val("SELECT COUNT(*) FROM shipments WHERE status = 'retour' AND holder = 'retour'");
    if (!$rows) return x_p('Aucune livraison en cours' . (count($w) > 1 ? ' avec ces critères' : '') . '. Toute la marchandise vendue est livrée ou au dépôt.') . ($back ? x_p("$back retour(s) sont en route vers le dépôt.") : '');
    $items = [];
    foreach (all('SELECT shipment_id, label, qty FROM shipment_items WHERE shipment_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')') as $it) $items[$it['shipment_id']][] = $it;
    $byHolder = [];
    foreach ($rows as $r) $byHolder[SHIP_HOLDERS[$r['holder']] ?? $r['holder']] = ($byHolder[SHIP_HOLDERS[$r['holder']] ?? $r['holder']] ?? 0) + 1;
    $h = x_p('<b>' . count($rows) . ' livraison(s) en cours</b> : ' . e(implode(' · ', array_map(fn($k, $v) => mb_strtolower($k) . " $v", array_keys($byHolder), $byHolder))) . '.');
    $h .= x_table(['N°', 'Client', 'Destination', 'Marchandise', 'Où est-elle ?', 'Depuis'], array_map(fn($r) => [
        x_raw('<a href="' . e(url('deliveries', ['a' => 'edit', 'id' => $r['id']])) . '">' . e($r['number']) . '</a>'), $r['recipient'],
        trim($r['gouvernorat'] . ($r['address'] ? ' — ' . mb_strimwidth($r['address'], 0, 30, '…') : ''), ' —'),
        isset($items[$r['id']]) ? shipment_items_text($items[$r['id']], 2) : '—', ship_holder_label($r, $r['carrier']),
        max(0, (int)floor((time() - strtotime((string)($r['planned_date'] ?: $r['created_at']))) / 86400)) . ' j',
    ], $rows), [5]);
    $old = array_filter($rows, fn($r) => (time() - strtotime((string)($r['planned_date'] ?: $r['created_at']))) > 7 * 86400);
    if ($old) $h .= x_p('⚠ ' . count($old) . ' livraison(s) attendent depuis plus de 7 jours : à relancer.');
    if ($back) $h .= x_p("$back retour(s) sont en route vers le dépôt.");
    return $h . x_p(x_btn('Livraisons à faire', url('deliveries')), 'x-actions');
}

function x_answer_returns(array $c): string
{
    $per = $c['period'];
    $f = $c['carriers'] ? ' AND sh.carrier_id IN (' . implode(',', array_keys($c['carriers'])) . ')' : '';
    $all = (int)val("SELECT COUNT(*) FROM shipments sh WHERE sh.status <> 'annulee' AND date(COALESCE(sh.planned_date, sh.created_at)) BETWEEN ? AND ?$f", [$per['from'], $per['to']]);
    $rows = all("SELECT sh.*, ca.name carrier FROM shipments sh LEFT JOIN carriers ca ON ca.id = sh.carrier_id WHERE sh.status = 'retour' AND date(COALESCE(sh.planned_date, sh.created_at)) BETWEEN ? AND ?$f ORDER BY sh.planned_date DESC", [$per['from'], $per['to']]);
    $lost = (int)val("SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE s.status = 'annulee' AND s.cancel_reason LIKE 'Retour%' AND date(s.created_at) BETWEEN ? AND ?", [$per['from'], $per['to']]);
    $h = x_p('<b>' . count($rows) . ' retour(s)</b> sur ' . $all . ' livraison(s), soit ' . x_pct(count($rows), $all) . ($lost ? ' — ventes annulées pour ' . money0($lost) : '') . '.');
    $h .= x_period_note($per, $c['assumed']);
    if ($rows) {
        $by = [];
        foreach ($rows as $r) $by[$r['carrier'] ?: '—'] = ($by[$r['carrier'] ?: '—'] ?? 0) + 1;
        arsort($by);
        $h .= x_p('Par transporteur : ' . e(implode(' · ', array_map(fn($k, $v) => "$k $v", array_keys($by), $by))) . '.');
        $h .= x_table(['N°', 'Date', 'Client', 'Destination', 'Raison / note'], array_map(fn($r) => [
            x_raw('<a href="' . e(url('deliveries', ['a' => 'edit', 'id' => $r['id']])) . '">' . e($r['number']) . '</a>'), fdate($r['planned_date']), $r['recipient'], $r['gouvernorat'],
            mb_strimwidth(preg_replace('/^Frais : /', '', (string)$r['note']), 0, 60, '…'),
        ], array_slice($rows, 0, 15)));
    }
    return $h . x_p(x_btn('Voir les retours', url('deliveries', ['status' => 'retour'])), 'x-actions');
}

function x_answer_repairs(array $c): string
{
    $w = ['1=1'];
    $p = [];
    $techName = $c['tech'];
    if ($techName) { $w[] = 'technician = ? COLLATE NOCASE'; $p[] = $techName; }
    if ($c['gouv']) { $w[] = 'gouvernorat = ?'; $p[] = $c['gouv']; }
    $ws = implode(' AND ', $w);
    $st = one("SELECT COUNT(*) n, SUM(status = 'nouveau') nv, SUM(status = 'en_cours') ec, SUM(status = 'repare') ok, SUM(status = 'annule') an,
        COALESCE(SUM(paid),0) paid, COALESCE(SUM(CASE WHEN status = 'repare' AND paid < amount THEN amount - paid END),0) owed FROM repairs WHERE $ws", $p);
    $who = $techName ? ' chez ' . $techName : '';
    $h = x_p('Réparations & SAV' . e($who) . ' : <b>' . (int)$st['nv'] . ' nouvelle(s) demande(s)</b> à planifier, <b>' . (int)$st['ec'] . ' en cours</b>, ' . (int)$st['ok'] . ' réparée(s), ' . (int)$st['an'] . ' annulée(s) — ' . (int)$st['n'] . ' au total.');
    if (x_has($c['s'], '\b(argent|encaiss|paye|payee|montant|combien a|gagne|recette)\b') || (int)$st['owed']) {
        $h .= x_p('Encaissé : <b>' . money0($st['paid']) . '</b>' . ((int)$st['owed'] ? ' · reste à encaisser sur des réparations terminées : <b>' . money0($st['owed']) . '</b>' : '') . '.');
    }
    if (!$techName) {
        $t = all("SELECT technician, COUNT(*) n, SUM(status IN ('nouveau','en_cours')) open, SUM(status = 'repare') ok FROM repairs WHERE technician <> '' GROUP BY technician ORDER BY open DESC, n DESC");
        if ($t) $h .= x_table(['Réparateur', 'À traiter', 'Réparées', 'Total'], array_map(fn($x) => [$x['technician'], (int)$x['open'], (int)$x['ok'], (int)$x['n']], $t), [1, 2, 3]);
    }
    if (x_has($c['s'], '\b(attente|en cours|a faire|a traiter|ouvert|liste|lesquelles|quelles|pendantes?|planifier)\b') || $techName) {
        $open = all("SELECT * FROM repairs WHERE $ws AND status IN ('nouveau','en_cours') ORDER BY CASE status WHEN 'en_cours' THEN 0 ELSE 1 END, id DESC LIMIT 20", $p);
        if ($open) $h .= x_table(['N°', 'Client', 'Téléphone', 'Machine · panne', 'Lieu', 'Statut'], array_map(fn($r) => [
            x_raw('<a href="' . e(url('repairs', ['a' => 'edit', 'id' => $r['id']])) . '">' . e($r['number']) . '</a>'), $r['client_name'], $r['phone'],
            mb_strimwidth(trim($r['product'] . ($r['issue'] ? ' · ' . $r['issue'] : ''), ' ·'), 0, 50, '…'), $r['gouvernorat'] ?: mb_strimwidth($r['address'], 0, 20, '…'), REPAIR_STATUS[$r['status']] ?? '',
        ], $open));
    }
    $pannes = all("SELECT LOWER(product) prod, COUNT(*) n FROM repairs WHERE $ws AND product <> '' GROUP BY prod ORDER BY n DESC LIMIT 3", $p);
    if ($pannes && x_has($c['s'], '\b(panne|machine|produit|souvent|plus)\b')) $h .= x_p('Machines les plus réparées : ' . e(implode(' · ', array_map(fn($x) => $x['prod'] . ' (' . $x['n'] . ')', $pannes))) . '.');
    return $h . x_p(x_btn('Réparations à traiter', url('repairs', array_filter(['tech' => $techName]))), 'x-actions');
}

function x_answer_technicians(array $c): string
{
    $per = $c['period'];
    $rows = all("SELECT technician, COUNT(*) n, SUM(technician_fee) fee FROM shipments WHERE technician <> '' AND status <> 'annulee' AND date(COALESCE(planned_date, created_at)) BETWEEN ? AND ?"
        . ($c['tech'] ? ' AND technician = ? COLLATE NOCASE' : '') . ' GROUP BY technician ORDER BY n DESC', array_merge([$per['from'], $per['to']], $c['tech'] ? [$c['tech']] : []));
    $reps = [];
    foreach (all("SELECT technician, COUNT(*) n, SUM(status IN ('nouveau','en_cours')) open FROM repairs WHERE technician <> '' GROUP BY technician") as $r) $reps[mb_strtolower($r['technician'])] = $r;
    if (!$rows) return x_p('Aucune installation par un technicien sur cette période.') . x_period_note($per, $c['assumed']);
    $h = x_p('Installations : <b>' . array_sum(array_column($rows, 'n')) . '</b>, primes des techniciens : <b>' . money0(array_sum(array_column($rows, 'fee'))) . '</b>.');
    $h .= x_period_note($per, $c['assumed']);
    $h .= x_table(['Technicien', 'Installations', 'Primes', 'Réparations (toutes)', 'Réparations à traiter'],
        array_map(fn($x) => [$x['technician'], (int)$x['n'], money0($x['fee']), (int)($reps[mb_strtolower($x['technician'])]['n'] ?? 0), (int)($reps[mb_strtolower($x['technician'])]['open'] ?? 0)], $rows), [1, 2, 3, 4]);
    return $h;
}

function x_answer_gouv(array $c): string
{
    $per = $c['period'];
    $rows = all("SELECT COALESCE(NULLIF((SELECT sh.gouvernorat FROM shipments sh WHERE sh.sale_id = s.id AND sh.gouvernorat <> '' LIMIT 1), ''), NULLIF(c.gouvernorat, ''), 'Non précisé') g,
            COUNT(*) n, SUM(s.total) t FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ?
            GROUP BY g ORDER BY t DESC", [$per['from'], $per['to']]);
    if (!$rows) return x_p('Aucune vente sur cette période.') . x_period_note($per, $c['assumed']);
    $tot = array_sum(array_column($rows, 't'));
    $known = array_values(array_filter($rows, fn($r) => $r['g'] !== 'Non précisé'));
    $h = x_p('On vend le plus à <b>' . e($known[0]['g'] ?? $rows[0]['g']) . '</b> (' . money0(($known[0] ?? $rows[0])['t']) . ', ' . x_pct((int)($known[0] ?? $rows[0])['t'], $tot) . ' du CA). Ventes dans ' . count($known) . ' gouvernorat(s).');
    $h .= x_period_note($per, $c['assumed']);
    $h .= x_table(['Gouvernorat', 'Ventes', 'CA', 'Part'], array_map(fn($x) => [$x['g'], (int)$x['n'], money0($x['t']), x_pct((int)$x['t'], $tot)], array_slice($rows, 0, 24)), [1, 2, 3]);
    $missing = array_diff(GOUVERNORATS, array_column($rows, 'g'));
    if ($missing && count($missing) < 15) $h .= x_p('Aucune vente vers : ' . e(implode(', ', $missing)) . ' — des zones à conquérir.', 'muted small');
    return $h;
}

function x_answer_client(array $c): string
{
    $phone = $c['phone'];
    $cl = $phone ? one('SELECT * FROM clients WHERE phone = ? OR phone2 = ?', [$phone, $phone]) : null;
    if (!$cl && $c['client_name'] !== '') $cl = one('SELECT * FROM clients WHERE name LIKE ? ORDER BY id DESC LIMIT 1', ['%' . $c['client_name'] . '%']);
    if (!$cl) {
        $r = $phone ? one('SELECT * FROM repairs WHERE phone = ? OR phone2 = ? ORDER BY id DESC LIMIT 1', [$phone, $phone]) : null;
        return x_p('Aucun client trouvé' . ($phone ? ' avec le numéro ' . e($phone) : '') . '.' . ($r ? ' Mais une réparation existe : ' . '<a href="' . e(url('repairs', ['a' => 'edit', 'id' => $r['id']])) . '">' . e($r['number']) . '</a>.' : ''));
    }
    $sales = all("SELECT s.id, s.number, date(s.created_at) d, s.total, s.status, GROUP_CONCAT(si.label, ' + ') items FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id WHERE s.client_id = ? GROUP BY s.id ORDER BY s.created_at DESC LIMIT 10", [$cl['id']]);
    $reps = all('SELECT id, number, product, issue, status FROM repairs WHERE client_id = ? ORDER BY id DESC LIMIT 5', [$cl['id']]);
    $ships = all('SELECT id, number, status, holder, holder_note, technician, carrier_id FROM shipments WHERE client_id = ? ORDER BY id DESC LIMIT 5', [$cl['id']]);
    $tot = array_sum(array_map(fn($s) => $s['status'] === 'annulee' ? 0 : (int)$s['total'], $sales));
    $h = x_p('<b>' . e($cl['name']) . '</b> — ' . e(trim($cl['phone'] . ($cl['phone2'] ? ' / ' . $cl['phone2'] : '') . ($cl['gouvernorat'] ? ' · ' . $cl['gouvernorat'] : '') . ($cl['address'] ? ' · ' . $cl['address'] : ''))) . '. '
        . count($sales) . ' achat(s) pour ' . money0($tot) . ', ' . count($reps) . ' réparation(s).');
    if ($sales) $h .= x_table(['Vente', 'Date', 'Produits', 'Montant'], array_map(fn($s) => [x_raw('<a href="' . e(url('sales', ['a' => 'view', 'id' => $s['id']])) . '">' . e($s['number']) . '</a>'), fdate($s['d']), (string)$s['items'], money0($s['total']) . ($s['status'] === 'annulee' ? ' (annulée)' : '')], $sales), [3]);
    if ($reps) $h .= x_p('Réparations : ' . implode(' · ', array_map(fn($r) => '<a href="' . e(url('repairs', ['a' => 'edit', 'id' => $r['id']])) . '">' . e($r['number']) . '</a> ' . e(trim($r['product'] . ' ' . $r['issue']) . ' (' . mb_strtolower(REPAIR_STATUS[$r['status']] ?? '') . ')'), $reps)) . '.');
    if ($ships) {
        $open = array_filter($ships, fn($s) => in_array($s['status'], ['a_preparer', 'en_cours'], true));
        if ($open) $h .= x_p('Livraison en cours : ' . implode(' · ', array_map(fn($s) => '<a href="' . e(url('deliveries', ['a' => 'edit', 'id' => $s['id']])) . '">' . e($s['number']) . '</a> ' . e(mb_strtolower(ship_holder_label($s))), $open)) . '.');
    }
    $wa = strlen(normalize_phone($cl['phone'])) === 8 ? x_btn('WhatsApp', 'https://wa.me/216' . normalize_phone($cl['phone'])) : '';
    return $h . x_p(x_btn('Fiche client', url('clients', ['a' => 'view', 'id' => $cl['id']])) . ' ' . $wa, 'x-actions');
}

function x_answer_top_clients(array $c): string
{
    $per = $c['period'];
    $count = (int)val('SELECT COUNT(*) FROM clients');
    $new = (int)val('SELECT COUNT(*) FROM clients WHERE date(created_at) BETWEEN ? AND ?', [$per['from'], $per['to']]);
    if (x_has($c['s'], '\b(combien de clients|nombre de clients)\b')) {
        return x_p('<b>' . $count . ' clients</b> enregistrés, dont <b>' . $new . '</b> créés sur la période.') . x_period_note($per, $c['assumed']);
    }
    $rows = all("SELECT c.id, c.name, c.phone, c.gouvernorat, COUNT(*) n, SUM(s.total) t FROM sales s JOIN clients c ON c.id = s.client_id
        WHERE s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ? GROUP BY c.id ORDER BY t DESC LIMIT 10", [$per['from'], $per['to']]);
    if (!$rows) return x_p('Aucune vente rattachée à un client sur cette période.');
    $fid = (int)val("SELECT COUNT(*) FROM (SELECT client_id FROM sales WHERE status <> 'annulee' AND client_id IS NOT NULL GROUP BY client_id HAVING COUNT(*) > 1)");
    $h = x_p('Meilleur client : <b>' . e($rows[0]['name']) . '</b> (' . money0($rows[0]['t']) . '). ' . $count . ' clients au total, ' . $fid . ' ont acheté plus d’une fois.');
    $h .= x_period_note($per, $c['assumed']);
    return $h . x_table(['Client', 'Téléphone', 'Gouvernorat', 'Achats', 'Montant'], array_map(fn($x) => [x_raw('<a href="' . e(url('clients', ['a' => 'view', 'id' => $x['id']])) . '">' . e($x['name']) . '</a>'), $x['phone'], $x['gouvernorat'], (int)$x['n'], money0($x['t'])], $rows), [3, 4]);
}

function x_answer_stock(array $c): string
{
    if ($c['product'] && $c['product']['ids']) {
        $rows = all('SELECT p.name, l.name loc, s.qty FROM stock s JOIN products p ON p.id = s.product_id JOIN locations l ON l.id = s.location_id WHERE s.product_id IN (' . implode(',', $c['product']['ids']) . ') ORDER BY p.name, l.position');
        $tot = array_sum(array_column($rows, 'qty'));
        return x_p('Stock de <b>' . e($c['product']['label']) . '</b> : <b>' . $tot . '</b>.') . x_table(['Produit', 'Emplacement', 'Quantité'], array_map(fn($x) => [$x['name'], $x['loc'], (int)$x['qty']], $rows), [2])
            . ($tot <= 0 ? x_p('Le stock n’a peut-être pas encore été saisi (Stock › Inventaire).', 'muted small') : '');
    }
    $low = all('SELECT p.name, p.min_stock, COALESCE(SUM(s.qty),0) q FROM products p LEFT JOIN stock s ON s.product_id = p.id WHERE p.active = 1 GROUP BY p.id HAVING q <= p.min_stock AND p.min_stock > 0 ORDER BY q LIMIT 20');
    $units = (int)val('SELECT COALESCE(SUM(qty),0) FROM stock WHERE qty > 0');
    $value = (int)val('SELECT COALESCE(SUM(s.qty * COALESCE(p.cost, p.price)),0) FROM stock s JOIN products p ON p.id = s.product_id WHERE s.qty > 0');
    $h = x_p('Stock total : <b>' . $units . '</b> article(s)' . ($value ? ', valeur ' . money0($value) : '') . '.' . ($units === 0 ? ' L’inventaire n’a pas encore été saisi (Stock › Inventaire).' : ''));
    if ($low) $h .= x_p('Sous le stock minimum :') . x_table(['Produit', 'Stock', 'Minimum'], array_map(fn($x) => [$x['name'], (int)$x['q'], (int)$x['min_stock']], $low), [1, 2]);
    return $h . x_p(x_btn('Stock', url('stock')), 'x-actions');
}

function x_answer_expenses(array $c): string
{
    $per = $c['period'];
    $rows = all('SELECT category, SUM(amount) t, COUNT(*) n FROM expenses WHERE date BETWEEN ? AND ? GROUP BY category ORDER BY t DESC', [$per['from'], $per['to']]);
    $ship = one("SELECT COALESCE(SUM(fee),0) fee, COALESCE(SUM(technician_fee),0) tf, COALESCE(SUM(client_fee),0) cf FROM shipments WHERE status <> 'annulee' AND date(COALESCE(planned_date, created_at)) BETWEEN ? AND ?", [$per['from'], $per['to']]);
    $exp = array_sum(array_column($rows, 't'));
    $h = x_p('Dépenses enregistrées : <b>' . money0($exp) . '</b>. Sur les livraisons : <b>' . money0($ship['fee']) . '</b> payés aux transporteurs et <b>' . money0($ship['tf']) . '</b> de primes aux techniciens (les clients ont payé ' . money0($ship['cf']) . ' de transport).');
    $h .= x_period_note($per, $c['assumed']);
    if ($rows) $h .= x_table(['Catégorie', 'Nombre', 'Montant'], array_map(fn($x) => [EXPENSE_CATEGORIES[$x['category']] ?? $x['category'], (int)$x['n'], money0($x['t'])], $rows), [1, 2]);
    if (x_has($c['s'], '\b(transporteurs?|techniciens?|primes?|camion)\b')) {
        $t = all("SELECT ca.name, COUNT(*) n, SUM(sh.fee) t FROM shipments sh JOIN carriers ca ON ca.id = sh.carrier_id WHERE sh.status <> 'annulee' AND sh.fee > 0 AND date(COALESCE(sh.planned_date, sh.created_at)) BETWEEN ? AND ? GROUP BY ca.id ORDER BY t DESC", [$per['from'], $per['to']]);
        if ($t) $h .= x_p('Payé par transporteur : ' . e(implode(' · ', array_map(fn($x) => $x['name'] . ' ' . money0($x['t']), $t))) . '.');
        $tt = all("SELECT technician, SUM(technician_fee) t FROM shipments WHERE technician <> '' AND technician_fee > 0 AND date(COALESCE(planned_date, created_at)) BETWEEN ? AND ? GROUP BY technician ORDER BY t DESC", [$per['from'], $per['to']]);
        if ($tt) $h .= x_p('Primes par technicien : ' . e(implode(' · ', array_map(fn($x) => $x['technician'] . ' ' . money0($x['t']), $tt))) . '.');
    }
    return $h . x_p(x_btn('Dépenses', url('finances', ['a' => 'expenses'])), 'x-actions');
}

function x_answer_payments(array $c): string
{
    $per = $c['period'];
    $by = [];
    foreach (all('SELECT method, SUM(amount) t, COUNT(*) n FROM payments WHERE date(created_at) BETWEEN ? AND ? GROUP BY method', [$per['from'], $per['to']]) as $r) $by[$r['method']] = ['t' => (int)$r['t'], 'n' => (int)$r['n']];
    foreach (all('SELECT method, SUM(amount) t, COUNT(*) n FROM document_payments WHERE date(COALESCE(date, created_at)) BETWEEN ? AND ? GROUP BY method', [$per['from'], $per['to']]) as $r) {
        $by[$r['method']] = ['t' => ($by[$r['method']]['t'] ?? 0) + (int)$r['t'], 'n' => ($by[$r['method']]['n'] ?? 0) + (int)$r['n']];
    }
    uasort($by, fn($a, $b) => $b['t'] <=> $a['t']);
    $tot = array_sum(array_column($by, 't'));
    if (!$by) return x_p('Aucun encaissement sur cette période.') . x_period_note($per, $c['assumed']);
    $h = x_p('Encaissé : <b>' . money0($tot) . '</b>. Mode le plus utilisé : <b>' . e(PAY_METHODS[array_key_first($by)] ?? array_key_first($by)) . '</b>.');
    $h .= x_period_note($per, $c['assumed']);
    return $h . x_table(['Mode de paiement', 'Paiements', 'Montant', 'Part'], array_map(fn($k, $x) => [PAY_METHODS[$k] ?? $k, $x['n'], money0($x['t']), x_pct($x['t'], $tot)], array_keys($by), $by), [1, 2, 3]);
}

/** Reste à encaisser : même calcul que le tableau de bord et Finances (receivables_summary). */
function x_receivables(): array
{
    $s = receivables_summary();
    $links = ['clients' => url('finances', ['a' => 'receivables']), 'carriers' => url('deliveries', ['status' => 'a_recuperer']),
        'invoices' => url('invoices', ['from' => '2000-01-01']), 'repairs' => url('repairs', ['status' => 'a_encaisser'])];
    $out = [];
    foreach ($links as $k => $href) $out[$s[$k]['label']] = [$s[$k]['t'], $href];
    return $out;
}

function x_answer_receivables(array $c): string
{
    $r = x_receivables();
    $tot = array_sum(array_column($r, 0));
    $h = x_p('Il reste <b>' . money0($tot) . '</b> à encaisser au total (même chiffre que le tableau de bord).');
    $h .= x_table(['Origine', 'Montant', ''], array_map(fn($k, $v) => [$k, money0($v[0]), x_raw($v[0] ? x_btn('Voir', $v[1]) : '')], array_keys($r), $r), [1]);
    $late = one("SELECT COUNT(*) n, COALESCE(SUM(net - paid),0) t FROM documents WHERE type = 'facture' AND status IN ('emise','partielle') AND due_date < ?", [today()]);
    if ((int)$late['n']) $h .= x_p('⚠ ' . (int)$late['n'] . ' facture(s) en retard de paiement : ' . money0($late['t']) . '.');
    return $h;
}

function x_answer_summary(array $c): string
{
    $per = $c['period'];
    $ca = x_ca($per['from'], $per['to']);
    $len = max(1, (int)round((strtotime($per['to']) - strtotime($per['from'])) / 86400) + 1);
    $prevTo = date('Y-m-d', strtotime($per['from'] . ' -1 day'));
    $prev = x_ca(date('Y-m-d', strtotime($prevTo . ' -' . ($len - 1) . ' days')), $prevTo);
    $paid = (int)val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE date(created_at) BETWEEN ? AND ?', [$per['from'], $per['to']])
        + (int)val('SELECT COALESCE(SUM(amount),0) FROM document_payments WHERE date(COALESCE(date, created_at)) BETWEEN ? AND ?', [$per['from'], $per['to']]);
    $exp = (int)val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE date BETWEEN ? AND ?', [$per['from'], $per['to']]);
    $ship = one("SELECT COUNT(*) n, SUM(status = 'retour') ret, COALESCE(SUM(fee + technician_fee),0) cost FROM shipments WHERE status <> 'annulee' AND date(COALESCE(planned_date, created_at)) BETWEEN ? AND ?", [$per['from'], $per['to']]);
    $open = (int)val("SELECT COUNT(*) FROM shipments WHERE status IN ('a_preparer','en_cours')");
    $rep = one("SELECT SUM(status IN ('nouveau','en_cours')) open FROM repairs");
    $recv = array_sum(array_column(x_receivables(), 0));
    $top = all("SELECT COALESCE(p.name, si.label) name, SUM(si.qty) q FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN products p ON p.id = si.product_id
        WHERE s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ? GROUP BY COALESCE(CAST(si.product_id AS TEXT), UPPER(si.label)) ORDER BY SUM(si.total) DESC LIMIT 3", [$per['from'], $per['to']]);
    $delta = $prev['t'] > 0 ? ($ca['t'] - $prev['t']) / $prev['t'] * 100 : null;
    $h = x_p('Bilan — ' . e($per['label']) . ' : chiffre d’affaires <b>' . money0($ca['t']) . '</b> (' . $ca['n'] . ' ventes)'
        . ($delta !== null ? ', <b class="' . ($delta >= 0 ? 'up' : 'down') . '">' . ($delta >= 0 ? '▲' : '▼') . ' ' . pct(abs($delta)) . '</b> par rapport à la période précédente' : '') . '.');
    $h .= x_period_note($per, $c['assumed']);
    $h .= x_table(['Indicateur', 'Valeur'], [
        ['Chiffre d’affaires', money0($ca['t'])], ['Ventes', $ca['n']], ['Panier moyen', $ca['n'] ? money0(intdiv($ca['t'] - $ca['inv'], $ca['n'])) : '—'],
        ['Encaissé', money0($paid)], ['Dépenses enregistrées', money0($exp)], ['Transport + primes techniciens', money0($ship['cost'])],
        ['Livraisons (dont retours)', (int)$ship['n'] . ' (' . (int)$ship['ret'] . ')'], ['Livraisons en cours aujourd’hui', $open],
        ['Réparations à traiter', (int)$rep['open']], ['Reste à encaisser (total)', money0($recv)],
    ], [1]);
    if ($top) $h .= x_p('Top produits : ' . e(implode(' · ', array_map(fn($x) => $x['name'] . ' (' . (int)$x['q'] . ')', $top))) . '.');
    return $h;
}

function x_help(string $intro = ''): string
{
    $ex = ['Bilan du mois', 'Chiffre d’affaires par mois cette année', 'Top 10 des produits vendus', 'Combien de BT 200+ vendus en octobre ?',
        'Quels transporteurs nous doivent de l’argent ?', 'Statistiques des transporteurs', 'Où est la marchandise en cours de livraison ?', 'Retours du mois dernier',
        'Réparations en attente chez Omar', 'Ventes par gouvernorat', 'Meilleurs clients', 'Client 98303463', 'Dépenses transport et techniciens en octobre',
        'Paiements par mode ce mois', 'Reste à encaisser', 'Stock du BT 400+'];
    return ($intro !== '' ? x_p($intro) : '') . x_p('Je réponds à partir des données de l’ERP sur : ventes et chiffre d’affaires, produits, transporteurs, livraisons et retours, réparations & SAV, techniciens, clients, gouvernorats, stock, dépenses, paiements, reste à encaisser, et un bilan. Ajoutez une période (« en octobre », « le mois dernier », « depuis mars »…). Exemples :')
        . '<div class="x-sugg">' . implode('', array_map(fn($q) => '<button type="button" class="chip" data-x-ask="' . e($q) . '">' . e($q) . '</button>', $ex)) . '</div>';
}

/* ---------- Aiguillage ---------- */

/** Répond à une question. $ctx = contexte de la question précédente (modifié). Retourne le HTML de la réponse. */
function x_answer(string $q, array &$ctx): string
{
    $s = x_norm($q);
    if (trim($s) === '') return x_help();
    // Politesse
    if (x_has($s, '^ ?(merci|mercii+|choukran|chokran|barcha merci|thanks)\b')) return x_p('Avec plaisir ! Posez-moi une autre question quand vous voulez.');
    if (x_has($s, '^ ?(bonjour|bonsoir|salut|salam|aslema|ahla|hello|coucou)\b') && !x_has($s, '\b(ca|ventes?|vendu|transporteurs?|reparations?|livraisons?|clients?|stock|bilan|combien)\b')) {
        return x_p('Bonjour ' . e(explode(' ', (string)(user()['name'] ?? ''))[0]) . ' ! Voici un aperçu rapide :') . x_answer_summary(['s' => $s, 'period' => x_default_period('month'), 'assumed' => false])
            . x_p('Posez-moi une question précise, par exemple « ventes par gouvernorat » ou « réparations en attente ».', 'muted small');
    }
    $techs = x_techs();
    $per = x_period($s);
    $digits = preg_replace('/(?<=\d)[ .,](?=\d)/', '', $q);
    $phone = preg_match('/(?<!\d)(\d{8})(?!\d)/', $digits, $m) ? $m[1] : '';
    $tech = null;
    foreach ($techs as $k => $name) if (x_has($s, '\b' . preg_quote($k, '/') . '\b')) { $tech = $name; break; }
    $carriers = x_find_carriers($s, array_keys($techs));
    $gouv = x_find_gouv($s);
    $product = x_find_products($q, $s);
    $loc = null;
    foreach (locations(true, 'showroom') as $l) {
        $k = trim(x_norm(str_replace(['Showroom ', 'showroom '], '', $l['name'])));
        if (x_has($s, '\b' . preg_quote($k, '/') . '\b') && !x_has($s, '\b(gouvernorat|region|clients? de|livr|ville)\b')) $loc = (int)$l['id'];
    }
    if ($loc && $gouv && trim(x_norm($gouv)) === trim(x_norm(str_replace('Showroom ', '', location($loc)['name'])))) $gouv = null;
    $clientName = preg_match('/\bclient(?:e)? (?!de |du |des )([a-z]{3,}(?: [a-z]{3,})?)\b/', $s, $m) && !in_array($m[1], ['qui', 'les', 'plus', 'fidele', 'fideles'], true) ? $m[1] : '';

    $money = '\b(doit|doivent|argent|recuperer|reverser|reverse|encaiss\w*|cod|contre remboursement|dette)\b';
    $intent = match (true) {
        x_has($s, '\b(aide|help|que sais|quoi demander|exemples?|comment ca marche|tu sais faire)\b') => 'help',
        $phone !== '' || ($clientName !== '' && !x_has($s, '\b(meilleur|top|combien)\b')) => 'client',
        x_has($s, '\b(bilan|resume|situation|synthese|point|rapport|comment va|ca va)\b') => 'summary',
        x_has($s, $money) && ($carriers || x_has($s, '\b(transporteurs?|livreurs?|jetpack|tps)\b')) => 'carriers_owed',
        x_has($s, '\b(reparations?|reparer|repare|sav|pannes?|reparateurs?|depannage|diagnostic)\b') => 'repairs',
        x_has($s, '\b(techniciens?|installations?|installateurs?|primes?)\b') && !x_has($s, '\b(depenses?|charges?|frais|paye|payer)\b') => 'technicians',
        x_has($s, '\b(retours?|retourne|echec|echoue|refus)\b') => 'returns',
        x_has($s, '\b(stock|rupture|inventaire|reste en stock|disponible)\b') => 'stock',
        x_has($s, '\b(depenses?|charges?|frais|couts?|paye aux|payes aux|paye au|loyer|salaires?)\b') => 'expenses',
        x_has($s, '\b(livraisons?|marchandise|colis|expedi\w*|en route|ou est|ou sont|en retard|a livrer)\b') && !x_has($s, '\b(statistiques?|stats?|taux|par transporteur|combien de livraisons)\b') => 'shipments',
        (bool)$carriers || x_has($s, '\b(transporteurs?|livreurs?)\b') => 'carriers',
        x_has($s, '\b(modes? de paiement|paiements?|especes|carte|cheques?|virements?|d17|flouci)\b') => 'payments',
        x_has($s, '\b(reste a (payer|encaisser)|impaye\w*|creances?|credits?|facilites?|non paye\w*|a encaisser)\b') => 'receivables',
        x_has($s, '\b(clients?|acheteurs?)\b') => 'top_clients',
        x_has($s, '\b(gouvernorats?|regions?|villes?|wilayas?|ou vend\w*|zones?)\b') => 'gouv',
        (x_has($s, '\b(top|meilleurs?|plus vendus?|classement|populaires?|marche le mieux|best)\b') || x_has($s, '\bproduits? .*\b(mieux|plus)\b|\bse vend\w* (le )?(mieux|plus)\b')) && !$product => 'top_products',
        $product !== null => 'product',
        x_has($s, '\b(ca|ventes?|vendu|vendus|vend|recettes?|gagne|combien|total|commandes?)\b') => 'ca',
        default => '',
    };
    // Suite de la question précédente (« et en septembre ? », « et chez TPS ? »)
    $followUp = $intent === '' && $ctx && ($per || $carriers || $tech || $gouv || $product || $loc);
    if ($followUp) $intent = $ctx['intent'];
    if ($intent === '') {
        return x_help('Je n’ai pas bien compris la question.' . ($gouv ? ' Voulez-vous les ventes vers ' . e($gouv) . ' ?' : ''));
    }
    $defaults = ['ca' => 'year', 'top_products' => 'year', 'product' => 'year', 'carriers' => 'year', 'returns' => 'year', 'gouv' => 'year',
        'top_clients' => 'year', 'expenses' => 'month', 'payments' => 'month', 'summary' => 'month', 'technicians' => 'year'];
    $c = [
        's' => $s, 'period' => $per ?? ($followUp && isset($ctx['period']) ? $ctx['period'] : x_default_period($defaults[$intent] ?? 'year')),
        'assumed' => $per === null && !($followUp && isset($ctx['period'])),
        'carriers' => $carriers ?: ($followUp ? ($ctx['carriers'] ?? []) : []), 'tech' => $tech ?? ($followUp ? ($ctx['tech'] ?? null) : null),
        'gouv' => $gouv ?? ($followUp ? ($ctx['gouv'] ?? null) : null), 'product' => $product ?? ($followUp ? ($ctx['product'] ?? null) : null),
        'loc' => $loc ?? ($followUp ? ($ctx['loc'] ?? null) : null), 'phone' => $phone, 'client_name' => $clientName,
    ];
    if ($intent === 'product' && !$c['product']) $intent = 'ca';
    if ($intent === 'stock' && !$c['product'] && $product) $c['product'] = $product;
    $ctx = ['intent' => $intent] + array_intersect_key($c, array_flip(['period', 'carriers', 'tech', 'gouv', 'product', 'loc']));
    if ($c['assumed']) unset($ctx['period']);
    return match ($intent) {
        'help' => x_help(),
        'client' => x_answer_client($c),
        'summary' => x_answer_summary($c),
        'carriers_owed' => x_answer_carriers_owed($c),
        'repairs' => x_answer_repairs($c),
        'technicians' => x_answer_technicians($c),
        'returns' => x_answer_returns($c),
        'stock' => x_answer_stock($c),
        'expenses' => x_answer_expenses($c),
        'shipments' => x_answer_shipments_open($c),
        'carriers' => x_answer_carriers($c),
        'payments' => x_answer_payments($c),
        'receivables' => x_answer_receivables($c),
        'top_clients' => x_answer_top_clients($c),
        'gouv' => x_answer_gouv($c),
        'top_products' => x_answer_top_products($c),
        'product' => x_answer_product($c),
        default => x_answer_ca($c),
    };
}
