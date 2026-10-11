<?php
declare(strict_types=1);

require_once __DIR__ . '/csv.php';

const FAMILIES = ['Tapis de course', 'Vélos', 'Elliptiques & cardio', 'Rameurs', 'Musculation', 'Pilates & yoga',
    'Accessoires', 'Padel & raquettes', 'Loisirs', 'Bien-être & médical', 'Pièces & entretien', 'Autres'];

/* ---------- Produits ---------- */

/** Prix appliqué en caisse (promo si elle est inférieure au prix régulier). */
function product_price(array $p): int
{
    $promo = $p['promo_price'] ?? null;
    if ($promo !== null && (int)$promo > 0 && ((int)$promo < (int)$p['price'] || (int)$p['price'] === 0)) return (int)$promo;
    return (int)$p['price'];
}

function product_image(array $p): string
{
    if (!empty($p['image'])) return $p['image'];
    return $p['image_url'] ?? '';
}

/* ---------- Stock ---------- */

function stock_qty(int $productId, int $locationId): int
{
    return (int)(val('SELECT qty FROM stock WHERE product_id = ? AND location_id = ?', [$productId, $locationId]) ?? 0);
}

/** Ajoute $delta au stock et trace le mouvement. Retourne la nouvelle quantité. */
function stock_change(int $productId, int $locationId, int $delta, string $type, string $ref = '', string $note = ''): int
{
    q('INSERT INTO stock(product_id, location_id, qty) VALUES (?, ?, 0) ON CONFLICT(product_id, location_id) DO NOTHING', [$productId, $locationId]);
    q('UPDATE stock SET qty = qty + ? WHERE product_id = ? AND location_id = ?', [$delta, $productId, $locationId]);
    $after = stock_qty($productId, $locationId);
    insert('stock_moves', [
        'created_at' => now(), 'product_id' => $productId, 'location_id' => $locationId, 'qty_change' => $delta,
        'qty_after' => $after, 'type' => $type, 'ref' => $ref, 'note' => $note, 'user_id' => $_SESSION['uid'] ?? null,
    ]);
    return $after;
}

/** Fixe la quantité (inventaire). Retourne l'écart appliqué. */
function stock_set(int $productId, int $locationId, int $qty, string $type = 'inventaire', string $note = ''): int
{
    $delta = $qty - stock_qty($productId, $locationId);
    if ($delta !== 0) stock_change($productId, $locationId, $delta, $type, '', $note);
    return $delta;
}

/* ---------- Nettoyage des textes WooCommerce ---------- */

function clean_text(string $html): string
{
    $s = str_replace(['\\n', '\\r'], ["\n", ''], $html);
    $s = preg_replace('/\[video[^\]]*\](\[\/video\])?/i', '', $s);
    $s = preg_replace('/\[\/?[a-z_]+[^\]]*\]/i', '', $s);
    $s = preg_replace('/<!--.*?-->/s', '', $s);
    $s = preg_replace('#<img\b[^>]*>#i', '', $s);
    $s = preg_replace('#<li\b[^>]*>#i', "\n• ", $s);
    $s = preg_replace('#</t[dh]>\s*<t[dh]\b[^>]*>#i', ' : ', $s);
    $s = preg_replace('#<(br|/p|/li|/h\d|/div|/tr|/ul|/ol|/blockquote|/section)\b[^>]*>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[\x{2066}-\x{2069}\x{FFFC}\x{200B}\x{FEFF}]/u', '', $s);
    $s = str_replace("\u{00A0}", ' ', $s);
    $out = [];
    foreach (preg_split('/\R/u', $s) as $line) {
        $line = trim(preg_replace('/[ \t]+/u', ' ', $line));
        if ($line === '•' || $line === "'-") $line = '';
        if (preg_match('/^Si tu veux, je peux aussi/i', $line)) continue;
        if ($line === '' && (empty($out) || end($out) === '')) continue;
        $out[] = $line;
    }
    while ($out && end($out) === '') array_pop($out);
    // pas de ligne vide entre deux puces
    $res = [];
    foreach ($out as $i => $line) {
        if ($line === '' && isset($out[$i - 1], $out[$i + 1]) && str_starts_with($out[$i - 1], '•') && str_starts_with($out[$i + 1], '•')) continue;
        $res[] = $line;
    }
    return implode("\n", $res);
}

