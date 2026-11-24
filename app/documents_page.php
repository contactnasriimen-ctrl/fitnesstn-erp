<?php
declare(strict_types=1);

// Devis, factures et bons de livraison : liste, saisie et fiche.
// $DOC_TYPE est fixé par pages/quotes.php, pages/invoices.php ou pages/delivery_notes.php.
require_perm('invoices.view');
$type = $DOC_TYPE;
$isQuote = $type === 'devis';
$isInvoice = $type === 'facture';
$isBl = $type === 'bl';
$fem = doc_fem($type);
$route = doc_route($type);
$label = DOC_TYPES[$type];
$plural = ['devis' => 'Devis', 'facture' => 'Factures', 'bl' => 'Bons de livraison'][$type];
$this_ = ['devis' => 'ce devis', 'facture' => 'cette facture', 'bl' => 'ce bon de livraison'][$type];
$newLabel = ['devis' => 'Nouveau devis', 'facture' => 'Nouvelle facture', 'bl' => 'Nouveau bon de livraison'][$type];
$a = (string)get('a', 'list');
$scope = doc_scope(); // null quand les factures sont partagées par toute l'équipe

/* ---------- Points d'accès JSON ---------- */

if ($a === 'clients') {
    $term = trim((string)get('q'));
    $rows = [];
    if (mb_strlen($term) >= 2) {
        $digits = normalize_phone($term);
        $rows = all("SELECT id, name, phone, email, mf, address, city, gouvernorat FROM clients
                     WHERE name LIKE ? OR (? <> '' AND phone LIKE ?) ORDER BY name LIMIT 8", ['%' . $term . '%', $digits, '%' . $digits . '%']);
    }
    json_out(['ok' => true, 'clients' => $rows]);
}

/* ---------- Actions ---------- */

if (is_post()) {
    csrf_check();
    $id = (int)post('id');
    $back = fn(int $docId, string $r = '') => url($r ?: $route, ['a' => 'view', 'id' => $docId]);
    try {
        if ($a === 'from_sale') {
            require_perm('invoices.edit');
            $newId = doc_from_sale((int)post('sale_id'));
            flash('ok', 'Facture établie à partir de la vente.');
            redirect(url('invoices', ['a' => 'view', 'id' => $newId]));
        }
        if ($a === 'save') {
            require_perm('invoices.edit');
            $newId = doc_save([
                'type' => $type, 'id' => $id, 'location_id' => post('location_id'), 'client' => (array)($_POST['client'] ?? []),
                'date' => post('date'), 'due_date' => post('due_date'), 'items' => array_values((array)($_POST['items'] ?? [])),
                'discount' => post('discount'), 'timbre' => post('timbre'), 'payment_mode' => post('payment_mode'), 'terms' => post('terms'),
                'transport' => post('transport'), 'transporter' => (array)($_POST['transporter'] ?? []),
                'warranty' => post('warranty'), 'notes' => post('notes'), 'internal_note' => post('internal_note'), 'stock' => post('stock'),
            ]);
            flash('ok', $label . ($id ? ' enregistré' : ' créé') . ($fem ? 'e.' : '.'));
            redirect($back($newId));
        }
        if ($a === 'bulk_delete') {
            require_perm('invoices.edit');
            [$done, $errors] = doc_delete_many((array)($_POST['ids'] ?? []), $type);
            if ($done) flash('ok', $done . ' ' . ['devis' => 'devis supprimé(s)', 'facture' => 'facture(s) supprimée(s)', 'bl' => 'bon(s) de livraison supprimé(s)'][$type] . '.');
            if ($errors) flash('err', implode(' — ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? ' (+' . (count($errors) - 3) . ')' : ''));
            if (!$done && !$errors) flash('err', 'Aucun document sélectionné.');
            redirect((string)(post('back') ?: url($route)));
        }
        $d = doc_get($id, $type);
        if (!$d) throw new RuntimeException('Document introuvable.');
        require_perm('invoices.edit');
        if (!doc_can_loc($d['location_id'] !== null ? (int)$d['location_id'] : null)) throw new RuntimeException('Document introuvable.');
        switch ($a) {
            case 'status':
                doc_set_status($d, (string)post('status'));
                flash('ok', 'Statut mis à jour : ' . DOC_STATUS[$type][(string)post('status')] . '.');
                break;
            case 'convert':
                $to = post('to') === 'bl' ? 'bl' : 'facture';
                $newId = doc_copy($d, $to, (bool)post('stock'));
                flash('ok', ($to === 'bl' ? 'Bon de livraison créé' : 'Facture créée') . ' à partir ' . ['devis' => 'du devis ', 'facture' => 'de la facture ', 'bl' => 'du bon de livraison '][$type] . $d['number'] . '.');
                redirect(url(doc_route($to), ['a' => 'view', 'id' => $newId]));
            case 'duplicate':
                $newId = doc_copy($d, $type);
                flash('ok', $label . ' dupliqué' . ($fem ? 'e' : '') . ' : vérifiez les prix et la date.');
                redirect(url($route, ['a' => 'edit', 'id' => $newId]));
            case 'pay':
                doc_add_payment($d, (string)post('method'), (int)parse_money(post('amount')), (string)post('reference'), parse_date(post('date')));
                flash('ok', 'Paiement enregistré.');
                break;
            case 'pay_delete':
                doc_delete_payment($d, (int)post('payment_id'));
                flash('ok', 'Paiement supprimé.');
                break;
            case 'cancel':
                doc_cancel($d, (string)post('reason'));
                flash('ok', 'Facture annulée' . ($d['stock_applied'] ? ' : articles remis en stock.' : '.'));
                break;
            case 'delete':
                $wasLast = !$isQuote && doc_is_last($d);
                doc_delete($d);
                flash('ok', $label . ' ' . $d['number'] . ' supprimé' . ($fem ? 'e' : '')
                    . ($wasLast ? ' : son numéro sera repris par ' . ($isBl ? 'le prochain bon de livraison.' : 'la prochaine facture.') : '.'));
                redirect(url($route));
            case 'internal_note':
                update('documents', ['internal_note' => mb_substr((string)post('internal_note'), 0, 1000), 'updated_at' => now()], 'id = ?', [$d['id']]);
                flash('ok', 'Note enregistrée.');
                break;
        }
        redirect($back($id));
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        if ($a === 'save') {
            $_SESSION['doc_form'] = $_POST;
            redirect(url($route, ['a' => 'edit', 'id' => $id ?: null, 'retry' => 1]));
        }
        back(url($route));
    }
}

/* ---------- Saisie ---------- */

if ($a === 'edit') {
    require_perm('invoices.edit');
    $id = (int)get('id');
    $d = $id ? doc_get($id, $type) : null;
    if ($id && !$d) redirect(url($route));
    if ($d && !doc_editable($d)) {
        flash('err', 'Ce document ne peut plus être modifié.');
        redirect(url($route, ['a' => 'view', 'id' => $id]));
    }
    $days = $isBl ? 0 : (int)setting($isQuote ? 'quote_validity_days' : 'invoice_due_days', 0);
    $f = $d ?: [
        'id' => 0, 'location_id' => scope_loc() ?? ($_SESSION['pos_loc'] ?? (locations(true, 'showroom')[0]['id'] ?? null)),
        'client_id' => null, 'client_name' => '', 'client_phone' => '', 'client_email' => '', 'client_address' => '', 'client_mf' => '',
        'date' => today(), 'due_date' => $days > 0 ? date('Y-m-d', strtotime("+$days days")) : null, 'discount' => 0, 'timbre' => $isBl ? 0 : (int)setting('timbre', 1000),
        'payment_mode' => setting('invoice_payment_mode', ''), 'terms' => setting('invoice_terms', ''), 'transport' => setting('invoice_transport', ''),
        'transporter_id' => null, 'transporter_name' => '', 'transporter_plate' => '', 'transporter_cin' => '',
        'warranty' => setting('invoice_warranty', ''), 'notes' => '', 'internal_note' => '', 'stock_applied' => 1, 'sale_id' => null,
    ];
    $items = $d ? doc_items((int)$d['id']) : [];
    if (!$d && ($cid = (int)get('client')) && ($c = one('SELECT * FROM clients WHERE id = ?', [$cid]))) {
        $f = array_merge($f, ['client_id' => $c['id'], 'client_name' => $c['name'], 'client_phone' => $c['phone'], 'client_email' => $c['email'],
            'client_address' => trim(implode(', ', array_filter([$c['address'], $c['city'], $c['gouvernorat']])), ', '), 'client_mf' => $c['mf'] ?? '']);
    }
    // Retour après une erreur de validation : on réaffiche la saisie.
    if (get('retry') && !empty($_SESSION['doc_form'])) {
        $p = $_SESSION['doc_form'];
        $cl = (array)($p['client'] ?? []);
        $f = array_merge($f, ['location_id' => $p['location_id'] ?? $f['location_id'], 'client_id' => $cl['id'] ?? null, 'client_name' => $cl['name'] ?? '',
            'client_phone' => $cl['phone'] ?? '', 'client_email' => $cl['email'] ?? '', 'client_address' => $cl['address'] ?? '', 'client_mf' => $cl['mf'] ?? '',
            'date' => $p['date'] ?? $f['date'], 'due_date' => $p['due_date'] ?? null, 'discount' => parse_money($p['discount'] ?? 0) ?? 0,
            'timbre' => !empty($p['timbre']) ? 1 : 0, 'terms' => $p['terms'] ?? '', 'transport' => $p['transport'] ?? '', 'warranty' => $p['warranty'] ?? '',
            'payment_mode' => $p['payment_mode'] ?? '', 'transporter_id' => $p['transporter']['id'] ?? null, 'transporter_name' => $p['transporter']['name'] ?? '',
            'transporter_plate' => $p['transporter']['plate'] ?? '', 'transporter_cin' => $p['transporter']['cin'] ?? '',
            'notes' => $p['notes'] ?? '', 'internal_note' => $p['internal_note'] ?? '', 'stock_applied' => !empty($p['stock']) ? 1 : 0]);
        $items = array_map(fn($it) => ['product_id' => $it['product_id'] ?? null, 'label' => $it['label'] ?? '', 'description' => $it['description'] ?? '',
            'qty' => $it['qty'] ?? 1, 'unit_price' => parse_money($it['unit_price'] ?? 0) ?? 0, 'discount_pct' => $it['discount_pct'] ?? 0,
            'vat_rate' => $it['vat_rate'] ?? 19, 'sku' => '', 'warranty_months' => null], array_values((array)($p['items'] ?? [])));
        unset($_SESSION['doc_form']);
    }
    $products = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'price' => product_price($p),
        'w' => $p['warranty_months'] !== null ? (int)$p['warranty_months'] : null, 'img' => product_image($p), 'fam' => $p['family']],
        all('SELECT * FROM products WHERE active = 1 ORDER BY name'));
    $jsItems = array_map(fn($it) => ['product_id' => $it['product_id'] ? (int)$it['product_id'] : null, 'label' => $it['label'], 'description' => $it['description'] ?? '',
        'qty' => (int)$it['qty'], 'unit_price' => (int)$it['unit_price'], 'discount_pct' => (float)$it['discount_pct'], 'vat_rate' => (float)$it['vat_rate'],
        'sku' => $it['sku'] ?? '', 'w' => $it['warranty_months'] ?? null], $items);
    $trList = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'plate' => $t['plate'], 'cin' => $t['cin']], transporters());
    $data = ['products' => $products, 'items' => $jsItems, 'vat' => (float)setting('vat_rate', 19), 'timbre' => (int)setting('timbre', 1000),
        'clientsUrl' => url($route, ['a' => 'clients']), 'transporters' => $trList];
    $stockLink = $d ? doc_stock_link($d) : null;
    $title = $d ? 'Modifier ' . mb_strtolower($label) . ' ' . $d['number'] : $newLabel;
    layout_start($title, $route, ['wide' => true]);
    ?>
    <p><a class="back" href="<?= e($d ? url($route, ['a' => 'view', 'id' => $d['id']]) : url($route)) ?>"><?= icon('left') ?><?= $d ? e($d['number']) : e($plural) ?></a></p>
    <form method="post" action="<?= e(url($route, ['a' => 'save'])) ?>" class="doc-editor" data-doc-form novalidate>
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <div class="doc-grid">
        <div class="doc-main">
          <section class="card">
            <div class="card-title"><h2><span class="step">1</span>Client</h2><span class="muted small">Recherchez un client existant ou saisissez un nouveau client</span></div>
            <div class="combo" data-client-combo>
              <span class="combo-ic"><?= icon('search') ?></span>
              <input type="search" data-client-q placeholder="Rechercher par nom ou téléphone…" autocomplete="off" aria-label="Rechercher un client">
              <div class="suggest" data-client-suggest hidden></div>
            </div>
            <input type="hidden" name="client[id]" value="<?= e($f['client_id']) ?>" data-c="id">
            <div class="client-linked" data-client-linked <?= $f['client_id'] ? '' : 'hidden' ?>><?= icon('user') ?><span>Client enregistré dans le fichier clients</span><button type="button" class="btn xs ghost" data-client-unlink>Détacher</button></div>
            <div class="form-grid">
              <label class="span2">Nom ou raison sociale<input name="client[name]" value="<?= e($f['client_name']) ?>" required data-c="name" autocomplete="off"></label>
              <label>Téléphone<input name="client[phone]" value="<?= e($f['client_phone']) ?>" inputmode="tel" data-c="phone" autocomplete="off"></label>
              <label>Matricule fiscal<input name="client[mf]" value="<?= e($f['client_mf']) ?>" data-c="mf" placeholder="Clients professionnels : 1234567/A/M/000"></label>
              <label>E-mail<input type="email" name="client[email]" value="<?= e($f['client_email']) ?>" data-c="email"></label>
              <label>Adresse<input name="client[address]" value="<?= e($f['client_address']) ?>" data-c="address" placeholder="Rue, ville, gouvernorat"></label>
            </div>
          </section>

          <section class="card flush lines-card">
            <div class="card-head"><b><span class="step">2</span>Articles</b><span class="muted small">Saisissez le prix HT ou TTC : l’autre se calcule automatiquement.</span></div>
            <div class="line-add">
              <div class="combo grow" data-prod-combo>
                <span class="combo-ic"><?= icon('box') ?></span>
                <input type="search" data-prod-q placeholder="Ajouter un produit du catalogue (nom ou référence)…" autocomplete="off" aria-label="Ajouter un produit">
                <div class="suggest wide" data-prod-suggest hidden></div>
              </div>
              <button type="button" class="btn" data-add-free><?= icon('plus') ?>Ligne libre</button>
            </div>
            <div class="lines-scroll">
              <table class="tbl doc-lines">
                <thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">P.U. HT</th><th class="num">P.U. TTC</th><th class="num">Remise %</th><th>TVA</th><th class="num">Total TTC</th><th></th></tr></thead>
                <tbody data-lines></tbody>
              </table>
            </div>
            <div class="lines-empty" data-lines-empty>
              <?= icon('receipt', 'ic lg') ?>
              <p>Aucun article pour l’instant.<br><span class="muted">Cherchez un produit ci-dessus ou ajoutez une ligne libre (transport, installation, service…).</span></p>
            </div>
          </section>

          <section class="card form-grid">
            <h2 class="span2"><span class="step">3</span>Paiement, transport & mentions</h2>
            <label>Modalité de paiement<select name="payment_mode" data-pay-mode><?= opt(payment_mode_options((string)$f['payment_mode']), $f['payment_mode'], true, 'Non précisée') ?></select></label>
            <label>Conditions de paiement<input name="terms" value="<?= e($f['terms']) ?>" list="dl-terms" maxlength="300" placeholder="Facultatif : traite à 60 jours, acompte de 30 %…"></label>
            <label>Transport<input name="transport" value="<?= e($f['transport']) ?>" list="dl-transport" maxlength="120"></label>
            <label>Garantie<input name="warranty" value="<?= e($f['warranty']) ?>" maxlength="160"></label>

            <h3 class="span2 tr-head">Transporteur <small class="muted">imprimé sur <?= $isBl ? 'le bon de livraison' : ($isQuote ? 'le devis' : 'la facture') ?></small>
              <a class="tr-manage" href="<?= e(url('doc_lists')) ?>" target="_blank" rel="noopener">Gérer la liste</a></h3>
            <input type="hidden" name="transporter[id]" value="<?= e($f['transporter_id']) ?>" data-tr="id">
            <label<?= $trList ? '' : ' hidden' ?>>Transporteur enregistré<select data-tr-pick aria-label="Choisir un transporteur enregistré">
              <option value="">Nouveau transporteur ou aucun</option>
              <?php foreach ($trList as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$f['transporter_id'] === $t['id'] ? ' selected' : '' ?>><?= e($t['name'] . ($t['plate'] ? ' · ' . $t['plate'] : '')) ?></option><?php endforeach; ?>
            </select></label>
            <label>Nom du transporteur<input name="transporter[name]" value="<?= e($f['transporter_name']) ?>" data-tr="name" maxlength="120" autocomplete="off" placeholder="Nom et prénom ou société"></label>
            <label>Matricule du véhicule<input name="transporter[plate]" value="<?= e($f['transporter_plate']) ?>" data-tr="plate" maxlength="20" autocomplete="off" placeholder="Ex. 245 TU 7788"></label>
            <label>CIN du chauffeur<input name="transporter[cin]" value="<?= e($f['transporter_cin']) ?>" data-tr="cin" maxlength="20" autocomplete="off" inputmode="numeric" placeholder="8 chiffres"></label>
            <label class="check span2" data-tr-save-wrap hidden><input type="checkbox" name="transporter[save]" value="1" checked> Ajouter ce transporteur à la liste <small class="muted">(pour le choisir en un clic la prochaine fois)</small></label>

            <h3 class="span2">Mentions</h3>
            <label class="span2">Note imprimée sur le document<input name="notes" value="<?= e($f['notes']) ?>" maxlength="1000" placeholder="Ex. délai de livraison, couleur…"></label>
            <label class="span2">Note interne <small>(non imprimée)</small><textarea name="internal_note" rows="2" maxlength="1000"><?= e($f['internal_note']) ?></textarea></label>
            <datalist id="dl-terms"><option>Acompte de 30 % à la commande, solde à la livraison</option><option>Traite à 30 jours</option><option>Traite à 60 jours</option><option>Virement à 30 jours</option><option>Chèque à l’encaissement</option></datalist>
            <datalist id="dl-transport"><option>Gratuit</option><option>À la charge du client</option><option>Livraison et installation incluses</option></datalist>
          </section>
        </div>

        <aside class="doc-side">
          <section class="card doc-summary">
            <?php if ($isBl): ?>
              <label>Date de livraison<input type="date" name="date" value="<?= e($f['date']) ?>" required></label>
            <?php else: ?>
            <div class="grid2 tight">
              <label><?= $isQuote ? 'Date du devis' : 'Date de facture' ?><input type="date" name="date" value="<?= e($f['date']) ?>" required></label>
              <label><?= $isQuote ? 'Valable jusqu’au' : 'Échéance' ?><input type="date" name="due_date" value="<?= e($f['due_date']) ?>"></label>
            </div>
            <?php endif; ?>
            <?php if (!scope_loc()): ?>
              <label>Showroom émetteur<select name="location_id"><?= opt(location_options(true), $f['location_id'], true, 'Aucun (siège)') ?></select></label>
            <?php endif; ?>
            <dl class="totals">
              <div><dt>Sous-total TTC</dt><dd data-t="subtotal">0,000</dd></div>
              <div class="disc"><dt><label for="doc-disc">Remise globale (DT)</label></dt><dd><input id="doc-disc" name="discount" inputmode="decimal" value="<?= e(m_in($f['discount'])) ?>" placeholder="0" data-discount></dd></div>
              <div><dt>Total HT</dt><dd data-t="ht">0,000</dd></div>
              <div data-vat-rows></div>
              <?php if (!$isBl): ?>
              <div class="timbre"><dt><label class="check"><input type="checkbox" name="timbre" value="1" <?= (int)$f['timbre'] > 0 ? 'checked' : '' ?> data-timbre> Timbre fiscal</label></dt><dd data-t="timbre">0,000</dd></div>
              <?php endif; ?>
              <div class="grand"><dt><?= $isBl ? 'Total TTC' : 'Net à payer' ?></dt><dd data-t="net">0,000 DT</dd></div>
            </dl>
            <p class="words" data-words></p>
            <?php if (!$isQuote && $stockLink): ?>
              <p class="hint"><?= icon('check') ?> Stock déjà sorti par <?= $stockLink['type'] === 'bl' ? 'le bon de livraison' : 'la facture' ?> <?= e($stockLink['number']) ?>.</p>
            <?php elseif (!$isQuote && !$f['sale_id']): ?>
              <label class="check stock-check"><input type="checkbox" name="stock" value="1" <?= $f['stock_applied'] ? 'checked' : '' ?>> Sortir les articles du stock du showroom<small>Décochez si la marchandise est déjà sortie (vente en caisse, <?= $isBl ? 'facture…' : 'bon de livraison…' ?>).</small></label>
            <?php elseif (!$isQuote): ?>
              <p class="hint"><?= icon('check') ?> <?= e($label) ?> lié<?= $fem ? 'e' : '' ?> à une vente de caisse : le stock a déjà été sorti.</p>
            <?php endif; ?>
            <button class="btn primary block lg-btn"><?= icon('check') ?><?= $d ? 'Enregistrer les modifications' : ['devis' => 'Créer le devis', 'facture' => 'Créer la facture', 'bl' => 'Créer le bon de livraison'][$type] ?></button>
            <p class="hint center"><?= $d ? 'N° ' . e($d['number']) : 'Le numéro est attribué à l’enregistrement.' ?></p>
          </section>
        </aside>
      </div>
      <template data-line-tpl>
        <tr class="dl">
          <td class="dl-name">
            <input type="hidden" data-f="product_id">
            <div class="dl-label"><span class="dl-thumb"></span><input data-f="label" placeholder="Désignation" required aria-label="Désignation"></div>
            <input data-f="description" class="dl-desc" placeholder="Description (facultatif)" aria-label="Description">
            <small class="dl-meta"></small>
          </td>
          <td data-th="Qté"><input data-f="qty" class="qty-in" inputmode="numeric" value="1" aria-label="Quantité"></td>
          <td data-th="P.U. HT"><input data-ht class="money-in" inputmode="decimal" aria-label="Prix unitaire HT"></td>
          <td data-th="P.U. TTC"><input data-f="unit_price" class="money-in" inputmode="decimal" aria-label="Prix unitaire TTC"></td>
          <td data-th="Remise %"><input data-f="discount_pct" class="pct-in" inputmode="decimal" placeholder="0" aria-label="Remise en %"></td>
          <td data-th="TVA"><select data-f="vat_rate" aria-label="TVA"><?= opt(VAT_RATES, (string)(int)setting('vat_rate', 19)) ?></select></td>
          <td data-th="Total TTC" class="num dl-total">0,000</td>
          <td class="dl-del"><button type="button" class="icon-btn xs" data-del title="Supprimer la ligne" aria-label="Supprimer la ligne"><?= icon('trash') ?></button></td>
        </tr>
      </template>
      <script type="application/json" data-doc-json><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </form>
    <?php
    layout_end(['doc.js']);
    exit;
}

