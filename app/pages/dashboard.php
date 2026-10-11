<?php
declare(strict_types=1);

require_perm('dashboard');
$u = user();
$role = $u['role'];
$scope = scope_loc();
$today = today();
$monthStart = date('Y-m-01');
$prevStart = date('Y-m-01', strtotime('first day of last month'));
$prevSameDay = date('Y-m-d', strtotime($prevStart . ' +' . ((int)date('j') - 1) . ' days'));
$showrooms = $scope ? [location($scope)] : locations(true, 'showroom');
$locIn = implode(',', array_map(fn($l) => (int)$l['id'], $showrooms)) ?: '0';

$ca = function (string $from, string $to, ?int $loc = null) use ($locIn): array {
    $sql = "SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(total - paid),0) rest FROM sales
            WHERE status <> 'annulee' AND date(created_at) BETWEEN ? AND ? AND location_id " . ($loc ? '= ' . (int)$loc : "IN ($locIn)");
    $r = one($sql, [$from, $to]);
    return ['n' => (int)$r['n'], 't' => (int)$r['t'], 'rest' => (int)$r['rest']];
};

$todayAll = $ca($today, $today);
$month = $ca($monthStart, $today);
$prev = $ca($prevStart, $prevSameDay);
$basket = $month['n'] ? intdiv($month['t'], $month['n']) : 0;
$receivable = (int)val("SELECT COALESCE(SUM(total - paid),0) FROM sales WHERE status = 'partielle' AND location_id IN ($locIn)");
$codOwed = (int)val("SELECT COALESCE(SUM(cod_amount),0) FROM shipments WHERE status = 'livree' AND cod_collected = 0" . ($scope ? ' AND location_id = ' . (int)$scope : ''));
$shipOpen = (int)val("SELECT COUNT(*) FROM shipments WHERE status IN ('a_preparer','en_cours')" . ($scope ? ' AND location_id = ' . (int)$scope : ''));
$shipLate = (int)val("SELECT COUNT(*) FROM shipments WHERE status IN ('a_preparer','en_cours') AND planned_date < ?" . ($scope ? ' AND location_id = ' . (int)$scope : ''), [$today]);

