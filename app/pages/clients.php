<?php
declare(strict_types=1);

require_perm('clients.view');
$a = (string)get('a', 'list');
$scope = scope_loc();

if (is_post()) {
    csrf_check();
    require_perm('clients.edit');
    $id = (int)post('id');
    try {
        if ($a === 'delete') {
            if (!is_admin()) throw new RuntimeException('Suppression réservée à l’administrateur.');
            if ((int)val('SELECT COUNT(*) FROM sales WHERE client_id = ?', [$id])) throw new RuntimeException('Ce client a des ventes : il ne peut pas être supprimé.');
            q('DELETE FROM clients WHERE id = ?', [$id]);
            flash('ok', 'Client supprimé.');
            redirect(url('clients'));
        }
        $name = (string)post('name');
        $phone = normalize_phone((string)post('phone'));
        if ($name === '') throw new RuntimeException('Le nom est obligatoire.');
        if ($phone !== '' && val('SELECT id FROM clients WHERE phone = ? AND id <> ?', [$phone, $id])) throw new RuntimeException('Ce numéro de téléphone existe déjà pour un autre client.');
        $src = (string)post('source');
        $data = [
            'name' => $name, 'phone' => $phone, 'phone2' => normalize_phone((string)post('phone2')), 'email' => (string)post('email'),
            'gouvernorat' => (string)post('gouvernorat'), 'city' => (string)post('city'), 'address' => (string)post('address'),
            'source' => isset(SOURCES[$src]) ? $src : '', 'notes' => (string)post('notes'), 'updated_at' => now(),
        ];
        if ($id) update('clients', $data, 'id = ?', [$id]);
        else $id = insert('clients', $data + ['created_at' => now()]);
        flash('ok', 'Client enregistré.');
        redirect(url('clients', ['a' => 'view', 'id' => $id]));
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        back(url('clients'));
    }
}

if ($a === 'edit') {
    require_perm('clients.edit');
    $c = (int)get('id') ? one('SELECT * FROM clients WHERE id = ?', [(int)get('id')]) : ['id' => 0, 'name' => '', 'phone' => '', 'phone2' => '', 'email' => '', 'gouvernorat' => '', 'city' => '', 'address' => '', 'source' => '', 'notes' => ''];
    if (!$c) redirect(url('clients'));
    layout_start($c['id'] ? 'Modifier · ' . $c['name'] : 'Nouveau client', 'clients');
    ?>
    <p><a class="back" href="<?= e(url('clients')) ?>"><?= icon('left') ?>Clients</a></p>
    <form method="post" class="card form-grid">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <label class="span2">Nom complet<input name="name" value="<?= e($c['name']) ?>" required></label>
      <label>Téléphone<input name="phone" value="<?= e($c['phone']) ?>" inputmode="tel"></label>
      <label>Téléphone 2<input name="phone2" value="<?= e($c['phone2']) ?>" inputmode="tel"></label>
      <label>E-mail<input type="email" name="email" value="<?= e($c['email']) ?>"></label>
      <label>Origine<select name="source"><?= opt(SOURCES, $c['source'], true) ?></select></label>
      <label>Gouvernorat<select name="gouvernorat"><?= opt(array_combine(GOUVERNORATS, GOUVERNORATS), $c['gouvernorat'], true) ?></select></label>
      <label>Ville / délégation<input name="city" value="<?= e($c['city']) ?>"></label>
      <label class="span2">Adresse<input name="address" value="<?= e($c['address']) ?>"></label>
      <label class="span2">Notes<textarea name="notes" rows="3"><?= e($c['notes']) ?></textarea></label>
      <div class="span2 row-actions"><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div>
    </form>
    <?php
    layout_end();
    exit;
}