/* ---------- Fiche ---------- */

if ($a === 'view') {
    $d = doc_get((int)get('id'), $type);
    if (!$d) {
        flash('err', 'Document introuvable.');
        redirect(url($route));
    }
    $items = doc_items((int)$d['id']);
    $pays = all('SELECT p.*, u.name AS user_name FROM document_payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.document_id = ? ORDER BY p.date, p.id', [$d['id']]);
    $source = $d['source_id'] ? one('SELECT id, number, type FROM documents WHERE id = ?', [$d['source_id']]) : null;
    $children = all("SELECT id, number, type, status, net FROM documents WHERE source_id = ? ORDER BY id", [$d['id']]);
    $sale = $d['sale_id'] ? one('SELECT id, number FROM sales WHERE id = ?', [$d['sale_id']]) : null;
    $rest = (int)$d['net'] - (int)$d['paid'];
    $editable = doc_editable($d);
    $canEdit = doc_can_manage($d);
    $stockLink = doc_stock_link($d);
    $blInvoice = $isBl ? doc_bl_invoice($d) : null;
    $printUrl = url('doc', ['id' => $d['id']]);
    $wa = '';
    if ($d['client_phone']) {
        $msg = 'Bonjour ' . $d['client_name'] . ",\nVoici " . ['devis' => 'votre devis', 'facture' => 'votre facture', 'bl' => 'votre bon de livraison'][$type] . ' N° ' . $d['number'] . ' de ' . setting('company_name', 'FitnessTN')
            . ' d’un montant de ' . money($d['net']) . ($isQuote && $d['due_date'] ? ', valable jusqu’au ' . fdate($d['due_date']) : '') . ".\nMerci pour votre confiance !";
        $wa = 'https://wa.me/216' . $d['client_phone'] . '?text=' . rawurlencode($msg);
    }
    // Bouton « Supprimer » visible en haut : direct si c'est possible, sinon il ouvre l'explication en bas de page
    $delBlock = doc_delete_block($d);
    $delEffects = doc_delete_effects($d);
    $delBtn = '';
    if ($delBlock === null) {
        $delBtn = '<form method="post" action="' . e(url($route, ['a' => 'delete'])) . '" class="inline-del" onsubmit="return confirm(' . e(json_encode($delEffects . "\n\nConfirmer la suppression ?")) . ')">'
            . csrf_field() . '<input type="hidden" name="id" value="' . (int)$d['id'] . '"><button class="btn sm ghost danger">' . icon('trash') . 'Supprimer</button></form>';
    } elseif (!$isQuote && $canEdit) {
        $delBtn = '<a class="btn sm ghost danger" href="#supprimer" onclick="var z=document.getElementById(\'supprimer\');if(z){z.open=true;}">' . icon('trash') . 'Supprimer…</a>';
    }
    $actions = $delBtn . '<a class="btn sm" target="_blank" href="' . e(url('doc', ['id' => $d['id'], 'print' => 1])) . '">' . icon('print') . 'Imprimer / PDF</a>'
        . ($isBl ? '<a class="btn sm ghost" target="_blank" href="' . e(url('doc', ['id' => $d['id'], 'print' => 1, 'noprice' => 1])) . '" title="Bon de livraison sans prix, à remettre au transporteur">' . icon('print') . 'Sans prix</a>' : '')
        . ($editable ? '<a class="btn sm primary" href="' . e(url($route, ['a' => 'edit', 'id' => $d['id']])) . '">' . icon('edit') . 'Modifier</a>' : '');
    layout_start($label . ' ' . $d['number'], $route, ['actions' => $actions, 'wide' => true]);
    ?>
    <p><a class="back" href="<?= e(url($route)) ?>"><?= icon('left') ?><?= ['devis' => 'Tous les devis', 'facture' => 'Toutes les factures', 'bl' => 'Tous les bons de livraison'][$type] ?></a></p>
    <div class="doc-view">
      <div class="doc-preview">
        <div class="paper-frame" data-paper-frame><iframe src="<?= e(url('doc', ['id' => $d['id'], 'embed' => 1])) ?>" title="Aperçu du document" loading="lazy"></iframe></div>
      </div>
      <aside class="doc-aside">
        <section class="card doc-status">
          <div class="ds-top"><?= doc_badge($d) ?><span class="muted small"><?= e($label) ?> du <?= fdate($d['date']) ?></span></div>
          <div class="ds-amount"><span><?= $isBl ? 'Total TTC' : 'Net à payer' ?></span><b><?= money($d['net']) ?></b></div>
          <?php if ($isInvoice && $d['status'] !== 'annulee'): ?>
            <div class="meter" title="<?= e(money($d['paid'])) ?> encaissés"><span style="width:<?= $d['net'] ? min(100, round($d['paid'] / $d['net'] * 100)) : 0 ?>%"></span></div>
            <div class="ds-split"><span>Encaissé <b><?= money($d['paid']) ?></b></span><span>Reste <b class="<?= $rest > 0 ? 'warn-t' : 'pos' ?>"><?= money($rest) ?></b></span></div>
          <?php endif; ?>
          <dl class="kv">
            <dt>Client</dt><dd><?= $d['client_id'] ? '<a href="' . e(url('clients', ['a' => 'view', 'id' => $d['client_id']])) . '">' . e($d['client_name']) . '</a>' : e($d['client_name']) ?>
              <?= $d['client_phone'] ? '<br><a href="tel:' . e($d['client_phone']) . '">' . e($d['client_phone']) . '</a>' : '' ?></dd>
            <?php if ($d['due_date']): ?><dt><?= $isQuote ? 'Valable jusqu’au' : 'Échéance' ?></dt><dd class="<?= doc_expired($d) || doc_overdue($d) ? 'late' : '' ?>"><?= fdate($d['due_date']) ?></dd><?php endif; ?>
            <?php if ($d['location_id']): ?><dt>Showroom</dt><dd><?= e(location((int)$d['location_id'])['name'] ?? '') ?></dd><?php endif; ?>
            <?php if ($d['payment_mode']): ?><dt>Paiement</dt><dd><?= e($d['payment_mode']) ?><?= $d['terms'] ? '<br><small class="muted">' . e($d['terms']) . '</small>' : '' ?></dd><?php endif; ?>
            <?php if ($d['transporter_name']): ?><dt>Transporteur</dt><dd><?= e($d['transporter_name']) ?><?= $d['transporter_plate'] || $d['transporter_cin'] ? '<br><small class="muted">' . e(implode(' · ', array_filter([$d['transporter_plate'] ? 'Matricule ' . $d['transporter_plate'] : '', $d['transporter_cin'] ? 'CIN ' . $d['transporter_cin'] : '']))) . '</small>' : '' ?></dd><?php endif; ?>
            <dt>Établi par</dt><dd><?= e($d['author'] ?: '—') ?></dd>
            <?php if ($source): ?><dt><?= e(DOC_TYPES[$source['type']]) ?> d’origine</dt><dd><a href="<?= e(url(doc_route($source['type']), ['a' => 'view', 'id' => $source['id']])) ?>"><?= e($source['number']) ?></a></dd><?php endif; ?>
            <?php foreach ($children as $ch): ?><dt><?= e(DOC_TYPES[$ch['type']]) ?></dt><dd><a href="<?= e(url(doc_route($ch['type']), ['a' => 'view', 'id' => $ch['id']])) ?>"><?= e($ch['number']) ?></a> <?= doc_badge($ch + ['due_date' => null]) ?></dd><?php endforeach; ?>
            <?php if ($sale): ?><dt>Vente caisse</dt><dd><a href="<?= e(url('sales', ['a' => 'view', 'id' => $sale['id']])) ?>"><?= e($sale['number']) ?></a></dd><?php endif; ?>
            <?php if (!$isQuote): ?><dt>Stock</dt><dd><?= $d['stock_applied'] ? 'Sorti du showroom' : ($sale ? 'Sorti par la vente de caisse'
                : ($stockLink ? 'Sorti par ' . ($stockLink['type'] === 'bl' ? 'le bon de livraison ' : 'la facture ') . e($stockLink['number']) : 'Non mouvementé')) ?></dd><?php endif; ?>
          </dl>
          <div class="ds-actions">
            <?php if ($wa): ?><a class="btn sm wa" target="_blank" rel="noopener" href="<?= e($wa) ?>"><?= icon('chat') ?>WhatsApp</a><?php endif; ?>
            <?php if ($d['client_email']): ?><a class="btn sm" href="mailto:<?= e($d['client_email']) ?>?subject=<?= rawurlencode($label . ' ' . $d['number'] . ' — ' . setting('company_name', 'FitnessTN')) ?>"><?= icon('mail') ?>E-mail</a><?php endif; ?>
            <?php if ($canEdit): ?>
              <form method="post" action="<?= e(url($route, ['a' => 'duplicate'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn sm ghost"><?= icon('copy') ?>Dupliquer</button></form>
            <?php endif; ?>
          </div>
          <?php if ($wa): ?><p class="hint">Astuce : « Imprimer / PDF » puis « Enregistrer au format PDF » pour joindre le document au message.</p><?php endif; ?>
        </section>

        <?php if ($isQuote && $canEdit && $d['status'] !== 'facture'): ?>
          <section class="card">
            <h2>Suivi du devis</h2>
            <div class="status-steps">
              <?php foreach (['brouillon' => 'edit', 'envoye' => 'send', 'accepte' => 'check', 'refuse' => 'x'] as $st => $ic): ?>
                <form method="post" action="<?= e(url($route, ['a' => 'status'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>">
                  <button class="step-btn st-<?= $st ?><?= $d['status'] === $st ? ' on' : '' ?>" <?= $d['status'] === $st ? 'disabled aria-current="true"' : '' ?>><?= icon($ic) ?><span><?= e(DOC_STATUS['devis'][$st]) ?></span></button></form>
              <?php endforeach; ?>
            </div>
            <form method="post" action="<?= e(url($route, ['a' => 'convert'])) ?>" class="convert" onsubmit="return confirm('Créer une facture à partir de ce devis ?')">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <label class="check"><input type="checkbox" name="stock" value="1" checked> Sortir les articles du stock<?= $d['location_id'] ? ' (' . e(location((int)$d['location_id'])['name'] ?? '') . ')' : '' ?></label>
              <button class="btn primary block"><?= icon('invoice') ?>Transformer en facture</button>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($isBl && $canEdit && $d['status'] !== 'facture'): ?>
          <section class="card">
            <h2>Suivi de la livraison</h2>
            <div class="status-steps two">
              <?php foreach (['a_livrer' => 'truck', 'livre' => 'check'] as $st => $ic): ?>
                <form method="post" action="<?= e(url($route, ['a' => 'status'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>">
                  <button class="step-btn st-<?= $st ?><?= $d['status'] === $st ? ' on' : '' ?>" <?= $d['status'] === $st ? 'disabled aria-current="true"' : '' ?>><?= icon($ic) ?><span><?= e(DOC_STATUS['bl'][$st]) ?></span></button></form>
              <?php endforeach; ?>
            </div>
            <?php if ($d['status'] === 'livre' && $d['closed_at']): ?><p class="hint"><?= icon('check') ?> Livré le <?= fdate($d['closed_at'], true) ?>.</p><?php endif; ?>
            <?php if ($blInvoice): ?>
              <p class="hint">Ce bon de livraison accompagne la facture <a href="<?= e(url('invoices', ['a' => 'view', 'id' => $blInvoice['id']])) ?>"><?= e($blInvoice['number']) ?></a> : il n’est pas facturé une seconde fois.</p>
            <?php else: ?>
              <form method="post" action="<?= e(url($route, ['a' => 'convert'])) ?>" class="convert" onsubmit="return confirm('Créer une facture à partir de ce bon de livraison ?')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="to" value="facture">
                <?php if (!$d['stock_applied'] && !$d['sale_id']): ?>
                  <label class="check"><input type="checkbox" name="stock" value="1"> Sortir les articles du stock avec la facture</label>
                <?php else: ?>
                  <p class="hint">La marchandise est déjà sortie du stock par ce bon : la facture ne la sort pas une seconde fois.</p>
                <?php endif; ?>
                <button class="btn primary block"><?= icon('invoice') ?>Établir la facture</button>
              </form>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <?php if ($isInvoice && $canEdit && $d['status'] !== 'annulee'): ?>
          <section class="card">
            <h2>Bon de livraison</h2>
            <?php $bls = array_filter($children, fn($ch) => $ch['type'] === 'bl'); ?>
            <?php if ($bls): ?><p class="muted small">Déjà établi : <?= implode(', ', array_map(fn($ch) => '<a href="' . e(url('delivery_notes', ['a' => 'view', 'id' => $ch['id']])) . '">' . e($ch['number']) . '</a>', $bls)) ?>.</p><?php endif; ?>
            <form method="post" action="<?= e(url($route, ['a' => 'convert'])) ?>" class="convert" onsubmit="return confirm('Créer un bon de livraison à partir de cette facture ?')">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="to" value="bl">
              <?php if (!$d['stock_applied'] && !$d['sale_id'] && !$stockLink): ?>
                <label class="check"><input type="checkbox" name="stock" value="1" checked> Sortir les articles du stock avec le bon de livraison</label>
              <?php endif; ?>
              <button class="btn block"><?= icon('clipboard') ?><?= $bls ? 'Établir un autre bon de livraison' : 'Établir le bon de livraison' ?></button>
            </form>
            <p class="hint">Mêmes articles, client et transporteur ; modifiable ensuite (livraison partielle, autre chauffeur…).</p>
          </section>
        <?php endif; ?>

        <?php if ($isInvoice): ?>
          <section class="card">
            <h2>Paiements</h2>
            <?php if ($pays): ?>
              <ul class="pay-list">
                <?php foreach ($pays as $p): ?>
                  <li><span class="pl-ic"><?= icon('cash') ?></span><span class="pl-main"><b><?= money($p['amount']) ?></b><small><?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?> · <?= fdate($p['date']) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?><?= $p['session_id'] ? ' · <a href="' . e(url('finances', ['a' => 'session', 'id' => $p['session_id']])) . '">caisse n° ' . (int)$p['session_id'] . '</a>' : '' ?></small></span>
                    <?php if ($canEdit && $d['status'] !== 'annulee'): ?>
                      <form method="post" action="<?= e(url($route, ['a' => 'pay_delete'])) ?>" onsubmit="return confirm('Supprimer ce paiement ?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button class="icon-btn xs" title="Supprimer"><?= icon('trash') ?></button></form>
                    <?php endif; ?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?><p class="muted">Aucun paiement reçu.</p><?php endif; ?>
            <?php if ($rest > 0 && $d['status'] !== 'annulee' && $canEdit): ?>
              <form method="post" action="<?= e(url($route, ['a' => 'pay'])) ?>" class="pay-add">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <h3>Enregistrer un paiement</h3>
                <div class="grid2 tight">
                  <label>Montant (DT)<input name="amount" inputmode="decimal" value="<?= e(m_in($rest)) ?>" required></label>
                  <label>Date<input type="date" name="date" value="<?= e(today()) ?>"></label>
                  <label>Mode<select name="method"><?= opt(PAY_METHODS, 'virement') ?></select></label>
                  <label>Référence<input name="reference" maxlength="80" placeholder="N° chèque, virement…"></label>
                </div>
                <?php $sess = $d['location_id'] ? open_session((int)$d['location_id']) : null; ?>
                <p class="hint"><?= $sess ? 'Espèces : ajoutées à la caisse ouverte de ' . e(location((int)$d['location_id'])['name'] ?? '') . ' et comptées à la clôture.' : 'Espèces : aucune caisse ouverte pour ce showroom, le montant ne sera pas compté dans une clôture.' ?></p>
                <button class="btn primary sm block"><?= icon('cash') ?>Enregistrer le paiement</button>
              </form>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <section class="card">
          <h2>Historique</h2>
          <ol class="timeline">
            <li><b><?= ['devis' => 'Devis créé', 'facture' => 'Facture émise', 'bl' => 'Bon de livraison établi'][$type] ?></b><small><?= fdate($d['created_at'], true) ?><?= $d['author'] ? ' · ' . e($d['author']) : '' ?></small></li>
            <?php if ($d['sent_at']): ?><li><b>Envoyé au client</b><small><?= fdate($d['sent_at'], true) ?></small></li><?php endif; ?>
            <?php if ($isQuote && in_array($d['status'], ['accepte', 'refuse', 'facture'], true) && $d['closed_at']): ?><li class="<?= $d['status'] === 'refuse' ? 'err' : 'ok' ?>"><b><?= $d['status'] === 'refuse' ? 'Refusé' : 'Accepté' ?> par le client</b><small><?= fdate($d['closed_at'], true) ?></small></li><?php endif; ?>
            <?php if ($isBl && $d['closed_at']): ?><li class="ok"><b>Livré au client</b><small><?= fdate($d['closed_at'], true) ?></small></li><?php endif; ?>
            <?php foreach ($children as $ch): ?><li class="ok"><b><?= $ch['type'] === 'facture' ? 'Facturé : facture ' . e($ch['number']) : 'Bon de livraison ' . e($ch['number']) . ' établi' ?></b></li><?php endforeach; ?>
            <?php foreach ($pays as $p): ?><li class="ok"><b>Paiement de <?= money($p['amount']) ?></b><small><?= fdate($p['date']) ?> · <?= e(PAY_METHODS[$p['method']] ?? '') ?><?= $p['user_name'] ? ' · ' . e($p['user_name']) : '' ?></small></li><?php endforeach; ?>
            <?php if ($d['status'] === 'annulee'): ?><li class="err"><b>Annulée</b><small><?= fdate($d['cancelled_at'], true) ?> — <?= e($d['cancel_reason']) ?></small></li><?php endif; ?>
          </ol>
          <?php if ($canEdit): ?>
          <form method="post" action="<?= e(url($route, ['a' => 'internal_note'])) ?>" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input name="internal_note" value="<?= e($d['internal_note']) ?>" placeholder="Note interne…" maxlength="1000">
            <button class="btn sm ghost">Enregistrer</button>
          </form>
          <?php elseif ($d['internal_note']): ?><p class="muted">Note interne : <?= e($d['internal_note']) ?></p><?php endif; ?>
        </section>

        <?php if ($canEdit): ?>
          <details class="card danger-zone" id="supprimer">
            <summary>Supprimer <?= e($this_) ?></summary>
            <?php if ($delBlock === null): ?>
              <form method="post" action="<?= e(url($route, ['a' => 'delete'])) ?>" onsubmit="return confirm(<?= e(json_encode($delEffects . "\n\nConfirmer la suppression ?")) ?>)">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <p class="muted"><?= e($delEffects) ?><?= $isInvoice && $d['status'] !== 'annulee' ? ' Pour une facture déjà remise au client, préférez « Annuler » : le numéro reste dans la suite.' : '' ?></p>
                <button class="btn danger"><?= icon('trash') ?><?= ['devis' => 'Supprimer le devis', 'facture' => 'Supprimer la facture', 'bl' => 'Supprimer le bon de livraison'][$type] ?></button>
              </form>
            <?php else: ?>
              <p class="muted"><?= e($delBlock) ?></p>
            <?php endif; ?>
          </details>
        <?php endif; ?>
        <?php if ($isInvoice && $canEdit && $d['status'] !== 'annulee'): ?>
          <details class="card danger-zone">
            <summary>Annuler cette facture</summary>
            <form method="post" action="<?= e(url($route, ['a' => 'cancel'])) ?>" onsubmit="return confirm('Annuler définitivement la facture <?= e($d['number']) ?> ?')">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <p class="muted">Le numéro reste attribué (la facture reste dans la liste, marquée « annulée »)<?= $d['stock_applied'] ? ' et les articles sont remis en stock' : '' ?>. Les paiements déjà reçus restent visibles.</p>
              <label>Motif<input name="reason" required maxlength="300"></label>
              <button class="btn danger"><?= icon('x') ?>Annuler la facture</button>
            </form>
          </details>
        <?php endif; ?>
      </aside>
    </div>
    <?php
    layout_end(['doc.js']);
    exit;
}

