<?php
declare(strict_types=1);

require_perm('sales.view');
$a = (string)get('a', 'list');
$scope = scope_loc();

if (is_post()) {
    csrf_check();
    $id = (int)post('id');
    try {
        if ($a === 'pay') {
            require_perm('sales.pay');
            $amount = parse_money(post('amount'));
            sale_add_payment($id, (string)post('method'), (int)$amount, (string)post('reference'));
            flash('ok', 'Paiement enregistré.');
        } elseif ($a === 'cancel') {
            if (!is_admin()) throw new RuntimeException('Seul l’administrateur peut annuler une vente.');
            sale_cancel($id, (string)post('reason'));
            flash('ok', 'Vente annulée : stock remis en place et remboursement tracé.');
        } elseif ($a === 'note') {
            $s = one('SELECT * FROM sales WHERE id = ?', [$id]);
            if (!$s || !can_loc((int)$s['location_id'])) throw new RuntimeException('Vente introuvable.');
            update('sales', ['note' => mb_substr((string)post('note'), 0, 500)], 'id = ?', [$id]);
            flash('ok', 'Note enregistrée.');
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    }
    redirect(url('sales', ['a' => 'view', 'id' => $id]));
}

/* ---------- Détail ---------- */

if ($a === 'view') {
    $s = one('SELECT s.*, u.name AS seller, c.name AS client_name, c.phone AS client_phone, cu.name AS canceller, cp.name AS campaign
        FROM sales s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN clients c ON c.id = s.client_id
        LEFT JOIN users cu ON cu.id = s.cancelled_by LEFT JOIN campaigns cp ON cp.id = s.campaign_id WHERE s.id = ?', [(int)get('id')]);
    if (!$s || !can_loc((int)$s['location_id'])) {
        flash('err', 'Vente introuvable.');
        redirect(url('sales'));
    }
    $items = sale_items((int)$s['id']);
    $pays = all('SELECT p.*, u.name AS user_name FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.sale_id = ? ORDER BY p.id', [$s['id']]);
    $ships = all('SELECT sh.*, ca.name AS carrier FROM shipments sh LEFT JOIN carriers ca ON ca.id = sh.carrier_id WHERE sh.sale_id = ?', [$s['id']]);
    $rest = (int)$s['total'] - (int)$s['paid'];
    $statusTone = ['payee' => 'ok', 'partielle' => 'warn', 'annulee' => 'err'][$s['status']] ?? '';
    $statusLabel = ['payee' => 'Payée', 'partielle' => 'Reste à payer', 'annulee' => 'Annulée'][$s['status']] ?? $s['status'];
    $actions = '<a class="btn sm" target="_blank" href="' . e(url('ticket', ['id' => $s['id'], 'print' => 1])) . '">' . icon('print') . 'Ticket</a>'
        . '<a class="btn sm" target="_blank" href="' . e(url('ticket', ['id' => $s['id'], 'format' => 'a4'])) . '">' . icon('receipt') . 'Facture A4</a>';
    layout_start('Vente ' . $s['number'], 'sales', ['actions' => $actions]);
    ?>
    <p><a class="back" href="<?= e(url('sales')) ?>"><?= icon('left') ?>Toutes les ventes</a></p>
    <div class="grid3 kpis">
      <div class="kpi"><span>Total TTC</span><b><?= money($s['total']) ?></b><?= badge($statusLabel, $statusTone) ?></div>
      <div class="kpi"><span>Encaissé</span><b><?= money($s['paid']) ?></b></div>
      <div class="kpi"><span>Reste à payer</span><b class="<?= $rest > 0 && $s['status'] !== 'annulee' ? 'warn-t' : '' ?>"><?= money($s['status'] === 'annulee' ? 0 : $rest) ?></b></div>
    </div>
    <div class="grid2">
      <section class="card">
        <h2>Informations</h2>
        <dl class="kv">
          <dt>Date</dt><dd><?= fdate($s['created_at'], true) ?></dd>
          <dt>Showroom</dt><dd><?= e(location((int)$s['location_id'])['name'] ?? '') ?></dd>
          <dt>Vendeur</dt><dd><?= e($s['seller']) ?></dd>
          <dt>Client</dt><dd><?= $s['client_id'] ? '<a href="' . e(url('clients', ['a' => 'view', 'id' => $s['client_id']])) . '">' . e($s['client_name']) . '</a> ' . e($s['client_phone']) : 'Client de passage' ?></dd>
          <dt>Origine</dt><dd><?= e(SOURCES[$s['source']] ?? '—') ?><?= $s['campaign'] ? ' · campagne « ' . e($s['campaign']) . ' »' : '' ?></dd>
          <?php if ($s['status'] === 'annulee'): ?>
            <dt>Annulation</dt><dd><?= fdate($s['cancelled_at'], true) ?> par <?= e($s['canceller']) ?> — <?= e($s['cancel_reason']) ?></dd>
          <?php endif; ?>
        </dl>
        <form method="post" action="<?= e(url('sales', ['a' => 'note'])) ?>" class="inline-form">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input name="note" value="<?= e($s['note']) ?>" placeholder="Note interne…" maxlength="500">
          <button class="btn sm ghost">Enregistrer</button>
        </form>
      </section>
      <section class="card">
        <h2>Paiements</h2>
        <table class="tbl">
          <?php foreach ($pays as $p): ?>
            <tr><td><?= fdate($p['created_at'], true) ?></td><td><?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? '<br><small class="muted">' . e($p['reference']) . '</small>' : '' ?></td><td class="num"><?= money($p['amount']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$pays): ?><tr><td class="muted">Aucun paiement.</td></tr><?php endif; ?>
        </table>
        <?php if ($rest > 0 && $s['status'] !== 'annulee' && can('sales.pay')): ?>
          <form method="post" action="<?= e(url('sales', ['a' => 'pay'])) ?>" class="pay-add">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <h3>Encaisser un versement</h3>
            <div class="grid3 tight">
              <label>Montant (DT)<input name="amount" inputmode="decimal" value="<?= e(m_in($rest)) ?>" required></label>
              <label>Mode<select name="method"><?= opt(PAY_METHODS, 'especes') ?></select></label>
              <label>Référence<input name="reference" maxlength="80"></label>
            </div>
            <button class="btn primary sm"><?= icon('cash') ?>Enregistrer le paiement</button>
          </form>
        <?php endif; ?>
      </section>
    </div>
    <section class="card">
      <h2>Articles</h2>
      <table class="tbl">
        <thead><tr><th></th><th>Désignation</th><th class="num">Qté</th><th class="num">Prix catalogue</th><th class="num">Prix vendu</th><th class="num">Total</th></tr></thead>
        <?php foreach ($items as $it): ?>
          <tr><td class="w-thumb"><?= $it['product_id'] ? product_thumb(['name' => $it['label'], 'image' => $it['image'], 'image_url' => $it['image_url'], 'family' => $it['family']], 'thumb sm') : '' ?></td>
            <td><?= e($it['label']) ?><?= $it['sku'] ? ' <small class="muted">' . e($it['sku']) . '</small>' : '' ?></td>
            <td class="num"><?= (int)$it['qty'] ?></td><td class="num muted"><?= money($it['list_price']) ?></td>
            <td class="num"><?= money($it['unit_price']) ?></td><td class="num"><?= money($it['total']) ?></td></tr>
        <?php endforeach; ?>
        <tfoot>
          <?php if ((int)$s['discount']): ?><tr><td></td><td colspan="4">Remise globale</td><td class="num">− <?= money($s['discount']) ?></td></tr><?php endif; ?>
          <tr class="strong"><td></td><td colspan="4">Total TTC</td><td class="num"><?= money($s['total']) ?></td></tr>
        </tfoot>
      </table>
    </section>
    <?php if ($ships): ?>
      <section class="card">
        <h2>Livraison</h2>
        <table class="tbl">
          <?php foreach ($ships as $sh): ?>
            <tr><td><a href="<?= e(url('deliveries', ['a' => 'edit', 'id' => $sh['id']])) ?>"><?= e($sh['number']) ?></a></td><td><?= e($sh['carrier'] ?: '—') ?></td>
              <td><?= e(trim($sh['city'] . ', ' . $sh['gouvernorat'], ', ')) ?></td><td><?= badge(SHIP_STATUS[$sh['status']] ?? $sh['status'], ship_tone($sh['status'])) ?></td>
              <td class="num"><?= (int)$sh['cod_amount'] ? 'À encaisser ' . money($sh['cod_amount']) . ($sh['cod_collected'] ? ' ✓' : '') : '' ?></td></tr>
          <?php endforeach; ?>
        </table>
      </section>
    <?php endif; ?>
    <?php if (is_admin() && $s['status'] !== 'annulee'): ?>
      <details class="card danger-zone">
        <summary>Annuler cette vente</summary>
        <form method="post" action="<?= e(url('sales', ['a' => 'cancel'])) ?>" onsubmit="return confirm('Annuler définitivement la vente <?= e($s['number']) ?> ?')">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <p class="muted">Les articles sont remis en stock au showroom, les paiements sont enregistrés en remboursement (dans la caisse ouverte du showroom pour les espèces) et les livraisons non effectuées sont annulées.</p>
          <label>Motif<input name="reason" required maxlength="300"></label>
          <button class="btn danger"><?= icon('x') ?>Annuler la vente</button>
        </form>
      </details>
    <?php endif; ?>
    <?php
    layout_end();
    exit;
}

/* ---------- Liste ---------- */

$f = [
    'from' => parse_date(get('from')) ?? date('Y-m-01'),
    'to' => parse_date(get('to')) ?? today(),
    'loc' => $scope ?? (int)get('loc'),
    'status' => (string)get('status'),
    'q' => (string)get('q'),
];
$where = ['date(s.created_at) BETWEEN ? AND ?'];
$p = [$f['from'], $f['to']];
if ($f['loc']) { $where[] = 's.location_id = ?'; $p[] = $f['loc']; }
if ($f['status'] !== '') { $where[] = 's.status = ?'; $p[] = $f['status']; }
if ($f['q'] !== '') {
    $where[] = '(s.number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
    array_push($p, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . normalize_phone($f['q']) . '%');
}
$w = implode(' AND ', $where);
$sum = one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN s.status <> 'annulee' THEN s.total END),0) total,
    COALESCE(SUM(CASE WHEN s.status <> 'annulee' THEN s.paid END),0) paid FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE $w", $p);
