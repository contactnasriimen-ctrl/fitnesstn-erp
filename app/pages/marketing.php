<?php
declare(strict_types=1);

require_perm('campaigns.view');
$a = (string)get('a', 'list');
$canEdit = can('campaigns.edit');

if (is_post()) {
    csrf_check();
    require_perm('campaigns.edit');
    $id = (int)post('id');
    if ($a === 'delete') {
        q('UPDATE sales SET campaign_id = NULL WHERE campaign_id = ?', [$id]);
        q('DELETE FROM campaigns WHERE id = ?', [$id]);
        audit('campagne supprimée', 'campaign', $id);
        flash('ok', 'Campagne supprimée.');
        redirect(url('marketing'));
    }
    $name = (string)post('name');
    $start = parse_date(post('start_date'));
    $end = parse_date(post('end_date'));
    $errors = [];
    if ($name === '') $errors[] = 'Le nom de la campagne est obligatoire.';
    if ($start && $end && $end < $start) $errors[] = 'La date de fin est avant la date de début.';
    $channel = (string)post('channel');
    $status = (string)post('status');
    $data = [
        'name' => $name, 'channel' => isset(CHANNELS[$channel]) ? $channel : 'autre',
        'location_id' => (int)post('location_id') ?: null, 'product_id' => (int)post('product_id') ?: null,
        'objective' => (string)post('objective'), 'budget' => max(0, parse_money(post('budget')) ?? 0),
        'spent' => max(0, parse_money(post('spent')) ?? 0), 'start_date' => $start, 'end_date' => $end,
        'status' => isset(CAMPAIGN_STATUS[$status]) ? $status : 'planifiee',
        'leads' => max(0, (int)parse_int(post('leads'), 0)), 'messages' => max(0, (int)parse_int(post('messages'), 0)),
        'reach' => max(0, (int)parse_int(post('reach'), 0)), 'notes' => (string)post('notes'), 'updated_at' => now(),
    ];
    if ($errors) {
        foreach ($errors as $er) flash('err', $er);
        $_SESSION['old'] = $_POST;
        redirect(url('marketing', ['a' => 'edit', 'id' => $id ?: null]));
    }
    if ($id) update('campaigns', $data, 'id = ?', [$id]);
    else $id = insert('campaigns', $data + ['created_by' => user()['id'], 'created_at' => now()]);
    audit('campagne enregistrée', 'campaign', $id, $name);
    flash('ok', 'Campagne enregistrée.');
    redirect(url('marketing'));
}

$tabsHtml = tabs(array_filter([
    'list' => ['Campagnes publicitaires', url('marketing')],
    'stats' => can('marketing.stats') ? ['Performance & origine des ventes', url('marketing', ['a' => 'stats'])] : null,
]), $a === 'edit' ? 'list' : $a);

/* ---------- Formulaire ---------- */

