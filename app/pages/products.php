<?php
declare(strict_types=1);

require_perm('products.view');
$a = (string)get('a', 'list');
$admin = is_admin();

/* ---------- Enregistrement ---------- */

if (is_post()) {
    csrf_check();
    if (!$admin) {
        flash('err', 'Seul l’administrateur peut modifier le catalogue.');
        redirect(url('products'));
    }
    $id = (int)post('id');
    if ($a === 'delete') {
        $p = one('SELECT * FROM products WHERE id = ?', [$id]);
        if ($p) {
            $used = (int)val('SELECT COUNT(*) FROM sale_items WHERE product_id = ?', [$id]) + (int)val('SELECT COUNT(*) FROM campaigns WHERE product_id = ?', [$id]);
            if ($used) {
                update('products', ['active' => 0, 'updated_at' => now()], 'id = ?', [$id]);
                flash('ok', 'Produit déjà vendu : il a été archivé (masqué de la caisse) au lieu d’être supprimé.');
            } else {
                q('DELETE FROM products WHERE id = ?', [$id]);
                flash('ok', 'Produit supprimé.');
            }
            audit('produit supprimé/archivé', 'product', $id, $p['name']);
        }
        redirect(url('products'));
    }
    $name = (string)post('name');
    $price = parse_money(post('price'));
    $errors = [];
    if ($name === '') $errors[] = 'Le nom est obligatoire.';
    if ($price === null || $price < 0) $errors[] = 'Prix de vente invalide (mettez 0 pour « sur devis »).';
    $promo = parse_money(post('promo_price'));
    if ($promo !== null && $price !== null && $price > 0 && $promo >= $price) $errors[] = 'Le prix promo doit être inférieur au prix de vente.';
    $sku = (string)post('sku');
    if ($sku !== '' && val('SELECT id FROM products WHERE sku = ? AND id <> ?', [$sku, $id])) $errors[] = 'Cette référence (UGS) est déjà utilisée.';
    $family = (string)post('family');
    if (!in_array($family, FAMILIES, true)) $family = 'Autres';
    $data = [
        'name' => $name, 'sku' => $sku, 'barcode' => (string)post('barcode'), 'family' => $family, 'brand' => (string)post('brand'),
        'price' => (int)$price, 'promo_price' => $promo ?: null, 'cost' => parse_money(post('cost')),
        'warranty_months' => parse_int(post('warranty_months')), 'min_stock' => max(0, (int)parse_int(post('min_stock'), 0)),
        'description' => mb_substr((string)post('description'), 0, 5000), 'active' => post('active') ? 1 : 0,
        'web_status' => in_array(post('web_status'), ['publie', 'prive', 'brouillon'], true) ? post('web_status') : 'publie',
        'updated_at' => now(),
    ];
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $info = @getimagesize($_FILES['image']['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (!$ext || $_FILES['image']['size'] > 4 * 1024 * 1024) {
            $errors[] = 'Image refusée : JPG, PNG ou WebP de 4 Mo maximum.';
        } else {
            $fname = 'assets/img/uploads/p-' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], APP_ROOT . '/' . $fname)) $data['image'] = $fname;
        }
    } elseif (post('remove_image')) {
        $data['image'] = '';
        $data['image_url'] = '';
    }
    if ($errors) {
        foreach ($errors as $er) flash('err', $er);
        $_SESSION['old'] = $_POST;
        redirect(url('products', ['a' => 'edit', 'id' => $id ?: null]));
    }
    if ($id) {
        $old = one('SELECT price, promo_price FROM products WHERE id = ?', [$id]);
        update('products', $data, 'id = ?', [$id]);
        if ($old && ((int)$old['price'] !== $data['price'] || (int)$old['promo_price'] !== (int)$data['promo_price'])) {
            audit('prix modifié', 'product', $id, $name . ' : ' . money($old['price']) . ' → ' . money($data['price']) . ($data['promo_price'] ? ' (promo ' . money($data['promo_price']) . ')' : ''));
        }
        flash('ok', 'Produit enregistré.');
    } else {
        $id = insert('products', $data + ['created_at' => now()]);
        audit('produit créé', 'product', $id, $name);
        flash('ok', 'Produit créé. Pensez à saisir son stock (Stock › Mouvement).');
    }
    redirect(url('products', ['a' => 'view', 'id' => $id]));
}

/* ---------- Formulaire ---------- */