// Graphique : 30 derniers jours par showroom
$days = [];
for ($i = 29; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i days"));
$series = [];
foreach ($showrooms as $l) {
    $vals = array_fill(0, 30, 0);
    foreach (all("SELECT date(created_at) d, SUM(total) t FROM sales WHERE status <> 'annulee' AND location_id = ? AND date(created_at) >= ? GROUP BY d", [$l['id'], $days[0]]) as $r) {
        $idx = array_search($r['d'], $days, true);
        if ($idx !== false) $vals[$idx] = (int)$r['t'];
    }
    $series[] = ['name' => $l['name'], 'values' => $vals];
}

$top = all("SELECT si.label, SUM(si.qty) q, SUM(si.total) t FROM sale_items si JOIN sales s ON s.id = si.sale_id
    WHERE s.status <> 'annulee' AND si.product_id IS NOT NULL AND date(s.created_at) >= ? AND s.location_id IN ($locIn)
    GROUP BY si.product_id ORDER BY t DESC LIMIT 6", [$monthStart]);
$sources = all("SELECT COALESCE(NULLIF(source,''),'—') src, COUNT(*) n, SUM(total) t FROM sales
    WHERE status <> 'annulee' AND date(created_at) >= ? AND location_id IN ($locIn) GROUP BY src ORDER BY t DESC", [$monthStart]);

// Tâches
$boardIds = [];
foreach (all('SELECT * FROM boards') as $b) {
    if ($role === 'admin' || ($role === 'marketing' && $b['kind'] === 'marketing') || ($role === 'showroom' && $b['kind'] === 'showroom' && (int)$b['location_id'] === (int)$u['location_id'])) $boardIds[] = (int)$b['id'];
}
$bIn = implode(',', $boardIds) ?: '0';
$myTasks = all("SELECT t.*, b.name board, c.name col FROM tasks t JOIN board_columns c ON c.id = t.column_id JOIN boards b ON b.id = t.board_id
    WHERE c.is_done = 0 AND t.board_id IN ($bIn) AND (t.assignee_id = ? OR t.assignee_id IS NULL)
    ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date, CASE t.priority WHEN 'urgente' THEN 0 WHEN 'haute' THEN 1 ELSE 2 END LIMIT 7", [$u['id']]);
$lateTasks = (int)val("SELECT COUNT(*) FROM tasks t JOIN board_columns c ON c.id = t.column_id WHERE c.is_done = 0 AND t.board_id IN ($bIn) AND t.due_date < ?", [$today]);

$lowStock = (int)val('SELECT COUNT(*) FROM (SELECT p.id FROM products p LEFT JOIN stock s ON s.product_id = p.id WHERE p.active = 1 AND p.min_stock > 0 GROUP BY p.id HAVING COALESCE(SUM(s.qty),0) <= p.min_stock)');
$negStock = (int)val('SELECT COUNT(DISTINCT product_id) FROM stock WHERE qty < 0');
$openSessions = all("SELECT cs.*, l.name FROM cash_sessions cs JOIN locations l ON l.id = cs.location_id WHERE cs.status = 'ouverte' AND cs.location_id IN ($locIn)");
$activeCampaigns = all("SELECT c.*, (SELECT COUNT(*) FROM sales s WHERE s.campaign_id = c.id AND s.status <> 'annulee') n,
    (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.campaign_id = c.id AND s.status <> 'annulee') ca FROM campaigns c WHERE c.status = 'active' ORDER BY c.end_date");
$recent = $role === 'marketing' ? [] : all("SELECT s.*, c.name client FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE s.location_id IN ($locIn) ORDER BY s.id DESC LIMIT 8");

$delta = $prev['t'] > 0 ? ($month['t'] - $prev['t']) / $prev['t'] * 100 : null;
$hello = (int)date('G') < 18 ? 'Bonjour' : 'Bonsoir';
$actions = can('pos') ? '<a class="btn sm primary" href="' . e(url('pos')) . '">' . icon('pos') . 'Ouvrir la caisse</a>' : '';
layout_start('Tableau de bord', 'dashboard', ['actions' => $actions]);
?>
<p class="lead"><?= e($hello) ?> <?= e(explode(' ', $u['name'])[0]) ?> — <?= e(strftime_fr($today)) ?> <?= fdate($today) ?><?= $scope ? ' · ' . e(location($scope)['name']) : '' ?></p>

<div class="grid4 kpis">
  <div class="kpi hero"><span>Chiffre d’affaires aujourd’hui</span><b><?= money0($todayAll['t']) ?></b>
    <small><?= $todayAll['n'] ?> vente<?= $todayAll['n'] > 1 ? 's' : '' ?><?php if (!$scope && count($showrooms) > 1): foreach ($showrooms as $l): $t = $ca($today, $today, (int)$l['id']); ?> · <?= e($l['code']) ?> <?= money0($t['t']) ?><?php endforeach; endif; ?></small></div>
  <div class="kpi"><span>CA du mois</span><b><?= money0($month['t']) ?></b>
    <small><?php if ($delta !== null): ?><span class="<?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '▲' : '▼' ?> <?= pct(abs($delta)) ?></span> vs même période du mois dernier<?php else: ?>Pas de comparaison disponible<?php endif; ?></small></div>
  <div class="kpi"><span>Ventes du mois</span><b><?= $month['n'] ?></b><small>Panier moyen <?= money0($basket) ?></small></div>
  <?php if ($role !== 'marketing'): ?>
    <div class="kpi"><span>Reste à encaisser</span><b class="<?= $receivable ? 'warn-t' : '' ?>"><?= money0($receivable) ?></b><small><?= $codOwed ? 'dont ' . money0($codOwed) . ' chez les transporteurs' : 'Facilités et paiements à la livraison' ?></small></div>
  <?php else: ?>
    <div class="kpi"><span>Campagnes actives</span><b><?= count($activeCampaigns) ?></b><small>Dépensé : <?= money0(array_sum(array_column($activeCampaigns, 'spent'))) ?></small></div>
  <?php endif; ?>
</div>

<?php
$alerts = [];
if ($role !== 'marketing') {
    if (can('pos') && !$openSessions) $alerts[] = ['warn', 'Aucune caisse ouverte' . ($scope ? '' : ' en ce moment') . '.', url('pos'), 'Ouvrir'];
    foreach ($openSessions as $s) if (substr($s['opened_at'], 0, 10) < $today) $alerts[] = ['err', 'La caisse « ' . $s['name'] . ' » est ouverte depuis le ' . fdate($s['opened_at']) . ' : pensez à la clôturer.', url('pos', ['loc' => $s['location_id'], 'a' => 'close']), 'Clôturer'];
    if ($shipLate) $alerts[] = ['err', "$shipLate livraison(s) en retard sur la date prévue.", url('deliveries'), 'Voir'];
    elseif ($shipOpen) $alerts[] = ['info', "$shipOpen livraison(s) à préparer ou en cours.", url('deliveries'), 'Voir'];
    if ($codOwed) $alerts[] = ['warn', money($codOwed) . ' encaissés par les transporteurs restent à récupérer.', url('deliveries', ['status' => 'a_recuperer']), 'Voir'];
}
if ($negStock && can('stock.move')) $alerts[] = ['warn', "$negStock produit(s) avec un stock négatif : un inventaire est nécessaire.", url('stock', ['show' => 'negatif']), 'Voir'];
if ($lowStock && can('stock.move')) $alerts[] = ['warn', "$lowStock produit(s) sous le stock minimum.", url('stock', ['show' => 'bas']), 'Voir'];
if ($lateTasks) $alerts[] = ['err', "$lateTasks tâche(s) en retard.", url('tasks'), 'Voir'];
?>
<?php if ($alerts): ?>
  <div class="alerts">
    <?php foreach ($alerts as [$tone, $msg, $href, $label]): ?>
      <div class="alert <?= e($tone) ?>"><?= icon($tone === 'info' ? 'truck' : 'alert') ?><span><?= e($msg) ?></span><a href="<?= e($href) ?>"><?= e($label) ?></a></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<section class="card">
  <div class="card-title"><h2>Chiffre d’affaires des 30 derniers jours</h2><span class="muted">TTC, ventes annulées exclues</span></div>
  <?php if (array_sum(array_map(fn($s) => array_sum($s['values']), $series)) > 0): ?>
    <?= chart_stacked_columns($days, $series, 'Chiffre d’affaires des 30 derniers jours par showroom') ?>
  <?php else: ?>
    <div class="chart-empty"><?= icon('chart') ?><p>Les ventes encaissées en caisse apparaîtront ici, jour par jour<?= count($series) > 1 ? ' et par showroom' : '' ?>.</p></div>
  <?php endif; ?>
</section>

<div class="grid2">
  <section class="card">
    <div class="card-title"><h2>Produits les plus vendus</h2><span class="muted">ce mois, en CA</span></div>
    <?= bar_list(array_map(fn($r) => [$r['label'], (int)$r['t'], money0($r['t']), (int)$r['q'] . ' vendu' . ((int)$r['q'] > 1 ? 's' : '')], $top), 'Aucune vente ce mois-ci.') ?>
  </section>
  <section class="card">
    <div class="card-title"><h2>D’où viennent les clients</h2><span class="muted">ce mois, en CA</span></div>
    <?= bar_list(array_map(fn($r) => [SOURCES[$r['src']] ?? 'Non renseigné', (int)$r['t'], money0($r['t']), (int)$r['n'] . ' vente' . ((int)$r['n'] > 1 ? 's' : '')], $sources), 'Renseignez « Comment nous a-t-il connus ? » en caisse.') ?>
  </section>
</div>

<div class="grid2">
  <section class="card">
    <div class="card-title"><h2>Mes tâches</h2><a href="<?= e(url('tasks')) ?>">Tout voir</a></div>
    <?php if ($myTasks): ?>
      <ul class="task-list">
        <?php foreach ($myTasks as $t): $late = $t['due_date'] && $t['due_date'] < $today; ?>
          <li><a href="<?= e(url('tasks', ['b' => $t['board_id']])) ?>"><b><?= e($t['title']) ?></b><small><?= e($t['board']) ?> · <?= e($t['col']) ?><?= $t['due_date'] ? ' · <span class="' . ($late ? 'late' : '') . '">' . fdate($t['due_date']) . '</span>' : '' ?></small></a>
            <?php if (in_array($t['priority'], ['haute', 'urgente'], true)) echo badge(PRIORITIES[$t['priority']], $t['priority'] === 'urgente' ? 'err' : 'warn'); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="muted">Rien à faire pour le moment.</p><?php endif; ?>
  </section>
  <?php if ($role === 'marketing' || $activeCampaigns && $role === 'admin'): ?>
    <section class="card">
      <div class="card-title"><h2>Campagnes actives</h2><a href="<?= e(url('marketing')) ?>">Marketing</a></div>
      <?php if ($activeCampaigns): ?>
        <table class="tbl">
          <?php foreach ($activeCampaigns as $c): ?>
            <tr><td><b><?= e($c['name']) ?></b><br><small class="muted"><?= e(CHANNELS[$c['channel']] ?? $c['channel']) ?><?= $c['end_date'] ? ' · jusqu’au ' . fdate($c['end_date']) : '' ?></small></td>
              <td class="num"><?= money0($c['spent']) ?><br><small class="muted">dépensé</small></td><td class="num"><?= (int)$c['n'] ?> vente(s)<br><small class="muted"><?= money0($c['ca']) ?></small></td></tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?><p class="muted">Aucune campagne active. <a href="<?= e(url('marketing', ['a' => 'edit'])) ?>">Créer une campagne</a></p><?php endif; ?>
    </section>
  <?php else: ?>
    <section class="card">
      <div class="card-title"><h2>Dernières ventes</h2><a href="<?= e(url('sales')) ?>">Toutes les ventes</a></div>
      <?php if ($recent): ?>
        <table class="tbl hover">
          <?php foreach ($recent as $s): ?>
            <tr data-href="<?= e(url('sales', ['a' => 'view', 'id' => $s['id']])) ?>"><td class="nowrap"><b><?= e($s['number']) ?></b><br><small class="muted"><?= fdate($s['created_at'], true) ?></small></td>
              <td><?= e($s['client'] ?: 'Client de passage') ?></td><td class="num"><?= money($s['total']) ?></td>
              <td><?= $s['status'] === 'annulee' ? badge('Annulée', 'err') : ($s['status'] === 'partielle' ? badge('Reste', 'warn') : '') ?></td></tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?><p class="muted">Aucune vente pour l’instant.</p><?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<?php
layout_end();
