<?php
declare(strict_types=1);

require_perm('pos');
$u = user();
$showrooms = locations(true, 'showroom');
$scope = scope_loc();
if ($scope) {
    $locId = $scope;
} else {
    $locId = (int)(get('loc') ?: ($_SESSION['pos_loc'] ?? 0));
    $l = location($locId);
    if (!$l || $l['type'] !== 'showroom' || !$l['active']) $locId = (int)($showrooms[0]['id'] ?? 0);
    $_SESSION['pos_loc'] = $locId;
}
$loc = location($locId);
$a = (string)get('a');

if (!$loc) {
    layout_start('Caisse', 'pos');
    echo '<div class="empty"><h2>Aucun showroom actif</h2><p>Activez un showroom dans Paramètres › Emplacements.</p></div>';
    layout_end();
    exit;
}

/* ---------- Points d'accès JSON ---------- */

if ($a === 'clients') {
    $term = trim((string)get('q'));
    $rows = [];
    if (mb_strlen($term) >= 2) {
        $digits = normalize_phone($term);
        $rows = all('SELECT id, name, phone, gouvernorat, city, address FROM clients
                     WHERE name LIKE ? OR (? <> \'\' AND phone LIKE ?) ORDER BY name LIMIT 8',
            ['%' . $term . '%', $digits, '%' . $digits . '%']);
    }
    json_out(['ok' => true, 'clients' => $rows]);
}

if ($a === 'checkout' && is_post()) {
    csrf_check();
    $in = json_input();
    try {
        $res = sale_create($in);
        json_out(['ok' => true, 'id' => $res['id'], 'number' => $res['number'],
            'ticket' => url('ticket', ['id' => $res['id']]), 'invoice' => url('ticket', ['id' => $res['id'], 'format' => 'a4'])]);
    } catch (RuntimeException $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

/* ---------- Actions de formulaire ---------- */

if (is_post()) {
    csrf_check();
    try {
        if ($a === 'open') {
            session_open($locId, parse_money(post('float')) ?? 0);
            flash('ok', 'Caisse ouverte. Bonne journée !');
            redirect(url('pos', ['loc' => $locId]));
        }
        if ($a === 'expense') {
            $s = open_session($locId);
            if (!$s) throw new RuntimeException('La caisse est fermée.');
            $amount = parse_money(post('amount'));
            $label = (string)post('label');
            if (!$amount || $amount <= 0) throw new RuntimeException('Montant invalide.');
            if ($label === '') throw new RuntimeException('Indiquez le motif de la sortie.');
            $cat = (string)post('category');
            if (!isset(EXPENSE_CATEGORIES[$cat])) $cat = 'divers';
            $id = insert('expenses', [
                'date' => today(), 'location_id' => $locId, 'category' => $cat, 'label' => $label, 'amount' => $amount,
                'method' => 'especes', 'session_id' => $s['id'], 'supplier' => (string)post('supplier'), 'reference' => (string)post('reference'),
                'user_id' => $u['id'], 'created_at' => now(),
            ]);
            audit('sortie de caisse', 'expense', $id, $label . ' · ' . money($amount));
            flash('ok', 'Sortie de caisse enregistrée : ' . money($amount));
            redirect(url('pos', ['loc' => $locId]));
        }
        if ($a === 'close') {
            $s = open_session($locId);
            if (!$s) throw new RuntimeException('Aucune session ouverte.');
            $t = session_totals($s);
            $detail = [];
            $counted = 0;
            foreach (DENOMINATIONS as $value => $label) {
                $n = max(0, (int)($_POST['d'][$value] ?? 0));
                if ($n) $detail[$value] = $n;
                $counted += $n * $value;
            }
            $manual = parse_money(post('counted'));
            if (!$detail && $manual !== null) $counted = $manual;
            $diff = $counted - $t['expected_cash'];
            $note = (string)post('note');
            if (abs($diff) > (int)setting('cash_diff_threshold', 5000) && $note === '') {
                throw new RuntimeException('Écart de ' . money($diff) . ' : la justification est obligatoire.');
            }
            $keep = parse_money(post('keep')) ?? 0;
            $keep = max(0, min($keep, $counted));
            update('cash_sessions', [
                'status' => 'fermee', 'closed_by' => $u['id'], 'closed_at' => now(), 'expected_cash' => $t['expected_cash'],
                'counted_cash' => $counted, 'difference' => $diff, 'to_safe' => $counted - $keep, 'envelope' => (string)post('envelope'),
                'count_detail' => json_encode($detail), 'note' => $note,
            ], 'id = ?', [$s['id']]);
            audit('clôture de caisse', 'session', $s['id'], 'Compté ' . money($counted) . ' · écart ' . money($diff));
            flash($diff === 0 ? 'ok' : 'warn', 'Caisse clôturée. Écart : ' . money($diff) . '.');
            redirect(url('finances', ['a' => 'session', 'id' => $s['id']]));
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        redirect(url('pos', ['loc' => $locId, 'a' => $a === 'close' ? 'close' : null]));
    }
}

$session = open_session($locId);

$switch = '';
if (!$scope && count($showrooms) > 1) {
    $switch = '<form class="loc-switch" method="get"><input type="hidden" name="r" value="pos"><select name="loc" onchange="this.form.submit()" aria-label="Showroom">'
        . opt(location_options(true, 'showroom'), $locId) . '</select></form>';
}

/* ---------- Ouverture ---------- */

if (!$session) {
    $last = one("SELECT * FROM cash_sessions WHERE location_id = ? AND status = 'fermee' ORDER BY id DESC LIMIT 1", [$locId]);
    layout_start('Caisse · ' . $loc['name'], 'pos', ['actions' => $switch]);
    ?>
    <div class="center-card">
      <form class="card" method="post" action="<?= e(url('pos', ['a' => 'open', 'loc' => $locId])) ?>">
        <?= csrf_field() ?>
        <div class="card-ic"><?= icon('cash') ?></div>
        <h2>Ouvrir la caisse — <?= e($loc['name']) ?></h2>
        <p class="muted">Comptez le fond de caisse avant de commencer les ventes.</p>
        <label>Fond de caisse (DT)<input name="float" inputmode="decimal" value="<?= e(m_in((int)setting('default_float', 300000))) ?>" required></label>
        <?php if ($last): ?>
          <p class="hint">Dernière clôture : <?= fdate($last['closed_at'], true) ?> — compté <?= money($last['counted_cash']) ?>, versé au coffre <?= money($last['to_safe']) ?>, conservé <?= money((int)$last['counted_cash'] - (int)$last['to_safe']) ?>.</p>
        <?php endif; ?>
        <button class="btn primary block"><?= icon('check') ?>Ouvrir la session</button>
      </form>
    </div>
    <?php
    layout_end();
    exit;
}

$totals = session_totals($session);

/* ---------- Clôture ---------- */

if ($a === 'close') {
    layout_start('Clôture de caisse · ' . $loc['name'], 'pos');
    ?>
    <form method="post" action="<?= e(url('pos', ['a' => 'close', 'loc' => $locId])) ?>" class="grid2 close-form" data-billetage data-expected="<?= (int)$totals['expected_cash'] ?>" data-threshold="<?= (int)setting('cash_diff_threshold', 5000) ?>">
      <?= csrf_field() ?>
      <section class="card">
        <h2>Rapport Z — session du <?= fdate($session['opened_at'], true) ?></h2>
        <table class="tbl">
          <tr><td>Ventes</td><td class="num"><?= $totals['sales_count'] ?> · <?= money($totals['sales_total']) ?></td></tr>
          <?php foreach (PAY_METHODS as $m => $label): if (empty($totals['by_method'][$m])) continue; ?>
            <tr><td><?= e($label) ?></td><td class="num"><?= money($totals['by_method'][$m]) ?></td></tr>
          <?php endforeach; ?>
          <tr><td>Fond de caisse initial</td><td class="num"><?= money($session['opening_float']) ?></td></tr>
          <tr><td>Sorties de caisse (espèces)</td><td class="num">− <?= money($totals['cash_out']) ?></td></tr>
          <tr class="strong"><td>Espèces attendues dans le tiroir</td><td class="num"><?= money($totals['expected_cash']) ?></td></tr>
        </table>
        <p class="hint">Les paiements par carte, chèque, virement et mobile se rapprochent avec le relevé TPE, les chèques et les relevés bancaires.</p>
      </section>
      <section class="card">
        <h2>Billetage (comptage des espèces)</h2>
        <table class="tbl billetage">
          <thead><tr><th>Coupure</th><th>Nombre</th><th class="num">Montant</th></tr></thead>
          <tbody>
          <?php foreach (DENOMINATIONS as $value => $label): ?>
            <tr><td><?= e($label) ?></td><td><input type="number" min="0" step="1" name="d[<?= $value ?>]" data-den="<?= $value ?>" inputmode="numeric"></td><td class="num" data-sub>0,000</td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="strong"><td colspan="2">Total compté</td><td class="num" data-counted>0,000 DT</td></tr>
            <tr><td colspan="2">Écart (compté − attendu)</td><td class="num" data-diff>—</td></tr>
          </tfoot>
        </table>
        <label>Fond conservé pour la prochaine ouverture (DT)<input name="keep" inputmode="decimal" value="<?= e(m_in((int)setting('default_float', 300000))) ?>"></label>
        <label>N° d’enveloppe / sac scellé (coffre)<input name="envelope" maxlength="40"></label>
        <label>Justification de l’écart <span class="muted">(obligatoire au-delà de <?= money(setting('cash_diff_threshold', 5000)) ?>)</span><textarea name="note" rows="2"></textarea></label>
        <p class="hint" data-tosafe></p>
        <div class="row-actions">
          <a class="btn ghost" href="<?= e(url('pos', ['loc' => $locId])) ?>">Retour à la caisse</a>
          <button class="btn primary" onclick="return confirm('Clôturer définitivement la session de caisse ?')"><?= icon('lock') ?>Clôturer la caisse</button>
        </div>
      </section>
    </form>
    <?php
    layout_end();
    exit;
}

/* ---------- Écran de vente ---------- */

$rows = all("SELECT p.id, p.name, p.family, p.brand, p.price, p.promo_price, p.image, p.image_url, p.sku, p.barcode, p.warranty_months,
        COALESCE((SELECT qty FROM stock WHERE product_id = p.id AND location_id = ?), 0) AS here,
        COALESCE((SELECT SUM(qty) FROM stock WHERE product_id = p.id), 0) AS total
    FROM products p WHERE p.active = 1 ORDER BY p.name COLLATE NOCASE", [$locId]);
$products = array_map(fn($p) => [
    'id' => (int)$p['id'], 'n' => $p['name'], 'f' => $p['family'], 'b' => $p['brand'], 'p' => product_price($p), 'r' => (int)$p['price'],
    'img' => product_image($p), 'sku' => $p['sku'], 'bc' => $p['barcode'], 'q' => (int)$p['here'], 'qt' => (int)$p['total'],
    'w' => $p['warranty_months'] ? (int)$p['warranty_months'] : null,
], $rows);
$families = array_values(array_intersect(FAMILIES, array_unique(array_column($rows, 'family'))));
$campaigns = all("SELECT id, name FROM campaigns WHERE status IN ('active','planifiee') AND (location_id IS NULL OR location_id = ?) ORDER BY name", [$locId]);
$carriers = all('SELECT id, name, default_fee FROM carriers WHERE active = 1 ORDER BY type DESC, name');
$payMethods = PAY_METHODS;
unset($payMethods['cod']);

$data = [
    'loc' => ['id' => $locId, 'name' => $loc['name'], 'code' => $loc['code'], 'gouvernorat' => $loc['code'] === 'NAB' ? 'Nabeul' : 'Ariana'],
    'products' => $products, 'families' => $families,
    'campaigns' => $campaigns, 'carriers' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'fee' => (int)$c['default_fee']], $carriers),
    'sources' => SOURCES, 'methods' => $payMethods, 'gouvernorats' => GOUVERNORATS,
    'vat' => (float)setting('vat_rate', 19), 'timbre' => (int)setting('timbre', 1000),
    'maxDiscount' => $u['role'] === 'admin' ? null : (float)setting('max_discount_pct', 20),
    'urls' => ['checkout' => url('pos', ['a' => 'checkout', 'loc' => $locId]), 'clients' => url('pos', ['a' => 'clients', 'loc' => $locId])],
];

$actions = $switch
    . '<span class="session-pill" title="Session ouverte le ' . e(fdate($session['opened_at'], true)) . '">' . icon('cash') . 'Caisse ouverte · ' . date('H:i', strtotime($session['opened_at'])) . ' · ' . money($totals['sales_total'], true, 0) . '</span>'
    . '<button class="btn sm ghost" type="button" data-open="exp-dlg">' . icon('minus') . 'Sortie de caisse</button>'
    . '<a class="btn sm" href="' . e(url('pos', ['a' => 'close', 'loc' => $locId])) . '">' . icon('lock') . 'Clôturer</a>';

layout_start('Caisse · ' . $loc['name'], 'pos', ['actions' => $actions, 'wide' => true, 'body' => 'pos-page']);
?>
<div class="pos-layout" id="pos">
  <section class="pos-catalog">
    <div class="pos-search">
      <?= icon('search') ?>
      <input id="pos-q" type="search" placeholder="Rechercher un produit, une référence ou scanner un code-barres…" autocomplete="off" autofocus>
    </div>
    <div class="chips" id="pos-fams"></div>
    <div class="pgrid" id="pos-grid"></div>
  </section>

  <aside class="pos-cart" aria-label="Panier">
    <div class="cart-client">
      <div class="client-pick" id="client-pick">
        <input id="cl-phone" placeholder="Téléphone client" inputmode="tel" autocomplete="off">
        <input id="cl-name" placeholder="Nom du client" autocomplete="off">
        <div class="suggest" id="cl-suggest" hidden></div>
      </div>
      <div class="client-chip" id="client-chip" hidden></div>
      <details id="cl-more"><summary>Adresse (livraison, facture)</summary>
        <div class="grid2 tight">
          <select id="cl-gov"><option value="">Gouvernorat</option><?php foreach (GOUVERNORATS as $g) echo '<option>' . e($g) . '</option>'; ?></select>
          <input id="cl-city" placeholder="Ville / délégation">
        </div>
        <input id="cl-address" placeholder="Adresse">
      </details>
    </div>
    <div class="cart-lines" id="cart-lines"></div>
    <div class="cart-extra">
      <button class="btn sm ghost" type="button" data-open="free-dlg"><?= icon('plus') ?>Ligne libre</button>
      <button class="btn sm ghost" type="button" id="add-timbre"><?= icon('plus') ?>Timbre fiscal</button>
      <button class="btn sm ghost danger" type="button" id="cart-clear"><?= icon('trash') ?>Vider</button>
    </div>
    <div class="cart-totals">
      <div><span>Sous-total</span><b id="t-sub">0,000 DT</b></div>
      <div><span>Remise globale (DT)</span><input id="t-disc" inputmode="decimal" placeholder="0"></div>
      <div class="hint" id="t-save"></div>
      <div class="grand"><span>Total TTC</span><b id="t-total">0,000 DT</b></div>
    </div>
    <div class="cart-meta">
      <select id="sale-source" aria-label="Comment le client nous a connus"><option value="">Origine du client</option></select>
      <select id="sale-campaign" aria-label="Campagne"><option value="">Campagne (facultatif)</option></select>
    </div>
    <button class="btn primary xl block" id="btn-pay" type="button" disabled><?= icon('cash') ?>Encaisser</button>
  </aside>
</div>

<dialog id="pay-dlg" class="dlg wide">
  <form method="dialog" class="dlg-body" id="pay-form">
    <header><h2>Encaissement</h2><button class="icon-btn" value="cancel" formnovalidate><?= icon('x') ?></button></header>
    <div class="pay-total"><span>Total à payer</span><b id="p-total"></b></div>
    <div class="pay-methods" id="p-methods"></div>
    <div id="p-rows" class="pay-rows"></div>
    <div class="pay-cash" id="p-cash" hidden>
      <label>Espèces reçues du client<input id="p-given" inputmode="decimal"></label>
      <div class="change">Monnaie à rendre <b id="p-change">0,000 DT</b></div>
    </div>
    <div class="pay-rest" id="p-rest"></div>
    <label class="check"><input type="checkbox" id="d-on"> Livraison à domicile / expédition</label>
    <div id="d-box" class="grid2 tight" hidden>
      <label>Transporteur<select id="d-carrier"></select></label>
      <label>Date prévue<input type="date" id="d-date"></label>
      <label>Gouvernorat<select id="d-gov"><option value="">—</option><?php foreach (GOUVERNORATS as $g) echo '<option>' . e($g) . '</option>'; ?></select></label>
      <label>Ville<input id="d-city"></label>
      <label class="span2">Adresse<input id="d-address"></label>
      <label>Coût transporteur (DT)<input id="d-fee" inputmode="decimal" placeholder="0"></label>
      <label>À encaisser à la livraison (DT)<input id="d-cod" inputmode="decimal"></label>
    </div>
    <label>Note (facultatif)<input id="p-note" maxlength="300"></label>
    <div class="dlg-error" id="p-error" hidden></div>
    <footer>
      <button class="btn ghost" value="cancel" formnovalidate>Annuler</button>
      <button class="btn primary" id="p-submit" value="ok"><?= icon('check') ?>Valider la vente</button>
    </footer>
  </form>
</dialog>

<dialog id="done-dlg" class="dlg">
  <div class="dlg-body center">
    <div class="done-ic"><?= icon('check') ?></div>
    <h2 id="done-title">Vente enregistrée</h2>
    <p id="done-info" class="muted"></p>
    <p class="change-big" id="done-change" hidden></p>
    <div class="row-actions center">
      <a class="btn" id="done-ticket" target="_blank"><?= icon('print') ?>Ticket</a>
      <a class="btn" id="done-invoice" target="_blank"><?= icon('receipt') ?>Facture A4</a>
      <button class="btn primary" type="button" id="done-new"><?= icon('plus') ?>Nouvelle vente</button>
    </div>
  </div>
</dialog>

<dialog id="free-dlg" class="dlg">
  <form method="dialog" class="dlg-body" id="free-form">
    <header><h2>Ligne libre</h2><button class="icon-btn" value="cancel" formnovalidate><?= icon('x') ?></button></header>
    <p class="muted">Service, frais de livraison facturés, pièce hors catalogue…</p>
    <label>Libellé<input id="f-label" maxlength="120" required></label>
    <div class="grid2 tight">
      <label>Prix unitaire TTC (DT)<input id="f-price" inputmode="decimal" required></label>
      <label>Quantité<input id="f-qty" type="number" min="1" value="1"></label>
    </div>
    <label>TVA<select id="f-vat"><option value="19">19 %</option><option value="13">13 %</option><option value="7">7 %</option><option value="0">0 %</option></select></label>
    <footer><button class="btn ghost" value="cancel" formnovalidate>Annuler</button><button class="btn primary" value="ok">Ajouter</button></footer>
  </form>
</dialog>

<dialog id="exp-dlg" class="dlg">
  <form method="post" class="dlg-body" action="<?= e(url('pos', ['a' => 'expense', 'loc' => $locId])) ?>">
    <?= csrf_field() ?>
    <header><h2>Sortie de caisse</h2><button class="icon-btn" type="button" data-close><?= icon('x') ?></button></header>
    <p class="muted">Argent pris dans le tiroir (achat, transport, avance…). Gardez le justificatif.</p>
    <label>Montant (DT)<input name="amount" inputmode="decimal" required></label>
    <label>Motif<input name="label" maxlength="120" required></label>
    <label>Catégorie<select name="category"><?= opt(EXPENSE_CATEGORIES, 'divers') ?></select></label>
    <div class="grid2 tight">
      <label>Fournisseur / bénéficiaire<input name="supplier" maxlength="80"></label>
      <label>N° de justificatif<input name="reference" maxlength="40"></label>
    </div>
    <footer><button class="btn ghost" type="button" data-close>Annuler</button><button class="btn primary"><?= icon('check') ?>Enregistrer</button></footer>
  </form>
</dialog>

<script id="pos-data" type="application/json"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
layout_end(['pos.js']);