if ($a === 'edit') {
    if (!$admin) redirect(url('products'));
    $id = (int)get('id');
    $p = $id ? one('SELECT * FROM products WHERE id = ?', [$id]) : null;
    if ($id && !$p) redirect(url('products'));
    $old = $_SESSION['old'] ?? null;
    unset($_SESSION['old']);
    $v = fn(string $k, $default = '') => $old[$k] ?? ($p[$k] ?? $default);
    layout_start($p ? 'Modifier · ' . $p['name'] : 'Nouveau produit', 'products');
    ?>
    <p><a class="back" href="<?= e($p ? url('products', ['a' => 'view', 'id' => $id]) : url('products')) ?>"><?= icon('left') ?>Retour</a></p>
    <form method="post" enctype="multipart/form-data" class="card form-grid">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
      <label class="span2">Nom<input name="name" value="<?= e($v('name')) ?>" required maxlength="160"></label>
      <label>Famille<select name="family"><?= opt(array_combine(FAMILIES, FAMILIES), $v('family', 'Accessoires')) ?></select></label>
      <label>Marque<input name="brand" value="<?= e($v('brand')) ?>" list="brands"></label>
      <label>Référence (UGS)<input name="sku" value="<?= e($v('sku')) ?>"></label>
      <label>Code-barres<input name="barcode" value="<?= e($v('barcode')) ?>"></label>
      <label>Prix de vente TTC (DT)<input name="price" inputmode="decimal" value="<?= e($old['price'] ?? m_in($p['price'] ?? null)) ?>" required><small>0 = prix sur devis (saisi en caisse)</small></label>
      <label>Prix promo TTC (DT)<input name="promo_price" inputmode="decimal" value="<?= e($old['promo_price'] ?? m_in($p['promo_price'] ?? null)) ?>"><small>Vide = pas de promotion</small></label>
      <label>Prix d’achat (DT)<input name="cost" inputmode="decimal" value="<?= e($old['cost'] ?? m_in($p['cost'] ?? null)) ?>"><small>Visible uniquement par l’administrateur (marges)</small></label>
      <label>Garantie (mois)<input name="warranty_months" type="number" min="0" max="240" value="<?= e($v('warranty_months')) ?>"></label>
      <label>Alerte stock minimum<input name="min_stock" type="number" min="0" value="<?= e($v('min_stock', 0)) ?>"></label>
      <label>Statut sur le site<select name="web_status"><?= opt(['publie' => 'Publié', 'prive' => 'Privé', 'brouillon' => 'Brouillon'], $v('web_status', 'publie')) ?></select></label>
      <label class="span2">Description<textarea name="description" rows="8"><?= e($v('description')) ?></textarea></label>
      <div class="span2 img-field">
        <?php if ($p): ?><?= product_thumb($p, 'thumb lg') ?><?php endif; ?>
        <label>Photo (JPG, PNG, WebP)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
        <?php if ($p && product_image($p)): ?><label class="check"><input type="checkbox" name="remove_image" value="1"> Retirer la photo</label><?php endif; ?>
      </div>
      <label class="check span2"><input type="checkbox" name="active" value="1" <?= $v('active', 1) ? 'checked' : '' ?>> Actif (visible en caisse)</label>
      <div class="span2 row-actions"><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div>
    </form>
    <datalist id="brands"><?php foreach (all("SELECT DISTINCT brand FROM products WHERE brand <> '' ORDER BY brand") as $b) echo '<option value="' . e($b['brand']) . '">'; ?></datalist>
    <?php if ($p): ?>
      <form method="post" action="<?= e(url('products', ['a' => 'delete'])) ?>" class="danger-inline" onsubmit="return confirm('Supprimer ce produit ? (s’il a déjà été vendu, il sera archivé)')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
        <button class="btn ghost danger sm"><?= icon('trash') ?>Supprimer / archiver</button>
      </form>
    <?php endif; ?>
    <?php
    layout_end();
    exit;
}

/* ---------- Fiche ---------- */

