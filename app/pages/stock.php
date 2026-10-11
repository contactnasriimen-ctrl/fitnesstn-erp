<?php
declare(strict_types=1);

require_perm('stock.view');
$a = (string)get('a', 'list');
$scope = scope_loc();
$admin = is_admin();
$locs = locations();
$locOpts = location_options();

if (is_post()) {
    csrf_check();
    require_perm('stock.move');
    try {
        if ($a === 'move') {
            $type = (string)post('type');
            if (!in_array($type, ['entree', 'sortie', 'transfert'], true)) throw new RuntimeException('Type de mouvement invalide.');
            $from = (int)post('from');
            $to = (int)post('to');
            $note = (string)post('note');
            $lines = [];
            foreach ((array)($_POST['product'] ?? []) as $i => $pid) {
                $pid = (int)$pid;
                $qty = (int)($_POST['qty'][$i] ?? 0);
                if (!$pid && !$qty) continue;
                if (!$pid || $qty <= 0) throw new RuntimeException('Chaque ligne doit avoir un produit et une quantité positive.');
                if (!one('SELECT id FROM products WHERE id = ?', [$pid])) throw new RuntimeException('Produit introuvable.');
                $lines[] = [$pid, $qty, parse_money($_POST['cost'][$i] ?? null)];
            }
            if (!$lines) throw new RuntimeException('Ajoutez au moins une ligne.');
            $locA = location($from);
            if (!$locA || !$locA['active']) throw new RuntimeException('Emplacement invalide.');
            if ($type === 'transfert') {
                $locB = location($to);
                if (!$locB || !$locB['active'] || $to === $from) throw new RuntimeException('Choisissez deux emplacements différents.');
                if ($scope && $from !== $scope && $to !== $scope) throw new RuntimeException('Un transfert doit partir de votre showroom ou y arriver.');
            } elseif ($scope && $from !== $scope) {
                throw new RuntimeException('Vous ne pouvez saisir des mouvements que pour votre showroom.');
            }
            $ref = tx(function () use ($type, $lines, $from, $to, $note, $admin) {
                $ref = strtoupper(['entree' => 'REC', 'sortie' => 'SOR', 'transfert' => 'TR'][$type]) . '-' . date('y') . '-' . str_pad((string)next_seq('move:' . $type . ':' . date('Y')), 4, '0', STR_PAD_LEFT);
                foreach ($lines as [$pid, $qty, $cost]) {
                    if ($type === 'entree') {
                        stock_change($pid, $from, $qty, 'entree', $ref, $note);
                        if ($admin && $cost !== null && $cost > 0) update('products', ['cost' => $cost, 'updated_at' => now()], 'id = ?', [$pid]);
                    } elseif ($type === 'sortie') {
                        stock_change($pid, $from, -$qty, 'sortie', $ref, $note);
                    } else {
                        if (setting('allow_negative_stock', '1') !== '1' && stock_qty($pid, $from) < $qty) {
                            throw new RuntimeException('Stock insuffisant pour « ' . val('SELECT name FROM products WHERE id = ?', [$pid]) . ' ».');
                        }
                        stock_change($pid, $from, -$qty, 'transfert_out', $ref, $note);
                        stock_change($pid, $to, $qty, 'transfert_in', $ref, $note);
                    }
                }
                audit('mouvement de stock', 'stock', null, $ref . ' · ' . count($lines) . ' ligne(s)');
                return $ref;
            });
            flash('ok', "Mouvement $ref enregistré (" . count($lines) . ' ligne' . (count($lines) > 1 ? 's' : '') . ').');
            redirect(url('stock', ['a' => 'history']));
        }
        if ($a === 'inventory') {
            $loc = (int)post('loc');
            if (!location($loc) || ($scope && $loc !== $scope)) throw new RuntimeException('Emplacement non autorisé.');
            $n = tx(function () use ($loc) {
                $n = 0;
                foreach ((array)($_POST['count'] ?? []) as $pid => $v) {
                    $v = trim((string)$v);
                    if ($v === '') continue;
                    $qty = parse_int($v);
                    if ($qty === null || $qty < 0) throw new RuntimeException('Quantité invalide : ' . $v);
                    if (stock_set((int)$pid, $loc, $qty, 'inventaire', 'Inventaire du ' . date('d/m/Y')) !== 0) $n++;
                }
                return $n;
            });
            audit('inventaire', 'stock', null, (location($loc)['name'] ?? '') . " · $n ajustement(s)");
            flash('ok', "Inventaire enregistré : $n ajustement" . ($n > 1 ? 's' : '') . '.');
            redirect(url('stock', ['a' => 'inventory', 'loc' => $loc, 'family' => get('family')]));
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        back(url('stock'));
    }
}

$tabsHtml = tabs(array_filter([
    'list' => ['État du stock', url('stock')],
    'move' => can('stock.move') ? ['Entrée · sortie · transfert', url('stock', ['a' => 'move'])] : null,
    'inventory' => can('stock.move') ? ['Inventaire', url('stock', ['a' => 'inventory'])] : null,
    'history' => ['Historique', url('stock', ['a' => 'history'])],
]), $a);
$actions = '';
if (can('stock.export')) $actions .= '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'stock'])) . '">' . icon('download') . 'Export CSV</a>';
if ($admin) $actions .= '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'stock'])) . '">' . icon('upload') . 'Import CSV</a>';

$productOptions = function (): string {
    $h = '<option value="">— Choisir un produit —</option>';
    $cur = null;
    foreach (all('SELECT id, name, sku, family FROM products WHERE active = 1 ORDER BY family, name COLLATE NOCASE') as $p) {
        if ($p['family'] !== $cur) {
            if ($cur !== null) $h .= '</optgroup>';
            $h .= '<optgroup label="' . e($p['family']) . '">';
            $cur = $p['family'];
        }
        $h .= '<option value="' . (int)$p['id'] . '">' . e($p['name'] . ($p['sku'] ? ' · ' . $p['sku'] : '')) . '</option>';
    }
    return $h . ($cur !== null ? '</optgroup>' : '');
};

/* ---------- Mouvement ---------- */

if ($a === 'move') {
    require_perm('stock.move');
    layout_start('Stock', 'stock', ['actions' => $actions]);
    echo $tabsHtml;
    $opts = $productOptions();
    ?>
    <form method="post" class="card" data-move-form>
      <?= csrf_field() ?>
      <div class="form-grid">
        <label>Type de mouvement<select name="type" data-move-type>
          <option value="entree">Entrée — réception fournisseur / retour</option>
          <option value="sortie">Sortie — casse, perte, don, usage interne</option>
          <option value="transfert">Transfert entre emplacements</option>
        </select></label>
        <label><span data-from-label>Emplacement</span><select name="from"><?= opt($locOpts, $scope ?: ($locs[count($locs) - 1]['id'] ?? '')) ?></select></label>
        <label data-to hidden>Vers<select name="to"><?= opt($locOpts, $scope ?: '') ?></select></label>
        <label>Note / n° de bon / fournisseur<input name="note" maxlength="200"></label>
      </div>
      <table class="tbl move-lines">
        <thead><tr><th>Produit</th><th class="num">Quantité</th><?php if ($admin): ?><th class="num" data-cost-col>Prix d’achat unitaire (DT)</th><?php endif; ?><th></th></tr></thead>
        <tbody>
          <tr><td><select name="product[]" class="block"><?= $opts ?></select></td><td><input name="qty[]" type="number" min="1" value="1" class="qty-in"></td>
            <?php if ($admin): ?><td data-cost-col><input name="cost[]" inputmode="decimal" placeholder="facultatif"></td><?php endif; ?>
            <td><button type="button" class="icon-btn xs" data-del-line title="Retirer"><?= icon('x') ?></button></td></tr>
        </tbody>
      </table>
      <div class="row-actions">
        <button type="button" class="btn sm ghost" data-add-line><?= icon('plus') ?>Ajouter une ligne</button>
        <span class="grow"></span>
        <button class="btn primary"><?= icon('check') ?>Enregistrer le mouvement</button>
      </div>
      <p class="hint">Pour une grosse réception, vous pouvez aussi importer un fichier CSV (Import / Export › Stock).</p>
    </form>
    <?php
    layout_end();
    exit;
}

/* ---------- Inventaire ---------- */

if ($a === 'inventory') {
    require_perm('stock.move');
    $loc = $scope ?: (int)get('loc', $locs[0]['id'] ?? 0);
    $family = (string)get('family');
    $rows = all('SELECT p.id, p.name, p.sku, p.family, COALESCE(s.qty, 0) qty FROM products p
        LEFT JOIN stock s ON s.product_id = p.id AND s.location_id = ? WHERE p.active = 1' . ($family !== '' ? ' AND p.family = ?' : '') . '
        ORDER BY p.family, p.name COLLATE NOCASE', $family !== '' ? [$loc, $family] : [$loc]);
    layout_start('Stock', 'stock', ['actions' => $actions]);
    echo $tabsHtml;
    ?>
    <form class="filters" method="get">
      <input type="hidden" name="r" value="stock"><input type="hidden" name="a" value="inventory">
      <?php if (!$scope): ?><label>Emplacement<select name="loc" onchange="this.form.submit()"><?= opt($locOpts, $loc) ?></select></label><?php endif; ?>
      <label>Famille<select name="family" onchange="this.form.submit()"><?= opt(array_combine(FAMILIES, FAMILIES), $family, true, 'Toutes') ?></select></label>
      <label class="grow">Filtrer la liste<input type="search" data-filter-rows="#inv-table" placeholder="Nom ou référence"></label>
    </form>
    <form method="post" class="card flush">
      <?= csrf_field() ?><input type="hidden" name="loc" value="<?= (int)$loc ?>">
      <div class="card-head"><b>Comptage — <?= e(location($loc)['name'] ?? '') ?></b><span class="muted">Ne remplissez que les produits comptés ; les cases vides ne changent rien.</span></div>
      <table class="tbl" id="inv-table">
        <thead><tr><th>Produit</th><th>Famille</th><th class="num">Stock système</th><th class="num">Quantité comptée</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['name']) ?> <small class="muted"><?= e($r['sku']) ?></small></td><td class="muted"><?= e($r['family']) ?></td>
            <td class="num <?= (int)$r['qty'] < 0 ? 'neg' : '' ?>"><?= (int)$r['qty'] ?></td>
            <td class="num"><input class="qty-in" type="number" min="0" name="count[<?= (int)$r['id'] ?>]" inputmode="numeric"></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="card-foot"><button class="btn primary" onclick="return confirm('Enregistrer l’inventaire ? Les écarts seront tracés dans l’historique.')"><?= icon('check') ?>Valider l’inventaire</button></div>
    </form>
    <?php
    layout_end();
    exit;
}

/* ---------- Historique ---------- */

if ($a === 'history') {
    $f = ['loc' => (int)get('loc'), 'type' => (string)get('type'), 'q' => (string)get('q'),
        'from' => parse_date(get('from')) ?? date('Y-m-d', strtotime('-30 days')), 'to' => parse_date(get('to')) ?? today()];
    $where = ['date(m.created_at) BETWEEN ? AND ?'];
    $p = [$f['from'], $f['to']];
    if ($f['loc']) { $where[] = 'm.location_id = ?'; $p[] = $f['loc']; }
    if ($f['type'] !== '') { $where[] = 'm.type = ?'; $p[] = $f['type']; }
    if ($f['q'] !== '') { $where[] = '(pr.name LIKE ? OR pr.sku LIKE ? OR m.ref LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
    $w = implode(' AND ', $where);
    $total = (int)val("SELECT COUNT(*) FROM stock_moves m JOIN products pr ON pr.id = m.product_id WHERE $w", $p);
    $page = max(1, (int)get('page', 1));
    $rows = all("SELECT m.*, pr.name, pr.sku, l.name loc, u.name user FROM stock_moves m JOIN products pr ON pr.id = m.product_id
        JOIN locations l ON l.id = m.location_id LEFT JOIN users u ON u.id = m.user_id WHERE $w ORDER BY m.id DESC LIMIT 100 OFFSET " . (($page - 1) * 100), $p);
    layout_start('Stock', 'stock', ['actions' => (can('stock.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'mouvements', 'from' => $f['from'], 'to' => $f['to']])) . '">' . icon('download') . 'Export CSV</a>' : '')]);
    echo $tabsHtml;
    ?>
    <form class="filters" method="get">
      <input type="hidden" name="r" value="stock"><input type="hidden" name="a" value="history">
      <label>Du<input type="date" name="from" value="<?= e($f['from']) ?>"></label>
      <label>Au<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
      <label>Emplacement<select name="loc"><?= opt($locOpts, $f['loc'], true, 'Tous') ?></select></label>
      <label>Type<select name="type"><?= opt(MOVE_TYPES, $f['type'], true, 'Tous') ?></select></label>
      <label class="grow">Produit / référence<input name="q" value="<?= e($f['q']) ?>"></label>
      <button class="btn"><?= icon('search') ?>Filtrer</button>
    </form>
    <div class="card flush">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Produit</th><th>Emplacement</th><th>Type</th><th>Réf.</th><th class="num">Quantité</th><th class="num">Après</th><th>Par</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $m): ?>
          <tr><td><?= fdate($m['created_at'], true) ?></td><td><?= e($m['name']) ?> <small class="muted"><?= e($m['sku']) ?></small><?= $m['note'] ? '<br><small class="muted">' . e($m['note']) . '</small>' : '' ?></td>
            <td><?= e($m['loc']) ?></td><td><?= e(MOVE_TYPES[$m['type']] ?? $m['type']) ?></td><td><?= e($m['ref']) ?></td>
            <td class="num <?= $m['qty_change'] < 0 ? 'neg' : 'pos' ?>"><?= $m['qty_change'] > 0 ? '+' : '' ?><?= (int)$m['qty_change'] ?></td>
            <td class="num"><?= (int)$m['qty_after'] ?></td><td class="muted"><?= e($m['user']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="empty-row">Aucun mouvement sur cette période.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= pager($total, $page, 100, array_filter(['a' => 'history'] + $f), 'stock') ?>
    <?php
    layout_end();
    exit;
}

/* ---------- État du stock ---------- */

$f = ['q' => (string)get('q'), 'family' => (string)get('family'), 'show' => (string)get('show')];
$where = ['p.active = 1'];
$p = [];
if ($f['q'] !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
if ($f['family'] !== '') { $where[] = 'p.family = ?'; $p[] = $f['family']; }
$having = '';
if ($f['show'] === 'stock') $having = 'HAVING total > 0';
if ($f['show'] === 'bas') $having = 'HAVING p.min_stock > 0 AND total <= p.min_stock';
if ($f['show'] === 'zero') $having = 'HAVING total <= 0';
if ($f['show'] === 'negatif') $having = 'HAVING MIN(COALESCE(s.qty,0)) < 0';
$w = implode(' AND ', $where);
$rows = all("SELECT p.id, p.name, p.sku, p.family, p.brand, p.price, p.promo_price, p.cost, p.min_stock, p.image, p.image_url,
        COALESCE(SUM(s.qty), 0) AS total FROM products p LEFT JOIN stock s ON s.product_id = p.id
    WHERE $w GROUP BY p.id $having ORDER BY p.family, p.name COLLATE NOCASE", $p);
$by = [];
foreach (all('SELECT product_id, location_id, qty FROM stock') as $s) $by[$s['product_id']][$s['location_id']] = (int)$s['qty'];
$sumLoc = [];
$valCost = 0;
$valSale = 0;
$units = 0;
$noCost = 0;
foreach ($rows as $r) {
    foreach ($locs as $l) $sumLoc[$l['id']] = ($sumLoc[$l['id']] ?? 0) + max(0, $by[$r['id']][$l['id']] ?? 0);
    $q = max(0, (int)$r['total']);
    $units += $q;
    $valSale += $q * product_price($r);
    if ($r['cost'] !== null) $valCost += $q * (int)$r['cost']; elseif ($q > 0) $noCost++;
}

layout_start('Stock', 'stock', ['actions' => $actions]);
echo $tabsHtml;
?>
<div class="grid4 kpis">
  <div class="kpi"><span>Unités en stock</span><b><?= number_format($units, 0, ',', ' ') ?></b></div>
  <?php foreach ($locs as $l): ?><div class="kpi"><span><?= e($l['name']) ?></span><b><?= number_format($sumLoc[$l['id']] ?? 0, 0, ',', ' ') ?></b></div><?php endforeach; ?>
  <div class="kpi"><span>Valeur au prix de vente</span><b><?= money0($valSale) ?></b>
    <?php if ($admin): ?><small>au prix d’achat : <?= money0($valCost) ?><?= $noCost ? ' · ' . $noCost . ' sans prix d’achat' : '' ?></small><?php endif; ?></div>
</div>
<form class="filters" method="get">
  <input type="hidden" name="r" value="stock">
  <label class="grow">Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="Nom, référence, marque"></label>
  <label>Famille<select name="family"><?= opt(array_combine(FAMILIES, FAMILIES), $f['family'], true, 'Toutes') ?></select></label>
  <label>Afficher<select name="show"><?= opt(['' => 'Tous les produits', 'stock' => 'En stock', 'bas' => 'Stock bas (sous le minimum)', 'zero' => 'Stock à zéro', 'negatif' => 'Stock négatif (à recompter)'], $f['show']) ?></select></label>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>
<div class="card flush">
  <table class="tbl hover">
    <thead><tr><th></th><th>Produit</th><th>Famille</th><?php foreach ($locs as $l): ?><th class="num"><?= e($l['name']) ?></th><?php endforeach; ?><th class="num">Total</th><th class="num">Mini</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-href="<?= e(url('products', ['a' => 'view', 'id' => $r['id']])) ?>">
        <td class="w-thumb"><?= product_thumb($r, 'thumb sm') ?></td>
        <td><b><?= e($r['name']) ?></b><br><small class="muted"><?= e($r['sku']) ?></small></td>
        <td class="muted"><?= e($r['family']) ?></td>
        <?php foreach ($locs as $l): $q = $by[$r['id']][$l['id']] ?? 0; ?><td class="num <?= $q < 0 ? 'neg' : ($q === 0 ? 'muted' : '') ?>"><?= $q ?></td><?php endforeach; ?>
        <td class="num"><b class="<?= (int)$r['min_stock'] > 0 && (int)$r['total'] <= (int)$r['min_stock'] ? 'warn-t' : '' ?>"><?= (int)$r['total'] ?></b></td>
        <td class="num muted"><?= (int)$r['min_stock'] ?: '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Aucun produit.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php
layout_end();
