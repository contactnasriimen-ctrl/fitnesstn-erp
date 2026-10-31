<?php
declare(strict_types=1);

// Devis et factures : liste, saisie et fiche. $DOC_TYPE est fixé par pages/quotes.php ou pages/invoices.php.
require_perm('invoices.view');
$type = $DOC_TYPE;
$isQuote = $type === 'devis';
$route = doc_route($type);
$label = DOC_TYPES[$type];
$a = (string)get('a', 'list');
$scope = scope_loc();

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
                'discount' => post('discount'), 'timbre' => post('timbre'), 'terms' => post('terms'), 'transport' => post('transport'),
                'warranty' => post('warranty'), 'notes' => post('notes'), 'internal_note' => post('internal_note'), 'stock' => post('stock'),
            ]);
            flash('ok', $label . ($id ? ' enregistré' : ' créé') . ($isQuote ? '.' : 'e.'));
            redirect($back($newId));
        }
        $d = doc_get($id, $type);
        if (!$d) throw new RuntimeException('Document introuvable.');
        if ($a !== 'internal_note') require_perm('invoices.edit');
        if (!can_loc($d['location_id'] !== null ? (int)$d['location_id'] : null)) throw new RuntimeException('Document introuvable.');
        switch ($a) {
            case 'status':
                doc_set_status($d, (string)post('status'));
                flash('ok', 'Statut mis à jour : ' . DOC_STATUS['devis'][(string)post('status')] . '.');
                break;
            case 'convert':
                $newId = doc_copy($d, 'facture', (bool)post('stock'));
                flash('ok', 'Facture créée à partir du devis ' . $d['number'] . '.');
                redirect(url('invoices', ['a' => 'view', 'id' => $newId]));
            case 'duplicate':
                $newId = doc_copy($d, $type);
                flash('ok', $label . ' dupliqué' . ($isQuote ? '' : 'e') . ' : vérifiez les prix et la date.');
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
                doc_delete($d);
                flash('ok', 'Devis supprimé.');
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
    $days = (int)setting($isQuote ? 'quote_validity_days' : 'invoice_due_days', 0);
    $f = $d ?: [
        'id' => 0, 'location_id' => $scope ?? ($_SESSION['pos_loc'] ?? (locations(true, 'showroom')[0]['id'] ?? null)),
        'client_id' => null, 'client_name' => '', 'client_phone' => '', 'client_email' => '', 'client_address' => '', 'client_mf' => '',
        'date' => today(), 'due_date' => $days > 0 ? date('Y-m-d', strtotime("+$days days")) : null, 'discount' => 0, 'timbre' => (int)setting('timbre', 1000),
        'terms' => setting('invoice_terms', ''), 'transport' => setting('invoice_transport', ''), 'warranty' => setting('invoice_warranty', ''),
        'notes' => '', 'internal_note' => '', 'stock_applied' => 1, 'sale_id' => null,
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
    $data = ['products' => $products, 'items' => $jsItems, 'vat' => (float)setting('vat_rate', 19), 'timbre' => (int)setting('timbre', 1000),
        'clientsUrl' => url($route, ['a' => 'clients'])];
    $title = $d ? 'Modifier ' . mb_strtolower($label) . ' ' . $d['number'] : ($isQuote ? 'Nouveau devis' : 'Nouvelle facture');
    layout_start($title, $route, ['wide' => true]);
    ?>
    <p><a class="back" href="<?= e($d ? url($route, ['a' => 'view', 'id' => $d['id']]) : url($route)) ?>"><?= icon('left') ?><?= $d ? e($d['number']) : ($isQuote ? 'Devis' : 'Factures') ?></a></p>
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
            <h2 class="span2"><span class="step">3</span>Conditions & mentions</h2>
            <label>Conditions de paiement<input name="terms" value="<?= e($f['terms']) ?>" list="dl-terms"></label>
            <label>Transport<input name="transport" value="<?= e($f['transport']) ?>" list="dl-transport"></label>
            <label>Garantie<input name="warranty" value="<?= e($f['warranty']) ?>"></label>
            <label>Note imprimée sur le document<input name="notes" value="<?= e($f['notes']) ?>" maxlength="1000" placeholder="Ex. délai de livraison, couleur…"></label>
            <label class="span2">Note interne <small>(non imprimée)</small><textarea name="internal_note" rows="2" maxlength="1000"><?= e($f['internal_note']) ?></textarea></label>
            <datalist id="dl-terms"><option>Paiement comptant à la livraison</option><option>Paiement comptant à la commande</option><option>Acompte de 30 % à la commande, solde à la livraison</option><option>Virement bancaire à 30 jours</option><option>Paiement par chèque</option></datalist>
            <datalist id="dl-transport"><option>Gratuit</option><option>À la charge du client</option><option>Livraison et installation incluses</option></datalist>
          </section>
        </div>

        <aside class="doc-side">
          <section class="card doc-summary">
            <div class="grid2 tight">
              <label><?= $isQuote ? 'Date du devis' : 'Date de facture' ?><input type="date" name="date" value="<?= e($f['date']) ?>" required></label>
              <label><?= $isQuote ? 'Valable jusqu’au' : 'Échéance' ?><input type="date" name="due_date" value="<?= e($f['due_date']) ?>"></label>
            </div>
            <?php if (!$scope): ?>
              <label>Showroom émetteur<select name="location_id"><?= opt(location_options(true), $f['location_id'], true, 'Aucun (siège)') ?></select></label>
            <?php endif; ?>
            <dl class="totals">
              <div><dt>Sous-total TTC</dt><dd data-t="subtotal">0,000</dd></div>
              <div class="disc"><dt><label for="doc-disc">Remise globale (DT)</label></dt><dd><input id="doc-disc" name="discount" inputmode="decimal" value="<?= e(m_in($f['discount'])) ?>" placeholder="0" data-discount></dd></div>
              <div><dt>Total HT</dt><dd data-t="ht">0,000</dd></div>
              <div data-vat-rows></div>
              <div class="timbre"><dt><label class="check"><input type="checkbox" name="timbre" value="1" <?= (int)$f['timbre'] > 0 ? 'checked' : '' ?> data-timbre> Timbre fiscal</label></dt><dd data-t="timbre">0,000</dd></div>
              <div class="grand"><dt>Net à payer</dt><dd data-t="net">0,000 DT</dd></div>
            </dl>
            <p class="words" data-words></p>
            <?php if (!$isQuote && !$f['sale_id']): ?>
              <label class="check stock-check"><input type="checkbox" name="stock" value="1" <?= $f['stock_applied'] ? 'checked' : '' ?>> Sortir les articles du stock du showroom<small>Décochez si la marchandise est déjà sortie (vente en caisse, livraison…).</small></label>
            <?php elseif (!$isQuote): ?>
              <p class="hint"><?= icon('check') ?> Facture liée à une vente de caisse : le stock a déjà été sorti.</p>
            <?php endif; ?>
            <button class="btn primary block lg-btn"><?= icon('check') ?><?= $d ? 'Enregistrer les modifications' : ($isQuote ? 'Créer le devis' : 'Créer la facture') ?></button>
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
    $canEdit = can('invoices.edit') && can_loc($d['location_id'] !== null ? (int)$d['location_id'] : null);
    $printUrl = url('doc', ['id' => $d['id']]);
    $wa = '';
    if ($d['client_phone']) {
        $msg = 'Bonjour ' . $d['client_name'] . ",\nVoici " . ($isQuote ? 'votre devis' : 'votre facture') . ' N° ' . $d['number'] . ' de ' . setting('company_name', 'FitnessTN')
            . ' d’un montant de ' . money($d['net']) . ($isQuote && $d['due_date'] ? ', valable jusqu’au ' . fdate($d['due_date']) : '') . ".\nMerci pour votre confiance !";
        $wa = 'https://wa.me/216' . $d['client_phone'] . '?text=' . rawurlencode($msg);
    }
    $actions = '<a class="btn sm" target="_blank" href="' . e(url('doc', ['id' => $d['id'], 'print' => 1])) . '">' . icon('print') . 'Imprimer / PDF</a>'
        . ($editable ? '<a class="btn sm primary" href="' . e(url($route, ['a' => 'edit', 'id' => $d['id']])) . '">' . icon('edit') . 'Modifier</a>' : '');
    layout_start($label . ' ' . $d['number'], $route, ['actions' => $actions, 'wide' => true]);
    ?>
    <p><a class="back" href="<?= e(url($route)) ?>"><?= icon('left') ?><?= $isQuote ? 'Tous les devis' : 'Toutes les factures' ?></a></p>
    <div class="doc-view">
      <div class="doc-preview">
        <div class="paper-frame" data-paper-frame><iframe src="<?= e(url('doc', ['id' => $d['id'], 'embed' => 1])) ?>" title="Aperçu du document" loading="lazy"></iframe></div>
      </div>
      <aside class="doc-aside">
        <section class="card doc-status">
          <div class="ds-top"><?= doc_badge($d) ?><span class="muted small"><?= $isQuote ? 'Devis' : 'Facture' ?> du <?= fdate($d['date']) ?></span></div>
          <div class="ds-amount"><span>Net à payer</span><b><?= money($d['net']) ?></b></div>
          <?php if (!$isQuote && $d['status'] !== 'annulee'): ?>
            <div class="meter" title="<?= e(money($d['paid'])) ?> encaissés"><span style="width:<?= $d['net'] ? min(100, round($d['paid'] / $d['net'] * 100)) : 0 ?>%"></span></div>
            <div class="ds-split"><span>Encaissé <b><?= money($d['paid']) ?></b></span><span>Reste <b class="<?= $rest > 0 ? 'warn-t' : 'pos' ?>"><?= money($rest) ?></b></span></div>
          <?php endif; ?>
          <dl class="kv">
            <dt>Client</dt><dd><?= $d['client_id'] ? '<a href="' . e(url('clients', ['a' => 'view', 'id' => $d['client_id']])) . '">' . e($d['client_name']) . '</a>' : e($d['client_name']) ?>
              <?= $d['client_phone'] ? '<br><a href="tel:' . e($d['client_phone']) . '">' . e($d['client_phone']) . '</a>' : '' ?></dd>
            <?php if ($d['due_date']): ?><dt><?= $isQuote ? 'Valable jusqu’au' : 'Échéance' ?></dt><dd class="<?= doc_expired($d) || doc_overdue($d) ? 'late' : '' ?>"><?= fdate($d['due_date']) ?></dd><?php endif; ?>
            <?php if ($d['location_id']): ?><dt>Showroom</dt><dd><?= e(location((int)$d['location_id'])['name'] ?? '') ?></dd><?php endif; ?>
            <dt>Établi par</dt><dd><?= e($d['author'] ?: '—') ?></dd>
            <?php if ($source): ?><dt>Devis d’origine</dt><dd><a href="<?= e(url(doc_route($source['type']), ['a' => 'view', 'id' => $source['id']])) ?>"><?= e($source['number']) ?></a></dd><?php endif; ?>
            <?php foreach ($children as $ch): ?><dt><?= e(DOC_TYPES[$ch['type']]) ?></dt><dd><a href="<?= e(url(doc_route($ch['type']), ['a' => 'view', 'id' => $ch['id']])) ?>"><?= e($ch['number']) ?></a> <?= doc_badge($ch + ['due_date' => null]) ?></dd><?php endforeach; ?>
            <?php if ($sale): ?><dt>Vente caisse</dt><dd><a href="<?= e(url('sales', ['a' => 'view', 'id' => $sale['id']])) ?>"><?= e($sale['number']) ?></a></dd><?php endif; ?>
            <?php if (!$isQuote): ?><dt>Stock</dt><dd><?= $d['stock_applied'] ? 'Sorti du showroom' : ($sale ? 'Sorti par la vente de caisse' : 'Non mouvementé') ?></dd><?php endif; ?>
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

        <?php if (!$isQuote): ?>
          <section class="card">
            <h2>Paiements</h2>
            <?php if ($pays): ?>
              <ul class="pay-list">
                <?php foreach ($pays as $p): ?>
                  <li><span class="pl-ic"><?= icon('cash') ?></span><span class="pl-main"><b><?= money($p['amount']) ?></b><small><?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?> · <?= fdate($p['date']) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></small></span>
                    <?php if (is_admin() && $d['status'] !== 'annulee'): ?>
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
                <button class="btn primary sm block"><?= icon('cash') ?>Enregistrer le paiement</button>
              </form>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <section class="card">
          <h2>Historique</h2>
          <ol class="timeline">
            <li><b><?= $isQuote ? 'Devis créé' : 'Facture émise' ?></b><small><?= fdate($d['created_at'], true) ?><?= $d['author'] ? ' · ' . e($d['author']) : '' ?></small></li>
            <?php if ($d['sent_at']): ?><li><b>Envoyé au client</b><small><?= fdate($d['sent_at'], true) ?></small></li><?php endif; ?>
            <?php if ($isQuote && in_array($d['status'], ['accepte', 'refuse', 'facture'], true) && $d['closed_at']): ?><li class="<?= $d['status'] === 'refuse' ? 'err' : 'ok' ?>"><b><?= $d['status'] === 'refuse' ? 'Refusé' : 'Accepté' ?> par le client</b><small><?= fdate($d['closed_at'], true) ?></small></li><?php endif; ?>
            <?php foreach ($children as $ch): ?><li class="ok"><b>Transformé en facture <?= e($ch['number']) ?></b></li><?php endforeach; ?>
            <?php foreach ($pays as $p): ?><li class="ok"><b>Paiement de <?= money($p['amount']) ?></b><small><?= fdate($p['date']) ?> · <?= e(PAY_METHODS[$p['method']] ?? '') ?><?= $p['user_name'] ? ' · ' . e($p['user_name']) : '' ?></small></li><?php endforeach; ?>
            <?php if ($d['status'] === 'annulee'): ?><li class="err"><b>Annulée</b><small><?= fdate($d['cancelled_at'], true) ?> — <?= e($d['cancel_reason']) ?></small></li><?php endif; ?>
          </ol>
          <form method="post" action="<?= e(url($route, ['a' => 'internal_note'])) ?>" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input name="internal_note" value="<?= e($d['internal_note']) ?>" placeholder="Note interne…" maxlength="1000">
            <button class="btn sm ghost">Enregistrer</button>
          </form>
        </section>

        <?php if (!$isQuote && is_admin() && $d['status'] !== 'annulee'): ?>
          <details class="card danger-zone">
            <summary>Annuler cette facture</summary>
            <form method="post" action="<?= e(url($route, ['a' => 'cancel'])) ?>" onsubmit="return confirm('Annuler définitivement la facture <?= e($d['number']) ?> ?')">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <p class="muted">Le numéro reste attribué (une facture ne se supprime pas)<?= $d['stock_applied'] ? ' et les articles sont remis en stock' : '' ?>. Les paiements déjà reçus restent visibles.</p>
              <label>Motif<input name="reason" required maxlength="300"></label>
              <button class="btn danger"><?= icon('x') ?>Annuler la facture</button>
            </form>
          </details>
        <?php elseif ($isQuote && $canEdit && $d['status'] !== 'facture' && (is_admin() || (int)$d['created_by'] === (int)user()['id'])): ?>
          <form method="post" action="<?= e(url($route, ['a' => 'delete'])) ?>" class="danger-inline" onsubmit="return confirm('Supprimer ce devis ?')">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn ghost danger sm"><?= icon('trash') ?>Supprimer le devis</button>
          </form>
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
    $base[] = '(d.number LIKE ? OR d.client_name LIKE ? OR d.client_phone LIKE ? OR d.client_mf LIKE ?)';
    array_push($bp, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . (normalize_phone($f['q']) ?: $f['q']) . '%', '%' . $f['q'] . '%');
}
$late = $isQuote ? "d.status IN ('brouillon','envoye') AND d.due_date < '" . today() . "'" : "d.status IN ('emise','partielle') AND d.due_date < '" . today() . "'";
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
} else {
    $k = one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN status <> 'annulee' THEN net END),0) t, COALESCE(SUM(CASE WHEN status <> 'annulee' THEN paid END),0) paid,
        COALESCE(SUM(CASE WHEN $late THEN net - paid END),0) overdue, SUM(CASE WHEN status IN ('emise','partielle') THEN 1 ELSE 0 END) open
        FROM documents d WHERE $bw", $bp);
}