function guess_family(string $cats, string $name): string
{
    $c = mb_strtolower($cats);
    $t = mb_strtolower($cats . ' ' . $name);
    if (preg_match('/pi[eè]ces de rechange|bande tapis|cle de securite|clé de sécurité|huile lubrifiant/u', $t)) return 'Pièces & entretien';
    if (preg_match('/tapis roulant|treadmill/u', $t)) return 'Tapis de course';
    if (preg_match('/ellipti|ellepti|climber/u', $t)) return 'Elliptiques & cardio';
    if (preg_match('/rameur|ski ?erg/u', $t)) return 'Rameurs';
    if (preg_match('/v[ée]lo|bike|spinning|biking|vtt/u', $t)) return 'Vélos';
    if (preg_match('/\bpilates\b|reformer|yoga/u', $t)) return 'Pilates & yoga';
    if (preg_match('/padel|ping ?pong|raquette/u', $t)) return 'Padel & raquettes';
    if (preg_match('/m[ée]dical|massage/u', $t)) return 'Bien-être & médical';
    if (preg_match('/skate|trampoline|balance board/u', $c)) return 'Loisirs';
    if (preg_match('/musculation|\bbanc\b|bench|machine|multifonction|multi exercice|leg |smith|station|halt[eè]re|dumbbell|trx|barre|crunch|curl|pec fly/u', $t)) return 'Musculation';
    return 'Accessoires';
}

function guess_brand(string $name, string $short): string
{
    $s = trim($short);
    if (preg_match('/^(SVELTUS|CARE|CORNILLEAU|CUBE|AWAII|ARK-ONE|ATHLI-TECK)$/i', $s, $m)) return mb_strtoupper($m[1]);
    $t = $name . ' ' . mb_substr($s, 0, 400);
    $rules = [
        '/body sculpture/i' => 'Body Sculpture', '/nordi[ck]tra[c]?k/i' => 'NordicTrack', '/impulse/i' => 'Impulse',
        '/pro[- ]?form/i' => 'ProForm', '/hit ?up/i' => 'Hit Up Sport', '/bab-?dida/i' => 'Bab-Dida', '/avon fitness/i' => 'Avon Fitness',
        '/bullpadel/i' => 'Bullpadel', '/babolat/i' => 'Babolat', '/\bBT\b|bt fitness/' => 'BT Fitness', '/\bCARE\b/' => 'CARE',
    ];
    foreach ($rules as $re => $b) if (preg_match($re, $t)) return $b;
    return '';
}

function guess_warranty(string $text): ?int
{
    if (preg_match('/garantie\s*:?\s*(\d{1,2})\s*ans?\b/iu', $text, $m)) return (int)$m[1] * 12;
    return null;
}

/* ---------- Import ---------- */

/**
 * Importe une ligne produit, au format WooCommerce (export du site) ou au format de l'ERP.
 * $r : ligne aux en-têtes normalisés (slug_key). $opts : stock_location (int|null), mode ('woo'|'erp').
 */