/* ---------- Liste ---------- */

$statuses = DOC_STATUS[$type];
$f = [
    'from' => parse_date(get('from')) ?? date('Y-01-01'),
    'to' => parse_date(get('to')) ?? today(),
    'loc' => $scope ?? (int)get('loc'),
    'status' => (string)get('status'),
    'q' => (string)get('q'),
];
$base = ['d.type = ?', 'd.date BETWEEN ? AND ?'];
$bp = [$type, $f['from'], $f['to']];
if ($f['loc']) { $base[] = 'd.location_id = ?'; $bp[] = $f['loc']; }
if ($f['q'] !== '') {
    $base[] = '(d.number LIKE ? OR d.client_name LIKE ? OR d.client_phone LIKE ? OR d.client_mf LIKE ? OR d.transporter_name LIKE ? OR d.transporter_plate LIKE ?)';
    array_push($bp, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . (normalize_phone($f['q']) ?: $f['q']) . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%');
}
$late = $isQuote ? "d.status IN ('brouillon','envoye') AND d.due_date < '" . today() . "'"
    : ($isInvoice ? "d.status IN ('emise','partielle') AND d.due_date < '" . today() . "'" : '0');
$counts = ['' => 0, 'late' => 0];
foreach (all('SELECT d.status, COUNT(*) n, SUM(CASE WHEN ' . $late . ' THEN 1 ELSE 0 END) l FROM documents d WHERE ' . implode(' AND ', $base) . ' GROUP BY d.status', $bp) as $r) {
    $counts[$r['status']] = (int)$r['n'];
    $counts[''] += (int)$r['n'];
    $counts['late'] += (int)$r['l'];
}
$where = $base;
$p = $bp;
if ($f['status'] === 'late') $where[] = $late;
elseif ($f['status'] !== '') { $where[] = 'd.status = ?'; $p[] = $f['status']; }
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM documents d WHERE $w", $p);
$page = max(1, (int)get('page', 1));
$per = 50;
$rows = all("SELECT d.* FROM documents d WHERE $w ORDER BY d.date DESC, d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);

// Indicateurs sur la période filtrée (hors statut)
$bw = implode(' AND ', $base);
if ($isQuote) {
    $k = one("SELECT COUNT(*) n, COALESCE(SUM(net),0) t,
        COALESCE(SUM(CASE WHEN status IN ('brouillon','envoye') THEN net END),0) pending, SUM(CASE WHEN status IN ('brouillon','envoye') THEN 1 ELSE 0 END) pn,
        SUM(CASE WHEN status IN ('accepte','facture') THEN 1 ELSE 0 END) won, SUM(CASE WHEN status IN ('accepte','facture','refuse') THEN 1 ELSE 0 END) closed,
        COALESCE(SUM(CASE WHEN status = 'accepte' THEN net END),0) tobill FROM documents d WHERE $bw", $bp);
    $rate = (int)$k['closed'] ? (int)$k['won'] / (int)$k['closed'] * 100 : null;
} elseif ($isBl) {
    $k = one("SELECT COUNT(*) n, COALESCE(SUM(net),0) t,
        SUM(CASE WHEN status = 'a_livrer' THEN 1 ELSE 0 END) todo, COALESCE(SUM(CASE WHEN status = 'a_livrer' THEN net END),0) todo_t,
        SUM(CASE WHEN status = 'livre' THEN 1 ELSE 0 END) done, SUM(CASE WHEN status = 'facture' THEN 1 ELSE 0 END) billed,
        SUM(CASE WHEN status <> 'facture' AND NOT EXISTS (SELECT 1 FROM documents f WHERE f.type = 'facture' AND f.status <> 'annulee' AND f.id = d.source_id) THEN 1 ELSE 0 END) tobill_n,
        COALESCE(SUM(CASE WHEN status <> 'facture' AND NOT EXISTS (SELECT 1 FROM documents f WHERE f.type = 'facture' AND f.status <> 'annulee' AND f.id = d.source_id) THEN net END),0) tobill
        FROM documents d WHERE $bw", $bp);
} else {
    $k = one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status <> 'annulee' THEN net END),0) t, COALESCE(SUM(CASE WHEN status <> 'annulee' THEN paid END),0) paid,
        COALESCE(SUM(CASE WHEN $late THEN net - paid END),0) overdue, SUM(CASE WHEN status IN ('emise','partielle') THEN 1 ELSE 0 END) open
        FROM documents d WHERE $bw", $bp);
}

$exportParams = array_filter(['ds' => ['devis' => 'devis', 'facture' => 'factures', 'bl' => 'bons_livraison'][$type], 'from' => $f['from'], 'to' => $f['to'], 'loc' => $f['loc'] ?: null]);
$actions = '<a class="btn sm ghost" href="' . e(url('doc_lists')) . '" title="Transporteurs et modalités de paiement">' . icon('truck') . 'Transporteurs & paiements</a>'
    . (can('invoices.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export'] + $exportParams)) . '">' . icon('download') . 'Exporter</a>' : '')
    . (can('invoices.edit') ? '<a class="btn sm primary" href="' . e(url($route, ['a' => 'edit'])) . '">' . icon('plus') . e($newLabel) . '</a>' : '');