$exportParams = array_filter(['ds' => $isQuote ? 'devis' : 'factures', 'from' => $f['from'], 'to' => $f['to'], 'loc' => $f['loc'] ?: null]);
$actions = (can('invoices.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export'] + $exportParams)) . '">' . icon('download') . 'Export CSV</a>' : '')
    . (can('invoices.edit') ? '<a class="btn sm primary" href="' . e(url($route, ['a' => 'edit'])) . '">' . icon('plus') . ($isQuote ? 'Nouveau devis' : 'Nouvelle facture') . '</a>' : '');
layout_start($isQuote ? 'Devis' : 'Factures', $route, ['actions' => $actions]);
$qs = array_filter(['from' => $f['from'], 'to' => $f['to'], 'loc' => $scope ? null : ($f['loc'] ?: null), 'q' => $f['q']]);
?>
<div class="grid4 kpis">
  <?php if ($isQuote): ?>
    <div class="kpi with-ic"><span class="kpi-ic"><?= icon('quote') ?></span><span>Devis émis</span><b><?= (int)$k['n'] ?></b><small><?= money0($k['t']) ?> au total</small></div>
    <div class="kpi with-ic"><span class="kpi-ic info"><?= icon('send') ?></span><span>En attente de réponse</span><b><?= money0($k['pending']) ?></b><small><?= (int)$k['pn'] ?> devis à relancer</small></div>
    <div class="kpi with-ic"><span class="kpi-ic ok"><?= icon('check') ?></span><span>Taux de transformation</span><b><?= $rate === null ? '—' : pct($rate) ?></b><small><?= (int)$k['won'] ?> accepté(s) sur <?= (int)$k['closed'] ?> réponse(s)</small></div>
    <div class="kpi with-ic"><span class="kpi-ic warn"><?= icon('invoice') ?></span><span>Acceptés à facturer</span><b class="<?= (int)$k['tobill'] ? 'warn-t' : '' ?>"><?= money0($k['tobill']) ?></b><small><a href="<?= e(url($route, $qs + ['status' => 'accepte'])) ?>">Voir les devis acceptés</a></small></div>
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
  <label class="grow">Recherche<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="N°, client, téléphone, matricule fiscal"></label>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>