if ($a === 'view') {
    $p = one('SELECT * FROM products WHERE id = ?', [(int)get('id')]);
    if (!$p) redirect(url('products'));
    $stock = all('SELECT l.id, l.name, l.type, COALESCE(s.qty, 0) qty FROM locations l LEFT JOIN stock s ON s.location_id = l.id AND s.product_id = ? WHERE l.active = 1 ORDER BY l.position', [$p['id']]);
    $sold = one("SELECT COALESCE(SUM(si.qty),0) q, COALESCE(SUM(si.total),0) t FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.product_id = ? AND s.status <> 'annulee' AND s.created_at >= ?", [$p['id'], date('Y-m-d', strtotime('-90 days'))]);
    $moves = can('stock.view') ? all('SELECT m.*, l.name loc, u.name user FROM stock_moves m JOIN locations l ON l.id = m.location_id LEFT JOIN users u ON u.id = m.user_id WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 10', [$p['id']]) : [];
    $actions = $admin ? '<a class="btn sm primary" href="' . e(url('products', ['a' => 'edit', 'id' => $p['id']])) . '">' . icon('edit') . 'Modifier</a>' : '';
    layout_start($p['name'], 'products', ['actions' => $actions]);
    ?>
    <p><a class="back" href="<?= e(url('products')) ?>"><?= icon('left') ?>Catalogue</a></p>
    <div class="product-sheet">
      <div class="card ps-img"><?= product_thumb($p, 'thumb xl') ?></div>
      <div class="card">
        <div class="tags"><?= badge($p['family']) ?><?= $p['brand'] ? badge($p['brand'], 'info') : '' ?><?= !$p['active'] ? badge('Archivé', 'err') : '' ?><?= $p['web_status'] !== 'publie' ? badge($p['web_status'] === 'prive' ? 'Privé sur le site' : 'Brouillon site', 'warn') : '' ?></div>
        <div class="ps-price">
          <?php if (product_price($p) > 0): ?>
            <b><?= money(product_price($p)) ?></b><?php if (product_price($p) < (int)$p['price']): ?> <s><?= money($p['price']) ?></s> <?= badge('−' . round((1 - product_price($p) / (int)$p['price']) * 100) . ' %', 'ok') ?><?php endif; ?>
          <?php else: ?><b>Sur devis</b><?php endif; ?>
        </div>
        <dl class="kv">
          <dt>Référence</dt><dd><?= e($p['sku'] ?: '—') ?></dd>
          <dt>Code-barres</dt><dd><?= e($p['barcode'] ?: '—') ?></dd>
          <dt>Garantie</dt><dd><?= $p['warranty_months'] ? (int)$p['warranty_months'] . ' mois' : '—' ?></dd>
          <?php if ($admin): ?><dt>Prix d’achat</dt><dd><?= $p['cost'] !== null ? money($p['cost']) . ' · marge ' . (product_price($p) ? pct((product_price($p) - (int)$p['cost']) / product_price($p) * 100) : '—') : '—' ?></dd><?php endif; ?>
          <dt>Vendus (90 jours)</dt><dd><?= (int)$sold['q'] ?> · <?= money($sold['t']) ?></dd>
          <?php if ($p['woo_id']): ?><dt>ID site</dt><dd><?= (int)$p['woo_id'] ?></dd><?php endif; ?>
        </dl>
        <h3>Stock</h3>
        <div class="stock-pills">
          <?php foreach ($stock as $s): ?><span class="sp <?= (int)$s['qty'] > 0 ? 'ok' : 'out' ?>"><?= e($s['name']) ?> <b><?= (int)$s['qty'] ?></b></span><?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php if ($p['description']): ?><section class="card"><h2>Description</h2><div class="prose"><?= nl2br(e($p['description'])) ?></div></section><?php endif; ?>
    <?php if ($moves): ?>
      <section class="card"><h2>Derniers mouvements de stock</h2>
        <table class="tbl"><?php foreach ($moves as $m): ?>
          <tr><td><?= fdate($m['created_at'], true) ?></td><td><?= e($m['loc']) ?></td><td><?= e(MOVE_TYPES[$m['type']] ?? $m['type']) ?> <?= e($m['ref']) ?></td><td class="num <?= $m['qty_change'] < 0 ? 'neg' : 'pos' ?>"><?= $m['qty_change'] > 0 ? '+' : '' ?><?= (int)$m['qty_change'] ?></td><td class="num muted">→ <?= (int)$m['qty_after'] ?></td><td class="muted"><?= e($m['user']) ?></td></tr>
        <?php endforeach; ?></table>
      </section>
    <?php endif; ?>
    <?php
    layout_end();
    exit;
}

/* ---------- Liste ---------- */

