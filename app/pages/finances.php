<?php
declare(strict_types=1);

require_perm('finance.view');
$a = (string)get('a', 'summary');
$scope = scope_loc();
$admin = is_admin();

function expense_editable(array $x): bool
{
    if (!can_loc($x['location_id'] !== null ? (int)$x['location_id'] : null)) return false;
    if (is_admin()) return true;
    if ($x['session_id']) return (bool)one("SELECT id FROM cash_sessions WHERE id = ? AND status = 'ouverte'", [$x['session_id']]);
    return true;
}

if (is_post()) {
    csrf_check();
    require_perm('expenses.edit');
    $id = (int)post('id');
    try {
        $x = $id ? one('SELECT * FROM expenses WHERE id = ?', [$id]) : null;
        if ($id && (!$x || !expense_editable($x))) throw new RuntimeException('Dépense non modifiable.');
        if ($a === 'expense_delete') {
            q('DELETE FROM expenses WHERE id = ?', [$id]);
            audit('dépense supprimée', 'expense', $id, $x['label'] . ' · ' . money($x['amount']));
            flash('ok', 'Dépense supprimée.');
            redirect(url('finances', ['a' => 'expenses']));
        }
        $amount = parse_money(post('amount'));
        $label = (string)post('label');
        if (!$amount || $amount <= 0) throw new RuntimeException('Montant invalide.');
        if ($label === '') throw new RuntimeException('Le libellé est obligatoire.');
        $cat = (string)post('category');
        $method = (string)post('method');
        $loc = $scope ?: ((int)post('location_id') ?: null);
        $data = [
            'date' => parse_date(post('date')) ?? today(), 'location_id' => $loc,
            'category' => isset(EXPENSE_CATEGORIES[$cat]) ? $cat : 'divers', 'label' => mb_substr($label, 0, 160), 'amount' => $amount,
            'method' => isset(PAY_METHODS[$method]) && $method !== 'cod' ? $method : 'especes',
            'supplier' => (string)post('supplier'), 'reference' => (string)post('reference'), 'note' => (string)post('note'),
        ];
        if ($x) {
            update('expenses', $data, 'id = ?', [$id]);
        } else {
            if (post('from_till') && $data['method'] === 'especes' && $loc) {
                $s = open_session($loc);
                if (!$s) throw new RuntimeException('La caisse de ce showroom est fermée : décochez « payé avec la caisse ».');
                $data['session_id'] = $s['id'];
            }
            $id = insert('expenses', $data + ['user_id' => user()['id'], 'created_at' => now()]);
        }
        audit('dépense enregistrée', 'expense', $id, $label . ' · ' . money($amount));
        flash('ok', 'Dépense enregistrée.');
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        back(url('finances', ['a' => 'expenses']));
    }
    redirect(url('finances', ['a' => 'expenses']));
}

/* ---------- Période ---------- */

$preset = (string)get('p', 'mois');
$presets = ['jour' => 'Aujourd’hui', '7j' => '7 derniers jours', 'mois' => 'Ce mois', 'mois_prec' => 'Mois dernier', 'annee' => 'Cette année', 'perso' => 'Personnalisée'];
[$from, $to] = match ($preset) {
    'jour' => [today(), today()],
    '7j' => [date('Y-m-d', strtotime('-6 days')), today()],
    'mois_prec' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    'annee' => [date('Y-01-01'), today()],
    'perso' => [parse_date(get('from')) ?? date('Y-m-01'), parse_date(get('to')) ?? today()],
    default => [date('Y-m-01'), today()],
};
$locF = $scope ?? (int)get('loc');
$periodForm = function (string $route) use ($preset, $presets, $from, $to, $scope, $locF, $a): string {
    $h = '<form class="filters" method="get"><input type="hidden" name="r" value="finances"><input type="hidden" name="a" value="' . e($a) . '">'
        . '<label>Période<select name="p" data-period>' . opt($presets, $preset) . '</select></label>'
        . '<label data-custom' . ($preset === 'perso' ? '' : ' hidden') . '>Du<input type="date" name="from" value="' . e($from) . '"></label>'
        . '<label data-custom' . ($preset === 'perso' ? '' : ' hidden') . '>Au<input type="date" name="to" value="' . e($to) . '"></label>';
    if (!$scope) $h .= '<label>Site<select name="loc">' . opt(location_options(false), $locF, true, 'Tous') . '</select></label>';
    return $h . '<button class="btn">' . icon('search') . 'Afficher</button><span class="muted period-label">' . fdate($from) . ' → ' . fdate($to) . '</span></form>';
};

