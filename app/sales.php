<?php
declare(strict_types=1);

/* ---------- Sessions de caisse ---------- */

function open_session(int $locId): ?array
{
    return one("SELECT * FROM cash_sessions WHERE location_id = ? AND status = 'ouverte' ORDER BY id DESC LIMIT 1", [$locId]);
}

function session_open(int $locId, int $float): int
{
    if (open_session($locId)) throw new RuntimeException('Une session de caisse est déjà ouverte pour ce showroom.');
    $id = insert('cash_sessions', [
        'location_id' => $locId, 'opened_by' => user()['id'] ?? null, 'opened_at' => now(),
        'opening_float' => max(0, $float), 'status' => 'ouverte',
    ]);
    audit('ouverture de caisse', 'session', $id, 'Fond : ' . money($float));
    return $id;
}

/** Totaux d'une session : encaissements par mode, sorties de caisse, espèces attendues. */
function session_totals(array $s): array
{
    $by = [];
    foreach (all('SELECT method, SUM(amount) t, COUNT(*) n FROM payments WHERE session_id = ? GROUP BY method', [$s['id']]) as $r) {
        $by[$r['method']] = (int)$r['t'];
    }
    // Paiements de factures reçus dans cette caisse (espèces)
    $inv = 0;
    foreach (all('SELECT method, SUM(amount) t FROM document_payments WHERE session_id = ? GROUP BY method', [$s['id']]) as $r) {
        $by[$r['method']] = ($by[$r['method']] ?? 0) + (int)$r['t'];
        $inv += (int)$r['t'];
    }
    $cashOut = (int)(val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE session_id = ? AND method = 'especes'", [$s['id']]) ?? 0);
    $sales = one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM sales WHERE session_id = ? AND status <> 'annulee'", [$s['id']]);
    $cashIn = $by['especes'] ?? 0;
    return [
        'by_method' => $by,
        'cash_in' => $cashIn,
        'cash_out' => $cashOut,
        'expected_cash' => (int)$s['opening_float'] + $cashIn - $cashOut,
        'sales_count' => (int)$sales['n'],
        'sales_total' => (int)$sales['t'],
        'collected' => array_sum($by),
        'invoices' => $inv,
    ];
}

const DENOMINATIONS = [
    50000 => '50 DT', 20000 => '20 DT', 10000 => '10 DT', 5000 => '5 DT (billet/pièce)', 2000 => '2 DT', 1000 => '1 DT',
    500 => '500 millimes', 200 => '200 millimes', 100 => '100 millimes', 50 => '50 millimes', 20 => '20 millimes', 10 => '10 millimes',
];

/* ---------- Clients ---------- */

function normalize_phone(string $p): string
{
    $d = preg_replace('/\D+/', '', $p);
    if (strlen($d) === 11 && str_starts_with($d, '216')) $d = substr($d, 3);
    if (strlen($d) === 13 && str_starts_with($d, '00216')) $d = substr($d, 5);
    return $d;
}

/** Retrouve (par téléphone) ou crée un client. Retourne son id, ou null si aucun nom/téléphone. */
function client_upsert(array $c): ?int
{
    if (!empty($c['id'])) {
        $id = (int)$c['id'];
        if (!one('SELECT id FROM clients WHERE id = ?', [$id])) throw new RuntimeException('Client introuvable.');
        return $id;
    }
    $name = trim((string)($c['name'] ?? ''));
    $phone = normalize_phone((string)($c['phone'] ?? ''));
    if ($name === '' && $phone === '') return null;
    if ($phone !== '') {
        $ex = one('SELECT * FROM clients WHERE phone = ?', [$phone]);
        if ($ex) {
            $upd = [];
            foreach (['gouvernorat', 'city', 'address'] as $k) {
                if (!empty($c[$k]) && empty($ex[$k])) $upd[$k] = trim((string)$c[$k]);
            }
            if ($upd) update('clients', $upd + ['updated_at' => now()], 'id = ?', [$ex['id']]);
            return (int)$ex['id'];
        }
    }
    return insert('clients', [
        'name' => $name !== '' ? $name : 'Client ' . $phone, 'phone' => $phone,
        'gouvernorat' => trim((string)($c['gouvernorat'] ?? '')), 'city' => trim((string)($c['city'] ?? '')),
        'address' => trim((string)($c['address'] ?? '')), 'source' => (string)($c['source'] ?? ''),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/* ---------- Reste à encaisser (même calcul partout : tableau de bord, Finances, X) ---------- */

/**
 * Argent dû à l'entreprise, sans double compte :
 *  - clients : reste à payer des ventes, hors part déjà encaissée par un transporteur (comptée dans « transporteurs ») ;
 *  - transporteurs : livraisons faites, argent encaissé chez le client mais pas encore reversé ;
 *  - factures : factures hors caisse non réglées ;
 *  - réparations : réparations terminées non payées.
 */
function receivables_summary(?int $loc = null): array
{
    $l = $loc ? ' AND location_id = ' . (int)$loc : '';
    $sl = $loc ? ' AND s.location_id = ' . (int)$loc : '';
    $c = one("SELECT COUNT(*) n, COALESCE(SUM(r),0) t FROM (SELECT MAX(0, (s.total - s.paid) - COALESCE((SELECT SUM(sh.cod_amount) FROM shipments sh
        WHERE sh.sale_id = s.id AND sh.status = 'livree' AND sh.cod_collected = 0), 0)) r FROM sales s WHERE s.status = 'partielle'$sl) WHERE r > 0");
    $k = one("SELECT COUNT(*) n, COALESCE(SUM(cod_amount),0) t FROM shipments WHERE status = 'livree' AND cod_collected = 0 AND cod_amount > 0$l");
    $i = one("SELECT COUNT(*) n, COALESCE(SUM(net - paid),0) t FROM documents WHERE type = 'facture' AND sale_id IS NULL AND status IN ('emise','partielle')$l");
    $r = one("SELECT COUNT(*) n, COALESCE(SUM(amount - paid),0) t FROM repairs WHERE status = 'repare' AND paid < amount$l");
    $out = [
        'clients' => ['t' => (int)$c['t'], 'n' => (int)$c['n'], 'label' => 'Clients (ventes avec reste à payer)'],
        'carriers' => ['t' => (int)$k['t'], 'n' => (int)$k['n'], 'label' => 'Chez les transporteurs (livré, pas encore reversé)'],
        'invoices' => ['t' => (int)$i['t'], 'n' => (int)$i['n'], 'label' => 'Factures non réglées'],
        'repairs' => ['t' => (int)$r['t'], 'n' => (int)$r['n'], 'label' => 'Réparations terminées non payées'],
    ];
    $out['total'] = array_sum(array_column($out, 't'));
    return $out;
}

/* ---------- Ventes ---------- */

function sale_status(int $total, int $paid): string
{
    return $paid >= $total ? 'payee' : 'partielle';
}

/**
 * Enregistre une vente de caisse. $in : location_id, items[], discount, client{}, source, campaign_id,
 * payments[], note, delivery{}. Retourne ['id' => …, 'number' => …].
 */
function sale_create(array $in): array
{
    $u = user();
    $locId = (int)($in['location_id'] ?? 0);
    $loc = location($locId);
    if (!$loc || !$loc['active']) throw new RuntimeException('Showroom inconnu.');
    if (!can_loc($locId)) throw new RuntimeException('Vous ne pouvez pas encaisser pour ce showroom.');
    $session = open_session($locId);
    if (!$session) throw new RuntimeException('La caisse de ce showroom est fermée : ouvrez une session.');

    $items = [];
    $subtotal = 0;
    $catalog = 0;
    foreach ((array)($in['items'] ?? []) as $it) {
        $qty = (int)($it['qty'] ?? 0);
        if ($qty < 1 || $qty > 999) throw new RuntimeException('Quantité invalide.');
        $unit = parse_money($it['unit_price'] ?? null);
        if ($unit === null || $unit < 0) throw new RuntimeException('Prix invalide.');
        $vat = (float)($it['vat_rate'] ?? setting('vat_rate', 19));
        if (!in_array($vat, [0.0, 7.0, 13.0, 19.0], true)) $vat = (float)setting('vat_rate', 19);
        $pid = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        if ($pid) {
            $p = one('SELECT * FROM products WHERE id = ?', [$pid]);
            if (!$p || !$p['active']) throw new RuntimeException('Produit introuvable ou archivé.');
            $list = product_price($p);
            if ($list === 0 && $unit === 0) throw new RuntimeException('« ' . $p['name'] . ' » est sur devis : saisissez son prix.');
            $items[] = ['product_id' => $pid, 'label' => $p['name'], 'qty' => $qty, 'unit_price' => $unit, 'list_price' => $list ?: $unit,
                'unit_cost' => $p['cost'] !== null ? (int)$p['cost'] : null, 'vat_rate' => $vat, 'total' => $unit * $qty,
                'warranty_months' => $p['warranty_months']];
            $catalog += ($list ?: $unit) * $qty;
        } else {
            $label = trim((string)($it['label'] ?? ''));
            if ($label === '') throw new RuntimeException('Libellé manquant pour une ligne libre.');
            $items[] = ['product_id' => null, 'label' => mb_substr($label, 0, 120), 'qty' => $qty, 'unit_price' => $unit, 'list_price' => $unit,
                'unit_cost' => null, 'vat_rate' => $vat, 'total' => $unit * $qty, 'warranty_months' => null];
            $catalog += $unit * $qty;
        }
        $subtotal += $unit * $qty;
    }
    if (!$items) throw new RuntimeException('Le panier est vide.');

    $discount = parse_money($in['discount'] ?? 0) ?? 0;
    if ($discount < 0 || $discount > $subtotal) throw new RuntimeException('Remise invalide.');
    $total = $subtotal - $discount;

    $maxPct = (float)setting('max_discount_pct', 20);
    if ($u['role'] !== 'admin' && $catalog > 0) {
        $pct = ($catalog - $total) / $catalog * 100;
        if ($pct > $maxPct + 0.001) {
            throw new RuntimeException(sprintf('Remise totale de %s : le maximum autorisé est %s. Demandez à l’administrateur.', pct($pct, 1), pct($maxPct)));
        }
    }

    $payments = [];
    $paid = 0;
    foreach ((array)($in['payments'] ?? []) as $pm) {
        $method = (string)($pm['method'] ?? '');
        if (!isset(PAY_METHODS[$method]) || $method === 'cod') throw new RuntimeException('Mode de paiement invalide.');
        $amount = parse_money($pm['amount'] ?? null);
        if ($amount === null || $amount <= 0) continue;
        $payments[] = ['method' => $method, 'amount' => $amount, 'reference' => mb_substr(trim((string)($pm['reference'] ?? '')), 0, 80)];
        $paid += $amount;
    }
    if ($paid > $total) throw new RuntimeException('Les paiements dépassent le total : saisissez le montant dû (la monnaie est calculée à part).');

    $client = (array)($in['client'] ?? []);
    $delivery = (array)($in['delivery'] ?? []);
    $hasDelivery = !empty($delivery['enabled']);
    if ($paid < $total && empty($client['id']) && (trim((string)($client['name'] ?? '')) === '' || normalize_phone((string)($client['phone'] ?? '')) === '')) {
        throw new RuntimeException('Reste à payer : le nom et le téléphone du client sont obligatoires.');
    }
    if ($hasDelivery && empty($client['id']) && normalize_phone((string)($client['phone'] ?? $delivery['phone'] ?? '')) === '') {
        throw new RuntimeException('Livraison : le téléphone du client est obligatoire.');
    }

    $source = (string)($in['source'] ?? '');
    if ($source !== '' && !isset(SOURCES[$source])) $source = 'autre';
    $campaignId = !empty($in['campaign_id']) ? (int)$in['campaign_id'] : null;
    if ($campaignId && !one('SELECT id FROM campaigns WHERE id = ?', [$campaignId])) $campaignId = null;

    return tx(function () use ($u, $loc, $locId, $session, $items, $subtotal, $discount, $total, $catalog, $payments, $paid, $client, $delivery, $hasDelivery, $source, $campaignId, $in) {
        if (setting('allow_negative_stock', '1') !== '1') {
            $need = [];
            foreach ($items as $it) if ($it['product_id']) $need[$it['product_id']] = ($need[$it['product_id']] ?? 0) + $it['qty'];
            foreach ($need as $pid => $qty) {
                $have = stock_qty((int)$pid, $locId);
                if ($have < $qty) {
                    $name = val('SELECT name FROM products WHERE id = ?', [$pid]);
                    throw new RuntimeException("Stock insuffisant pour « $name » ($have disponible au showroom).");
                }
            }
        }
        $client['source'] = $source;
        $clientId = client_upsert($client);
        $number = $loc['code'] . '-' . date('y') . '-' . str_pad((string)next_seq('sale:' . $loc['code'] . ':' . date('Y')), 5, '0', STR_PAD_LEFT);
        $saleId = insert('sales', [
            'number' => $number, 'location_id' => $locId, 'session_id' => $session['id'], 'user_id' => $u['id'],
            'client_id' => $clientId, 'created_at' => now(), 'subtotal' => $subtotal, 'discount' => $discount,
            'total' => $total, 'paid' => $paid, 'catalog_total' => $catalog, 'status' => sale_status($total, $paid),
            'source' => $source, 'campaign_id' => $campaignId, 'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 500),
        ]);
        foreach ($items as $it) {
            insert('sale_items', ['sale_id' => $saleId] + $it);
            if ($it['product_id']) stock_change((int)$it['product_id'], $locId, -$it['qty'], 'vente', $number);
        }
        foreach ($payments as $pm) {
            insert('payments', $pm + ['sale_id' => $saleId, 'session_id' => $session['id'], 'location_id' => $locId,
                    'created_at' => now(), 'user_id' => $u['id']]);
        }
        if ($hasDelivery) {
            $c = $clientId ? one('SELECT * FROM clients WHERE id = ?', [$clientId]) : [];
            $carrier = !empty($delivery['carrier_id']) ? (int)$delivery['carrier_id'] : null;
            if ($carrier && !one('SELECT id FROM carriers WHERE id = ?', [$carrier])) $carrier = null;
            $cod = parse_money($delivery['cod_amount'] ?? null);
            shipment_create([
                'sale_id' => $saleId, 'client_id' => $clientId, 'location_id' => $locId, 'carrier_id' => $carrier,
                'recipient' => trim((string)($delivery['recipient'] ?? '')) ?: ($c['name'] ?? ''),
                'phone' => normalize_phone((string)($delivery['phone'] ?? '')) ?: ($c['phone'] ?? ''),
                'gouvernorat' => trim((string)($delivery['gouvernorat'] ?? '')) ?: ($c['gouvernorat'] ?? ''),
                'city' => trim((string)($delivery['city'] ?? '')) ?: ($c['city'] ?? ''),
                'address' => trim((string)($delivery['address'] ?? '')) ?: ($c['address'] ?? ''),
                'planned_date' => parse_date($delivery['planned_date'] ?? null),
                'fee' => max(0, parse_money($delivery['fee'] ?? 0) ?? 0),
                'cod_amount' => $cod !== null ? max(0, min($cod, $total - $paid)) : $total - $paid,
                'note' => mb_substr(trim((string)($delivery['note'] ?? '')), 0, 500),
            ]);
        }
        audit('vente', 'sale', $saleId, $number . ' · ' . money($total) . ($paid < $total ? ' · reste ' . money($total - $paid) : ''));
        return ['id' => $saleId, 'number' => $number];
    });
}

/** Encaissement complémentaire (reste à payer, facilité). */
function sale_add_payment(int $saleId, string $method, int $amount, string $reference = ''): void
{
    $s = one('SELECT * FROM sales WHERE id = ?', [$saleId]);
    if (!$s || !can_loc((int)$s['location_id'])) throw new RuntimeException('Vente introuvable.');
    if ($s['status'] === 'annulee') throw new RuntimeException('Cette vente est annulée.');
    if (!isset(PAY_METHODS[$method])) throw new RuntimeException('Mode de paiement invalide.');
    $rest = (int)$s['total'] - (int)$s['paid'];
    if ($amount <= 0 || $amount > $rest) throw new RuntimeException('Montant invalide (reste à payer : ' . money($rest) . ').');
    $session = $method === 'cod' ? null : open_session((int)$s['location_id']);
    if ($method === 'especes' && !$session) throw new RuntimeException('Pour un encaissement en espèces, ouvrez d’abord la caisse du showroom.');
    tx(function () use ($s, $method, $amount, $reference, $session) {
        insert('payments', [
            'sale_id' => $s['id'], 'session_id' => $session['id'] ?? null, 'location_id' => $s['location_id'],
            'created_at' => now(), 'method' => $method, 'amount' => $amount, 'reference' => mb_substr($reference, 0, 80),
            'user_id' => user()['id'] ?? null,
        ]);
        $paid = (int)$s['paid'] + $amount;
        update('sales', ['paid' => $paid, 'status' => sale_status((int)$s['total'], $paid)], 'id = ?', [$s['id']]);
        audit('encaissement', 'sale', $s['id'], $s['number'] . ' · ' . PAY_METHODS[$method] . ' ' . money($amount));
    });
}

/** Annulation (admin) : remise en stock, remboursement tracé, livraisons annulées. */
function sale_cancel(int $saleId, string $reason): void
{
    $s = one('SELECT * FROM sales WHERE id = ?', [$saleId]);
    if (!$s) throw new RuntimeException('Vente introuvable.');
    if ($s['status'] === 'annulee') throw new RuntimeException('Vente déjà annulée.');
    if (trim($reason) === '') throw new RuntimeException('Indiquez le motif de l’annulation.');
    tx(function () use ($s, $reason) {
        foreach (all('SELECT * FROM sale_items WHERE sale_id = ? AND product_id IS NOT NULL', [$s['id']]) as $it) {
            stock_change((int)$it['product_id'], (int)$s['location_id'], (int)$it['qty'], 'annulation', $s['number'], $reason);
        }
        $session = open_session((int)$s['location_id']);
        foreach (all('SELECT method, SUM(amount) t FROM payments WHERE sale_id = ? GROUP BY method HAVING SUM(amount) <> 0', [$s['id']]) as $p) {
            insert('payments', [
                'sale_id' => $s['id'], 'session_id' => $p['method'] === 'cod' ? null : ($session['id'] ?? null),
                'location_id' => $s['location_id'], 'created_at' => now(), 'method' => $p['method'],
                'amount' => -(int)$p['t'], 'reference' => 'Remboursement (annulation)', 'user_id' => user()['id'] ?? null,
            ]);
        }
        update('sales', ['status' => 'annulee', 'paid' => 0, 'cancelled_at' => now(), 'cancelled_by' => user()['id'] ?? null,
            'cancel_reason' => mb_substr($reason, 0, 300)], 'id = ?', [$s['id']]);
        q("UPDATE shipments SET status = 'annulee', updated_at = ? WHERE sale_id = ? AND status IN ('a_preparer','en_cours')", [now(), $s['id']]);
        audit('annulation de vente', 'sale', $s['id'], $s['number'] . ' · ' . $reason);
    });
}

/* ---------- Livraisons ---------- */

/**
 * Crée une livraison. $items : [['product_id' => …, 'label' => …, 'qty' => …]] ; sans lignes et avec une vente liée,
 * la marchandise de la vente est reprise automatiquement.
 */
function shipment_create(array $d, ?array $items = null, string $eventText = 'Livraison créée'): int
{
    $d['number'] = 'LIV-' . date('y') . '-' . str_pad((string)next_seq('ship:' . date('Y')), 5, '0', STR_PAD_LEFT);
    $d += ['status' => 'a_preparer', 'user_id' => user()['id'] ?? null, 'created_at' => now(), 'updated_at' => now()];
    $d += ['holder' => ship_holder_for_status($d['status'])];
    $id = insert('shipments', $d);
    if ($items === null && !empty($d['sale_id'])) {
        $items = all('SELECT product_id, label, qty FROM sale_items WHERE sale_id = ?', [$d['sale_id']]);
    }
    shipment_set_items($id, $items ?? []);
    $carrierName = !empty($d['carrier_id']) ? (string)val('SELECT name FROM carriers WHERE id = ?', [$d['carrier_id']]) : '';
    shipment_event($id, $eventText . ($carrierName !== '' ? ' · transporteur : ' . $carrierName : ''), 'info', $d['created_at']);
    audit('livraison créée', 'shipment', $id, $d['number']);
    return $id;
}

/** Remplace la marchandise d'une livraison. */
function shipment_set_items(int $shipmentId, array $items): void
{
    q('DELETE FROM shipment_items WHERE shipment_id = ?', [$shipmentId]);
    foreach ($items as $it) {
        $label = trim((string)($it['label'] ?? ''));
        $pid = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        if ($pid && $label === '') $label = (string)val('SELECT name FROM products WHERE id = ?', [$pid]);
        if ($label === '') continue;
        insert('shipment_items', ['shipment_id' => $shipmentId, 'product_id' => $pid, 'label' => mb_substr($label, 0, 160), 'qty' => max(1, min(999, (int)($it['qty'] ?? 1)))]);
    }
}

function shipment_items(int $shipmentId): array
{
    return all('SELECT * FROM shipment_items WHERE shipment_id = ? ORDER BY id', [$shipmentId]);
}

/** « 2 × BT 200+ · Huile lubrifiant » */
function shipment_items_text(array $items, int $max = 3): string
{
    $parts = [];
    foreach (array_slice($items, 0, $max) as $it) $parts[] = ((int)$it['qty'] > 1 ? (int)$it['qty'] . ' × ' : '') . $it['label'];
    if (count($items) > $max) $parts[] = '+' . (count($items) - $max);
    return implode(' · ', $parts);
}

/** Ajoute une étape à l'historique d'une livraison. */
function shipment_event(int $shipmentId, string $text, string $kind = 'info', ?string $at = null): void
{
    insert('shipment_events', ['shipment_id' => $shipmentId, 'at' => $at ?: now(), 'kind' => $kind, 'text' => mb_substr($text, 0, 300), 'user_id' => user()['id'] ?? null]);
}

/** Où se trouve normalement la marchandise pour un statut donné. */
function ship_holder_for_status(string $status): string
{
    return ['en_cours' => 'transporteur', 'livree' => 'client', 'retour' => 'retour'][$status] ?? 'depart';
}

/** Libellé « Chez le transporteur (TPS) ». */
function ship_holder_label(array $sh, ?string $carrier = null): string
{
    $label = SHIP_HOLDERS[$sh['holder'] ?? 'depart'] ?? '';
    $extra = trim((string)($sh['holder_note'] ?? ''));
    if ($extra === '' && ($sh['holder'] ?? '') === 'transporteur' && $carrier) $extra = $carrier;
    if ($extra === '' && ($sh['holder'] ?? '') === 'technicien' && !empty($sh['technician'])) $extra = $sh['technician'];
    return $label . ($extra !== '' ? ' — ' . $extra : '');
}

/** Change le statut (et, sauf précision, le lieu de la marchandise) en gardant une trace dans l'historique. */
function shipment_set_status(array $sh, string $status, ?string $holder = null, string $note = ''): void
{
    if (!isset(SHIP_STATUS[$status])) throw new RuntimeException('Statut invalide.');
    $holder = $holder !== null && isset(SHIP_HOLDERS[$holder]) ? $holder : ($status !== $sh['status'] ? ship_holder_for_status($status) : $sh['holder']);
    $data = ['status' => $status, 'holder' => $holder, 'updated_at' => now(),
        'delivered_at' => $status === 'livree' ? ($sh['delivered_at'] ?: now()) : null];
    if ($holder !== $sh['holder']) $data['holder_note'] = '';
    update('shipments', $data, 'id = ?', [$sh['id']]);
    if ($status !== $sh['status']) {
        shipment_event((int)$sh['id'], SHIP_STATUS[$status] . ($note !== '' ? ' · ' . $note : ''), $status === 'livree' ? 'ok' : ($status === 'retour' || $status === 'annulee' ? 'err' : 'info'));
    } elseif ($holder !== $sh['holder']) {
        shipment_event((int)$sh['id'], 'Marchandise : ' . mb_strtolower(SHIP_HOLDERS[$holder]));
    }
}

/** Contre-remboursement encaissé par le transporteur : crée le paiement sur la vente liée. */
function shipment_collect(array $sh): void
{
    if ($sh['cod_collected']) return;
    tx(function () use ($sh) {
        if ($sh['sale_id'] && (int)$sh['cod_amount'] > 0) {
            $s = one('SELECT * FROM sales WHERE id = ?', [$sh['sale_id']]);
            if ($s && $s['status'] !== 'annulee') {
                $amount = min((int)$sh['cod_amount'], (int)$s['total'] - (int)$s['paid']);
                if ($amount > 0) sale_add_payment((int)$s['id'], 'cod', $amount, $sh['number']);
            }
        }
        update('shipments', ['cod_collected' => 1, 'updated_at' => now()], 'id = ?', [$sh['id']]);
        if ((int)$sh['cod_amount'] > 0) shipment_event((int)$sh['id'], 'Argent récupéré : ' . money($sh['cod_amount']), 'ok');
    });
}

function sale_items(int $saleId): array
{
    return all('SELECT si.*, p.sku, p.image, p.image_url, p.family FROM sale_items si LEFT JOIN products p ON p.id = si.product_id WHERE si.sale_id = ? ORDER BY si.id', [$saleId]);
}

/** Décomposition HT / TVA d'une vente (remise globale répartie au prorata). */
function sale_vat(array $sale, array $items): array
{
    $sub = max(1, (int)$sale['subtotal']);
    $ratio = ((int)$sale['total']) / $sub;
    $rates = [];
    foreach ($items as $it) {
        $ttc = (int)round((int)$it['total'] * $ratio);
        $rate = (float)$it['vat_rate'];
        $rates[(string)$rate] = ($rates[(string)$rate] ?? 0) + $ttc;
    }
    $out = [];
    foreach ($rates as $rate => $ttc) {
        $ht = (int)round($ttc / (1 + (float)$rate / 100));
        $out[] = ['rate' => (float)$rate, 'ttc' => $ttc, 'ht' => $ht, 'vat' => $ttc - $ht];
    }
    return $out;
}
