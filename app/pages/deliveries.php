<?php
declare(strict_types=1);

require_perm('deliveries.view');
$a = (string)get('a', 'list');
$scope = scope_loc();
$admin = is_admin();

// Statuts que le livreur peut choisir lui-même
const DRIVER_STATUS = ['en_cours' => 'En cours de livraison', 'livree' => 'Livrée', 'retour' => 'Retour / échec'];

/** Lien Google Maps vers l'adresse de livraison. */
function maps_url(array $r): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($r['address'] . ', ' . $r['city'] . ', ' . $r['gouvernorat'] . ', Tunisie', ', '));
}

function shipment_load(int $id): ?array
{
    $sh = one('SELECT * FROM shipments WHERE id = ?', [$id]);
    return $sh && can_loc($sh['location_id'] !== null ? (int)$sh['location_id'] : null) ? $sh : null;
}

/** « Showroom Tunis » ou la précision saisie (fournisseur, dépôt…). */
function ship_origin(array $sh): string
{
    $loc = $sh['location_id'] ? location((int)$sh['location_id']) : null;
    return trim(($loc['name'] ?? '') . (($sh['origin_note'] ?? '') !== '' ? ($loc ? ' · ' : '') . $sh['origin_note'] : '')) ?: 'Départ non précisé';
}

/** « Gafsa — Hay Essourour » */
function ship_destination(array $sh): string
{
    $place = trim(($sh['city'] ?? '') !== '' ? $sh['city'] : ($sh['address'] ?? ''));
    $gouv = (string)($sh['gouvernorat'] ?? '');
    if ($gouv !== '' && $place !== '' && slug_key($place) !== slug_key($gouv)) return $gouv . ' — ' . $place;
    return $gouv !== '' ? $gouv : ($place !== '' ? $place : 'Destination à préciser');
}

/** Lignes de marchandise envoyées par le formulaire. */
function posted_items(): array
{
    $out = [];
    foreach ((array)($_POST['items'] ?? []) as $it) {
        $label = trim((string)($it['label'] ?? ''));
        if ($label === '') continue;
        $pid = val('SELECT id FROM products WHERE name = ? COLLATE NOCASE LIMIT 1', [$label]);
        $out[] = ['label' => $label, 'qty' => max(1, (int)($it['qty'] ?? 1)), 'product_id' => $pid ? (int)$pid : null];
    }
    return $out;
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

        if ($a === 'status') {
            if (!can('deliveries.edit') && !can('deliveries.status')) throw new RuntimeException('Action non autorisée.');
            $sh = shipment_load((int)post('id'));
            if (!$sh) throw new RuntimeException('Livraison introuvable.');
            $status = (string)post('status');
            if (!isset(SHIP_STATUS[$status])) throw new RuntimeException('Statut invalide.');
            if (!can('deliveries.edit') && (!isset(DRIVER_STATUS[$status]) || !in_array($sh['status'], ['a_preparer', 'en_cours', 'retour'], true))) {
                throw new RuntimeException('Le livreur peut seulement indiquer « en cours », « livrée » ou « retour / échec ».');
            }
            shipment_set_status($sh, $status);
            audit('statut livraison', 'shipment', $sh['id'], $sh['number'] . ' → ' . SHIP_STATUS[$status]);
            flash('ok', $sh['number'] . ' : ' . SHIP_STATUS[$status] . '.');
            back(url('deliveries'));
        }
        require_perm('deliveries.edit');
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
            $holder = (string)post('holder');
            $holder = isset(SHIP_HOLDERS[$holder]) ? $holder : null;
            $carrier = (int)post('carrier_id') ?: null;
            $address = (string)post('address');
            $gouv = (string)post('gouvernorat');
            if ($gouv === '') $gouv = guess_gouvernorat(post('city') . ' ' . $address);
            $data = [
                'sale_id' => $saleId, 'location_id' => $locId, 'origin_note' => mb_substr((string)post('origin_note'), 0, 120), 'carrier_id' => $carrier,
                'recipient' => (string)post('recipient'), 'phone' => normalize_phone((string)post('phone')),
                'gouvernorat' => $gouv, 'city' => (string)post('city'), 'address' => $address,
                'status' => $status, 'tracking' => (string)post('tracking'), 'holder_note' => mb_substr((string)post('holder_note'), 0, 120),
                'technician' => mb_substr((string)post('technician'), 0, 60), 'technician_fee' => max(0, parse_money(post('technician_fee')) ?? 0),
                'fee' => max(0, parse_money(post('fee')) ?? 0), 'client_fee' => max(0, parse_money(post('client_fee')) ?? 0),
                'cod_amount' => max(0, parse_money(post('cod_amount')) ?? 0),
                'planned_date' => parse_date(post('planned_date')), 'note' => (string)post('note'), 'updated_at' => now(),
            ];
            if ($data['recipient'] === '') throw new RuntimeException('Le destinataire est obligatoire.');
            $items = posted_items();
            if ($sh) {
                if ($sh['cod_collected']) unset($data['cod_amount']);
                $statusChanged = $status !== $sh['status'];
                // Lieu de la marchandise : choix explicite, sinon déduit du nouveau statut
                $data['holder'] = $holder !== null && $holder !== $sh['holder'] ? $holder : ($statusChanged ? ship_holder_for_status($status) : $sh['holder']);
                $data['delivered_at'] = $status === 'livree' ? ($sh['delivered_at'] ?: now()) : null;
                update('shipments', $data, 'id = ?', [$sh['id']]);
                if ($statusChanged) shipment_event((int)$sh['id'], SHIP_STATUS[$status], $status === 'livree' ? 'ok' : (in_array($status, ['retour', 'annulee'], true) ? 'err' : 'info'));
                elseif ($data['holder'] !== $sh['holder']) shipment_event((int)$sh['id'], 'Marchandise : ' . mb_strtolower(SHIP_HOLDERS[$data['holder']]) . ($data['holder_note'] !== '' ? ' (' . $data['holder_note'] . ')' : ''));
                if ((int)$carrier !== (int)$sh['carrier_id'] && $carrier) shipment_event((int)$sh['id'], 'Transporteur : ' . val('SELECT name FROM carriers WHERE id = ?', [$carrier]));
                if (isset($_POST['items_sent'])) shipment_set_items((int)$sh['id'], $items);
            } else {
                $data['holder'] = $holder ?? ship_holder_for_status($status);
                if ($status === 'livree') $data['delivered_at'] = now();
                if ($saleId) $data['client_id'] = val('SELECT client_id FROM sales WHERE id = ?', [$saleId]);
                $id = shipment_create($data, $items ?: null);
            }
            if (post('cod_collected')) {
                $fresh = one('SELECT * FROM shipments WHERE id = ?', [$id]);
                shipment_collect($fresh);
            }
            flash('ok', 'Livraison enregistrée.');
            redirect(url('deliveries', ['a' => 'edit', 'id' => $id]));
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        back(url('deliveries'));
    }
}