layout_start($plural, $route, ['actions' => $actions]);
$qs = array_filter(['from' => $f['from'], 'to' => $f['to'], 'loc' => $scope ? null : ($f['loc'] ?: null), 'q' => $f['q']]);
?>
<div class="grid4 kpis">
  <?php if ($isQuote): ?>
    <div class="kpi with-ic"><span class="kpi-ic"><?= icon('quote') ?></span><span>Devis émis</span><b><?= (int)$k['n'] ?></b><small><?= money0($k['t']) ?> au total</small></div>
    <div class="kpi with-ic"><span class="kpi-ic info"><?= icon('send') ?></span><span>En attente de réponse</span><b><?= money0($k['pending']) ?></b><small><?= (int)$k['pn'] ?> devis à relancer</small></div>
    <div class="kpi with-ic"><span class="kpi-ic ok"><?= icon('check') ?></span><span>Taux de transformation</span><b><?= $rate === null ? '—' : pct($rate) ?></b><small><?= (int)$k['won'] ?> accepté(s) sur <?= (int)$k['closed'] ?> réponse(s)</small></div>
    <div class="kpi with-ic"><span class="kpi-ic warn"><?= icon('invoice') ?></span><span>Acceptés à facturer</span><b class="<?= (int)$k['tobill'] ? 'warn-t' : '' ?>"><?= money0($k['tobill']) ?></b><small><a href="<?= e(url($route, $qs + ['status' => 'accepte'])) ?>">Voir les devis acceptés</a></small></div>
  <?php elseif ($isBl): ?>
    <div class="kpi with-ic"><span class="kpi-ic"><?= icon('clipboard') ?></span><span>Bons de livraison</span><b><?= (int)$k['n'] ?></b><small><?= money0($k['t']) ?> de marchandise</small></div>
    <div class="kpi with-ic"><span class="kpi-ic warn"><?= icon('truck') ?></span><span>À livrer</span><b class="<?= (int)$k['todo'] ? 'warn-t' : '' ?>"><?= (int)$k['todo'] ?></b><small><?= money0($k['todo_t']) ?> en attente de livraison</small></div>
    <div class="kpi with-ic"><span class="kpi-ic ok"><?= icon('check') ?></span><span>Livrés</span><b><?= (int)$k['done'] + (int)$k['billed'] ?></b><small><?= (int)$k['billed'] ?> déjà facturé(s)</small></div>
    <div class="kpi with-ic"><span class="kpi-ic info"><?= icon('invoice') ?></span><span>À facturer</span><b class="<?= (int)$k['tobill'] ? 'warn-t' : '' ?>"><?= money0($k['tobill']) ?></b><small><?= (int)$k['tobill_n'] ?> bon(s) sans facture</small></div>
  <?php else: ?>
    <div class="kpi with-ic"><span class="kpi-ic"><?= icon('invoice') ?></span><span>Chiffre facturé</span><b><?= money0($k['t']) ?></b><small><?= (int)$k['n'] ?> facture(s) sur la période</small></div>
    <div class="kpi with-ic"><span class="kpi-ic ok"><?= icon('cash') ?></span><span>Encaissé</span><b><?= money0($k['paid']) ?></b><small><?= (int)$k['t'] ? pct((int)$k['paid'] / (int)$k['t'] * 100) . ' du facturé' : '—' ?></small></div>
    <div class="kpi with-ic"><span class="kpi-ic warn"><?= icon('wallet') ?></span><span>Reste à encaisser</span><b class="<?= (int)$k['t'] - (int)$k['paid'] > 0 ? 'warn-t' : '' ?>"><?= money0((int)$k['t'] - (int)$k['paid']) ?></b><small><?= (int)$k['open'] ?> facture(s) ouverte(s)</small></div>
    <div class="kpi with-ic"><span class="kpi-ic err"><?= icon('alert') ?></span><span>En retard</span><b class="<?= (int)$k['overdue'] ? 'neg' : '' ?>"><?= money0($k['overdue']) ?></b><small><?= $counts['late'] ?> facture(s) après échéance</small></div>
  <?php endif; ?>