$page = max(1, (int)get('page', 1));
$per = 50;
$rows = all("SELECT s.*, c.name AS client_name, c.phone AS client_phone, u.name AS seller FROM sales s
    LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN users u ON u.id = s.user_id WHERE $w ORDER BY s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);

$exportParams = array_filter(['ds' => 'ventes', 'from' => $f['from'], 'to' => $f['to'], 'loc' => $f['loc'] ?: null]);
$actions = can('sales.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export'] + $exportParams)) . '">' . icon('download') . 'Export CSV</a>' : '';
$actions .= can('pos') ? '<a class="btn sm primary" href="' . e(url('pos')) . '">' . icon('pos') . 'Caisse</a>' : '';
layout_start('Ventes', 'sales', ['actions' => $actions]);
?>
<form class="filters" method="get">
  <input type="hidden" name="r" value="sales">
  <label>Du<input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label>Au<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <?php if (!$scope): ?><label>Showroom<select name="loc"><?= opt(location_options(false, 'showroom'), $f['loc'], true, 'Tous') ?></select></label><?php endif; ?>
  <label>Statut<select name="status"><?= opt(['payee' => 'Payée', 'partielle' => 'Reste à payer', 'annulee' => 'Annulée'], $f['status'], true, 'Tous') ?></select></label>
  <label class="grow">Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="N° de vente, client, téléphone"></label>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>