if ($a === 'view') {
    $c = one('SELECT * FROM clients WHERE id = ?', [(int)get('id')]);
    if (!$c) redirect(url('clients'));
    $sales = all('SELECT s.*, l.name loc FROM sales s JOIN locations l ON l.id = s.location_id WHERE s.client_id = ?' . ($scope ? ' AND s.location_id = ' . (int)$scope : '') . ' ORDER BY s.id DESC', [$c['id']]);
    $items = all("SELECT si.label, si.qty, si.warranty_months, s.created_at, s.number FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE s.client_id = ? AND s.status <> 'annulee' AND si.product_id IS NOT NULL ORDER BY s.id DESC", [$c['id']]);
    $sum = array_sum(array_map(fn($s) => $s['status'] !== 'annulee' ? (int)$s['total'] : 0, $sales));
    $rest = array_sum(array_map(fn($s) => $s['status'] === 'partielle' ? (int)$s['total'] - (int)$s['paid'] : 0, $sales));
    $actions = can('clients.edit') ? '<a class="btn sm primary" href="' . e(url('clients', ['a' => 'edit', 'id' => $c['id']])) . '">' . icon('edit') . 'Modifier</a>' : '';
    layout_start($c['name'], 'clients', ['actions' => $actions]);
    ?>
    <p><a class="back" href="<?= e(url('clients')) ?>"><?= icon('left') ?>Clients</a></p>
    <div class="grid3 kpis">
      <div class="kpi"><span>Achats</span><b><?= money0($sum) ?></b><small><?= count($sales) ?> vente(s)</small></div>
      <div class="kpi"><span>Reste à payer</span><b class="<?= $rest ? 'warn-t' : '' ?>"><?= money($rest) ?></b></div>
      <div class="kpi"><span>Client depuis</span><b><?= fdate($c['created_at']) ?></b><small><?= e(SOURCES[$c['source']] ?? '') ?></small></div>
    </div>
    <div class="grid2">
      <section class="card"><h2>Coordonnées</h2>
        <dl class="kv">
          <dt>Téléphone</dt><dd><?= $c['phone'] ? '<a href="tel:' . e($c['phone']) . '">' . e($c['phone']) . '</a> · <a href="https://wa.me/216' . e($c['phone']) . '" target="_blank" rel="noopener">WhatsApp</a>' : '—' ?><?= $c['phone2'] ? ' / ' . e($c['phone2']) : '' ?></dd>
          <dt>E-mail</dt><dd><?= e($c['email'] ?: '—') ?></dd>
          <dt>Adresse</dt><dd><?= e(trim($c['address'] . ', ' . $c['city'] . ', ' . $c['gouvernorat'], ', ') ?: '—') ?></dd>
          <?php if ($c['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($c['notes'])) ?></dd><?php endif; ?>
        </dl></section>
      <section class="card"><h2>Appareils & garanties</h2>
        <?php if ($items): ?><table class="tbl"><?php foreach ($items as $it):
            $end = $it['warranty_months'] ? date('Y-m-d', strtotime($it['created_at'] . ' +' . (int)$it['warranty_months'] . ' months')) : null; ?>
          <tr><td><?= e($it['label']) ?> <?= (int)$it['qty'] > 1 ? '× ' . (int)$it['qty'] : '' ?><br><small class="muted"><?= e($it['number']) ?> · <?= fdate($it['created_at']) ?></small></td>
            <td><?= $end ? ($end >= today() ? badge('Garantie jusqu’au ' . fdate($end), 'ok') : badge('Garantie terminée', '')) : '' ?></td></tr>
        <?php endforeach; ?></table><?php else: ?><p class="muted">Aucun produit acheté.</p><?php endif; ?></section>
    </div>
    <section class="card flush"><div class="card-head"><b>Historique des ventes</b></div>
      <table class="tbl hover">
        <?php foreach ($sales as $s): ?>
          <tr data-href="<?= e(url('sales', ['a' => 'view', 'id' => $s['id']])) ?>"><td><b><?= e($s['number']) ?></b></td><td><?= fdate($s['created_at'], true) ?></td><td><?= e($s['loc']) ?></td><td class="num"><?= money($s['total']) ?></td>
            <td><?= $s['status'] === 'annulee' ? badge('Annulée', 'err') : ($s['status'] === 'partielle' ? badge('Reste ' . money((int)$s['total'] - (int)$s['paid']), 'warn') : badge('Payée', 'ok')) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$sales): ?><tr><td class="empty-row">Aucune vente.</td></tr><?php endif; ?>
      </table></section>
    <?php if (is_admin() && !$sales): ?>
      <form method="post" action="<?= e(url('clients', ['a' => 'delete'])) ?>" class="danger-inline" onsubmit="return confirm('Supprimer ce client ?')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn ghost danger sm"><?= icon('trash') ?>Supprimer</button>
      </form>
    <?php endif;
    layout_end();
    exit;
}

$f = ['q' => (string)get('q'), 'gov' => (string)get('gov'), 'source' => (string)get('source')];
$where = ['1=1'];
$p = [];
if ($f['q'] !== '') { $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR c.city LIKE ? OR c.email LIKE ?)'; array_push($p, "%{$f['q']}%", '%' . (normalize_phone($f['q']) ?: $f['q']) . '%', "%{$f['q']}%", "%{$f['q']}%"); }
if ($f['gov'] !== '') { $where[] = 'c.gouvernorat = ?'; $p[] = $f['gov']; }
if ($f['source'] !== '') { $where[] = 'c.source = ?'; $p[] = $f['source']; }
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM clients c WHERE $w", $p);
$page = max(1, (int)get('page', 1));
$rows = all("SELECT c.*, (SELECT COUNT(*) FROM sales s WHERE s.client_id = c.id AND s.status <> 'annulee') n,
    (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.client_id = c.id AND s.status <> 'annulee') t,
    (SELECT MAX(created_at) FROM sales s WHERE s.client_id = c.id) last
    FROM clients c WHERE $w ORDER BY COALESCE(last, c.created_at) DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $p);
$actions = (can('clients.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'clients'])) . '">' . icon('download') . 'Export CSV</a>' : '')
    . (can('clients.import') ? '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'clients'])) . '">' . icon('upload') . 'Import CSV</a>' : '')
    . (can('clients.edit') ? '<a class="btn sm primary" href="' . e(url('clients', ['a' => 'edit'])) . '">' . icon('plus') . 'Nouveau client</a>' : '');
layout_start('Clients', 'clients', ['actions' => $actions]);
?>
<form class="filters" method="get">
  <input type="hidden" name="r" value="clients">
  <label class="grow">Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="Nom, téléphone, ville, e-mail"></label>
  <label>Gouvernorat<select name="gov"><?= opt(array_combine(GOUVERNORATS, GOUVERNORATS), $f['gov'], true, 'Tous') ?></select></label>
  <label>Origine<select name="source"><?= opt(SOURCES, $f['source'], true, 'Toutes') ?></select></label>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>
<div class="card flush">
  <table class="tbl hover">
    <thead><tr><th>Client</th><th>Téléphone</th><th>Ville</th><th>Origine</th><th class="num">Achats</th><th>Dernier achat</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-href="<?= e(url('clients', ['a' => 'view', 'id' => $r['id']])) ?>">
        <td><span class="avatar sm"><?= e(initials($r['name'])) ?></span> <a href="<?= e(url('clients', ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= e($r['name']) ?></b></a></td>
        <td><?= e($r['phone']) ?></td><td><?= e(trim($r['city'] . ', ' . $r['gouvernorat'], ', ')) ?></td><td><?= e(SOURCES[$r['source']] ?? '') ?></td>
        <td class="num"><?= (int)$r['n'] ? money0($r['t']) . '<br><small class="muted">' . (int)$r['n'] . ' vente(s)</small>' : '' ?></td>
        <td><?= $r['last'] ? fdate($r['last']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty-row">Aucun client. Ils sont créés automatiquement en caisse quand vous saisissez un nom et un téléphone.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager($total, $page, 50, array_filter($f), 'clients') ?>
<?php
layout_end();