</div>

<div class="chips">
  <a class="chip<?= $f['status'] === '' ? ' on' : '' ?>" href="<?= e(url($route, $qs)) ?>">Tous <span class="count"><?= $counts[''] ?></span></a>
  <?php foreach ($statuses as $st => $lab): ?>
    <a class="chip<?= $f['status'] === $st ? ' on' : '' ?>" href="<?= e(url($route, $qs + ['status' => $st])) ?>"><?= e($lab) ?> <span class="count"><?= $counts[$st] ?? 0 ?></span></a>
  <?php endforeach; ?>
  <?php if ($counts['late']): ?><a class="chip alert-chip<?= $f['status'] === 'late' ? ' on' : '' ?>" href="<?= e(url($route, $qs + ['status' => 'late'])) ?>"><?= $isQuote ? 'Expirés' : 'En retard' ?> <span class="count"><?= $counts['late'] ?></span></a><?php endif; ?>
</div>

<form class="filters" method="get">
  <input type="hidden" name="r" value="<?= e($route) ?>"><?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <label>Du<input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label>Au<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <?php if (!$scope): ?><label>Showroom<select name="loc"><?= opt(location_options(false), $f['loc'], true, 'Tous') ?></select></label><?php endif; ?>
  <label class="grow">Recherche<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="N°, client, téléphone, matricule fiscal, transporteur"></label>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>