if ($a === 'edit') {
    require_perm('campaigns.edit');
    $id = (int)get('id');
    $c = $id ? one('SELECT * FROM campaigns WHERE id = ?', [$id]) : null;
    $old = $_SESSION['old'] ?? null;
    unset($_SESSION['old']);
    $v = fn(string $k, $d = '') => $old[$k] ?? ($c[$k] ?? $d);
    $m = fn(string $k) => $old[$k] ?? m_in($c[$k] ?? null);
    $products = ['' => 'Aucun en particulier'];
    foreach (all('SELECT id, name FROM products WHERE active = 1 ORDER BY name COLLATE NOCASE') as $p) $products[$p['id']] = $p['name'];
    layout_start($c ? 'Campagne · ' . $c['name'] : 'Nouvelle campagne', 'marketing');
    echo $tabsHtml;
    ?>
    <form method="post" class="card form-grid">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
      <label class="span2">Nom de la campagne<input name="name" value="<?= e($v('name')) ?>" required maxlength="120" placeholder="ex. Promo rentrée tapis BT — TikTok"></label>
      <label>Canal<select name="channel"><?= opt(CHANNELS, $v('channel', 'facebook')) ?></select></label>
      <label>Statut<select name="status"><?= opt(CAMPAIGN_STATUS, $v('status', 'planifiee')) ?></select></label>
      <label>Showroom ciblé<select name="location_id"><?= opt(location_options(true, 'showroom'), $v('location_id'), true, 'Tous les showrooms') ?></select></label>
      <label>Produit mis en avant<select name="product_id"><?= opt($products, $v('product_id')) ?></select></label>
      <label>Début<input type="date" name="start_date" value="<?= e($v('start_date')) ?>"></label>
      <label>Fin<input type="date" name="end_date" value="<?= e($v('end_date')) ?>"></label>
      <label>Budget prévu (DT)<input name="budget" inputmode="decimal" value="<?= e($m('budget')) ?>"></label>
      <label>Montant dépensé (DT)<input name="spent" inputmode="decimal" value="<?= e($m('spent')) ?>"></label>
      <label>Personnes touchées (portée)<input name="reach" type="number" min="0" value="<?= e($v('reach', 0)) ?>"></label>
      <label>Messages reçus<input name="messages" type="number" min="0" value="<?= e($v('messages', 0)) ?>"></label>
      <label>Prospects qualifiés (leads)<input name="leads" type="number" min="0" value="<?= e($v('leads', 0)) ?>"></label>
      <label>Objectif<input name="objective" value="<?= e($v('objective')) ?>" maxlength="200" placeholder="ex. 20 ventes de tapis"></label>
      <label class="span2">Notes (ciblage, visuels, textes…)<textarea name="notes" rows="3"><?= e($v('notes')) ?></textarea></label>
      <p class="span2 hint">Les ventes sont rattachées à la campagne en caisse (menu « Campagne »). Le coût par vente et le retour sur investissement sont calculés automatiquement.</p>
      <div class="span2 row-actions"><a class="btn ghost" href="<?= e(url('marketing')) ?>">Annuler</a><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div>
    </form>
    <?php if ($c): ?>
      <form method="post" action="<?= e(url('marketing', ['a' => 'delete'])) ?>" class="danger-inline" onsubmit="return confirm('Supprimer cette campagne ?')">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><button class="btn ghost danger sm"><?= icon('trash') ?>Supprimer</button>
      </form>
    <?php endif;
    layout_end();
    exit;
}

/* ---------- Performance ---------- */

