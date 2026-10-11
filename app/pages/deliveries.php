<?php
declare(strict_types=1);

require_perm('deliveries.view');
$a = (string)get('a', 'list');
$scope = scope_loc();
$admin = is_admin();

function shipment_load(int $id): ?array
{
    $sh = one('SELECT * FROM shipments WHERE id = ?', [$id]);
    return $sh && can_loc($sh['location_id'] !== null ? (int)$sh['location_id'] : null) ? $sh : null;
}

if (is_post()) {
    csrf_check();
    try {
        if ($a === 'carrier_save') {
            if (!$admin) throw new RuntimeException('Seul l’administrateur gère les transporteurs.');
            $id = (int)post('id');
            $data = [
                'name' => (string)post('name'), 'type' => post('type') === 'interne' ? 'interne' : 'externe',
                'phone' => (string)post('phone'), 'contact' => (string)post('contact'), 'zones' => (string)post('zones'),
                'default_fee' => max(0, parse_money(post('default_fee')) ?? 0), 'cod' => post('cod') ? 1 : 0,
                'notes' => (string)post('notes'), 'active' => post('active') ? 1 : 0,
            ];
            if ($data['name'] === '') throw new RuntimeException('Le nom du transporteur est obligatoire.');
            if ($id) update('carriers', $data, 'id = ?', [$id]);
            else $id = insert('carriers', $data + ['created_at' => now()]);
            audit('transporteur enregistré', 'carrier', $id, $data['name']);
            flash('ok', 'Transporteur enregistré.');
            redirect(url('deliveries', ['a' => 'carriers']));
        }
        if ($a === 'carrier_delete') {
            if (!$admin) throw new RuntimeException('Action réservée à l’administrateur.');
            $id = (int)post('id');
            if ((int)val('SELECT COUNT(*) FROM shipments WHERE carrier_id = ?', [$id])) {
                update('carriers', ['active' => 0], 'id = ?', [$id]);
                flash('ok', 'Transporteur utilisé dans des livraisons : il a été désactivé.');
            } else {
                q('DELETE FROM carriers WHERE id = ?', [$id]);
                flash('ok', 'Transporteur supprimé.');
            }
            redirect(url('deliveries', ['a' => 'carriers']));
        }

        require_perm('deliveries.edit');
        if ($a === 'status') {
            $sh = shipment_load((int)post('id'));
            if (!$sh) throw new RuntimeException('Livraison introuvable.');
            $status = (string)post('status');
            if (!isset(SHIP_STATUS[$status])) throw new RuntimeException('Statut invalide.');
            update('shipments', ['status' => $status, 'updated_at' => now(), 'delivered_at' => $status === 'livree' ? ($sh['delivered_at'] ?: now()) : null], 'id = ?', [$sh['id']]);
            audit('statut livraison', 'shipment', $sh['id'], $sh['number'] . ' → ' . SHIP_STATUS[$status]);
            flash('ok', $sh['number'] . ' : ' . SHIP_STATUS[$status] . '.');
            back(url('deliveries'));
        }
        if ($a === 'collect') {
            $sh = shipment_load((int)post('id'));
            if (!$sh) throw new RuntimeException('Livraison introuvable.');
            shipment_collect($sh);
            flash('ok', 'Montant de ' . money($sh['cod_amount']) . ' marqué comme encaissé (' . $sh['number'] . ').');
            back(url('deliveries'));
        }
        if ($a === 'save') {
            $id = (int)post('id');
            $sh = $id ? shipment_load($id) : null;
            if ($id && !$sh) throw new RuntimeException('Livraison introuvable.');
            $locId = $scope ?: ((int)post('location_id') ?: null);
            $saleId = null;
            $saleNumber = (string)post('sale_number');
            if ($saleNumber !== '') {
                $sale = one('SELECT id, location_id, client_id FROM sales WHERE number = ?', [$saleNumber]);
                if (!$sale || !can_loc((int)$sale['location_id'])) throw new RuntimeException('Vente « ' . $saleNumber . ' » introuvable.');
                $saleId = (int)$sale['id'];
            }
            $status = (string)post('status', 'a_preparer');
            if (!isset(SHIP_STATUS[$status])) $status = 'a_preparer';
            $carrier = (int)post('carrier_id') ?: null;
            $data = [
                'sale_id' => $saleId, 'location_id' => $locId, 'carrier_id' => $carrier,
                'recipient' => (string)post('recipient'), 'phone' => normalize_phone((string)post('phone')),
                'gouvernorat' => (string)post('gouvernorat'), 'city' => (string)post('city'), 'address' => (string)post('address'),
                'status' => $status, 'tracking' => (string)post('tracking'),
                'fee' => max(0, parse_money(post('fee')) ?? 0), 'cod_amount' => max(0, parse_money(post('cod_amount')) ?? 0),
                'planned_date' => parse_date(post('planned_date')), 'note' => (string)post('note'), 'updated_at' => now(),
            ];
            if ($data['recipient'] === '') throw new RuntimeException('Le destinataire est obligatoire.');
            if ($status === 'livree') $data['delivered_at'] = $sh['delivered_at'] ?? now();
            if ($sh) {
                if ($sh['cod_collected']) unset($data['cod_amount']);
                update('shipments', $data, 'id = ?', [$sh['id']]);
            } else {
                if ($saleId) $data['client_id'] = val('SELECT client_id FROM sales WHERE id = ?', [$saleId]);
                $id = shipment_create($data);
            }
            if (post('cod_collected')) {
                $fresh = one('SELECT * FROM shipments WHERE id = ?', [$id]);
                shipment_collect($fresh);
            }
            flash('ok', 'Livraison enregistrée.');
            redirect(url('deliveries'));
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        back(url('deliveries'));
    }
}

$carriersAll = all('SELECT * FROM carriers ORDER BY active DESC, type DESC, name');
$carrierOpts = [];
foreach ($carriersAll as $c) if ($c['active']) $carrierOpts[$c['id']] = $c['name'];

$tabsHtml = tabs([
    'list' => ['Livraisons', url('deliveries')],
    'carriers' => ['Transporteurs', url('deliveries', ['a' => 'carriers'])],
], $a === 'edit' ? 'list' : ($a === 'carrier_edit' ? 'carriers' : $a));

/* ---------- Transporteurs ---------- */

if ($a === 'carriers' || $a === 'carrier_edit') {
    require_perm('carriers.view');
    $stats = [];
    $scopeSql = $scope ? ' AND location_id = ' . (int)$scope : '';
    foreach (all("SELECT carrier_id, COUNT(*) n,
            SUM(status IN ('a_preparer','en_cours')) open,
            SUM(status = 'livree') done,
            SUM(status = 'retour') ret,
            COALESCE(SUM(CASE WHEN status = 'livree' AND cod_collected = 0 THEN cod_amount END), 0) owed,
            COALESCE(SUM(CASE WHEN status <> 'annulee' THEN fee END), 0) fees
        FROM shipments WHERE carrier_id IS NOT NULL $scopeSql GROUP BY carrier_id") as $s) $stats[$s['carrier_id']] = $s;
    $edit = null;
    if ($a === 'carrier_edit') {
        if (!$admin) redirect(url('deliveries', ['a' => 'carriers']));
        $edit = (int)get('id') ? one('SELECT * FROM carriers WHERE id = ?', [(int)get('id')]) : ['id' => 0, 'name' => '', 'type' => 'externe', 'phone' => '', 'contact' => '', 'zones' => '', 'default_fee' => 0, 'cod' => 1, 'notes' => '', 'active' => 1];
    }
    $actions = (can('deliveries.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'transporteurs'])) . '">' . icon('download') . 'Export CSV</a>' : '')
        . ($admin ? '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'transporteurs'])) . '">' . icon('upload') . 'Import CSV</a><a class="btn sm primary" href="' . e(url('deliveries', ['a' => 'carrier_edit'])) . '">' . icon('plus') . 'Nouveau transporteur</a>' : '');
    layout_start('Livraisons & transporteurs', 'deliveries', ['actions' => $actions]);
    echo $tabsHtml;
    if ($edit): ?>
      <form method="post" action="<?= e(url('deliveries', ['a' => 'carrier_save'])) ?>" class="card form-grid">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <h2 class="span2"><?= $edit['id'] ? 'Modifier le transporteur' : 'Nouveau transporteur' ?></h2>
        <label>Nom<input name="name" value="<?= e($edit['name']) ?>" required></label>
        <label>Type<select name="type"><?= opt(['interne' => 'Interne (camion FitnessTN)', 'externe' => 'Externe (société de livraison)'], $edit['type']) ?></select></label>
        <label>Téléphone<input name="phone" value="<?= e($edit['phone']) ?>"></label>
        <label>Contact<input name="contact" value="<?= e($edit['contact']) ?>"></label>
        <label>Zones desservies<input name="zones" value="<?= e($edit['zones']) ?>" placeholder="Grand Tunis, Cap Bon, Sahel…"></label>
        <label>Tarif par défaut (DT)<input name="default_fee" inputmode="decimal" value="<?= e(m_in($edit['default_fee'])) ?>"></label>
        <label class="span2">Notes<textarea name="notes" rows="2"><?= e($edit['notes']) ?></textarea></label>
        <label class="check"><input type="checkbox" name="cod" value="1" <?= $edit['cod'] ? 'checked' : '' ?>> Accepte le paiement à la livraison</label>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Actif</label>
        <div class="span2 row-actions"><a class="btn ghost" href="<?= e(url('deliveries', ['a' => 'carriers'])) ?>">Annuler</a><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div>
      </form>
      <?php if ($edit['id']): ?>
        <form method="post" action="<?= e(url('deliveries', ['a' => 'carrier_delete'])) ?>" class="danger-inline" onsubmit="return confirm('Supprimer ce transporteur ?')">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn ghost danger sm"><?= icon('trash') ?>Supprimer</button>
        </form>
      <?php endif;
    endif; ?>
    <div class="card flush">
      <table class="tbl">
        <thead><tr><th>Transporteur</th><th>Contact</th><th>Zones</th><th class="num">Tarif</th><th class="num">Livraisons</th><th class="num">En cours</th><th class="num">Retours</th><th class="num">À récupérer</th><th class="num">Frais payés</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($carriersAll as $c): $s = $stats[$c['id']] ?? ['n' => 0, 'open' => 0, 'done' => 0, 'ret' => 0, 'owed' => 0, 'fees' => 0]; ?>
          <tr class="<?= $c['active'] ? '' : 'dim' ?>">
            <td><b><?= e($c['name']) ?></b><br><small class="muted"><?= $c['type'] === 'interne' ? 'Interne' : 'Externe' ?><?= $c['cod'] ? ' · paiement à la livraison' : '' ?><?= $c['active'] ? '' : ' · inactif' ?></small></td>
            <td><?= e($c['contact']) ?><?= $c['phone'] ? '<br><a href="tel:' . e($c['phone']) . '">' . e($c['phone']) . '</a>' : '' ?></td>
            <td class="muted"><?= e($c['zones']) ?></td>
            <td class="num"><?= (int)$c['default_fee'] ? money($c['default_fee']) : '' ?></td>
            <td class="num"><?= (int)$s['done'] ?> / <?= (int)$s['n'] ?></td>
            <td class="num"><?= (int)$s['open'] ?></td>
            <td class="num"><?= (int)$s['ret'] ?></td>
            <td class="num"><?= (int)$s['owed'] ? '<b class="warn-t">' . money($s['owed']) . '</b>' : '' ?></td>
            <td class="num"><?= money($s['fees']) ?></td>
            <td class="num"><?php if ($admin): ?><a class="icon-btn xs" title="Modifier" href="<?= e(url('deliveries', ['a' => 'carrier_edit', 'id' => $c['id']])) ?>"><?= icon('edit') ?></a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">« À récupérer » = montants encaissés chez les clients (paiement à la livraison) que le transporteur doit encore reverser.</p>
    <?php
    layout_end();
    exit;
}

/* ---------- Formulaire livraison ---------- */

if ($a === 'edit') {
    $id = (int)get('id');
    $sh = $id ? shipment_load($id) : null;
    if ($id && !$sh) redirect(url('deliveries'));
    $sale = $sh && $sh['sale_id'] ? one('SELECT number, total, paid FROM sales WHERE id = ?', [$sh['sale_id']]) : null;
    $sh = $sh ?: ['id' => 0, 'number' => '', 'recipient' => '', 'phone' => '', 'gouvernorat' => '', 'city' => '', 'address' => '', 'status' => 'a_preparer',
        'tracking' => '', 'fee' => 0, 'cod_amount' => 0, 'cod_collected' => 0, 'planned_date' => '', 'note' => '', 'carrier_id' => null, 'location_id' => $scope];
    $editable = can('deliveries.edit');
    layout_start($sh['id'] ? 'Livraison ' . $sh['number'] : 'Nouvelle livraison', 'deliveries');
    ?>
    <p><a class="back" href="<?= e(url('deliveries')) ?>"><?= icon('left') ?>Toutes les livraisons</a></p>
    <form method="post" action="<?= e(url('deliveries', ['a' => 'save'])) ?>" class="card form-grid">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$sh['id'] ?>">
      <label>Vente liée (n°)<input name="sale_number" value="<?= e($sale['number'] ?? '') ?>" placeholder="ex. TUN-26-00012"><?php if ($sale): ?><small><a href="<?= e(url('sales', ['a' => 'view', 'id' => $sh['sale_id']])) ?>">Voir la vente</a> · total <?= money($sale['total']) ?>, payé <?= money($sale['paid']) ?></small><?php endif; ?></label>
      <?php if (!$scope): ?><label>Départ<select name="location_id"><?= opt(location_options(), $sh['location_id'], true) ?></select></label><?php endif; ?>
      <label>Destinataire<input name="recipient" value="<?= e($sh['recipient']) ?>" required></label>
      <label>Téléphone<input name="phone" value="<?= e($sh['phone']) ?>" inputmode="tel"></label>
      <label>Gouvernorat<select name="gouvernorat"><?= opt(array_combine(GOUVERNORATS, GOUVERNORATS), $sh['gouvernorat'], true) ?></select></label>
      <label>Ville / délégation<input name="city" value="<?= e($sh['city']) ?>"></label>
      <label class="span2">Adresse<input name="address" value="<?= e($sh['address']) ?>"></label>
      <label>Transporteur<select name="carrier_id"><?= opt($carrierOpts, $sh['carrier_id'], true, 'À définir') ?></select></label>
      <label>N° de suivi<input name="tracking" value="<?= e($sh['tracking']) ?>"></label>
      <label>Date prévue<input type="date" name="planned_date" value="<?= e($sh['planned_date']) ?>"></label>
      <label>Statut<select name="status"><?= opt(SHIP_STATUS, $sh['status']) ?></select></label>
      <label>Coût transporteur (DT)<input name="fee" inputmode="decimal" value="<?= e(m_in($sh['fee'])) ?>"></label>
      <label>À encaisser à la livraison (DT)<input name="cod_amount" inputmode="decimal" value="<?= e(m_in($sh['cod_amount'])) ?>" <?= $sh['cod_collected'] ? 'readonly' : '' ?>></label>
      <?php if (!$sh['cod_collected']): ?>
        <label class="check span2"><input type="checkbox" name="cod_collected" value="1"> Le montant à encaisser a été récupéré (enregistre le paiement sur la vente)</label>
      <?php else: ?><p class="span2"><?= badge('Montant encaissé', 'ok') ?></p><?php endif; ?>
      <label class="span2">Note<textarea name="note" rows="2"><?= e($sh['note']) ?></textarea></label>
      <?php if ($editable): ?><div class="span2 row-actions"><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div><?php endif; ?>
    </form>
    <?php
    layout_end();
    exit;
}

/* ---------- Liste ---------- */

$f = ['status' => (string)get('status', 'ouvertes'), 'carrier' => (int)get('carrier'), 'loc' => $scope ?? (int)get('loc'), 'q' => (string)get('q')];
$where = ['1=1'];
$p = [];
if ($f['status'] === 'ouvertes') $where[] = "sh.status IN ('a_preparer','en_cours')";
elseif ($f['status'] === 'a_recuperer') $where[] = "sh.status = 'livree' AND sh.cod_collected = 0 AND sh.cod_amount > 0";
elseif ($f['status'] !== '' && $f['status'] !== 'toutes') { $where[] = 'sh.status = ?'; $p[] = $f['status']; }
if ($f['carrier']) { $where[] = 'sh.carrier_id = ?'; $p[] = $f['carrier']; }
if ($f['loc']) { $where[] = 'sh.location_id = ?'; $p[] = $f['loc']; }
if ($f['q'] !== '') { $where[] = '(sh.number LIKE ? OR sh.recipient LIKE ? OR sh.phone LIKE ? OR sh.city LIKE ? OR sh.tracking LIKE ?)'; array_push($p, ...array_fill(0, 5, "%{$f['q']}%")); }
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM shipments sh WHERE $w", $p);
$page = max(1, (int)get('page', 1));
$rows = all("SELECT sh.*, ca.name carrier, s.number sale_number FROM shipments sh LEFT JOIN carriers ca ON ca.id = sh.carrier_id
    LEFT JOIN sales s ON s.id = sh.sale_id WHERE $w ORDER BY CASE sh.status WHEN 'a_preparer' THEN 0 WHEN 'en_cours' THEN 1 ELSE 2 END,
    COALESCE(sh.planned_date, '9999'), sh.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $p);
$scopeWhere = $scope ? 'WHERE location_id = ' . (int)$scope : '';
$counts = [];
foreach (all("SELECT status, COUNT(*) n FROM shipments $scopeWhere GROUP BY status") as $c) $counts[$c['status']] = (int)$c['n'];
$owed = (int)val("SELECT COUNT(*) FROM shipments WHERE status = 'livree' AND cod_collected = 0 AND cod_amount > 0" . ($scope ? ' AND location_id = ' . (int)$scope : ''));

$actions = (can('deliveries.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'livraisons'])) . '">' . icon('download') . 'Export CSV</a>' : '')
    . (can('deliveries.import') ? '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'livraisons'])) . '">' . icon('upload') . 'Import CSV</a>' : '')
    . (can('deliveries.edit') ? '<a class="btn sm primary" href="' . e(url('deliveries', ['a' => 'edit'])) . '">' . icon('plus') . 'Nouvelle livraison</a>' : '');
layout_start('Livraisons & transporteurs', 'deliveries', ['actions' => $actions]);
echo $tabsHtml;
$chips = ['ouvertes' => 'À faire (' . (($counts['a_preparer'] ?? 0) + ($counts['en_cours'] ?? 0)) . ')'];
foreach (SHIP_STATUS as $k => $label) $chips[$k] = $label . ' (' . ($counts[$k] ?? 0) . ')';
$chips['a_recuperer'] = 'Argent à récupérer (' . $owed . ')';
$chips['toutes'] = 'Toutes';
?>
<div class="chips">
  <?php foreach ($chips as $k => $label): ?>
    <a class="chip <?= $f['status'] === $k ? 'on' : '' ?>" href="<?= e(url('deliveries', array_filter(['status' => $k, 'carrier' => $f['carrier'] ?: null, 'q' => $f['q']]))) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<form class="filters" method="get">
  <input type="hidden" name="r" value="deliveries"><input type="hidden" name="status" value="<?= e($f['status']) ?>">
  <label class="grow">Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="N°, client, téléphone, ville, suivi"></label>
  <label>Transporteur<select name="carrier"><?= opt($carrierOpts, $f['carrier'], true, 'Tous') ?></select></label>
  <?php if (!$scope): ?><label>Départ<select name="loc"><?= opt(location_options(), $f['loc'], true, 'Tous') ?></select></label><?php endif; ?>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>
<div class="card flush">
  <table class="tbl">
    <thead><tr><th>N°</th><th>Prévue</th><th>Destinataire</th><th>Destination</th><th>Transporteur</th><th class="num">À encaisser</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= e(url('deliveries', ['a' => 'edit', 'id' => $r['id']])) ?>"><b><?= e($r['number']) ?></b></a><?= $r['sale_number'] ? '<br><small><a class="muted" href="' . e(url('sales', ['a' => 'view', 'id' => $r['sale_id']])) . '">' . e($r['sale_number']) . '</a></small>' : '' ?></td>
        <td><?= $r['planned_date'] ? fdate($r['planned_date']) : '<span class="muted">—</span>' ?><?= $r['delivered_at'] ? '<br><small class="muted">livrée ' . fdate($r['delivered_at']) . '</small>' : '' ?></td>
        <td><?= e($r['recipient']) ?><?= $r['phone'] ? '<br><a href="tel:' . e($r['phone']) . '"><small>' . e($r['phone']) . '</small></a>' : '' ?></td>
        <td><?= e(trim($r['city'] . ', ' . $r['gouvernorat'], ', ')) ?><?= $r['address'] ? '<br><small class="muted">' . e($r['address']) . '</small>' : '' ?></td>
        <td><?= e($r['carrier'] ?: '—') ?><?= $r['tracking'] ? '<br><small class="muted">' . e($r['tracking']) . '</small>' : '' ?></td>
        <td class="num"><?php if ((int)$r['cod_amount']): ?><?= money($r['cod_amount']) ?><br><?= $r['cod_collected'] ? badge('encaissé', 'ok') : ($r['status'] === 'livree' ? badge('à récupérer', 'warn') : '') ?><?php endif; ?></td>
        <td>
          <?php if (can('deliveries.edit') && in_array($r['status'], ['a_preparer', 'en_cours', 'retour'], true)): ?>
            <form method="post" action="<?= e(url('deliveries', ['a' => 'status'])) ?>" class="status-form">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status" onchange="this.form.submit()" class="st-<?= e($r['status']) ?>"><?= opt(SHIP_STATUS, $r['status']) ?></select>
            </form>
          <?php else: ?><?= badge(SHIP_STATUS[$r['status']] ?? $r['status'], ship_tone($r['status'])) ?><?php endif; ?>
        </td>
        <td class="num">
          <?php if (can('deliveries.edit') && $r['status'] === 'livree' && !$r['cod_collected'] && (int)$r['cod_amount'] > 0): ?>
            <form method="post" action="<?= e(url('deliveries', ['a' => 'collect'])) ?>" onsubmit="return confirm('Confirmer la réception de <?= e(money($r['cod_amount'])) ?> ?')">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn xs">Encaissé</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty-row">Aucune livraison.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager($total, $page, 50, array_filter($f), 'deliveries') ?>
<?php
layout_end();