<div class="grid3 kpis">
  <div class="kpi"><span>Ventes</span><b><?= (int)$sum['n'] ?></b></div>
  <div class="kpi"><span>Chiffre d’affaires TTC</span><b><?= money($sum['total']) ?></b></div>
  <div class="kpi"><span>Reste à encaisser</span><b><?= money((int)$sum['total'] - (int)$sum['paid']) ?></b></div>
</div>
<div class="card flush">
  <table class="tbl hover">
    <thead><tr><th>N°</th><th>Date</th><?php if (!$scope): ?><th>Showroom</th><?php endif; ?><th>Client</th><th>Vendeur</th><th class="num">Total</th><th class="num">Reste</th><th>Statut</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $rest = $r['status'] === 'annulee' ? 0 : (int)$r['total'] - (int)$r['paid']; ?>
      <tr data-href="<?= e(url('sales', ['a' => 'view', 'id' => $r['id']])) ?>">
        <td><a href="<?= e(url('sales', ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= e($r['number']) ?></b></a></td>
        <td><?= fdate($r['created_at'], true) ?></td>
        <?php if (!$scope): ?><td><?= e(location((int)$r['location_id'])['name'] ?? '') ?></td><?php endif; ?>
        <td><?= e($r['client_name'] ?: '—') ?><?= $r['client_phone'] ? '<br><small class="muted">' . e($r['client_phone']) . '</small>' : '' ?></td>
        <td><?= e($r['seller']) ?></td>
        <td class="num"><?= money($r['total']) ?></td>
        <td class="num"><?= $rest > 0 ? money($rest) : '' ?></td>
        <td><?= badge(['payee' => 'Payée', 'partielle' => 'Reste à payer', 'annulee' => 'Annulée'][$r['status']] ?? $r['status'], ['payee' => 'ok', 'partielle' => 'warn', 'annulee' => 'err'][$r['status']] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty-row">Aucune vente sur cette période.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager((int)$sum['n'], $page, $per, array_filter(['from' => $f['from'], 'to' => $f['to'], 'loc' => $scope ? null : ($f['loc'] ?: null), 'status' => $f['status'], 'q' => $f['q']]), 'sales') ?>
<?php
layout_end();
