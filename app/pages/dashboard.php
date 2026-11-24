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
    // Factures établies hors caisse (devis transformés, factures directes)
    $inv = (int)val("SELECT COALESCE(SUM(total),0) FROM documents WHERE type = 'facture' AND sale_id IS NULL AND status <> 'annulee'
        AND date BETWEEN ? AND ? AND location_id " . ($loc ? '= ' . (int)$loc : "IN ($locIn)"), [$from, $to]);
    return ['n' => (int)$r['n'], 't' => (int)$r['t'] + $inv, 'inv' => $inv, 'rest' => (int)$r['rest']];
};

$todayAll = $ca($today, $today);
$month = $ca($monthStart, $today);
$prev = $ca($prevStart, $prevSameDay);
$basket = $month['n'] ? intdiv($month['t'] - $month['inv'], $month['n']) : 0;
// Même calcul que Finances › À encaisser et X
$recv = receivables_summary($scope);
$receivable = $recv['total'];
$codOwed = $recv['carriers']['t'];
$recvParts = [];
foreach (['clients' => 'clients', 'carriers' => 'transporteurs', 'invoices' => 'factures', 'repairs' => 'réparations'] as $k => $word) {
    if ($recv[$k]['t'] > 0) $recvParts[] = $word . ' ' . money0($recv[$k]['t']);
}
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
    foreach (all("SELECT date d, SUM(total) t FROM documents WHERE type = 'facture' AND sale_id IS NULL AND status <> 'annulee' AND location_id = ? AND date >= ? GROUP BY date", [$l['id'], $days[0]]) as $r) {
        $idx = array_search($r['d'], $days, true);
        if ($idx !== false) $vals[$idx] += (int)$r['t'];
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
<?php
$heroBits = [];
if ($role !== 'marketing') {
    $heroBits[] = '<b>' . $todayAll['n'] . '</b> vente' . ($todayAll['n'] > 1 ? 's' : '') . ' aujourd’hui';
    $heroBits[] = '<b>' . $shipOpen . '</b> livraison' . ($shipOpen > 1 ? 's' : '') . ' en cours';
}
if (can('repairs.view')) {
    $repOpen = (int)val("SELECT COUNT(*) FROM repairs WHERE status IN ('nouveau','en_cours')" . ($scope ? ' AND location_id = ' . (int)$scope : ''));
    $heroBits[] = '<b>' . $repOpen . '</b> réparation' . ($repOpen > 1 ? 's' : '') . ' à traiter';
}
?>
<section class="welcome">
  <div>
    <small><?= e(ucfirst(strftime_fr($today))) ?> <?= fdate($today) ?><?= $scope ? ' · ' . e(location($scope)['name']) : '' ?></small>
    <h2><?= e($hello) ?> <?= e(explode(' ', $u['name'])[0]) ?></h2>
    <?php if ($heroBits): ?><p><?= implode(' · ', $heroBits) ?></p><?php endif; ?>
  </div>
  <img class="welcome-mark" src="assets/img/runner-white.svg" alt="">
</section>

<div class="grid4 kpis">
  <div class="kpi hero with-ic"><span class="kpi-ic"><?= icon('cash') ?></span><span>Chiffre d’affaires aujourd’hui</span><b><?= money0($todayAll['t']) ?></b>
    <small><?= $todayAll['n'] ?> vente<?= $todayAll['n'] > 1 ? 's' : '' ?><?= $todayAll['inv'] ? ' + factures ' . money0($todayAll['inv']) : '' ?><?php if (!$scope && count($showrooms) > 1): foreach ($showrooms as $l): $t = $ca($today, $today, (int)$l['id']); ?> · <?= e($l['code']) ?> <?= money0($t['t']) ?><?php endforeach; endif; ?></small></div>
  <div class="kpi with-ic"><span class="kpi-ic info"><?= icon('chart') ?></span><span>CA du mois</span><b><?= money0($month['t']) ?></b>
    <small><?php if ($delta !== null): ?><span class="<?= $delta >= 0 ? 'up' : 'down' ?>"><?= $delta >= 0 ? '▲' : '▼' ?> <?= pct(abs($delta)) ?></span> vs même période du mois dernier<?php else: ?>Pas de comparaison disponible<?php endif; ?></small></div>
  <div class="kpi with-ic"><span class="kpi-ic ok"><?= icon('receipt') ?></span><span>Ventes du mois</span><b><?= $month['n'] ?></b><small>Panier moyen <?= money0($basket) ?></small></div>
  <?php if ($role !== 'marketing'): ?>
    <a class="kpi with-ic kpi-link" href="<?= e(url('finances', ['a' => 'receivables'])) ?>"><span class="kpi-ic warn"><?= icon('wallet') ?></span><span>Reste à encaisser</span><b class="<?= $receivable ? 'warn-t' : '' ?>"><?= money0($receivable) ?></b><small><?= $recvParts ? e(implode(' · ', $recvParts)) : 'Rien à encaisser' ?></small></a>
  <?php else: ?>
    <div class="kpi with-ic"><span class="kpi-ic"><?= icon('megaphone') ?></span><span>Campagnes actives</span><b><?= count($activeCampaigns) ?></b><small>Dépensé : <?= money0(array_sum(array_column($activeCampaigns, 'spent'))) ?></small></div>
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
// Alertes logistiques et SAV
if (can('deliveries.view') && $role !== 'marketing') {
    $sl = $scope ? ' AND location_id = ' . (int)$scope : '';
    $stuck = (int)val("SELECT COUNT(*) FROM shipments WHERE status = 'en_cours' AND holder = 'transporteur' AND COALESCE(updated_at, created_at) < ?$sl", [date('Y-m-d H:i:s', strtotime('-7 days'))]);
    if ($stuck) $alerts[] = ['warn', "$stuck livraison(s) : la marchandise est chez le transporteur depuis plus de 7 jours sans être livrée.", url('deliveries', ['status' => 'en_cours', 'holder' => 'transporteur']), 'Relancer'];
    $back = (int)val("SELECT COUNT(*) FROM shipments WHERE status = 'retour' AND holder = 'retour'$sl");
    if ($back) $alerts[] = ['warn', "$back retour(s) en route vers le dépôt : vérifiez la réception de la marchandise.", url('deliveries', ['status' => 'retour', 'holder' => 'retour']), 'Voir'];
}
if (can('repairs.view')) {
    $rl = $scope ? ' AND location_id = ' . (int)$scope : '';
    $rep = one("SELECT SUM(status = 'nouveau') n, SUM(status = 'en_cours') c, SUM(appointment IS NOT NULL AND date(appointment) = ?) today FROM repairs WHERE 1$rl", [$today]);
    if ((int)$rep['today']) $alerts[] = ['info', (int)$rep['today'] . ' rendez-vous de réparation aujourd’hui.', url('repairs'), 'Voir'];
    if ((int)$rep['n'] + (int)$rep['c']) $alerts[] = ['info', (int)$rep['n'] . ' demande(s) de réparation à planifier, ' . (int)$rep['c'] . ' en cours.', url('repairs'), 'Réparations'];
}
if (can('invoices.view')) {
    $dl = $scope ? ' AND location_id = ' . (int)$scope : '';
    $od = one("SELECT COUNT(*) n, COALESCE(SUM(net - paid),0) t FROM documents WHERE type = 'facture' AND status IN ('emise','partielle') AND due_date < ?$dl", [$today]);
    if ((int)$od['n']) $alerts[] = ['err', (int)$od['n'] . ' facture(s) en retard de paiement : ' . money($od['t']) . ' à encaisser.', url('invoices', ['status' => 'late', 'from' => '2000-01-01']), 'Voir'];
    $acc = one("SELECT COUNT(*) n, COALESCE(SUM(net),0) t FROM documents WHERE type = 'devis' AND status = 'accepte'$dl");
    if ((int)$acc['n']) $alerts[] = ['info', (int)$acc['n'] . ' devis accepté(s) à facturer (' . money0($acc['t']) . ').', url('quotes', ['status' => 'accepte', 'from' => '2000-01-01']), 'Facturer'];
    $bl = one("SELECT COUNT(*) n FROM documents WHERE type = 'bl' AND status = 'a_livrer'$dl");
    if ((int)$bl['n']) $alerts[] = ['info', (int)$bl['n'] . ' bon(s) de livraison à livrer.', url('delivery_notes', ['status' => 'a_livrer', 'from' => '2000-01-01']), 'Voir'];
}
?>
<?php if ($role === 'admin'): ?>
  <form class="x-ask" method="get" action="index.php">
    <input type="hidden" name="r" value="x">
    <span class="x-logo sm">X</span>
    <input name="q" placeholder="Demandez à X : « Quel transporteur nous doit le plus d’argent ? », « Ventes de BT 200+ en octobre ? »…" aria-label="Question pour X" autocomplete="off">
    <button class="btn sm primary"><?= icon('send') ?>Demander</button>
  </form>
<?php endif; ?>
<?php if ($alerts): ?>
  <div class="alerts">
    <?php foreach ($alerts as [$tone, $msg, $href, $label]): ?>
      <div class="alert <?= e($tone) ?>"><?= icon($tone === 'info' ? (str_contains($msg, 'devis') ? 'quote' : (str_contains($msg, 'réparation') ? 'tool' : 'truck')) : 'alert') ?><span><?= e($msg) ?></span><a href="<?= e($href) ?>"><?= e($label) ?></a></div>
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
