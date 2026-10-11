<?php
declare(strict_types=1);

/*
 * Jeux de données importables / exportables en CSV.
 * Chaque entrée : label, help, export (permission ou liste), import (permission|null),
 * cols (clé => en-tête), rows(filtres) => itérable, import_row(ligne, options) => created|updated|skipped.
 */

function loc_from_text(string $s): ?int
{
    $s = trim($s);
    if ($s === '') return null;
    foreach (locations(false) as $l) {
        if (strcasecmp($l['code'], $s) === 0 || slug_key($l['name']) === slug_key($s) || (string)$l['id'] === $s) return (int)$l['id'];
    }
    throw new RuntimeException("Site « $s » inconnu (codes : " . implode(', ', array_column(locations(false), 'code')) . ').');
}

function user_from_text(string $s): ?int
{
    $s = trim($s);
    if ($s === '') return null;
    $id = val('SELECT id FROM users WHERE username = ? OR name = ? COLLATE NOCASE', [$s, $s]);
    return $id ? (int)$id : null;
}

function key_from_label(array $map, string $s, string $default): string
{
    $s = trim($s);
    if ($s === '') return $default;
    if (isset($map[$s])) return $s;
    foreach ($map as $k => $label) if (slug_key($label) === slug_key($s) || slug_key($k) === slug_key($s)) return $k;
    return $default;
}

function product_from_row(array $r): ?array
{
    if (($id = parse_int($r['id'] ?? '')) && ($p = one('SELECT * FROM products WHERE id = ?', [$id]))) return $p;
    if (($wid = parse_int($r['woo_id'] ?? '')) && ($p = one('SELECT * FROM products WHERE woo_id = ?', [$wid]))) return $p;
    if (trim($r['sku'] ?? '') !== '' && ($p = one('SELECT * FROM products WHERE sku = ?', [trim($r['sku'])]))) return $p;
    if (trim($r['name'] ?? '') !== '' && ($p = one('SELECT * FROM products WHERE name = ? COLLATE NOCASE', [trim($r['name'])]))) return $p;
    return null;
}

function export_scope(array $f): ?int
{
    $s = scope_loc();
    return $s ?? ((int)($f['loc'] ?? 0) ?: null);
}