if ($a === 'stats') {
    require_perm('marketing.stats');
    $from = parse_date(get('from')) ?? date('Y-m-01');
    $to = parse_date(get('to')) ?? today();
    $loc = (int)get('loc');
    $locSql = $loc ? ' AND s.location_id = ' . $loc : '';
    $base = "s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ?" . $locSql;
    $tot = one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM sales s WHERE $base", [$from, $to]);
    $bySource = all("SELECT COALESCE(NULLIF(s.source,''),'—') src, COUNT(*) n, SUM(s.total) t FROM sales s WHERE $base GROUP BY src ORDER BY t DESC", [$from, $to]);
    $byFamily = all("SELECT COALESCE(p.family, 'Lignes libres') fam, SUM(si.qty) q, SUM(si.total) t FROM sale_items si JOIN sales s ON s.id = si.sale_id
        LEFT JOIN products p ON p.id = si.product_id WHERE $base GROUP BY fam ORDER BY t DESC", [$from, $to]);
    $top = all("SELECT si.label, SUM(si.qty) q, SUM(si.total) t FROM sale_items si JOIN sales s ON s.id = si.sale_id
        WHERE $base AND si.product_id IS NOT NULL GROUP BY si.product_id ORDER BY q DESC, t DESC LIMIT 10", [$from, $to]);
    $newClients = all("SELECT COALESCE(NULLIF(source,''),'—') src, COUNT(*) n FROM clients WHERE date(created_at) BETWEEN ? AND ? GROUP BY src ORDER BY n DESC", [$from, $to]);
    $camps = all("SELECT c.*, COUNT(s.id) n, COALESCE(SUM(s.total),0) ca FROM campaigns c
        LEFT JOIN sales s ON s.campaign_id = c.id AND $base
        WHERE (c.start_date IS NULL OR c.start_date <= ?) AND (c.end_date IS NULL OR c.end_date >= ?)
        GROUP BY c.id ORDER BY ca DESC", [$from, $to, $to, $from]);
    $known = array_sum(array_map(fn($r) => $r['src'] !== '—' ? (int)$r['n'] : 0, $bySource));
    layout_start('Marketing', 'marketing', ['actions' => can('campaigns.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'ventes', 'from' => $from, 'to' => $to])) . '">' . icon('download') . 'Ventes CSV</a>' : '']);
    echo $tabsHtml;
    ?>
    <form class="filters" method="get">
      <input type="hidden" name="r" value="marketing"><input type="hidden" name="a" value="stats">
      <label>Du<input type="date" name="from" value="<?= e($from) ?>"></label>
      <label>Au<input type="date" name="to" value="<?= e($to) ?>"></label>
      <label>Showroom<select name="loc"><?= opt(location_options(true, 'showroom'), $loc, true, 'Tous') ?></select></label>
      <button class="btn"><?= icon('search') ?>Afficher</button>
    </form>
    <div class="grid3 kpis">
      <div class="kpi"><span>Ventes</span><b><?= (int)$tot['n'] ?></b><small><?= money0($tot['t']) ?></small></div>
      <div class="kpi"><span>Origine renseignée</span><b><?= $tot['n'] ? pct($known / (int)$tot['n'] * 100) : '—' ?></b><small>des ventes ont une origine connue</small></div>
      <div class="kpi"><span>Nouveaux clients</span><b><?= array_sum(array_column($newClients, 'n')) ?></b><small>créés sur la période</small></div>
    </div>
    <div class="grid2">
      <section class="card"><div class="card-title"><h2>CA par origine du client</h2></div>
        <?= bar_list(array_map(fn($r) => [SOURCES[$r['src']] ?? 'Non renseigné', (int)$r['t'], money0($r['t']), (int)$r['n'] . ' vente(s)'], $bySource)) ?></section>
      <section class="card"><div class="card-title"><h2>CA par famille de produits</h2></div>
        <?= bar_list(array_map(fn($r) => [$r['fam'], (int)$r['t'], money0($r['t']), (int)$r['q'] . ' article(s)'], $byFamily)) ?></section>
      <section class="card"><div class="card-title"><h2>Top 10 des produits vendus</h2><span class="muted">en quantité</span></div>
        <?= bar_list(array_map(fn($r) => [$r['label'], (int)$r['q'], (int)$r['q'] . ' u.', money0($r['t'])], $top)) ?></section>
      <section class="card"><div class="card-title"><h2>Nouveaux clients par origine</h2></div>
        <?= bar_list(array_map(fn($r) => [SOURCES[$r['src']] ?? 'Non renseigné', (int)$r['n'], (string)$r['n']], $newClients)) ?></section>
    </div>
    <section class="card flush">
      <div class="card-head"><b>Rentabilité des campagnes de la période</b></div>
      <table class="tbl">
        <thead><tr><th>Campagne</th><th class="num">Dépensé</th><th class="num">Leads</th><th class="num">Ventes</th><th class="num">CA généré</th><th class="num">Coût par vente</th><th class="num">CA / dépense</th></tr></thead>
        <?php foreach ($camps as $c): ?>
          <tr><td><b><?= e($c['name']) ?></b><br><small class="muted"><?= e(CHANNELS[$c['channel']] ?? '') ?></small></td>
            <td class="num"><?= money0($c['spent']) ?></td><td class="num"><?= (int)$c['leads'] ?></td><td class="num"><?= (int)$c['n'] ?></td>
            <td class="num"><?= money0($c['ca']) ?></td>
            <td class="num"><?= (int)$c['n'] ? money0(intdiv((int)$c['spent'], (int)$c['n'])) : '—' ?></td>
            <td class="num"><?= (int)$c['spent'] ? '<b>× ' . number_format((int)$c['ca'] / (int)$c['spent'], 1, ',', ' ') . '</b>' : '—' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$camps): ?><tr><td colspan="7" class="empty-row">Aucune campagne sur cette période.</td></tr><?php endif; ?>
      </table>
    </section>
    <?php
    layout_end();
    exit;
}

/* ---------- Liste ---------- */

$status = (string)get('status');
$where = $status !== '' ? 'WHERE c.status = ?' : '';
$rows = all("SELECT c.*, p.name product, l.name loc, (SELECT COUNT(*) FROM sales s WHERE s.campaign_id = c.id AND s.status <> 'annulee') n,
    (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.campaign_id = c.id AND s.status <> 'annulee') ca
    FROM campaigns c LEFT JOIN products p ON p.id = c.product_id LEFT JOIN locations l ON l.id = c.location_id $where
    ORDER BY CASE c.status WHEN 'active' THEN 0 WHEN 'planifiee' THEN 1 WHEN 'pause' THEN 2 ELSE 3 END, c.start_date DESC", $status !== '' ? [$status] : []);
$actions = (can('campaigns.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'campagnes'])) . '">' . icon('download') . 'Export CSV</a>' : '')
    . (can('campaigns.import') ? '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'campagnes'])) . '">' . icon('upload') . 'Import CSV</a>' : '')
    . ($canEdit ? '<a class="btn sm primary" href="' . e(url('marketing', ['a' => 'edit'])) . '">' . icon('plus') . 'Nouvelle campagne</a>' : '');
layout_start('Marketing', 'marketing', ['actions' => $actions]);
echo $tabsHtml;
?>
<div class="chips">
  <a class="chip <?= $status === '' ? 'on' : '' ?>" href="<?= e(url('marketing')) ?>">Toutes</a>
  <?php foreach (CAMPAIGN_STATUS as $k => $label): ?><a class="chip <?= $status === $k ? 'on' : '' ?>" href="<?= e(url('marketing', ['status' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>
<div class="camp-grid">
  <?php foreach ($rows as $c):
      $pctSpent = (int)$c['budget'] ? min(100, (int)$c['spent'] / (int)$c['budget'] * 100) : 0; ?>
    <article class="card camp">
      <header><span class="badge <?= ['active' => 'ok', 'planifiee' => 'info', 'pause' => 'warn', 'terminee' => ''][$c['status']] ?? '' ?>"><?= e(CAMPAIGN_STATUS[$c['status']] ?? $c['status']) ?></span>
        <span class="muted"><?= e(CHANNELS[$c['channel']] ?? $c['channel']) ?></span>
        <?php if ($canEdit): ?><a class="icon-btn xs" title="Modifier" href="<?= e(url('marketing', ['a' => 'edit', 'id' => $c['id']])) ?>"><?= icon('edit') ?></a><?php endif; ?></header>
      <h3><?= e($c['name']) ?></h3>
      <p class="muted"><?= $c['start_date'] ? fdate($c['start_date']) : '…' ?> → <?= $c['end_date'] ? fdate($c['end_date']) : '…' ?> · <?= e($c['loc'] ?: 'Tous les showrooms') ?><?= $c['product'] ? ' · ' . e($c['product']) : '' ?></p>
      <div class="meter" title="Budget consommé"><span style="width:<?= round($pctSpent, 1) ?>%"></span></div>
      <p class="small"><?= money0($c['spent']) ?> dépensés sur <?= money0($c['budget']) ?></p>
      <dl class="camp-stats">
        <div><dt>Portée</dt><dd><?= number_format((int)$c['reach'], 0, ',', ' ') ?></dd></div>
        <div><dt>Messages</dt><dd><?= (int)$c['messages'] ?></dd></div>
        <div><dt>Leads</dt><dd><?= (int)$c['leads'] ?></dd></div>
        <div><dt>Ventes</dt><dd><?= (int)$c['n'] ?></dd></div>
        <div><dt>CA</dt><dd><?= money0($c['ca']) ?></dd></div>
        <div><dt>CA / dépense</dt><dd><?= (int)$c['spent'] ? '× ' . number_format((int)$c['ca'] / (int)$c['spent'], 1, ',', ' ') : '—' ?></dd></div>
      </dl>
    </article>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <div class="empty"><h2>Aucune campagne</h2><p>Créez vos campagnes Facebook, Instagram, TikTok… pour suivre budget, leads et ventes générées.</p>
      <?php if ($canEdit): ?><a class="btn primary" href="<?= e(url('marketing', ['a' => 'edit'])) ?>"><?= icon('plus') ?>Nouvelle campagne</a><?php endif; ?></div>
  <?php endif; ?>
</div>
<?php
layout_end();