$tabsHtml = tabs([
    'summary' => ['Synthèse', url('finances')],
    'sessions' => ['Caisses & clôtures', url('finances', ['a' => 'sessions'])],
    'expenses' => ['Dépenses', url('finances', ['a' => 'expenses'])],
    'receivables' => ['À encaisser', url('finances', ['a' => 'receivables'])],
], $a === 'session' ? 'sessions' : ($a === 'expense_edit' ? 'expenses' : $a));

/* ---------- Rapport de session (Z) ---------- */

if ($a === 'session') {
    $s = one('SELECT cs.*, l.name loc, uo.name opener, uc.name closer FROM cash_sessions cs JOIN locations l ON l.id = cs.location_id
        LEFT JOIN users uo ON uo.id = cs.opened_by LEFT JOIN users uc ON uc.id = cs.closed_by WHERE cs.id = ?', [(int)get('id')]);
    if (!$s || !can_loc((int)$s['location_id'])) redirect(url('finances', ['a' => 'sessions']));
    $t = session_totals($s);
    $sales = all('SELECT s.*, c.name client FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE s.session_id = ? ORDER BY s.id', [$s['id']]);
    $outs = all('SELECT * FROM expenses WHERE session_id = ? ORDER BY id', [$s['id']]);
    $detail = json_decode((string)$s['count_detail'], true) ?: [];
    layout_start('Rapport de caisse n° ' . $s['id'], 'finances', ['actions' => '<button class="btn sm" onclick="window.print()">' . icon('print') . 'Imprimer</button>']);
    echo $tabsHtml;
    ?>
    <div class="grid4 kpis">
      <div class="kpi"><span>Ventes</span><b><?= $t['sales_count'] ?></b><small><?= money($t['sales_total']) ?></small></div>
      <div class="kpi"><span>Espèces attendues</span><b><?= money($s['status'] === 'fermee' ? $s['expected_cash'] : $t['expected_cash']) ?></b></div>
      <div class="kpi"><span>Espèces comptées</span><b><?= $s['status'] === 'fermee' ? money($s['counted_cash']) : '—' ?></b></div>
      <div class="kpi"><span>Écart</span><b class="<?= (int)$s['difference'] < 0 ? 'neg' : ((int)$s['difference'] > 0 ? 'warn-t' : '') ?>"><?= $s['status'] === 'fermee' ? money($s['difference']) : 'Session ouverte' ?></b></div>
    </div>
    <div class="grid2">
      <section class="card">
        <h2><?= e($s['loc']) ?></h2>
        <dl class="kv">
          <dt>Ouverture</dt><dd><?= fdate($s['opened_at'], true) ?> par <?= e($s['opener']) ?> · fond <?= money($s['opening_float']) ?></dd>
          <dt>Clôture</dt><dd><?= $s['closed_at'] ? fdate($s['closed_at'], true) . ' par ' . e($s['closer']) : '<span class="badge ok">Ouverte</span>' ?></dd>
          <?php if ($s['status'] === 'fermee'): ?>
            <dt>Versé au coffre</dt><dd><?= money($s['to_safe']) ?><?= $s['envelope'] ? ' · enveloppe n° ' . e($s['envelope']) : '' ?></dd>
            <dt>Fond conservé</dt><dd><?= money((int)$s['counted_cash'] - (int)$s['to_safe']) ?></dd>
            <?php if ($s['note']): ?><dt>Justification</dt><dd><?= e($s['note']) ?></dd><?php endif; ?>
          <?php endif; ?>
        </dl>
        <h3>Encaissements par mode</h3>
        <table class="tbl">
          <?php foreach (PAY_METHODS as $m => $label): if (empty($t['by_method'][$m])) continue; ?>
            <tr><td><?= e($label) ?></td><td class="num"><?= money($t['by_method'][$m]) ?></td></tr>
          <?php endforeach; ?>
          <tr><td>Sorties de caisse (espèces)</td><td class="num">− <?= money($t['cash_out']) ?></td></tr>
        </table>
      </section>
      <section class="card">
        <h2>Billetage</h2>
        <?php if ($detail): ?>
          <table class="tbl"><?php foreach ($detail as $v => $n): ?><tr><td><?= e(DENOMINATIONS[$v] ?? money($v)) ?></td><td class="num">× <?= (int)$n ?></td><td class="num"><?= money((int)$v * (int)$n) ?></td></tr><?php endforeach; ?></table>
        <?php else: ?><p class="muted"><?= $s['status'] === 'fermee' ? 'Montant saisi globalement.' : 'Le billetage est saisi à la clôture.' ?></p><?php endif; ?>
        <?php if ($outs): ?>
          <h3>Sorties de caisse</h3>
          <table class="tbl"><?php foreach ($outs as $o): ?><tr><td><?= e($o['label']) ?><br><small class="muted"><?= e(EXPENSE_CATEGORIES[$o['category']] ?? '') ?> <?= e($o['supplier']) ?></small></td><td class="num"><?= money($o['amount']) ?></td></tr><?php endforeach; ?></table>
        <?php endif; ?>
      </section>
    </div>
    <section class="card flush">
      <div class="card-head"><b>Ventes de la session</b></div>
      <table class="tbl hover">
        <?php foreach ($sales as $v): ?>
          <tr data-href="<?= e(url('sales', ['a' => 'view', 'id' => $v['id']])) ?>"><td><?= e($v['number']) ?></td><td><?= date('H:i', strtotime($v['created_at'])) ?></td><td><?= e($v['client'] ?: '—') ?></td>
            <td class="num"><?= money($v['total']) ?></td><td><?= $v['status'] === 'annulee' ? badge('Annulée', 'err') : ($v['status'] === 'partielle' ? badge('Reste ' . money((int)$v['total'] - (int)$v['paid']), 'warn') : '') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$sales): ?><tr><td class="empty-row">Aucune vente.</td></tr><?php endif; ?>
      </table>
    </section>
    <?php
    layout_end();
    exit;
}