function product_import_row(array $r, array $opts): string
{
    $woo = ($opts['mode'] ?? '') === 'woo';
    $g = function (string ...$keys) use ($r): string {
        foreach ($keys as $k) if (isset($r[$k]) && trim((string)$r[$k]) !== '') return trim((string)$r[$k]);
        return '';
    };

    if ($woo) {
        $type = $g('type');
        if ($type !== '' && !in_array($type, ['simple', 'variable', 'variation', 'external', 'grouped'], true)) return 'skipped';
        $short = clean_text($g('description_courte'));
        $long = clean_text($g('description'));
        $name = trim(preg_replace('/\s+/u', ' ', $g('nom')));
        if ($name === '') $name = mb_substr(strtok($short ?: '', "\n") ?: '', 0, 80);
        if ($name === '') return 'skipped';
        $brand = $g('marques') ?: guess_brand($name, $short);
        $desc = mb_strlen($short) >= 40 ? $short : ($long ?: $short);
        if (mb_strtoupper($desc) === mb_strtoupper($brand)) $desc = '';
        $published = $g('publie');
        $regular = parse_money($g('tarif_regulier'));
        $promo = parse_money($g('tarif_promo'));
        $images = array_values(array_filter(array_map('trim', explode(',', $g('images')))));
        $wooId = parse_int($g('id'));
        $local = $wooId && is_file(APP_ROOT . "/assets/img/products/$wooId.jpg") ? "assets/img/products/$wooId.jpg" : '';
        $cats = $g('categories');
        $data = [
            'woo_id' => $wooId,
            'sku' => $g('ugs'),
            'barcode' => $g('gtin_upc_ean_ou_isbn'),
            'name' => $name,
            'categories' => $cats,
            'price' => $regular ?? ($promo ?? 0),
            'promo_price' => ($promo !== null && $regular !== null && $promo < $regular) ? $promo : null,
            'description' => mb_substr($desc, 0, 3000),
            'image_url' => $images[0] ?? '',
            'web_status' => $published === '1' ? 'publie' : ($published === '-1' ? 'brouillon' : 'prive'),
            'active' => $published === '-1' ? 0 : 1,
        ];
        $existing = $wooId ? one('SELECT * FROM products WHERE woo_id = ?', [$wooId]) : null;
        if (!$existing && $data['sku'] !== '') $existing = one('SELECT * FROM products WHERE sku = ? AND woo_id IS NULL', [$data['sku']]);
        $minStock = parse_int($g('montant_de_stock_faible'));
        if ($existing) {
            if ($local && !$existing['image']) $data['image'] = $local;
            if (!$existing['brand'] && $brand) $data['brand'] = $brand;
            if (!$existing['warranty_months']) $data['warranty_months'] = guess_warranty($short . "\n" . $long);
            $data['updated_at'] = now();
            update('products', $data, 'id = ?', [$existing['id']]);
            return 'updated';
        }
        $data += [
            'family' => guess_family($cats, $name), 'brand' => $brand, 'image' => $local,
            'warranty_months' => guess_warranty($short . "\n" . $long), 'min_stock' => $minStock ?? 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
        $pid = insert('products', $data);
        $qty = parse_int($g('stock'));
        if ($qty !== null && $qty !== 0 && !empty($opts['stock_location'])) {
            stock_change($pid, (int)$opts['stock_location'], $qty, 'import', 'CSV site', 'Stock indiqué dans l’export du site');
        }
        return 'created';
    }

    // Format ERP (export de cette application) : $r est indexé par les clés du jeu de données « produits »
    $name = $g('name');
    if ($name === '') throw new RuntimeException('Nom du produit manquant');
    $id = parse_int($g('id'));
    $wooId = parse_int($g('woo_id'));
    $sku = $g('sku');
    $existing = $id ? one('SELECT * FROM products WHERE id = ?', [$id]) : null;
    if (!$existing && $wooId) $existing = one('SELECT * FROM products WHERE woo_id = ?', [$wooId]);
    if (!$existing && $sku !== '') $existing = one('SELECT * FROM products WHERE sku = ?', [$sku]);
    $price = parse_money($g('price'));
    $family = $g('family');
    if ($family !== '' && !in_array($family, FAMILIES, true)) {
        foreach (FAMILIES as $f) if (slug_key($f) === slug_key($family)) $family = $f;
    }
    $active = $g('active');
    $data = ['name' => $name];
    foreach (['sku', 'barcode', 'brand', 'categories', 'description', 'image_url'] as $k) {
        if (array_key_exists($k, $r)) $data[$k] = $g($k);
    }
    if ($family !== '') $data['family'] = $family;
    if ($price !== null) $data['price'] = $price;
    if (array_key_exists('promo_price', $r)) $data['promo_price'] = parse_money($g('promo_price'));
    if (array_key_exists('cost', $r) && ($opts['can_cost'] ?? false)) $data['cost'] = parse_money($g('cost'));
    if (($w = parse_int($g('warranty_months'))) !== null) $data['warranty_months'] = $w;
    if (($ms = parse_int($g('min_stock'))) !== null) $data['min_stock'] = $ms;
    if ($active !== '') $data['active'] = in_array(mb_strtolower($active), ['1', 'oui', 'yes', 'true', 'actif', 'o'], true) ? 1 : 0;
    if ($wooId) $data['woo_id'] = $wooId;
    $data['updated_at'] = now();
    if ($existing) {
        update('products', $data, 'id = ?', [$existing['id']]);
        return 'updated';
    }
    if ($price === null) throw new RuntimeException('Prix de vente manquant pour « ' . $name . ' »');
    $data += ['family' => guess_family($data['categories'] ?? '', $name), 'created_at' => now()];
    insert('products', $data);
    return 'created';
}

/** Import initial (installation) du fichier exporté depuis WooCommerce. */
function catalog_import_woo_file(PDO $pdo, string $file): array
{
    $csv = csv_read_file($file);
    $count = ['created' => 0, 'updated' => 0, 'skipped' => 0];
    $dep = (int)(val("SELECT id FROM locations WHERE code = 'DEP'") ?? 0);
    foreach ($csv['rows'] as $row) {
        $res = product_import_row($row, ['mode' => 'woo', 'stock_location' => $dep ?: null]);
        $count[$res]++;
    }
    return $count;
}

function csv_is_woo(array $headers): bool
{
    return in_array('tarif_regulier', $headers, true) || in_array('ugs', $headers, true);
}
