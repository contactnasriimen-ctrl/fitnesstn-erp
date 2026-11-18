<?php
declare(strict_types=1);

// Réparations & service après-vente : demandes des clients, panne, réparateur, statut, montant.
require_perm('repairs.view');
$a = (string)get('a', 'list');
$scope = scope_loc();
$canEdit = can('repairs.edit');

// Client déjà connu (saisie du téléphone dans la fiche) : coordonnées + historique
if ($a === 'client') {
    $phone = normalize_phone((string)get('phone'));
    $c = strlen($phone) >= 8 ? one('SELECT id, name, phone, phone2, address, gouvernorat FROM clients WHERE phone = ? OR (phone2 = ? AND phone2 <> \'\')', [$phone, $phone]) : null;
    if (!$c) json_out(['ok' => true, 'client' => null]);
    $sales = all("SELECT s.number, date(s.created_at) d, GROUP_CONCAT(si.label, ' + ') items FROM sales s JOIN sale_items si ON si.sale_id = s.id
        WHERE s.client_id = ? AND s.status <> 'annulee' GROUP BY s.id ORDER BY s.created_at DESC LIMIT 3", [$c['id']]);
    $reps = (int)val('SELECT COUNT(*) FROM repairs WHERE client_id = ?', [$c['id']]);
    json_out(['ok' => true, 'client' => $c, 'sales' => $sales, 'repairs' => $reps]);
}

if (is_post()) {
    csrf_check();
    try {
        if (!$canEdit) throw new RuntimeException('Action non autorisée.');
        if ($a === 'status') {
            $r = repair_load((int)post('id'));
            if (!$r) throw new RuntimeException('Fiche introuvable.');
            $status = (string)post('status');
            if (!isset(REPAIR_STATUS[$status])) throw new RuntimeException('Statut invalide.');
            repair_save(['status' => $status] + $r, $r);
            flash('ok', $r['number'] . ' : ' . REPAIR_STATUS[$status] . '.');
            back(url('repairs'));
        }
        if ($a === 'save') {
            $id = (int)post('id');
            $old = $id ? repair_load($id) : null;
            if ($id && !$old) throw new RuntimeException('Fiche introuvable.');
            $productName = (string)post('product');
            $pid = $productName !== '' ? val('SELECT id FROM products WHERE name = ? COLLATE NOCASE LIMIT 1', [$productName]) : null;
            $in = [
                'client_name' => (string)post('client_name'), 'phone' => (string)post('phone'), 'phone2' => (string)post('phone2'),
                'address' => (string)post('address'), 'gouvernorat' => (string)post('gouvernorat'), 'product' => $productName, 'product_id' => $pid ?: null,
                'issue' => (string)post('issue'), 'kind' => (string)post('kind'), 'technician' => (string)post('technician'), 'status' => (string)post('status'),
                'place' => (string)post('place'), 'amount' => max(0, parse_money(post('amount')) ?? 0), 'paid' => max(0, parse_money(post('paid')) ?? 0),
                'appointment' => (string)post('appointment'), 'received_at' => (string)post('received_at'), 'note' => (string)post('note'),
                'location_id' => $scope ?: ((int)post('location_id') ?: ($old['location_id'] ?? null)), 'source' => 'autre',
            ];
            if (post('paid_all')) $in['paid'] = $in['amount'];
            $id = repair_save($in, $old);
            flash('ok', 'Fiche enregistrée.');
            redirect(url('repairs', ['a' => 'edit', 'id' => $id]));
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        back(url('repairs'));
    }
}

$techs = array_column(all("SELECT technician FROM repairs WHERE technician <> '' GROUP BY technician ORDER BY COUNT(*) DESC LIMIT 20"), 'technician');

/* ---------- Fiche ---------- */

if ($a === 'edit') {
    $id = (int)get('id');
    $r = $id ? repair_load($id) : null;
    if ($id && !$r) redirect(url('repairs'));
    $r = $r ?: ['id' => 0, 'number' => '', 'kind' => 'reparation', 'client_id' => null, 'client_name' => '', 'phone' => '', 'phone2' => '', 'gouvernorat' => '', 'address' => '',
        'product' => '', 'issue' => '', 'technician' => $techs[0] ?? '', 'status' => 'nouveau', 'place' => 'domicile', 'amount' => 0, 'paid' => 0,
        'appointment' => null, 'received_at' => today(), 'done_at' => null, 'note' => '', 'location_id' => $scope, 'created_at' => null];
    $history = $r['client_id'] ? all('SELECT id, number, product, issue, status, created_at FROM repairs WHERE client_id = ? AND id <> ? ORDER BY id DESC LIMIT 6', [$r['client_id'], $r['id']]) : [];
    $wa = $r['id'] ? repair_whatsapp($r) : '';
    layout_start($r['id'] ? 'Réparation ' . $r['number'] : 'Nouvelle réparation', 'repairs');
    ?>
    <p><a class="back" href="<?= e(url('repairs')) ?>"><?= icon('left') ?>Toutes les réparations</a></p>
    <?php if ($r['id']): ?>
      <section class="card ship-card">
        <div class="ship-top">
          <div><span class="muted small"><?= e(REPAIR_KINDS[$r['kind']] ?? '') ?> · <?= e(REPAIR_PLACES[$r['place']] ?? '') ?></span>
            <h2 class="ship-now"><?= icon('tool') ?><?= e($r['product'] ?: 'Machine non précisée') ?><?= $r['issue'] ? ' — ' . e($r['issue']) : '' ?></h2></div>
          <div class="ship-badges"><?= badge(REPAIR_STATUS[$r['status']] ?? $r['status'], repair_tone($r['status'])) ?>
            <?php if ((int)$r['amount']): ?> <?= (int)$r['paid'] >= (int)$r['amount'] ? badge(money($r['amount']) . ' payé', 'ok') : badge(money((int)$r['amount'] - (int)$r['paid']) . ' à encaisser', 'warn') ?><?php endif; ?></div>
        </div>
        <p class="row-actions" style="justify-content:flex-start">
          <?php if ($r['phone']): ?><a class="btn sm" href="tel:<?= e($r['phone']) ?>"><?= icon('user') ?>Appeler <?= e($r['phone']) ?></a><?php endif; ?>
          <?php if ($wa): ?><a class="btn sm" target="_blank" rel="noopener" href="<?= e($wa) ?>"><?= icon('chat') ?>WhatsApp au client</a><?php endif; ?>
          <?php if ($r['address']): ?><a class="btn sm" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=<?= e(rawurlencode($r['address'] . ', ' . $r['gouvernorat'] . ', Tunisie')) ?>"><?= icon('truck') ?>Itinéraire</a><?php endif; ?>
          <?php if ($r['client_id'] && can('clients.view')): ?><a class="btn sm ghost" href="<?= e(url('clients', ['a' => 'view', 'id' => $r['client_id']])) ?>"><?= icon('users') ?>Fiche client</a><?php endif; ?>
        </p>
        <?php if ($history): ?><p class="small muted"><b>Autres demandes de ce client :</b> <?php foreach ($history as $h) echo '<a href="' . e(url('repairs', ['a' => 'edit', 'id' => $h['id']])) . '">' . e($h['number']) . '</a> (' . e($h['product'] ?: $h['issue']) . ', ' . e(mb_strtolower(REPAIR_STATUS[$h['status']] ?? '')) . ') '; ?></p><?php endif; ?>
      </section>
    <?php endif; ?>
    <form method="post" action="<?= e(url('repairs', ['a' => 'save'])) ?>" class="card form-grid" id="rep-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <h2 class="span2"><?= $r['id'] ? 'Modifier la fiche' : 'Nouvelle demande de réparation' ?></h2>
      <label>Téléphone<input name="phone" value="<?= e($r['phone']) ?>" inputmode="tel" data-phone autocomplete="off"><small data-client-hint></small></label>
      <label>Nom du client<input name="client_name" value="<?= e($r['client_name']) ?>" data-name></label>
      <label>Autre téléphone<input name="phone2" value="<?= e($r['phone2']) ?>" inputmode="tel" data-phone2></label>
      <label>Gouvernorat<select name="gouvernorat" data-gouv><?= opt(array_combine(GOUVERNORATS, GOUVERNORATS), $r['gouvernorat'], true, 'Deviner d’après l’adresse') ?></select></label>
      <label class="span2">Adresse<input name="address" value="<?= e($r['address']) ?>" data-addr></label>
      <label>Machine<input name="product" value="<?= e($r['product']) ?>" list="dl-products" placeholder="ex. Tapis roulant BT 360+"><datalist id="dl-products"><?php foreach (all('SELECT name FROM products WHERE active = 1 ORDER BY name') as $p) echo '<option value="' . e($p['name']) . '">'; ?></datalist></label>
      <label>Type<select name="kind"><?= opt(REPAIR_KINDS, $r['kind']) ?></select></label>
      <label class="span2">Panne décrite par le client<textarea name="issue" rows="2"><?= e($r['issue']) ?></textarea></label>
      <label>Réparateur<input name="technician" value="<?= e($r['technician']) ?>" list="dl-techs"><datalist id="dl-techs"><?php foreach ($techs as $t) echo '<option value="' . e($t) . '">'; ?></datalist></label>
      <label>Statut<select name="status"><?= opt(REPAIR_STATUS, $r['status']) ?></select></label>
      <label>Où se fait la réparation<select name="place"><?= opt(REPAIR_PLACES, $r['place']) ?></select></label>
      <label>Rendez-vous<input type="datetime-local" name="appointment" value="<?= e($r['appointment'] ? str_replace(' ', 'T', substr($r['appointment'], 0, 16)) : '') ?>"></label>
      <label>Date de la demande<input type="date" name="received_at" value="<?= e($r['received_at']) ?>"></label>
      <?php if (!$scope): ?><label>Showroom<select name="location_id"><?= opt(location_options(true, 'showroom'), $r['location_id'], true, '—') ?></select></label><?php endif; ?>
      <label>Montant (diagnostic, déplacement, réparation) DT<input name="amount" inputmode="decimal" value="<?= e(m_in($r['amount'])) ?>"></label>
      <label>Déjà payé (DT)<input name="paid" inputmode="decimal" value="<?= e(m_in($r['paid'])) ?>"></label>
      <label class="check span2"><input type="checkbox" name="paid_all" value="1"> Le client a tout payé</label>
      <label class="span2">Note interne<textarea name="note" rows="2"><?= e($r['note']) ?></textarea></label>
      <?php if ($canEdit): ?><div class="span2 row-actions"><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div><?php endif; ?>
    </form>
    <script>
    (() => {
      const f = document.getElementById('rep-form');
      <?php if (!$canEdit): ?>f.querySelectorAll('input, select, textarea, button').forEach((el) => { el.disabled = true; }); return;<?php endif; ?>
      const ph = f.querySelector('[data-phone]'), hint = f.querySelector('[data-client-hint]');
      let last = '';
      const look = async () => {
        const d = ph.value.replace(/\D+/g, '').replace(/^216/, '');
        if (d.length < 8 || d === last) return;
        last = d;
        try {
          const r = await fetch(<?= json_encode(url('repairs', ['a' => 'client'])) ?> + '&phone=' + d, { headers: { Accept: 'application/json' } }).then((x) => x.json());
          if (!r.client) { hint.textContent = 'Nouveau client.'; return; }
          const c = r.client;
          const set = (sel, v) => { const el = f.querySelector(sel); if (el && !el.value && v) el.value = v; };
          set('[data-name]', c.name.startsWith('Client ') ? '' : c.name); set('[data-addr]', c.address); set('[data-phone2]', c.phone2 !== d ? c.phone2 : '');
          if (c.gouvernorat) { const g = f.querySelector('[data-gouv]'); if (!g.value) g.value = c.gouvernorat; }
          const bought = (r.sales || []).map((s) => s.items + ' (' + s.d.split('-').reverse().join('/') + ')').join(', ');
          hint.textContent = 'Client connu : ' + c.name + (bought ? ' · a acheté ' + bought : '') + (r.repairs ? ' · ' + r.repairs + ' réparation(s)' : '');
          if (bought && !f.querySelector('[name=product]').value) f.querySelector('[name=product]').placeholder = (r.sales[0].items || '').split(' + ')[0];
        } catch (e) { /* hors ligne : rien */ }
      };
      ph.addEventListener('change', look);
      ph.addEventListener('keyup', () => { if (ph.value.replace(/\D+/g, '').length >= 8) look(); });
    })();
    </script>
    <?php
    layout_end();
    exit;
}

/* ---------- Liste ---------- */

$f = ['status' => (string)get('status', 'ouvertes'), 'q' => (string)get('q'), 'tech' => (string)get('tech'), 'kind' => (string)get('kind')];
$where = ['1=1'];
$p = [];
if ($scope) $where[] = 'r.location_id = ' . (int)$scope;
if ($f['status'] === 'ouvertes') $where[] = "r.status IN ('nouveau','en_cours')";
elseif ($f['status'] === 'a_encaisser') $where[] = "r.status = 'repare' AND r.paid < r.amount";
elseif (isset(REPAIR_STATUS[$f['status']])) { $where[] = 'r.status = ?'; $p[] = $f['status']; }
if ($f['tech'] !== '') { $where[] = 'r.technician = ? COLLATE NOCASE'; $p[] = $f['tech']; }
if (isset(REPAIR_KINDS[$f['kind']])) { $where[] = 'r.kind = ?'; $p[] = $f['kind']; }
if ($f['q'] !== '') {
    $digits = normalize_phone($f['q']);
    $where[] = '(r.number LIKE ? OR r.client_name LIKE ? OR r.product LIKE ? OR r.issue LIKE ? OR r.address LIKE ? OR r.gouvernorat LIKE ?' . ($digits !== '' ? ' OR r.phone LIKE ? OR r.phone2 LIKE ?' : '') . ')';
    array_push($p, ...array_fill(0, 6, "%{$f['q']}%"));
    if ($digits !== '') array_push($p, "%$digits%", "%$digits%");
}
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM repairs r WHERE $w", $p);
$page = max(1, (int)get('page', 1));
$rows = all("SELECT r.* FROM repairs r WHERE $w ORDER BY CASE r.status WHEN 'en_cours' THEN 0 WHEN 'nouveau' THEN 1 ELSE 2 END, COALESCE(r.appointment, '9999'), r.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $p);
$sw = $scope ? 'WHERE location_id = ' . (int)$scope : '';
$counts = [];
foreach (all("SELECT status, COUNT(*) n FROM repairs $sw GROUP BY status") as $c) $counts[$c['status']] = (int)$c['n'];
$money = one("SELECT COALESCE(SUM(paid),0) paid, COALESCE(SUM(CASE WHEN status = 'repare' AND paid < amount THEN amount - paid END),0) owed,
    SUM(CASE WHEN status = 'repare' AND paid < amount THEN 1 ELSE 0 END) owed_n FROM repairs $sw");
$byTech = all("SELECT technician, COUNT(*) n, SUM(status IN ('nouveau','en_cours')) open FROM repairs " . ($sw ?: 'WHERE 1') . " AND technician <> '' GROUP BY technician ORDER BY open DESC, n DESC LIMIT 4");

$actions = (can('repairs.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'reparations'])) . '">' . icon('download') . 'Export</a>' : '')
    . (is_admin() ? '<a class="btn sm ghost" href="' . e(url('io')) . '#excel">' . icon('upload') . 'Import Excel</a>' : '')
    . ($canEdit ? '<a class="btn sm primary" href="' . e(url('repairs', ['a' => 'edit'])) . '">' . icon('plus') . 'Nouvelle réparation</a>' : '');
layout_start('Réparations & SAV', 'repairs', ['actions' => $actions]);
?>
<div class="grid4 kpis">
  <div class="kpi with-ic"><span class="kpi-ic info"><?= icon('tool') ?></span><span>Nouvelles demandes</span><b><?= $counts['nouveau'] ?? 0 ?></b><small>à planifier</small></div>
  <div class="kpi with-ic"><span class="kpi-ic warn"><?= icon('tasks') ?></span><span>En cours</span><b><?= $counts['en_cours'] ?? 0 ?></b>
    <small><?= e(implode(' · ', array_map(fn($t) => $t['technician'] . ' ' . (int)$t['open'], array_filter($byTech, fn($t) => (int)$t['open'] > 0)))) ?: 'chez les réparateurs' ?></small></div>
  <div class="kpi with-ic"><span class="kpi-ic ok"><?= icon('check') ?></span><span>Réparées</span><b><?= $counts['repare'] ?? 0 ?></b><small><?= $counts['annule'] ?? 0 ?> annulée(s)</small></div>
  <div class="kpi with-ic"><span class="kpi-ic"><?= icon('cash') ?></span><span>Encaissé</span><b><?= money0($money['paid']) ?></b><small><?= (int)$money['owed_n'] ? money0($money['owed']) . ' à encaisser (' . (int)$money['owed_n'] . ')' : 'rien à encaisser' ?></small></div>
</div>
<div class="chips">
  <?php
  $chips = ['ouvertes' => 'À traiter (' . (($counts['nouveau'] ?? 0) + ($counts['en_cours'] ?? 0)) . ')'];
  foreach (REPAIR_STATUS as $k => $l) $chips[$k] = $l . ' (' . ($counts[$k] ?? 0) . ')';
  $chips['a_encaisser'] = 'À encaisser (' . (int)$money['owed_n'] . ')';
  $chips['toutes'] = 'Toutes';
  foreach ($chips as $k => $label): ?>
    <a class="chip <?= $f['status'] === $k ? 'on' : '' ?>" href="<?= e(url('repairs', array_filter(['status' => $k, 'q' => $f['q'], 'tech' => $f['tech'], 'kind' => $f['kind']]))) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<form class="filters" method="get">
  <input type="hidden" name="r" value="repairs"><input type="hidden" name="status" value="<?= e($f['status']) ?>">
  <label class="grow">Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="Client, téléphone, machine, panne, adresse, n°"></label>
  <label>Réparateur<select name="tech"><?= opt(array_combine($techs, $techs) ?: [], $f['tech'], true, 'Tous') ?></select></label>
  <label>Type<select name="kind"><?= opt(REPAIR_KINDS, $f['kind'], true, 'Tous') ?></select></label>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>
<div class="card flush">
  <table class="tbl stack">
    <thead><tr><th>N°</th><th>Client</th><th>Machine · panne</th><th>Lieu</th><th>Réparateur</th><th class="num">Montant</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $wa = repair_whatsapp($r); ?>
      <tr>
        <td class="nowrap"><a href="<?= e(url('repairs', ['a' => 'edit', 'id' => $r['id']])) ?>"><b><?= e($r['number']) ?></b></a><br><small class="muted"><?= $r['kind'] === 'sav' ? 'SAV garantie' : 'Réparation' ?><?= $r['received_at'] ? ' · ' . fdate($r['received_at']) : '' ?></small></td>
        <td data-th="Client"><?= e($r['client_name'] ?: '—') ?><?= $r['phone'] ? '<br><a href="tel:' . e($r['phone']) . '"><small>' . e($r['phone']) . '</small></a>' : '' ?></td>
        <td data-th="Machine"><b class="fw5"><?= e($r['product'] ?: '—') ?></b><?= $r['issue'] ? '<br><small class="muted">' . e(mb_strimwidth($r['issue'], 0, 90, '…')) . '</small>' : '' ?></td>
        <td data-th="Lieu"><?= e($r['gouvernorat'] ?: '') ?><?= $r['address'] ? '<br><small class="muted">' . e(mb_strimwidth($r['address'], 0, 50, '…')) . '</small>' : '' ?><?= $r['appointment'] ? '<br>' . badge('RDV ' . fdate($r['appointment'], true), 'info') : '' ?></td>
        <td data-th="Réparateur"><?= e($r['technician'] ?: '—') ?><br><small class="muted"><?= e(REPAIR_PLACES[$r['place']] ?? '') ?></small></td>
        <td class="num" data-th="Montant"><?php if ((int)$r['amount']): ?><?= money($r['amount']) ?><br><?= (int)$r['paid'] >= (int)$r['amount'] ? badge('payé', 'ok') : badge('à encaisser', 'warn') ?><?php endif; ?></td>
        <td data-th="Statut">
          <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('repairs', ['a' => 'status'])) ?>" class="status-form">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status" onchange="this.form.submit()" aria-label="Statut"><?= opt(REPAIR_STATUS, $r['status']) ?></select>
            </form>
          <?php else: ?><?= badge(REPAIR_STATUS[$r['status']] ?? $r['status'], repair_tone($r['status'])) ?><?php endif; ?>
        </td>
        <td class="num nowrap"><?php if ($wa): ?><a class="icon-btn xs" title="WhatsApp au client" target="_blank" rel="noopener" href="<?= e($wa) ?>"><?= icon('chat') ?></a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty-row">Aucune réparation<?= $f['status'] === 'ouvertes' ? ' à traiter' : '' ?>.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager($total, $page, 50, array_filter($f), 'repairs') ?>
<?php
layout_end();