$carriersAll = all('SELECT * FROM carriers ORDER BY active DESC, type DESC, name');
$carrierOpts = [];
foreach ($carriersAll as $c) if ($c['active']) $carrierOpts[$c['id']] = $c['name'];

$tabsHtml = can('carriers.view') ? tabs([
    'list' => ['Livraisons', url('deliveries')],
    'carriers' => ['Transporteurs', url('deliveries', ['a' => 'carriers'])],
], $a === 'edit' ? 'list' : ($a === 'carrier_edit' ? 'carriers' : $a)) : '';
$driver = !can('deliveries.edit') && can('deliveries.status');

/* ---------- Transporteurs ---------- */

if ($a === 'carriers' || $a === 'carrier_edit') {
    require_perm('carriers.view');
    $stats = [];
    $scopeSql = $scope ? ' AND location_id = ' . (int)$scope : '';
    foreach (all("SELECT carrier_id, COUNT(*) n,
            SUM(status IN ('a_preparer','en_cours')) open,
            SUM(status = 'livree') done,
            SUM(status = 'retour') ret,
            SUM(holder = 'transporteur') holding,
            COALESCE(SUM(CASE WHEN status = 'livree' AND cod_collected = 0 THEN cod_amount END), 0) owed,
            COALESCE(SUM(CASE WHEN status <> 'annulee' THEN fee END), 0) fees
        FROM shipments WHERE carrier_id IS NOT NULL $scopeSql GROUP BY carrier_id") as $s) $stats[$s['carrier_id']] = $s;
    $edit = null;
    if ($a === 'carrier_edit') {
        if (!$admin) redirect(url('deliveries', ['a' => 'carriers']));
        $edit = (int)get('id') ? one('SELECT * FROM carriers WHERE id = ?', [(int)get('id')]) : ['id' => 0, 'name' => '', 'type' => 'externe', 'phone' => '', 'contact' => '', 'zones' => '', 'default_fee' => 0, 'cod' => 1, 'notes' => '', 'active' => 1];
    }
    $actions = (can('deliveries.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'transporteurs'])) . '">' . icon('download') . 'Exporter</a>' : '')
        . ($admin ? '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'transporteurs'])) . '">' . icon('upload') . 'Importer</a><a class="btn sm primary" href="' . e(url('deliveries', ['a' => 'carrier_edit'])) . '">' . icon('plus') . 'Nouveau transporteur</a>' : '');
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
        <thead><tr><th>Transporteur</th><th>Contact</th><th>Zones</th><th class="num">Tarif</th><th class="num">Livraisons</th><th class="num">En cours</th><th class="num">Marchandise chez lui</th><th class="num">Retours</th><th class="num">À récupérer</th><th class="num">Frais payés</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($carriersAll as $c): $s = $stats[$c['id']] ?? ['n' => 0, 'open' => 0, 'done' => 0, 'ret' => 0, 'holding' => 0, 'owed' => 0, 'fees' => 0]; ?>
          <tr class="<?= $c['active'] ? '' : 'dim' ?>">
            <td><b><?= e($c['name']) ?></b><br><small class="muted"><?= $c['type'] === 'interne' ? 'Interne' : 'Externe' ?><?= $c['cod'] ? ' · paiement à la livraison' : '' ?><?= $c['active'] ? '' : ' · inactif' ?></small></td>
            <td><?= e($c['contact']) ?><?= $c['phone'] ? '<br><a href="tel:' . e($c['phone']) . '">' . e($c['phone']) . '</a>' : '' ?></td>
            <td class="muted"><?= e($c['zones']) ?></td>
            <td class="num"><?= (int)$c['default_fee'] ? money($c['default_fee']) : '' ?></td>
            <td class="num"><a href="<?= e(url('deliveries', ['status' => 'toutes', 'carrier' => $c['id']])) ?>"><?= (int)$s['done'] ?> / <?= (int)$s['n'] ?></a></td>
            <td class="num"><?= (int)$s['open'] ?></td>
            <td class="num"><?= (int)$s['holding'] ? '<a href="' . e(url('deliveries', ['status' => 'toutes', 'carrier' => $c['id'], 'holder' => 'transporteur'])) . '"><b>' . (int)$s['holding'] . '</b></a>' : '' ?></td>
            <td class="num"><?= (int)$s['ret'] ?></td>
            <td class="num"><?= (int)$s['owed'] ? '<b class="warn-t">' . money($s['owed']) . '</b>' : '' ?></td>
            <td class="num"><?= money($s['fees']) ?></td>
            <td class="num"><?php if ($admin): ?><a class="icon-btn xs" title="Modifier" href="<?= e(url('deliveries', ['a' => 'carrier_edit', 'id' => $c['id']])) ?>"><?= icon('edit') ?></a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">« À récupérer » = montants encaissés chez les clients (paiement à la livraison) que le transporteur doit encore reverser. « Marchandise chez lui » = livraisons dont la marchandise est actuellement chez ce transporteur.</p>
    <?php
    layout_end();
    exit;
}

/* ---------- Fiche livraison ---------- */

if ($a === 'edit') {
    $id = (int)get('id');
    $sh = $id ? shipment_load($id) : null;
    if ($id && !$sh) redirect(url('deliveries'));
    $sale = $sh && $sh['sale_id'] ? one('SELECT number, total, paid FROM sales WHERE id = ?', [$sh['sale_id']]) : null;
    $items = $sh ? shipment_items((int)$sh['id']) : [];
    $events = $sh ? all('SELECT e.*, u.name uname FROM shipment_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.shipment_id = ? ORDER BY e.at DESC, e.id DESC', [$sh['id']]) : [];
    $carrierName = $sh && $sh['carrier_id'] ? (string)val('SELECT name FROM carriers WHERE id = ?', [$sh['carrier_id']]) : '';
    $sh = $sh ?: ['id' => 0, 'number' => '', 'recipient' => '', 'phone' => '', 'gouvernorat' => '', 'city' => '', 'address' => '', 'status' => 'a_preparer',
        'tracking' => '', 'fee' => 0, 'cod_amount' => 0, 'cod_collected' => 0, 'planned_date' => '', 'note' => '', 'carrier_id' => null, 'location_id' => $scope,
        'holder' => 'depart', 'holder_note' => '', 'origin_note' => '', 'technician' => '', 'technician_fee' => 0, 'client_fee' => 0, 'delivered_at' => null];
    $editable = can('deliveries.edit');
    $technicians = array_column(all("SELECT technician FROM shipments WHERE technician <> '' GROUP BY technician ORDER BY COUNT(*) DESC LIMIT 20"), 'technician');
    layout_start($sh['id'] ? 'Livraison ' . $sh['number'] : 'Nouvelle livraison', 'deliveries');
    ?>
    <p><a class="back" href="<?= e(url('deliveries')) ?>"><?= icon('left') ?>Toutes les livraisons</a></p>
    <?php if ($sh['id']):
        $stage = ['depart' => 0, 'transporteur' => 1, 'technicien' => 1, 'retour' => 1, 'client' => 2][$sh['holder']] ?? 0;
        $mid = $sh['holder'] === 'technicien' ? 'Technicien' : ($sh['holder'] === 'retour' ? 'Retour en cours' : 'Transporteur'); ?>
      <section class="card ship-card">
        <div class="ship-top">
          <div><span class="muted small">Où est la marchandise maintenant ?</span>
            <h2 class="ship-now <?= $sh['holder'] === 'retour' ? 'warn' : '' ?>"><?= icon($sh['holder'] === 'client' ? 'check' : ($sh['holder'] === 'retour' ? 'alert' : 'truck')) ?><?= e(ship_holder_label($sh, $carrierName)) ?></h2></div>
          <div class="ship-badges"><?= badge(SHIP_STATUS[$sh['status']] ?? $sh['status'], ship_tone($sh['status'])) ?><?php if ((int)$sh['cod_amount']): ?> <?= $sh['cod_collected'] ? badge('Argent récupéré', 'ok') : badge(money($sh['cod_amount']) . ' à récupérer', 'warn') ?><?php endif; ?></div>
        </div>
        <ol class="route" aria-label="Trajet">
          <li class="<?= $stage > 0 ? 'done' : 'now' ?>"><span class="r-dot"><?= icon('stock') ?></span><small>Départ</small><b><?= e(ship_origin($sh)) ?></b></li>
          <li class="<?= $stage > 1 ? 'done' : ($stage === 1 ? 'now' . ($sh['holder'] === 'retour' ? ' warn' : '') : '') ?>"><span class="r-dot"><?= icon('truck') ?></span><small><?= e($mid) ?></small>
            <b><?= e($sh['holder'] === 'technicien' && $sh['technician'] ? $sh['technician'] : ($carrierName ?: 'À définir')) ?></b><?= $sh['tracking'] ? '<small>n° ' . e($sh['tracking']) . '</small>' : '' ?></li>
          <li class="<?= $stage === 2 ? 'now done' : '' ?>"><span class="r-dot"><?= icon('user') ?></span><small>Arrivée</small><b><?= e(ship_destination($sh)) ?></b>
            <small><?= e($sh['recipient']) ?><?= $sh['phone'] ? ' · <a href="tel:' . e($sh['phone']) . '">' . e($sh['phone']) . '</a>' : '' ?></small></li>
        </ol>
        <div class="grid2 ship-info">
          <div>
            <h3>Marchandise</h3>
            <?php if ($items): ?><ul class="goods"><?php foreach ($items as $it): ?><li><b><?= (int)$it['qty'] ?> ×</b> <?= $it['product_id'] && can('products.view') ? '<a href="' . e(url('products', ['a' => 'view', 'id' => $it['product_id']])) . '">' . e($it['label']) . '</a>' : e($it['label']) ?></li><?php endforeach; ?></ul>
            <?php else: ?><p class="muted small">Aucun produit indiqué.</p><?php endif; ?>
            <dl class="kv small">
              <?php if ($sh['technician']): ?><dt>Technicien</dt><dd><?= e($sh['technician']) ?><?= (int)$sh['technician_fee'] && !$driver ? ' · prime ' . money($sh['technician_fee']) : '' ?></dd><?php endif; ?>
              <?php if ((int)$sh['client_fee']): ?><dt>Transport payé par le client</dt><dd><?= money($sh['client_fee']) ?></dd><?php endif; ?>
              <?php if ((int)$sh['fee'] && !$driver): ?><dt>Coût transporteur</dt><dd><?= money($sh['fee']) ?></dd><?php endif; ?>
              <?php if ($sh['planned_date']): ?><dt>Date prévue</dt><dd><?= fdate($sh['planned_date']) ?></dd><?php endif; ?>
              <?php if ($sh['delivered_at']): ?><dt>Livrée le</dt><dd><?= fdate($sh['delivered_at'], true) ?></dd><?php endif; ?>
            </dl>
            <p class="row-actions" style="justify-content:flex-start"><a class="btn sm" target="_blank" rel="noopener" href="<?= e(maps_url($sh)) ?>"><?= icon('truck') ?>Itinéraire</a>
              <?php if ($sh['phone']): ?><a class="btn sm" href="tel:<?= e($sh['phone']) ?>"><?= icon('user') ?>Appeler</a><a class="btn sm" target="_blank" rel="noopener" href="https://wa.me/216<?= e(normalize_phone($sh['phone'])) ?>"><?= icon('chat') ?>WhatsApp</a><?php endif; ?></p>
          </div>
          <div>
            <h3>Historique</h3>
            <?php if ($events): ?>
              <ol class="timeline"><?php foreach ($events as $ev): ?><li class="<?= e($ev['kind']) ?>"><b><?= e($ev['text']) ?></b><small><?= fdate($ev['at'], true) ?><?= $ev['uname'] ? ' · ' . e($ev['uname']) : '' ?></small></li><?php endforeach; ?></ol>
            <?php else: ?><p class="muted small">Pas encore d’historique.</p><?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($driver && $sh['id'] && in_array($sh['status'], ['a_preparer', 'en_cours', 'retour'], true)): ?>
      <section class="card driver-actions">
        <h2>Mettre à jour la livraison</h2>
        <div class="row-actions center">
          <?php foreach (DRIVER_STATUS as $st => $label): if ($st === $sh['status']) continue; ?>
            <form method="post" action="<?= e(url('deliveries', ['a' => 'status'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$sh['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>">
              <button class="btn <?= $st === 'livree' ? 'primary' : '' ?> lg-btn"><?= icon($st === 'livree' ? 'check' : ($st === 'retour' ? 'x' : 'truck')) ?><?= e($label) ?></button></form>
          <?php endforeach; ?>
        </div>
        <?php if ((int)$sh['cod_amount'] && !$sh['cod_collected']): ?><p class="hint center">À encaisser chez le client : <b><?= money($sh['cod_amount']) ?></b></p><?php endif; ?>
      </section>
    <?php endif; ?>
    <?php if (!$driver): ?>
    <form method="post" action="<?= e(url('deliveries', ['a' => 'save'])) ?>" class="card form-grid" id="ship-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$sh['id'] ?>">
      <h2 class="span2"><?= $sh['id'] ? 'Modifier la livraison' : 'Nouvelle livraison' ?></h2>
      <label>Vente liée (n°)<input name="sale_number" value="<?= e($sale['number'] ?? '') ?>" placeholder="ex. TUN-26-00012"><?php if ($sale): ?><small><a href="<?= e(url('sales', ['a' => 'view', 'id' => $sh['sale_id']])) ?>">Voir la vente</a> · total <?= money($sale['total']) ?>, payé <?= money($sale['paid']) ?></small><?php else: ?><small>La marchandise de la vente est reprise automatiquement.</small><?php endif; ?></label>
      <label>Destinataire<input name="recipient" value="<?= e($sh['recipient']) ?>" required></label>

      <h3 class="span2">Trajet</h3>
      <?php if (!$scope): ?><label>Départ (de)<select name="location_id"><?= opt(location_options(), $sh['location_id'], true, 'Autre (préciser)') ?></select></label><?php endif; ?>
      <label>Précision sur le départ<input name="origin_note" value="<?= e($sh['origin_note']) ?>" placeholder="Fournisseur, dépôt de Mégrine…"></label>
      <label>Gouvernorat (à)<select name="gouvernorat" data-gouv><?= opt(array_combine(GOUVERNORATS, GOUVERNORATS), $sh['gouvernorat'], true, 'Deviner d’après l’adresse') ?></select></label>
      <label>Ville / délégation<input name="city" value="<?= e($sh['city']) ?>"></label>
      <label class="span2">Adresse<input name="address" value="<?= e($sh['address']) ?>" data-addr></label>
      <label>Téléphone<input name="phone" value="<?= e($sh['phone']) ?>" inputmode="tel"></label>
      <label>Date prévue<input type="date" name="planned_date" value="<?= e($sh['planned_date']) ?>"></label>

      <h3 class="span2">Marchandise</h3>
      <div class="span2 goods-edit" data-goods>
        <input type="hidden" name="items_sent" value="1">
        <?php foreach (array_merge($items, [['label' => '', 'qty' => 1]]) as $i => $it): ?>
          <div class="goods-row"><input name="items[<?= $i ?>][label]" value="<?= e($it['label']) ?>" list="dl-products" placeholder="Produit (ex. BT 200+)" aria-label="Produit"><input name="items[<?= $i ?>][qty]" type="number" min="1" max="999" value="<?= (int)$it['qty'] ?>" aria-label="Quantité"><button type="button" class="icon-btn xs" data-goods-del aria-label="Retirer"><?= icon('x') ?></button></div>
        <?php endforeach; ?>
        <button type="button" class="btn sm ghost" data-goods-add><?= icon('plus') ?>Ajouter un produit</button>
        <datalist id="dl-products"><?php foreach (all('SELECT name FROM products WHERE active = 1 ORDER BY name') as $p) echo '<option value="' . e($p['name']) . '">'; ?></datalist>
      </div>

      <h3 class="span2">Où est la marchandise maintenant ?</h3>
      <label>Statut<select name="status" data-status><?= opt(SHIP_STATUS, $sh['status']) ?></select></label>
      <label>La marchandise est<select name="holder" data-holder><?= opt(SHIP_HOLDERS, $sh['holder']) ?></select><small>Se met à jour avec le statut ; modifiable.</small></label>
      <label class="span2">Précision<input name="holder_note" value="<?= e($sh['holder_note']) ?>" placeholder="ex. au dépôt TPS de Gafsa, chez Omar pour réglage…"></label>

      <h3 class="span2">Intervenants & argent</h3>
      <label>Transporteur<select name="carrier_id"><?= opt($carrierOpts, $sh['carrier_id'], true, 'À définir') ?></select></label>
      <label>N° de suivi<input name="tracking" value="<?= e($sh['tracking']) ?>"></label>
      <label>Technicien / installateur<input name="technician" value="<?= e($sh['technician']) ?>" list="dl-techs"><datalist id="dl-techs"><?php foreach ($technicians as $t) echo '<option value="' . e($t) . '">'; ?></datalist></label>
      <label>Prime du technicien (DT)<input name="technician_fee" inputmode="decimal" value="<?= e(m_in($sh['technician_fee'])) ?>"></label>
      <label>Transport payé par le client (DT)<input name="client_fee" inputmode="decimal" value="<?= e(m_in($sh['client_fee'])) ?>"></label>
      <label>Coût transporteur (DT)<input name="fee" inputmode="decimal" value="<?= e(m_in($sh['fee'])) ?>"></label>
      <label>À encaisser à la livraison (DT)<input name="cod_amount" inputmode="decimal" value="<?= e(m_in($sh['cod_amount'])) ?>" <?= $sh['cod_collected'] ? 'readonly' : '' ?>></label>
      <?php if (!$sh['cod_collected']): ?>
        <label class="check"><input type="checkbox" name="cod_collected" value="1"> Montant récupéré auprès du transporteur (enregistre le paiement sur la vente)</label>
      <?php else: ?><p><?= badge('Montant encaissé', 'ok') ?></p><?php endif; ?>
      <label class="span2">Note<textarea name="note" rows="2"><?= e($sh['note']) ?></textarea></label>
      <?php if ($editable): ?><div class="span2 row-actions"><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div><?php endif; ?>
    </form>
    <script>
    (() => {
      const f = document.getElementById('ship-form');
      if (!f) return;
      const map = { a_preparer: 'depart', en_cours: 'transporteur', livree: 'client', retour: 'retour', annulee: 'depart' };
      const st = f.querySelector('[data-status]'), ho = f.querySelector('[data-holder]');
      st.addEventListener('change', () => { if (map[st.value]) ho.value = map[st.value]; });
      const box = f.querySelector('[data-goods]');
      let n = box.querySelectorAll('.goods-row').length;
      box.addEventListener('click', (ev) => {
        const del = ev.target.closest('[data-goods-del]');
        if (del) { const row = del.closest('.goods-row'); if (box.querySelectorAll('.goods-row').length > 1) row.remove(); else row.querySelector('input').value = ''; }
        if (ev.target.closest('[data-goods-add]')) {
          const row = box.querySelector('.goods-row').cloneNode(true);
          row.querySelectorAll('input').forEach((i) => { i.name = i.name.replace(/\[\d+\]/, '[' + n + ']'); i.value = i.type === 'number' ? 1 : ''; });
          n++;
          ev.target.closest('[data-goods-add]').before(row);
          row.querySelector('input').focus();
        }
      });
      <?php if (!$editable): ?>f.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = true; });<?php endif; ?>
    })();
    </script>
    <?php endif; ?>
    <?php
    layout_end();
    exit;
}

/* ---------- Liste ---------- */

$f = ['status' => (string)get('status', 'ouvertes'), 'carrier' => (int)get('carrier'), 'loc' => $scope ?? (int)get('loc'), 'q' => (string)get('q'),
    'holder' => isset(SHIP_HOLDERS[(string)get('holder')]) ? (string)get('holder') : ''];
$where = ['1=1'];
$p = [];
if ($f['status'] === 'ouvertes') $where[] = "sh.status IN ('a_preparer','en_cours')";
elseif ($f['status'] === 'a_recuperer') $where[] = "sh.status = 'livree' AND sh.cod_collected = 0 AND sh.cod_amount > 0";
elseif ($f['status'] !== '' && $f['status'] !== 'toutes') { $where[] = 'sh.status = ?'; $p[] = $f['status']; }
if ($f['carrier']) { $where[] = 'sh.carrier_id = ?'; $p[] = $f['carrier']; }
if ($f['loc']) { $where[] = 'sh.location_id = ?'; $p[] = $f['loc']; }
if ($f['holder'] !== '') { $where[] = 'sh.holder = ?'; $p[] = $f['holder']; }
if ($f['q'] !== '') {
    $where[] = '(sh.number LIKE ? OR sh.recipient LIKE ? OR sh.phone LIKE ? OR sh.city LIKE ? OR sh.address LIKE ? OR sh.gouvernorat LIKE ? OR sh.tracking LIKE ? OR sh.technician LIKE ?
        OR EXISTS (SELECT 1 FROM shipment_items si WHERE si.shipment_id = sh.id AND si.label LIKE ?))';
    array_push($p, ...array_fill(0, 9, "%{$f['q']}%"));
}
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM shipments sh WHERE $w", $p);
$page = max(1, (int)get('page', 1));
$rows = all("SELECT sh.*, ca.name carrier, s.number sale_number FROM shipments sh LEFT JOIN carriers ca ON ca.id = sh.carrier_id
    LEFT JOIN sales s ON s.id = sh.sale_id WHERE $w ORDER BY CASE sh.status WHEN 'a_preparer' THEN 0 WHEN 'en_cours' THEN 1 ELSE 2 END,
    COALESCE(sh.planned_date, '9999') " . (in_array($f['status'], ['ouvertes', 'a_preparer', 'en_cours'], true) ? 'ASC' : 'DESC') . ", sh.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $p);
$itemsBy = [];
if ($rows) {
    foreach (all('SELECT * FROM shipment_items WHERE shipment_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ') ORDER BY id') as $it) $itemsBy[$it['shipment_id']][] = $it;
}
$scopeWhere = $scope ? 'WHERE location_id = ' . (int)$scope : '';
$counts = [];
foreach (all("SELECT status, COUNT(*) n FROM shipments $scopeWhere GROUP BY status") as $c) $counts[$c['status']] = (int)$c['n'];
$owed = (int)val("SELECT COUNT(*) FROM shipments WHERE status = 'livree' AND cod_collected = 0 AND cod_amount > 0" . ($scope ? ' AND location_id = ' . (int)$scope : ''));

$actions = (can('deliveries.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'livraisons'])) . '">' . icon('download') . 'Exporter</a>' : '')
    . (can('deliveries.import') ? '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'livraisons'])) . '">' . icon('upload') . 'Importer</a>' : '')
    . (can('deliveries.edit') ? '<a class="btn sm primary" href="' . e(url('deliveries', ['a' => 'edit'])) . '">' . icon('plus') . 'Nouvelle livraison</a>' : '');
layout_start('Livraisons & transporteurs', 'deliveries', ['actions' => $actions]);
echo $tabsHtml;
$chips = ['ouvertes' => 'À faire (' . (($counts['a_preparer'] ?? 0) + ($counts['en_cours'] ?? 0)) . ')'];
foreach (SHIP_STATUS as $k => $label) $chips[$k] = $label . ' (' . ($counts[$k] ?? 0) . ')';
if (!$driver) $chips['a_recuperer'] = 'Argent à récupérer (' . $owed . ')';
$chips['toutes'] = 'Toutes';
?>
<div class="chips">
  <?php foreach ($chips as $k => $label): ?>
    <a class="chip <?= $f['status'] === $k ? 'on' : '' ?>" href="<?= e(url('deliveries', array_filter(['status' => $k, 'carrier' => $f['carrier'] ?: null, 'q' => $f['q'], 'holder' => $f['holder']]))) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<form class="filters" method="get">
  <input type="hidden" name="r" value="deliveries"><input type="hidden" name="status" value="<?= e($f['status']) ?>">
  <label class="grow">Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="N°, client, téléphone, ville, produit, suivi, technicien"></label>
  <label>Transporteur<select name="carrier"><?= opt($carrierOpts, $f['carrier'], true, 'Tous') ?></select></label>
  <label>Marchandise<select name="holder"><?= opt(SHIP_HOLDERS, $f['holder'], true, 'Partout') ?></select></label>
  <?php if (!$scope): ?><label>Départ<select name="loc"><?= opt(location_options(), $f['loc'], true, 'Tous') ?></select></label><?php endif; ?>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>
<div class="card flush">
  <table class="tbl stack">
    <thead><tr><th>N°</th><th>Date</th><th>Destinataire</th><th>Trajet</th><th>Marchandise · où est-elle ?</th><th>Transporteur</th><th class="num">À encaisser</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $its = $itemsBy[$r['id']] ?? []; ?>
      <tr>
        <td class="nowrap"><a href="<?= e(url('deliveries', ['a' => 'edit', 'id' => $r['id']])) ?>"><b><?= e($r['number']) ?></b></a><?= $r['sale_number'] && can('sales.view') ? '<br><small><a class="muted" href="' . e(url('sales', ['a' => 'view', 'id' => $r['sale_id']])) . '">' . e($r['sale_number']) . '</a></small>' : '' ?></td>
        <td data-th="Date"><?= $r['planned_date'] ? fdate($r['planned_date']) : '<span class="muted">—</span>' ?><?= $r['delivered_at'] && substr($r['delivered_at'], 0, 10) !== $r['planned_date'] ? '<br><small class="muted">livrée ' . fdate($r['delivered_at']) . '</small>' : '' ?></td>
        <td data-th="Client"><?= e($r['recipient']) ?><?= $r['phone'] ? '<br><a href="tel:' . e($r['phone']) . '"><small>' . e($r['phone']) . '</small></a>' : '' ?></td>
        <td data-th="Trajet"><small class="muted"><?= e(ship_origin($r)) ?> →</small><br><b class="fw5"><?= e(ship_destination($r)) ?></b><br><a class="small" target="_blank" rel="noopener" href="<?= e(maps_url($r)) ?>">Itinéraire</a></td>
        <td data-th="Marchandise"><?= $its ? e(shipment_items_text($its)) : '<span class="muted">—</span>' ?><br><?= badge(ship_holder_label($r, $r['carrier']), ['depart' => '', 'transporteur' => 'info', 'technicien' => 'info', 'client' => 'ok', 'retour' => 'warn'][$r['holder']] ?? '') ?></td>
        <td data-th="Transporteur"><?= e($r['carrier'] ?: '—') ?><?= $r['tracking'] ? '<br><small class="muted">' . e($r['tracking']) . '</small>' : '' ?><?= $r['technician'] ? '<br><small class="muted">Tech. ' . e($r['technician']) . '</small>' : '' ?></td>
        <td class="num" data-th="À encaisser"><?php if ((int)$r['cod_amount']): ?><?= money($r['cod_amount']) ?><br><?= $r['cod_collected'] ? badge('encaissé', 'ok') : ($r['status'] === 'livree' ? badge('à récupérer', 'warn') : '') ?><?php endif; ?></td>
        <td data-th="Statut">
          <?php if ((can('deliveries.edit') || $driver) && in_array($r['status'], ['a_preparer', 'en_cours', 'retour'], true)): ?>
            <form method="post" action="<?= e(url('deliveries', ['a' => 'status'])) ?>" class="status-form">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status" onchange="this.form.submit()" class="st-<?= e($r['status']) ?>" aria-label="Statut"><?= opt($driver ? [$r['status'] => SHIP_STATUS[$r['status']]] + DRIVER_STATUS : SHIP_STATUS, $r['status']) ?></select>
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
    <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Aucune livraison.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager($total, $page, 50, array_filter($f), 'deliveries') ?>
<?php
layout_end();