<div class="card flush">
  <table class="tbl hover">
    <thead><tr><th>N°</th><th>Date</th><th>Client</th><?php if (!$scope): ?><th>Showroom</th><?php endif; ?><th><?= $isQuote ? 'Validité' : 'Échéance' ?></th><th class="num">Net à payer</th><?php if (!$isQuote): ?><th class="num">Reste</th><?php endif; ?><th>Statut</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $rr = (int)$r['net'] - (int)$r['paid']; ?>
      <tr data-href="<?= e(url($route, ['a' => 'view', 'id' => $r['id']])) ?>">
        <td><a href="<?= e(url($route, ['a' => 'view', 'id' => $r['id']])) ?>"><b><?= e($r['number']) ?></b></a></td>
        <td><?= fdate($r['date']) ?></td>
        <td><b class="fw5"><?= e($r['client_name']) ?></b><?= $r['client_phone'] || $r['client_mf'] ? '<br><small class="muted">' . e(trim($r['client_phone'] . ($r['client_mf'] ? ' · MF ' . $r['client_mf'] : ''), ' ·')) . '</small>' : '' ?></td>
        <?php if (!$scope): ?><td><?= e($r['location_id'] ? (location((int)$r['location_id'])['name'] ?? '') : 'Siège') ?></td><?php endif; ?>
        <td class="<?= doc_expired($r) || doc_overdue($r) ? 'late' : '' ?>"><?= $r['due_date'] ? fdate($r['due_date']) : '<span class="muted">—</span>' ?></td>
        <td class="num"><b><?= money($r['net']) ?></b></td>
        <?php if (!$isQuote): ?><td class="num"><?= $r['status'] !== 'annulee' && $rr > 0 ? money($rr) : '' ?></td><?php endif; ?>
        <td><?= doc_badge($r) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="8" class="empty-row">
        <div class="empty-state"><?= icon($isQuote ? 'quote' : 'invoice', 'ic xl') ?><b><?= $counts[''] ? 'Aucun résultat pour ces filtres.' : ($isQuote ? 'Aucun devis pour l’instant.' : 'Aucune facture pour l’instant.') ?></b>
          <?php if (!$counts[''] && can('invoices.edit')): ?><span><?= $isQuote ? 'Préparez une proposition chiffrée pour un client, puis transformez-la en facture en un clic.' : 'Créez une facture directement, depuis un devis accepté ou depuis une vente de caisse.' ?></span>
            <a class="btn primary sm" href="<?= e(url($route, ['a' => 'edit'])) ?>"><?= icon('plus') ?><?= $isQuote ? 'Créer un devis' : 'Créer une facture' ?></a><?php endif; ?></div>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager($total, $page, $per, $qs + ['status' => $f['status']], $route) ?>
<?php
layout_end();