function datasets(): array
{
    static $ds = null;
    if ($ds) return $ds;
    $locs = locations(false);

    $ds['produits'] = [
        'label' => 'Produits (catalogue)', 'icon' => 'box', 'export' => 'products.export', 'import' => 'products.import',
        'help' => 'Accepte l’export WooCommerce du site (wc-product-export…csv) ou ce format. Correspondance par ID, ID site, référence (UGS).',
        'cols' => ['id' => 'ID', 'woo_id' => 'ID site', 'sku' => 'Référence', 'barcode' => 'Code-barres', 'name' => 'Nom', 'family' => 'Famille',
            'brand' => 'Marque', 'price' => 'Prix de vente', 'promo_price' => 'Prix promo', 'cost' => 'Prix d’achat', 'warranty_months' => 'Garantie (mois)',
            'min_stock' => 'Stock minimum', 'active' => 'Actif', 'stock_total' => 'Stock total', 'categories' => 'Catégories site',
            'description' => 'Description', 'image_url' => 'Image (URL)'],
        'rows' => function (array $f) {
            $admin = is_admin();
            foreach (all('SELECT p.*, COALESCE((SELECT SUM(qty) FROM stock WHERE product_id = p.id),0) st FROM products p ORDER BY p.family, p.name COLLATE NOCASE') as $p) {
                yield [$p['id'], $p['woo_id'], $p['sku'], $p['barcode'], $p['name'], $p['family'], $p['brand'], csv_money($p['price']),
                    csv_money($p['promo_price']), $admin ? csv_money($p['cost']) : '', $p['warranty_months'], $p['min_stock'], csv_bool($p['active']),
                    $p['st'], $p['categories'], $p['description'], $p['image_url'] ?: $p['image']];
            }
        },
        'import_row' => function (array $r, array $o) {
            if (!$o['woo'] && !is_admin()) unset($r['cost']);
            return product_import_row($r, ['mode' => $o['woo'] ? 'woo' : 'erp', 'stock_location' => $o['stock_location'] ?? null, 'can_cost' => is_admin()]);
        },
    ];

    $stockCols = ['id' => 'ID', 'sku' => 'Référence', 'name' => 'Nom', 'family' => 'Famille'];
    foreach ($locs as $l) $stockCols['loc_' . strtolower($l['code'])] = 'Stock ' . $l['code'];
    $stockCols += ['total' => 'Total', 'location' => 'Emplacement', 'qty' => 'Quantité'];
    $ds['stock'] = [
        'label' => 'Stock par emplacement', 'icon' => 'stock', 'export' => 'stock.export', 'import' => 'stock.import',
        'help' => 'Deux formats : une colonne « Stock TUN / Stock NAB / Stock DEP » par site, ou les colonnes « Emplacement » + « Quantité ». Produit reconnu par ID, référence ou nom exact.',
        'cols' => $stockCols,
        'rows' => function (array $f) use ($locs) {
            $by = [];
            foreach (all('SELECT product_id, location_id, qty FROM stock') as $s) $by[$s['product_id']][$s['location_id']] = (int)$s['qty'];
            foreach (all('SELECT id, sku, name, family FROM products WHERE active = 1 ORDER BY family, name COLLATE NOCASE') as $p) {
                $row = [$p['id'], $p['sku'], $p['name'], $p['family']];
                $tot = 0;
                foreach ($locs as $l) { $q = $by[$p['id']][$l['id']] ?? 0; $row[] = $q; $tot += $q; }
                $row[] = $tot;
                $row[] = '';
                $row[] = '';
                yield $row;
            }
        },
        'import_row' => function (array $r, array $o) use ($locs) {
            $p = product_from_row($r);
            if (!$p) throw new RuntimeException('Produit introuvable (' . trim(($r['id'] ?? '') . ' ' . ($r['sku'] ?? '') . ' ' . ($r['name'] ?? '')) . ').');
            $add = ($o['mode'] ?? 'set') === 'add';
            $done = false;
            $apply = function (int $loc, int $qty) use ($p, $add, &$done) {
                if ($add) { if ($qty !== 0) stock_change((int)$p['id'], $loc, $qty, 'entree', 'Import CSV'); }
                else stock_set((int)$p['id'], $loc, $qty, 'import', 'Import CSV (inventaire)');
                $done = true;
            };
            foreach ($locs as $l) {
                $k = 'loc_' . strtolower($l['code']);
                if (isset($r[$k]) && trim($r[$k]) !== '') {
                    $q = parse_int($r[$k]);
                    if ($q === null) throw new RuntimeException('Quantité invalide « ' . $r[$k] . ' ».');
                    $apply((int)$l['id'], $q);
                }
            }
            if (trim($r['qty'] ?? '') !== '') {
                $loc = loc_from_text($r['location'] ?? '') ?? ($o['location'] ?? null);
                if (!$loc) throw new RuntimeException('Emplacement manquant.');
                $q = parse_int($r['qty']);
                if ($q === null) throw new RuntimeException('Quantité invalide « ' . $r['qty'] . ' ».');
                $apply($loc, $q);
            }
            return $done ? 'updated' : 'skipped';
        },
    ];

    $ds['mouvements'] = [
        'label' => 'Mouvements de stock', 'icon' => 'transfer', 'export' => 'stock.export', 'import' => null, 'dates' => true,
        'help' => 'Historique des entrées, sorties, transferts, ventes et inventaires.',
        'cols' => ['date' => 'Date', 'product' => 'Produit', 'sku' => 'Référence', 'location' => 'Emplacement', 'type' => 'Type', 'qty' => 'Quantité', 'after' => 'Stock après', 'ref' => 'Référence mouvement', 'note' => 'Note', 'user' => 'Utilisateur'],
        'rows' => function (array $f) {
            foreach (all('SELECT m.*, p.name, p.sku, l.code, u.name un FROM stock_moves m JOIN products p ON p.id = m.product_id JOIN locations l ON l.id = m.location_id
                LEFT JOIN users u ON u.id = m.user_id WHERE date(m.created_at) BETWEEN ? AND ? ORDER BY m.id', [$f['from'], $f['to']]) as $m) {
                yield [$m['created_at'], $m['name'], $m['sku'], $m['code'], MOVE_TYPES[$m['type']] ?? $m['type'], $m['qty_change'], $m['qty_after'], $m['ref'], $m['note'], $m['un']];
            }
        },
    ];

    $ds['ventes'] = [
        'label' => 'Ventes (détail des articles)', 'icon' => 'receipt', 'export' => ['sales.export', 'marketing.stats'], 'import' => null, 'dates' => true, 'loc' => true,
        'help' => 'Une ligne par article vendu, avec le client, l’origine et la campagne.',
        'cols' => ['date' => 'Date', 'number' => 'N° vente', 'loc' => 'Showroom', 'status' => 'Statut', 'client' => 'Client', 'phone' => 'Téléphone', 'city' => 'Ville',
            'source' => 'Origine', 'campaign' => 'Campagne', 'product' => 'Article', 'sku' => 'Référence', 'family' => 'Famille', 'qty' => 'Quantité',
            'list' => 'Prix catalogue', 'unit' => 'Prix unitaire', 'line' => 'Total ligne', 'sale_total' => 'Total vente', 'paid' => 'Payé', 'rest' => 'Reste', 'seller' => 'Vendeur'],
        'rows' => function (array $f) {
            $loc = export_scope($f);
            $hidePhone = user()['role'] === 'marketing';
            foreach (all('SELECT s.*, si.label, si.qty, si.list_price, si.unit_price, si.total line, p.sku, p.family, l.name loc, c.name client, c.phone, c.city, cp.name camp, u.name seller
                FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN locations l ON l.id = s.location_id LEFT JOIN products p ON p.id = si.product_id
                LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN campaigns cp ON cp.id = s.campaign_id LEFT JOIN users u ON u.id = s.user_id
                WHERE date(s.created_at) BETWEEN ? AND ?' . ($loc ? ' AND s.location_id = ' . (int)$loc : '') . ' ORDER BY s.id, si.id', [$f['from'], $f['to']]) as $r) {
                $st = ['payee' => 'Payée', 'partielle' => 'Reste à payer', 'annulee' => 'Annulée'][$r['status']] ?? $r['status'];
                yield [$r['created_at'], $r['number'], $r['loc'], $st, $r['client'], $hidePhone ? '' : $r['phone'], $r['city'], SOURCES[$r['source']] ?? '', $r['camp'],
                    $r['label'], $r['sku'], $r['family'], $r['qty'], csv_money($r['list_price']), csv_money($r['unit_price']), csv_money($r['line']),
                    csv_money($r['total']), csv_money($r['paid']), csv_money($r['status'] === 'annulee' ? 0 : $r['total'] - $r['paid']), $r['seller']];
            }
        },
    ];

    $ds['paiements'] = [
        'label' => 'Paiements & encaissements', 'icon' => 'cash', 'export' => 'payments.export', 'import' => null, 'dates' => true, 'loc' => true,
        'help' => 'Tous les encaissements (et remboursements en négatif) par mode de paiement — pour le rapprochement bancaire.',
        'cols' => ['date' => 'Date', 'sale' => 'N° vente', 'loc' => 'Showroom', 'method' => 'Mode', 'amount' => 'Montant', 'ref' => 'Référence', 'session' => 'Session de caisse', 'user' => 'Par'],
        'rows' => function (array $f) {
            $loc = export_scope($f);
            foreach (all('SELECT p.*, s.number, l.name loc, u.name un FROM payments p JOIN sales s ON s.id = p.sale_id JOIN locations l ON l.id = p.location_id LEFT JOIN users u ON u.id = p.user_id
                WHERE date(p.created_at) BETWEEN ? AND ?' . ($loc ? ' AND p.location_id = ' . (int)$loc : '') . ' ORDER BY p.id', [$f['from'], $f['to']]) as $p) {
                yield [$p['created_at'], $p['number'], $p['loc'], PAY_METHODS[$p['method']] ?? $p['method'], csv_money($p['amount']), $p['reference'], $p['session_id'], $p['un']];
            }
        },
    ];

    $ds['caisses'] = [
        'label' => 'Sessions de caisse (clôtures)', 'icon' => 'pos', 'export' => 'sessions.export', 'import' => null, 'dates' => true, 'loc' => true,
        'help' => 'Ouvertures, clôtures, espèces attendues et comptées, écarts, versements au coffre.',
        'cols' => ['id' => 'N°', 'loc' => 'Showroom', 'opened' => 'Ouverture', 'closed' => 'Clôture', 'float' => 'Fond de caisse', 'expected' => 'Espèces attendues',
            'counted' => 'Espèces comptées', 'diff' => 'Écart', 'safe' => 'Versé au coffre', 'envelope' => 'Enveloppe', 'note' => 'Justification'],
        'rows' => function (array $f) {
            $loc = export_scope($f);
            foreach (all('SELECT cs.*, l.name loc FROM cash_sessions cs JOIN locations l ON l.id = cs.location_id WHERE date(cs.opened_at) BETWEEN ? AND ?'
                . ($loc ? ' AND cs.location_id = ' . (int)$loc : '') . ' ORDER BY cs.id', [$f['from'], $f['to']]) as $s) {
                yield [$s['id'], $s['loc'], $s['opened_at'], $s['closed_at'], csv_money($s['opening_float']), csv_money($s['expected_cash']), csv_money($s['counted_cash']),
                    csv_money($s['difference']), csv_money($s['to_safe']), $s['envelope'], $s['note']];
            }
        },
    ];

    $ds['creances'] = [
        'label' => 'Reste à encaisser (créances)', 'icon' => 'wallet', 'export' => 'sales.export', 'import' => null,
        'help' => 'Ventes avec un reste à payer (facilités, acomptes).',
        'cols' => ['date' => 'Date', 'number' => 'N° vente', 'loc' => 'Showroom', 'client' => 'Client', 'phone' => 'Téléphone', 'total' => 'Total', 'paid' => 'Payé', 'rest' => 'Reste'],
        'rows' => function (array $f) {
            $loc = scope_loc();
            foreach (all("SELECT s.*, l.name loc, c.name client, c.phone FROM sales s JOIN locations l ON l.id = s.location_id LEFT JOIN clients c ON c.id = s.client_id
                WHERE s.status = 'partielle'" . ($loc ? ' AND s.location_id = ' . (int)$loc : '') . ' ORDER BY s.created_at') as $r) {
                yield [$r['created_at'], $r['number'], $r['loc'], $r['client'], $r['phone'], csv_money($r['total']), csv_money($r['paid']), csv_money($r['total'] - $r['paid'])];
            }
        },
    ];

    $ds['clients'] = [
        'label' => 'Clients', 'icon' => 'users', 'export' => 'clients.export', 'import' => 'clients.import',
        'help' => 'Correspondance par ID puis par téléphone (8 chiffres). Les doublons de téléphone sont fusionnés.',
        'cols' => ['id' => 'ID', 'name' => 'Nom', 'phone' => 'Téléphone', 'phone2' => 'Téléphone 2', 'email' => 'E-mail', 'gouvernorat' => 'Gouvernorat',
            'city' => 'Ville', 'address' => 'Adresse', 'source' => 'Origine', 'notes' => 'Notes', 'created_at' => 'Créé le', 'sales_total' => 'Total achats'],
        'rows' => function (array $f) {
            foreach (all("SELECT c.*, (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.client_id = c.id AND s.status <> 'annulee') t FROM clients c ORDER BY c.name") as $c) {
                yield [$c['id'], $c['name'], $c['phone'], $c['phone2'], $c['email'], $c['gouvernorat'], $c['city'], $c['address'], SOURCES[$c['source']] ?? $c['source'],
                    $c['notes'], $c['created_at'], csv_money($c['t'])];
            }
        },
        'import_row' => function (array $r) {
            $name = trim($r['name'] ?? '');
            $phone = normalize_phone($r['phone'] ?? '');
            if ($name === '' && $phone === '') return 'skipped';
            $ex = ($id = parse_int($r['id'] ?? '')) ? one('SELECT * FROM clients WHERE id = ?', [$id]) : null;
            if (!$ex && $phone !== '') $ex = one('SELECT * FROM clients WHERE phone = ?', [$phone]);
            $data = ['updated_at' => now()];
            foreach (['name', 'email', 'gouvernorat', 'city', 'address', 'notes'] as $k) if (isset($r[$k]) && trim($r[$k]) !== '') $data[$k] = trim($r[$k]);
            if ($phone !== '') $data['phone'] = $phone;
            if (trim($r['phone2'] ?? '') !== '') $data['phone2'] = normalize_phone($r['phone2']);
            if (trim($r['source'] ?? '') !== '') $data['source'] = key_from_label(SOURCES, $r['source'], 'autre');
            if ($ex) { update('clients', $data, 'id = ?', [$ex['id']]); return 'updated'; }
            if ($name === '') $data['name'] = 'Client ' . $phone;
            insert('clients', $data + ['created_at' => now()]);
            return 'created';
        },
    ];

    $ds['transporteurs'] = [
        'label' => 'Transporteurs', 'icon' => 'truck', 'export' => 'deliveries.export', 'import' => 'carriers.import',
        'help' => 'Correspondance par ID puis par nom.',
        'cols' => ['id' => 'ID', 'name' => 'Nom', 'type' => 'Type', 'phone' => 'Téléphone', 'contact' => 'Contact', 'zones' => 'Zones', 'default_fee' => 'Tarif par défaut', 'cod' => 'Paiement à la livraison', 'active' => 'Actif', 'notes' => 'Notes'],
        'rows' => function () {
            foreach (all('SELECT * FROM carriers ORDER BY name') as $c) {
                yield [$c['id'], $c['name'], $c['type'], $c['phone'], $c['contact'], $c['zones'], csv_money($c['default_fee']), csv_bool($c['cod']), csv_bool($c['active']), $c['notes']];
            }
        },
        'import_row' => function (array $r) {
            $name = trim($r['name'] ?? '');
            if ($name === '') return 'skipped';
            $ex = ($id = parse_int($r['id'] ?? '')) ? one('SELECT * FROM carriers WHERE id = ?', [$id]) : null;
            if (!$ex) $ex = one('SELECT * FROM carriers WHERE name = ? COLLATE NOCASE', [$name]);
            $data = ['name' => $name, 'type' => slug_key($r['type'] ?? '') === 'interne' ? 'interne' : 'externe'];
            foreach (['phone', 'contact', 'zones', 'notes'] as $k) if (isset($r[$k])) $data[$k] = trim($r[$k]);
            if (isset($r['default_fee'])) $data['default_fee'] = max(0, parse_money($r['default_fee']) ?? 0);
            if (isset($r['cod']) && trim($r['cod']) !== '') $data['cod'] = csv_yes($r['cod']) ? 1 : 0;
            if (isset($r['active']) && trim($r['active']) !== '') $data['active'] = csv_yes($r['active']) ? 1 : 0;
            if ($ex) { update('carriers', $data, 'id = ?', [$ex['id']]); return 'updated'; }
            insert('carriers', $data + ['created_at' => now()]);
            return 'created';
        },
    ];

    $ds['livraisons'] = [
        'label' => 'Livraisons', 'icon' => 'truck', 'export' => 'deliveries.export', 'import' => 'deliveries.import', 'loc' => true,
        'help' => 'Mise à jour en masse par N° de livraison (statut, n° de suivi, frais…). Sans N°, une nouvelle livraison est créée. Statuts : ' . implode(', ', SHIP_STATUS) . '.',
        'cols' => ['number' => 'N° livraison', 'sale' => 'N° vente', 'loc' => 'Départ', 'recipient' => 'Destinataire', 'phone' => 'Téléphone', 'gouvernorat' => 'Gouvernorat',
            'city' => 'Ville', 'address' => 'Adresse', 'carrier' => 'Transporteur', 'tracking' => 'N° de suivi', 'status' => 'Statut', 'planned_date' => 'Date prévue',
            'delivered_at' => 'Livrée le', 'fee' => 'Coût transport', 'cod_amount' => 'À encaisser', 'cod_collected' => 'Encaissé', 'note' => 'Note'],
        'rows' => function (array $f) {
            $loc = export_scope($f);
            foreach (all('SELECT sh.*, s.number sale, l.code loc, ca.name carrier FROM shipments sh LEFT JOIN sales s ON s.id = sh.sale_id LEFT JOIN locations l ON l.id = sh.location_id
                LEFT JOIN carriers ca ON ca.id = sh.carrier_id' . ($loc ? ' WHERE sh.location_id = ' . (int)$loc : '') . ' ORDER BY sh.id') as $s) {
                yield [$s['number'], $s['sale'], $s['loc'], $s['recipient'], $s['phone'], $s['gouvernorat'], $s['city'], $s['address'], $s['carrier'], $s['tracking'],
                    SHIP_STATUS[$s['status']] ?? $s['status'], $s['planned_date'], $s['delivered_at'], csv_money($s['fee']), csv_money($s['cod_amount']), csv_bool($s['cod_collected']), $s['note']];
            }
        },
        'import_row' => function (array $r) {
            $num = trim($r['number'] ?? '');
            $ex = $num !== '' ? one('SELECT * FROM shipments WHERE number = ?', [$num]) : null;
            if ($num !== '' && !$ex) throw new RuntimeException("Livraison $num introuvable.");
            if ($ex && !can_loc($ex['location_id'] !== null ? (int)$ex['location_id'] : null)) throw new RuntimeException("Livraison $num : autre showroom.");
            $data = ['updated_at' => now()];
            foreach (['recipient', 'gouvernorat', 'city', 'address', 'tracking', 'note'] as $k) if (isset($r[$k]) && trim($r[$k]) !== '') $data[$k] = trim($r[$k]);
            if (trim($r['phone'] ?? '') !== '') $data['phone'] = normalize_phone($r['phone']);
            if (trim($r['status'] ?? '') !== '') {
                $st = key_from_label(SHIP_STATUS, $r['status'], '');
                if ($st === '') throw new RuntimeException('Statut « ' . $r['status'] . ' » inconnu.');
                $data['status'] = $st;
                if ($st === 'livree') $data['delivered_at'] = parse_date($r['delivered_at'] ?? '') ?: ($ex['delivered_at'] ?? now());
            }
            if (trim($r['carrier'] ?? '') !== '') {
                $cid = val('SELECT id FROM carriers WHERE name = ? COLLATE NOCASE', [trim($r['carrier'])]);
                if (!$cid) throw new RuntimeException('Transporteur « ' . $r['carrier'] . ' » inconnu.');
                $data['carrier_id'] = (int)$cid;
            }
            if (trim($r['planned_date'] ?? '') !== '') $data['planned_date'] = parse_date($r['planned_date']);
            if (trim($r['fee'] ?? '') !== '') $data['fee'] = max(0, parse_money($r['fee']) ?? 0);
            if (trim($r['cod_amount'] ?? '') !== '' && !($ex['cod_collected'] ?? 0)) $data['cod_amount'] = max(0, parse_money($r['cod_amount']) ?? 0);
            if ($ex) {
                update('shipments', $data, 'id = ?', [$ex['id']]);
                if (csv_yes($r['cod_collected'] ?? '') && !$ex['cod_collected']) shipment_collect(one('SELECT * FROM shipments WHERE id = ?', [$ex['id']]));
                return 'updated';
            }
            if (empty($data['recipient'])) throw new RuntimeException('Destinataire manquant.');
            $loc = scope_loc() ?? loc_from_text($r['loc'] ?? '');
            $saleId = null;
            if (trim($r['sale'] ?? '') !== '') {
                $saleId = val('SELECT id FROM sales WHERE number = ?', [trim($r['sale'])]);
                if (!$saleId) throw new RuntimeException('Vente « ' . $r['sale'] . ' » introuvable.');
            }
            shipment_create($data + ['location_id' => $loc, 'sale_id' => $saleId]);
            return 'created';
        },
    ];

    $ds['taches'] = [
        'label' => 'Tâches', 'icon' => 'tasks', 'export' => 'tasks.export', 'import' => 'tasks.import', 'board' => true,
        'help' => 'Par tableau. Correspondance par ID ; une colonne inconnue est créée. Responsable = identifiant ou nom. Priorités : basse, normale, haute, urgente.',
        'cols' => ['id' => 'ID', 'board' => 'Tableau', 'column' => 'Colonne', 'title' => 'Titre', 'description' => 'Description', 'assignee' => 'Responsable',
            'priority' => 'Priorité', 'due_date' => 'Échéance', 'labels' => 'Étiquettes', 'created_at' => 'Créée le', 'done_at' => 'Terminée le'],
        'rows' => function (array $f) {
            $b = one('SELECT * FROM boards WHERE id = ?', [(int)($f['board'] ?? 0)]);
            if (!$b || !board_can_view($b)) return;
            foreach (all('SELECT t.*, c.name col, u.username FROM tasks t JOIN board_columns c ON c.id = t.column_id LEFT JOIN users u ON u.id = t.assignee_id
                WHERE t.board_id = ? ORDER BY c.position, t.position', [$b['id']]) as $t) {
                yield [$t['id'], $b['name'], $t['col'], $t['title'], $t['description'], $t['username'], PRIORITIES[$t['priority']] ?? $t['priority'], $t['due_date'], $t['labels'], $t['created_at'], $t['done_at']];
            }
        },
        'import_row' => function (array $r, array $o) {
            $b = one('SELECT * FROM boards WHERE id = ?', [(int)($o['board'] ?? 0)]);
            if (!$b || !board_can_edit($b)) throw new RuntimeException('Tableau non autorisé.');
            $title = trim($r['title'] ?? '');
            if ($title === '') return 'skipped';
            $colName = trim($r['column'] ?? '');
            $col = $colName !== '' ? one('SELECT * FROM board_columns WHERE board_id = ? AND name = ? COLLATE NOCASE', [$b['id'], $colName]) : null;
            if (!$col && $colName !== '') {
                $cid = insert('board_columns', ['board_id' => $b['id'], 'name' => mb_substr($colName, 0, 40), 'position' => (int)val('SELECT COALESCE(MAX(position),-1)+1 FROM board_columns WHERE board_id = ?', [$b['id']]), 'is_done' => 0, 'color' => '#64748b']);
                $col = one('SELECT * FROM board_columns WHERE id = ?', [$cid]);
            }
            if (!$col) $col = one('SELECT * FROM board_columns WHERE board_id = ? ORDER BY position LIMIT 1', [$b['id']]);
            $data = ['title' => mb_substr($title, 0, 200), 'column_id' => $col['id'], 'updated_at' => now(),
                'done_at' => $col['is_done'] ? now() : null];
            foreach (['description', 'labels'] as $k) if (isset($r[$k])) $data[$k] = trim($r[$k]);
            if (trim($r['assignee'] ?? '') !== '') $data['assignee_id'] = user_from_text($r['assignee']);
            if (trim($r['priority'] ?? '') !== '') $data['priority'] = key_from_label(PRIORITIES, $r['priority'], 'normale');
            if (trim($r['due_date'] ?? '') !== '') $data['due_date'] = parse_date($r['due_date']);
            $ex = ($id = parse_int($r['id'] ?? '')) ? one('SELECT * FROM tasks WHERE id = ? AND board_id = ?', [$id, $b['id']]) : null;
            if ($ex) { update('tasks', $data, 'id = ?', [$ex['id']]); return 'updated'; }
            insert('tasks', $data + ['board_id' => $b['id'], 'created_by' => user()['id'], 'created_at' => now(),
                'position' => (int)val('SELECT COALESCE(MAX(position),-1)+1 FROM tasks WHERE column_id = ?', [$col['id']])]);
            return 'created';
        },
    ];

    $ds['campagnes'] = [
        'label' => 'Campagnes marketing', 'icon' => 'megaphone', 'export' => 'campaigns.export', 'import' => 'campaigns.import',
        'help' => 'Correspondance par ID puis par nom. Canaux : ' . implode(', ', CHANNELS) . '.',
        'cols' => ['id' => 'ID', 'name' => 'Nom', 'channel' => 'Canal', 'status' => 'Statut', 'loc' => 'Showroom', 'product' => 'Produit', 'start_date' => 'Début', 'end_date' => 'Fin',
            'budget' => 'Budget', 'spent' => 'Dépensé', 'reach' => 'Portée', 'messages' => 'Messages', 'leads' => 'Leads', 'sales' => 'Ventes', 'revenue' => 'CA généré', 'objective' => 'Objectif', 'notes' => 'Notes'],
        'rows' => function () {
            foreach (all("SELECT c.*, l.code loc, p.name product, (SELECT COUNT(*) FROM sales s WHERE s.campaign_id = c.id AND s.status <> 'annulee') n,
                (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.campaign_id = c.id AND s.status <> 'annulee') ca
                FROM campaigns c LEFT JOIN locations l ON l.id = c.location_id LEFT JOIN products p ON p.id = c.product_id ORDER BY c.start_date") as $c) {
                yield [$c['id'], $c['name'], CHANNELS[$c['channel']] ?? $c['channel'], CAMPAIGN_STATUS[$c['status']] ?? $c['status'], $c['loc'], $c['product'], $c['start_date'], $c['end_date'],
                    csv_money($c['budget']), csv_money($c['spent']), $c['reach'], $c['messages'], $c['leads'], $c['n'], csv_money($c['ca']), $c['objective'], $c['notes']];
            }
        },
        'import_row' => function (array $r) {
            $name = trim($r['name'] ?? '');
            if ($name === '') return 'skipped';
            $ex = ($id = parse_int($r['id'] ?? '')) ? one('SELECT * FROM campaigns WHERE id = ?', [$id]) : null;
            if (!$ex) $ex = one('SELECT * FROM campaigns WHERE name = ? COLLATE NOCASE', [$name]);
            $data = ['name' => $name, 'updated_at' => now()];
            if (trim($r['channel'] ?? '') !== '') $data['channel'] = key_from_label(CHANNELS, $r['channel'], 'autre');
            if (trim($r['status'] ?? '') !== '') $data['status'] = key_from_label(CAMPAIGN_STATUS, $r['status'], 'planifiee');
            if (isset($r['loc'])) $data['location_id'] = loc_from_text($r['loc']);
            if (trim($r['product'] ?? '') !== '') $data['product_id'] = val('SELECT id FROM products WHERE name = ? COLLATE NOCASE OR sku = ?', [trim($r['product']), trim($r['product'])]) ?: null;
            foreach (['start_date', 'end_date'] as $k) if (trim($r[$k] ?? '') !== '') $data[$k] = parse_date($r[$k]);
            foreach (['budget', 'spent'] as $k) if (trim($r[$k] ?? '') !== '') $data[$k] = max(0, parse_money($r[$k]) ?? 0);
            foreach (['reach', 'messages', 'leads'] as $k) if (trim($r[$k] ?? '') !== '') $data[$k] = max(0, (int)parse_int($r[$k], 0));
            foreach (['objective', 'notes'] as $k) if (isset($r[$k])) $data[$k] = trim($r[$k]);
            if ($ex) { update('campaigns', $data, 'id = ?', [$ex['id']]); return 'updated'; }
            insert('campaigns', $data + ['created_by' => user()['id'], 'created_at' => now()]);
            return 'created';
        },
    ];

    $ds['depenses'] = [
        'label' => 'Dépenses', 'icon' => 'wallet', 'export' => 'expenses.export', 'import' => 'expenses.import', 'dates' => true, 'loc' => true,
        'help' => 'Catégories : ' . implode(', ', EXPENSE_CATEGORIES) . '. Site = code (TUN, NAB, DEP) ou vide pour « général ». Une ligne avec un ID existant est mise à jour.',
        'cols' => ['id' => 'ID', 'date' => 'Date', 'loc' => 'Site', 'category' => 'Catégorie', 'label' => 'Libellé', 'amount' => 'Montant', 'method' => 'Mode',
            'supplier' => 'Fournisseur', 'reference' => 'Référence', 'note' => 'Note'],
        'rows' => function (array $f) {
            $loc = export_scope($f);
            foreach (all('SELECT e.*, l.code FROM expenses e LEFT JOIN locations l ON l.id = e.location_id WHERE e.date BETWEEN ? AND ?' . ($loc ? ' AND e.location_id = ' . (int)$loc : '') . ' ORDER BY e.date, e.id', [$f['from'], $f['to']]) as $x) {
                yield [$x['id'], $x['date'], $x['code'], EXPENSE_CATEGORIES[$x['category']] ?? $x['category'], $x['label'], csv_money($x['amount']), PAY_METHODS[$x['method']] ?? $x['method'], $x['supplier'], $x['reference'], $x['note']];
            }
        },
        'import_row' => function (array $r) {
            $label = trim($r['label'] ?? '');
            $amount = parse_money($r['amount'] ?? '');
            if ($label === '' && $amount === null) return 'skipped';
            if ($label === '' || !$amount || $amount <= 0) throw new RuntimeException('Libellé et montant positif obligatoires.');
            $date = parse_date($r['date'] ?? '');
            if (!$date) throw new RuntimeException('Date invalide « ' . ($r['date'] ?? '') . ' ».');
            $loc = scope_loc() ?? loc_from_text($r['loc'] ?? '');
            $methods = PAY_METHODS;
            unset($methods['cod']);
            $data = ['date' => $date, 'location_id' => $loc, 'category' => key_from_label(EXPENSE_CATEGORIES, $r['category'] ?? '', 'divers'),
                'label' => mb_substr($label, 0, 160), 'amount' => $amount, 'method' => key_from_label($methods, $r['method'] ?? '', 'especes'),
                'supplier' => trim($r['supplier'] ?? ''), 'reference' => trim($r['reference'] ?? ''), 'note' => trim($r['note'] ?? '')];
            $ex = ($id = parse_int($r['id'] ?? '')) ? one('SELECT * FROM expenses WHERE id = ?', [$id]) : null;
            if ($ex) {
                if (!can_loc($ex['location_id'] !== null ? (int)$ex['location_id'] : null) || $ex['session_id']) throw new RuntimeException("Dépense $id non modifiable.");
                update('expenses', $data, 'id = ?', [$ex['id']]);
                return 'updated';
            }
            insert('expenses', $data + ['user_id' => user()['id'], 'created_at' => now()]);
            return 'created';
        },
    ];
    return $ds;
}

function ds_can(array $d, string $what): bool
{
    $perm = $d[$what] ?? null;
    if (!$perm) return false;
    foreach ((array)$perm as $p) if (can($p)) return true;
    return false;
}

/** Envoie l'export CSV d'un jeu de données. */
function ds_export(string $key, array $f, bool $templateOnly = false): void
{
    $d = datasets()[$key] ?? null;
    if (!$d || !ds_can($d, $templateOnly ? 'import' : 'export')) {
        http_response_code(403);
        exit('Export non autorisé.');
    }
    $cols = $d['cols'];
    if ($key === 'produits' && !is_admin()) unset($cols['cost']);
    $rows = $templateOnly ? [] : (function () use ($d, $f, $key) {
        foreach ($d['rows']($f) as $r) {
            if ($key === 'produits' && !is_admin()) unset($r[9]);
            yield array_values($r);
        }
    })();
    $name = $key . ($templateOnly ? '-modele' : '') . (!empty($d['dates']) && !$templateOnly ? '-' . $f['from'] . '_' . $f['to'] : '') . '-' . date('Ymd-Hi') . '.csv';
    if (!$templateOnly) audit('export CSV', $key, null, $d['label']);
    csv_send($name, array_values($cols), $rows);
}

/** Importe (ou simule) un fichier. Retourne un rapport. */
function ds_import(string $key, string $file, bool $commit, array $opts): array
{
    $d = datasets()[$key] ?? null;
    if (!$d || !ds_can($d, 'import')) throw new RuntimeException('Import non autorisé.');
    $csv = csv_read_file($file);
    $woo = $key === 'produits' && csv_is_woo($csv['headers']);
    $map = [];
    foreach ($d['cols'] as $k => $label) {
        $map[slug_key($label)] = $k;
        $map[slug_key($k)] = $k;
    }
    $unknown = [];
    $known = [];
    if (!$woo) {
        foreach ($csv['headers'] as $i => $h) {
            if ($h === '') continue;
            if (isset($map[$h])) $known[] = $map[$h];
            else $unknown[] = $csv['labels'][$i];
        }
        if (!$known) throw new RuntimeException('Aucune colonne reconnue. Téléchargez le modèle CSV pour voir les en-têtes attendus.');
    }
    $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'total' => count($csv['rows']), 'unknown' => $unknown,
        'woo' => $woo, 'columns' => $woo ? ['Format WooCommerce (export du site)'] : array_values(array_unique(array_map(fn($k) => $d['cols'][$k], $known)))];
    $opts['woo'] = $woo;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($csv['rows'] as $row) {
            $line = $row['__line'];
            unset($row['__line']);
            $r = [];
            if ($woo) $r = $row;
            else foreach ($row as $h => $v) if (isset($map[$h])) $r[$map[$h]] = $v;
            $pdo->exec('SAVEPOINT r');
            try {
                $res = $d['import_row']($r, $opts);
                $report[$res]++;
                $pdo->exec('RELEASE r');
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK TO r');
                $pdo->exec('RELEASE r');
                if (count($report['errors']) < 200) $report['errors'][] = "Ligne $line : " . $e->getMessage();
            }
        }
        if ($commit) {
            $pdo->commit();
            audit('import CSV', $key, null, $d['label'] . " · {$report['created']} créé(s), {$report['updated']} mis à jour, " . count($report['errors']) . ' erreur(s)');
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $report;
}