<?php $listDelete = can('invoices.edit'); ?>
<?php if ($listDelete && $rows): ?>
<form method="post" action="<?= e(url($route, ['a' => 'bulk_delete'])) ?>" id="bulk-del" class="bulk-bar" data-bulk hidden
  onsubmit="return confirm(<?= e(json_encode(['devis' => 'Supprimer définitivement les devis sélectionnés ?',
      'facture' => 'Supprimer définitivement les factures sélectionnées ? Leurs paiements sont supprimés aussi et le stock sorti est remis.' . "\n\nPour une facture déjà remise au client, préférez « Annuler » : le numéro reste dans la suite.",
      'bl' => 'Supprimer définitivement les bons de livraison sélectionnés ? Le stock sorti est remis.'][$type])) ?>)">
  <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
  <span><b data-bulk-count>0</b> sélectionné(s)</span>
  <button type="button" class="btn sm ghost" data-bulk-clear>Tout désélectionner</button>
  <button class="btn sm danger"><?= icon('trash') ?>Supprimer la sélection</button>
</form>
<?php endif; ?>
<div class="card flush">
  <table class="tbl hover">
    <thead><tr><?php if ($listDelete && $rows): ?><th class="sel"><input type="checkbox" data-bulk-all aria-label="Tout sélectionner" title="Tout sélectionner"></th><?php endif; ?><th>N°</th><th>Date</th><th>Client</th><?php if (!$scope): ?><th>Showroom</th><?php endif; ?><th><?= ['devis' => 'Validité', 'facture' => 'Échéance', 'bl' => 'Transporteur'][$type] ?></th><th class="num"><?= $isBl ? 'Total TTC' : 'Net à payer' ?></th><?php if ($isInvoice): ?><th class="num">Reste</th><?php endif; ?><th>Statut</th><?php if ($listDelete): ?><th class="num"></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $rr = (int)$r['net'] - (int)$r['paid']; $rowDel = $listDelete && doc_delete_block($r) === null; ?>
      <tr data-href="<?= e(url($route, ['a' => 'view', 'id' => $r['id']])) ?>">
        <?php if ($listDelete): ?><td class="sel"><?php if ($rowDel): ?><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulk-del" data-bulk-item aria-label="Sélectionner <?= e($r['number']) ?>"><?php endif; ?></td><?php endif; ?>
        <td><a href="<?= e(url($route, ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= e($r['number']) ?></b></a></td>
        <td><?= fdate($r['date']) ?></td>
        <td><b class="fw5"><?= e($r['client_name']) ?></b><?= $r['client_phone'] || $r['client_mf'] ? '<br><small class="muted">' . e(trim($r['client_phone'] . ($r['client_mf'] ? ' · MF ' . $r['client_mf'] : ''), ' ·')) . '</small>' : '' ?></td>
        <?php if (!$scope): ?><td><?= e($r['location_id'] ? (location((int)$r['location_id'])['name'] ?? '') : 'Siège') ?></td><?php endif; ?>
        <?php if ($isBl): ?>
        <td><?= $r['transporter_name'] ? e($r['transporter_name']) . ($r['transporter_plate'] ? '<br><small class="muted">' . e($r['transporter_plate']) . '</small>' : '') : '<span class="muted">—</span>' ?></td>
        <?php else: ?>
        <td class="<?= doc_expired($r) || doc_overdue($r) ? 'late' : '' ?>"><?= $r['due_date'] ? fdate($r['due_date']) : '<span class="muted">—</span>' ?></td>
        <?php endif; ?>
        <td class="num"><b><?= money($r['net']) ?></b></td>
        <?php if ($isInvoice): ?><td class="num"><?= $r['status'] !== 'annulee' && $rr > 0 ? money($rr) : '' ?></td><?php endif; ?>
        <td><?= doc_badge($r) ?></td>
        <?php if ($listDelete): ?><td class="num"><?php if ($rowDel): ?>
          <form method="post" action="<?= e(url($route, ['a' => 'delete'])) ?>" onsubmit="return confirm(<?= e(json_encode(doc_delete_effects($r) . "\n\nConfirmer la suppression ?")) ?>)"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="icon-btn xs" title="Supprimer <?= e($r['number']) ?>" aria-label="Supprimer <?= e($r['number']) ?>"><?= icon('trash') ?></button></form>
        <?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="10" class="empty-row">
        <div class="empty-state"><?= icon(doc_icon($type), 'ic xl') ?><b><?= $counts[''] ? 'Aucun résultat pour ces filtres.' : ['devis' => 'Aucun devis pour l’instant.', 'facture' => 'Aucune facture pour l’instant.', 'bl' => 'Aucun bon de livraison pour l’instant.'][$type] ?></b>
          <?php if (!$counts[''] && can('invoices.edit')): ?><span><?= ['devis' => 'Préparez une proposition chiffrée pour un client, puis transformez-la en facture en un clic.',
              'facture' => 'Créez une facture directement, depuis un devis accepté ou depuis une vente de caisse.',
              'bl' => 'Établissez un bon de livraison directement ou depuis une facture (bouton « Établir le bon de livraison »), avec le transporteur, son matricule et sa CIN.'][$type] ?></span>
            <a class="btn primary sm" href="<?= e(url($route, ['a' => 'edit'])) ?>"><?= icon('plus') ?><?= ['devis' => 'Créer un devis', 'facture' => 'Créer une facture', 'bl' => 'Créer un bon de livraison'][$type] ?></a><?php endif; ?></div>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager($total, $page, $per, $qs + ['status' => $f['status']], $route) ?>
<?php
layout_end();