$f = ['q' => (string)get('q'), 'family' => (string)get('family'), 'status' => (string)get('status', 'actifs')];
$where = ['1=1'];
$p = [];
if ($f['q'] !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.brand LIKE ?)';
    array_push($p, ...array_fill(0, 4, '%' . $f['q'] . '%'));
}
if ($f['family'] !== '') { $where[] = 'p.family = ?'; $p[] = $f['family']; }
if ($f['status'] === 'actifs') $where[] = 'p.active = 1';
if ($f['status'] === 'archives') $where[] = 'p.active = 0';
if ($f['status'] === 'promo') $where[] = 'p.active = 1 AND p.promo_price IS NOT NULL';
if ($f['status'] === 'devis') $where[] = 'p.active = 1 AND p.price = 0';
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM products p WHERE $w", $p);
$page = max(1, (int)get('page', 1));
$per = 60;
$rows = all("SELECT p.*, COALESCE((SELECT SUM(qty) FROM stock WHERE product_id = p.id), 0) AS stock_total FROM products p WHERE $w ORDER BY p.family, p.name COLLATE NOCASE LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$locs = locations();
$byLoc = [];
if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    foreach (all("SELECT product_id, location_id, qty FROM stock WHERE product_id IN ($ids)") as $s) $byLoc[$s['product_id']][$s['location_id']] = (int)$s['qty'];
}

$actions = '';
if (can('products.export')) $actions .= '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'produits'])) . '">' . icon('download') . 'Export CSV</a>';
if ($admin) $actions .= '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'produits'])) . '">' . icon('upload') . 'Import CSV</a>'
    . '<a class="btn sm primary" href="' . e(url('products', ['a' => 'edit'])) . '">' . icon('plus') . 'Nouveau produit</a>';
layout_start('Produits', 'products', ['actions' => $actions]);
?>
<form class="filters" method="get">
  <input type="hidden" name="r" value="products">
  <label class="grow">Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="Nom, référence, code-barres, marque"></label>
  <label>Famille<select name="family"><?= opt(array_combine(FAMILIES, FAMILIES), $f['family'], true, 'Toutes') ?></select></label>
  <label>Afficher<select name="status"><?= opt(['actifs' => 'Produits actifs', 'promo' => 'En promotion', 'devis' => 'Prix sur devis', 'archives' => 'Archivés', 'tous' => 'Tous'], $f['status']) ?></select></label>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>
<div class="card flush">
  <table class="tbl hover">
    <thead><tr><th></th><th>Produit</th><th>Famille</th><th class="num">Prix</th><?php if ($admin): ?><th class="num">Achat</th><?php endif; ?>
      <?php foreach ($locs as $l): ?><th class="num" title="<?= e($l['name']) ?>"><?= e($l['code']) ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $eff = product_price($r); ?>
      <tr data-href="<?= e(url('products', ['a' => 'view', 'id' => $r['id']])) ?>">
        <td class="w-thumb"><?= product_thumb($r, 'thumb sm') ?></td>
        <td><a href="<?= e(url('products', ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= e($r['name']) ?></b></a>
          <br><small class="muted"><?= e(trim(($r['brand'] ? $r['brand'] . ' · ' : '') . ($r['sku'] ?: ''), ' ·')) ?></small>
          <?= !$r['active'] ? badge('Archivé', 'err') : '' ?></td>
        <td><?= e($r['family']) ?></td>
        <td class="num"><?php if ($eff > 0): ?><?= money($eff) ?><?php if ($eff < (int)$r['price']): ?><br><s class="muted"><?= money($r['price']) ?></s><?php endif; ?><?php else: ?><span class="muted">Sur devis</span><?php endif; ?></td>
        <?php if ($admin): ?><td class="num muted"><?= $r['cost'] !== null ? money($r['cost']) : '—' ?></td><?php endif; ?>
        <?php foreach ($locs as $l): $q = $byLoc[$r['id']][$l['id']] ?? 0; ?><td class="num <?= $q < 0 ? 'neg' : ($q === 0 ? 'muted' : '') ?>"><?= $q ?></td><?php endforeach; ?>
        <td class="num"><b class="<?= (int)$r['min_stock'] > 0 && (int)$r['stock_total'] <= (int)$r['min_stock'] ? 'warn-t' : '' ?>"><?= (int)$r['stock_total'] ?></b></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Aucun produit.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager($total, $page, $per, array_filter($f), 'products') ?>
<?php
layout_end();