/* ---------- Sessions ---------- */

if ($a === 'sessions') {
    $where = 'date(cs.opened_at) BETWEEN ? AND ?';
    $p = [$from, $to];
    if ($locF) { $where .= ' AND cs.location_id = ?'; $p[] = $locF; }
    $rows = all("SELECT cs.*, l.name loc, u.name opener, (SELECT COUNT(*) FROM sales WHERE session_id = cs.id AND status <> 'annulee') n,
        (SELECT COALESCE(SUM(total),0) FROM sales WHERE session_id = cs.id AND status <> 'annulee') t
        FROM cash_sessions cs JOIN locations l ON l.id = cs.location_id LEFT JOIN users u ON u.id = cs.opened_by WHERE $where ORDER BY cs.id DESC", $p);
    $actions = can('sessions.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'caisses', 'from' => $from, 'to' => $to, 'loc' => $locF ?: null])) . '">' . icon('download') . 'Export CSV</a>' : '';
    layout_start('Finances', 'finances', ['actions' => $actions]);
    echo $tabsHtml . $periodForm('sessions');
    $sumDiff = array_sum(array_map(fn($r) => (int)$r['difference'], $rows));
    $sumSafe = array_sum(array_map(fn($r) => (int)$r['to_safe'], $rows));
    ?>
    <div class="grid3 kpis">
      <div class="kpi"><span>Sessions</span><b><?= count($rows) ?></b></div>
      <div class="kpi"><span>Versé au coffre</span><b><?= money0($sumSafe) ?></b></div>
      <div class="kpi"><span>Écarts cumulés</span><b class="<?= $sumDiff < 0 ? 'neg' : '' ?>"><?= money($sumDiff) ?></b></div>
    </div>
    <div class="card flush">
      <table class="tbl hover">
        <thead><tr><th>N°</th><th>Showroom</th><th>Ouverture</th><th>Clôture</th><th class="num">Ventes</th><th class="num">Espèces attendues</th><th class="num">Comptées</th><th class="num">Écart</th><th class="num">Au coffre</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr data-href="<?= e(url('finances', ['a' => 'session', 'id' => $r['id']])) ?>">
            <td><a href="<?= e(url('finances', ['a' => 'session', 'id' => $r['id']])) ?>"><?= (int)$r['id'] ?></a></td><td><?= e($r['loc']) ?></td>
            <td><?= fdate($r['opened_at'], true) ?><br><small class="muted"><?= e($r['opener']) ?></small></td>
            <td><?= $r['closed_at'] ? fdate($r['closed_at'], true) : badge('Ouverte', 'ok') ?></td>
            <td class="num"><?= (int)$r['n'] ?><br><small class="muted"><?= money0($r['t']) ?></small></td>
            <td class="num"><?= $r['status'] === 'fermee' ? money($r['expected_cash']) : '' ?></td>
            <td class="num"><?= $r['status'] === 'fermee' ? money($r['counted_cash']) : '' ?></td>
            <td class="num <?= (int)$r['difference'] < 0 ? 'neg' : ((int)$r['difference'] > 0 ? 'warn-t' : '') ?>"><?= $r['status'] === 'fermee' ? money($r['difference']) : '' ?></td>
            <td class="num"><?= $r['status'] === 'fermee' ? money($r['to_safe']) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty-row">Aucune session sur cette période.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    layout_end();
    exit;
}

/* ---------- Dépenses ---------- */

if ($a === 'expenses' || $a === 'expense_edit') {
    $edit = null;
    if ($a === 'expense_edit') {
        require_perm('expenses.edit');
        $edit = (int)get('id') ? one('SELECT * FROM expenses WHERE id = ?', [(int)get('id')]) : ['id' => 0, 'date' => today(), 'location_id' => $scope, 'category' => 'divers', 'label' => '', 'amount' => null, 'method' => 'especes', 'supplier' => '', 'reference' => '', 'note' => '', 'session_id' => null];
        if (!$edit || ($edit['id'] && !expense_editable($edit))) redirect(url('finances', ['a' => 'expenses']));
    }
    $cat = (string)get('cat');
    $where = 'e.date BETWEEN ? AND ?';
    $p = [$from, $to];
    if ($locF) { $where .= ' AND e.location_id = ?'; $p[] = $locF; }
    if ($cat !== '') { $where .= ' AND e.category = ?'; $p[] = $cat; }
    $rows = all("SELECT e.*, l.name loc, u.name user FROM expenses e LEFT JOIN locations l ON l.id = e.location_id LEFT JOIN users u ON u.id = e.user_id WHERE $where ORDER BY e.date DESC, e.id DESC LIMIT 500", $p);
    $byCat = all("SELECT e.category, SUM(e.amount) t FROM expenses e WHERE $where GROUP BY e.category ORDER BY t DESC", $p);
    $actions = (can('expenses.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'depenses', 'from' => $from, 'to' => $to, 'loc' => $locF ?: null])) . '">' . icon('download') . 'Export CSV</a>' : '')
        . (can('expenses.import') ? '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'depenses'])) . '">' . icon('upload') . 'Import CSV</a>' : '')
        . (can('expenses.edit') ? '<a class="btn sm primary" href="' . e(url('finances', ['a' => 'expense_edit'])) . '">' . icon('plus') . 'Nouvelle dépense</a>' : '');
    layout_start('Finances', 'finances', ['actions' => $actions]);
    echo $tabsHtml;
    if ($edit): ?>
      <form method="post" action="<?= e(url('finances', ['a' => 'expense_save'])) ?>" class="card form-grid">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <h2 class="span2"><?= $edit['id'] ? 'Modifier la dépense' : 'Nouvelle dépense' ?></h2>
        <label>Date<input type="date" name="date" value="<?= e($edit['date']) ?>" required></label>
        <?php if (!$scope): ?><label>Site<select name="location_id"><?= opt(location_options(false), $edit['location_id'], true, 'Général / siège') ?></select></label><?php endif; ?>
        <label>Catégorie<select name="category"><?= opt(EXPENSE_CATEGORIES, $edit['category']) ?></select></label>
        <label>Montant (DT)<input name="amount" inputmode="decimal" value="<?= e(m_in($edit['amount'])) ?>" required></label>
        <label class="span2">Libellé<input name="label" value="<?= e($edit['label']) ?>" required maxlength="160"></label>
        <label>Mode de paiement<select name="method"><?php $pm = PAY_METHODS; unset($pm['cod']); echo opt($pm, $edit['method']); ?></select></label>
        <label>Fournisseur / bénéficiaire<input name="supplier" value="<?= e($edit['supplier']) ?>"></label>
        <label>N° de facture / justificatif<input name="reference" value="<?= e($edit['reference']) ?>"></label>
        <label>Note<input name="note" value="<?= e($edit['note']) ?>"></label>
        <?php if (!$edit['id']): ?><label class="check span2"><input type="checkbox" name="from_till" value="1"> Payé en espèces avec la caisse du showroom (sortie de caisse, comptée à la clôture)</label><?php endif; ?>
        <div class="span2 row-actions"><a class="btn ghost" href="<?= e(url('finances', ['a' => 'expenses'])) ?>">Annuler</a><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div>
      </form>
      <?php if ($edit['id']): ?>
        <form method="post" action="<?= e(url('finances', ['a' => 'expense_delete'])) ?>" class="danger-inline" onsubmit="return confirm('Supprimer cette dépense ?')">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn ghost danger sm"><?= icon('trash') ?>Supprimer</button>
        </form>
      <?php endif;
    endif;
    echo $periodForm('expenses');
    ?>
    <div class="grid2">
      <section class="card"><div class="card-title"><h2>Dépenses par catégorie</h2><b><?= money(array_sum(array_column($byCat, 't'))) ?></b></div>
        <?= bar_list(array_map(fn($r) => [EXPENSE_CATEGORIES[$r['category']] ?? $r['category'], (int)$r['t'], money0($r['t'])], $byCat), 'Aucune dépense sur la période.') ?></section>
      <section class="card"><h2>Filtrer par catégorie</h2>
        <div class="chips"><a class="chip <?= $cat === '' ? 'on' : '' ?>" href="<?= e(url('finances', ['a' => 'expenses', 'p' => $preset, 'from' => $from, 'to' => $to, 'loc' => $scope ? null : ($locF ?: null)])) ?>">Toutes</a>
          <?php foreach (EXPENSE_CATEGORIES as $k => $l): ?><a class="chip <?= $cat === $k ? 'on' : '' ?>" href="<?= e(url('finances', ['a' => 'expenses', 'cat' => $k, 'p' => $preset, 'from' => $from, 'to' => $to, 'loc' => $scope ? null : ($locF ?: null)])) ?>"><?= e($l) ?></a><?php endforeach; ?></div></section>
    </div>
    <div class="card flush">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Libellé</th><th>Catégorie</th><th>Site</th><th>Mode</th><th class="num">Montant</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= fdate($r['date']) ?></td><td><b><?= e($r['label']) ?></b><?= $r['supplier'] || $r['reference'] ? '<br><small class="muted">' . e(trim($r['supplier'] . ' ' . $r['reference'])) . '</small>' : '' ?><?= $r['session_id'] ? ' ' . badge('caisse', 'info') : '' ?></td>
            <td><?= e(EXPENSE_CATEGORIES[$r['category']] ?? $r['category']) ?></td><td><?= e($r['loc'] ?: 'Général') ?></td>
            <td><?= e(PAY_METHODS[$r['method']] ?? $r['method']) ?></td><td class="num"><?= money($r['amount']) ?></td>
            <td class="num"><?php if (can('expenses.edit') && expense_editable($r)): ?><a class="icon-btn xs" title="Modifier" href="<?= e(url('finances', ['a' => 'expense_edit', 'id' => $r['id']])) ?>"><?= icon('edit') ?></a><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty-row">Aucune dépense sur cette période.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    layout_end();
    exit;
}

/* ---------- À encaisser ---------- */

if ($a === 'receivables') {
    $rows = all("SELECT s.*, c.name client, c.phone, l.name loc, CAST(julianday('now') - julianday(s.created_at) AS INTEGER) age,
        (SELECT MAX(created_at) FROM payments WHERE sale_id = s.id) last_pay
        FROM sales s LEFT JOIN clients c ON c.id = s.client_id JOIN locations l ON l.id = s.location_id
        WHERE s.status = 'partielle'" . ($locF ? ' AND s.location_id = ' . (int)$locF : '') . ' ORDER BY s.created_at');
    $cod = all("SELECT sh.*, ca.name carrier FROM shipments sh LEFT JOIN carriers ca ON ca.id = sh.carrier_id
        WHERE sh.status = 'livree' AND sh.cod_collected = 0 AND sh.cod_amount > 0" . ($locF ? ' AND sh.location_id = ' . (int)$locF : '') . ' ORDER BY sh.delivered_at');
    $actions = can('sales.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'creances'])) . '">' . icon('download') . 'Export CSV</a>' : '';
    layout_start('Finances', 'finances', ['actions' => $actions]);
    echo $tabsHtml;
    ?>
    <div class="grid3 kpis">
      <div class="kpi"><span>Reste à payer (clients)</span><b><?= money0(array_sum(array_map(fn($r) => (int)$r['total'] - (int)$r['paid'], $rows))) ?></b><small><?= count($rows) ?> vente(s)</small></div>
      <div class="kpi"><span>Chez les transporteurs</span><b><?= money0(array_sum(array_column($cod, 'cod_amount'))) ?></b><small><?= count($cod) ?> livraison(s) livrée(s) non reversée(s)</small></div>
      <div class="kpi"><span>Plus ancienne créance</span><b><?= $rows ? (int)max(array_column($rows, 'age')) . ' j' : '—' ?></b></div>
    </div>
    <section class="card flush">
      <div class="card-head"><b>Ventes avec reste à payer (facilités, acomptes)</b></div>
      <table class="tbl hover">
        <thead><tr><th>Vente</th><th>Client</th><th>Showroom</th><th class="num">Total</th><th class="num">Payé</th><th class="num">Reste</th><th class="num">Ancienneté</th></tr></thead>
        <?php foreach ($rows as $r): ?>
          <tr data-href="<?= e(url('sales', ['a' => 'view', 'id' => $r['id']])) ?>"><td><b><?= e($r['number']) ?></b><br><small class="muted"><?= fdate($r['created_at']) ?></small></td>
            <td><?= e($r['client'] ?: '—') ?><?= $r['phone'] ? '<br><a href="tel:' . e($r['phone']) . '"><small>' . e($r['phone']) . '</small></a>' : '' ?></td><td><?= e($r['loc']) ?></td>
            <td class="num"><?= money($r['total']) ?></td><td class="num"><?= money($r['paid']) ?></td><td class="num"><b class="warn-t"><?= money((int)$r['total'] - (int)$r['paid']) ?></b></td>
            <td class="num <?= (int)$r['age'] > 30 ? 'neg' : '' ?>"><?= (int)$r['age'] ?> j<?= $r['last_pay'] ? '<br><small class="muted">dernier versement ' . fdate($r['last_pay']) . '</small>' : '' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty-row">Aucune créance client. 👍</td></tr><?php endif; ?>
      </table>
    </section>
    <section class="card flush">
      <div class="card-head"><b>Paiements à la livraison à récupérer auprès des transporteurs</b></div>
      <table class="tbl">
        <thead><tr><th>Livraison</th><th>Transporteur</th><th>Destinataire</th><th>Livrée le</th><th class="num">Montant</th></tr></thead>
        <?php foreach ($cod as $r): ?>
          <tr><td><a href="<?= e(url('deliveries', ['a' => 'edit', 'id' => $r['id']])) ?>"><?= e($r['number']) ?></a></td><td><?= e($r['carrier'] ?: '—') ?></td><td><?= e($r['recipient']) ?></td><td><?= fdate($r['delivered_at']) ?></td><td class="num"><?= money($r['cod_amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$cod): ?><tr><td colspan="5" class="empty-row">Rien à récupérer.</td></tr><?php endif; ?>
      </table>
    </section>
    <?php
    layout_end();
    exit;
}

/* ---------- Synthèse ---------- */

$ls = $locF ? ' AND s.location_id = ' . (int)$locF : '';
$sales = one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t, COALESCE(SUM(discount),0) d, COALESCE(SUM(catalog_total - total),0) disc_all
    FROM sales s WHERE status <> 'annulee' AND date(created_at) BETWEEN ? AND ?$ls", [$from, $to]);
$lines = one("SELECT COALESCE(SUM(si.total * (CAST(s.total AS REAL) / MAX(s.subtotal, 1)) / (1 + si.vat_rate / 100.0)), 0) ht,
    COALESCE(SUM(CASE WHEN si.unit_cost IS NOT NULL THEN si.unit_cost * si.qty END), 0) cost,
    COALESCE(SUM(CASE WHEN si.unit_cost IS NOT NULL THEN si.total * (CAST(s.total AS REAL) / MAX(s.subtotal, 1)) / (1 + si.vat_rate / 100.0) END), 0) ht_costed
    FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ?$ls", [$from, $to]);
$pl = $locF ? ' AND p.location_id = ' . (int)$locF : '';
$byMethod = all("SELECT method, SUM(amount) t, COUNT(*) n FROM payments p WHERE date(created_at) BETWEEN ? AND ?$pl GROUP BY method ORDER BY t DESC", [$from, $to]);
$collected = array_sum(array_map(fn($r) => (int)$r['t'], $byMethod));
$el = $locF ? ' AND e.location_id = ' . (int)$locF : '';
$exp = one("SELECT COALESCE(SUM(amount),0) t, COALESCE(SUM(CASE WHEN category = 'achats' THEN amount END),0) achats FROM expenses e WHERE date BETWEEN ? AND ?$el", [$from, $to]);
$fees = (int)val("SELECT COALESCE(SUM(fee),0) FROM shipments WHERE status <> 'annulee' AND date(created_at) BETWEEN ? AND ?" . ($locF ? ' AND location_id = ' . (int)$locF : ''), [$from, $to]);
$byLoc = all("SELECT l.id, l.name,
    (SELECT COUNT(*) FROM sales s WHERE s.location_id = l.id AND s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ?) n,
    (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.location_id = l.id AND s.status <> 'annulee' AND date(s.created_at) BETWEEN ? AND ?) t,
    (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.location_id = l.id AND date(p.created_at) BETWEEN ? AND ?) enc,
    (SELECT COALESCE(SUM(amount),0) FROM expenses e WHERE e.location_id = l.id AND e.date BETWEEN ? AND ?) dep
    FROM locations l WHERE l.active = 1" . ($locF ? ' AND l.id = ' . (int)$locF : '') . ' ORDER BY l.position', array_merge(...array_fill(0, 4, [$from, $to])));
$general = $locF ? 0 : (int)val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE location_id IS NULL AND date BETWEEN ? AND ?', [$from, $to]);
$margin = (int)round((float)$lines['ht_costed']) - (int)$lines['cost'];
$coverage = (float)$lines['ht'] > 0 ? (float)$lines['ht_costed'] / (float)$lines['ht'] * 100 : 0;
$opex = (int)$exp['t'] - (int)$exp['achats'];

$actions = can('payments.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'paiements', 'from' => $from, 'to' => $to, 'loc' => $locF ?: null])) . '">' . icon('download') . 'Paiements CSV</a>' : '';
layout_start('Finances', 'finances', ['actions' => $actions]);
echo $tabsHtml . $periodForm('summary');
?>
<div class="grid4 kpis">
  <div class="kpi hero"><span>Chiffre d’affaires TTC</span><b><?= money0($sales['t']) ?></b><small><?= (int)$sales['n'] ?> vente(s) · HT <?= money0((int)round((float)$lines['ht'])) ?></small></div>
  <div class="kpi"><span>Encaissements</span><b><?= money0($collected) ?></b><small>tous modes, remboursements déduits</small></div>
  <div class="kpi"><span>Dépenses</span><b><?= money0($exp['t']) ?></b><small><?= (int)$exp['achats'] ? 'dont achats marchandises ' . money0($exp['achats']) : 'saisies dans Dépenses' ?></small></div>
  <div class="kpi"><span>Trésorerie de la période</span><b class="<?= $collected - (int)$exp['t'] < 0 ? 'neg' : '' ?>"><?= money0($collected - (int)$exp['t']) ?></b><small>encaissements − dépenses</small></div>
</div>
<?php if ($admin): ?>
  <div class="grid3 kpis">
    <div class="kpi"><span>Marge brute estimée (HT)</span><b><?= money0($margin) ?></b><small>sur <?= pct($coverage) ?> du CA (produits avec prix d’achat renseigné)</small></div>
    <div class="kpi"><span>Remises accordées</span><b><?= money0($sales['disc_all']) ?></b><small>écart prix catalogue → prix vendu</small></div>
    <div class="kpi"><span>Résultat estimé</span><b class="<?= $margin - $opex - $fees < 0 ? 'neg' : '' ?>"><?= money0($margin - $opex - $fees) ?></b><small>marge brute − dépenses (hors achats) − transport</small></div>
  </div>
<?php endif; ?>
<div class="grid2">
  <section class="card">
    <div class="card-title"><h2>Encaissements par mode de paiement</h2></div>
    <?= bar_list(array_map(fn($r) => [PAY_METHODS[$r['method']] ?? $r['method'], max(0, (int)$r['t']), money($r['t']), (int)$r['n'] . ' opération(s)'], $byMethod), 'Aucun encaissement sur la période.') ?>
  </section>
  <section class="card flush">
    <div class="card-head"><b>Par site</b></div>
    <table class="tbl">
      <thead><tr><th>Site</th><th class="num">Ventes</th><th class="num">CA TTC</th><th class="num">Encaissé</th><th class="num">Dépenses</th></tr></thead>
      <?php foreach ($byLoc as $r): ?>
        <tr><td><?= e($r['name']) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= money0($r['t']) ?></td><td class="num"><?= money0($r['enc']) ?></td><td class="num"><?= money0($r['dep']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($general): ?><tr><td>Général / siège</td><td></td><td></td><td></td><td class="num"><?= money0($general) ?></td></tr><?php endif; ?>
      <tr class="strong"><td>Frais de transport (livraisons)</td><td></td><td></td><td></td><td class="num"><?= money0($fees) ?></td></tr>
    </table>
  </section>
</div>
<?php
layout_end();
