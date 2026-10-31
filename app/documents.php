<?php
declare(strict_types=1);

/* ---------- Devis & factures ---------- */

const DOC_TYPES = ['devis' => 'Devis', 'facture' => 'Facture'];

const DOC_STATUS = [
    'devis' => ['brouillon' => 'Brouillon', 'envoye' => 'Envoyé', 'accepte' => 'Accepté', 'refuse' => 'Refusé', 'facture' => 'Facturé'],
    'facture' => ['emise' => 'À encaisser', 'partielle' => 'Paiement partiel', 'payee' => 'Payée', 'annulee' => 'Annulée'],
];

const VAT_RATES = ['19' => '19 %', '13' => '13 %', '7' => '7 %', '0' => '0 %'];

function doc_route(string $type): string
{
    return $type === 'devis' ? 'quotes' : 'invoices';
}

/** Devis dont la validité est dépassée sans réponse. */
function doc_expired(array $d): bool
{
    return $d['type'] === 'devis' && in_array($d['status'], ['brouillon', 'envoye'], true) && $d['due_date'] && $d['due_date'] < today();
}

/** Facture non soldée après l'échéance. */
function doc_overdue(array $d): bool
{
    return $d['type'] === 'facture' && in_array($d['status'], ['emise', 'partielle'], true) && $d['due_date'] && $d['due_date'] < today();
}

/** [libellé, ton du badge] en tenant compte de l'expiration / du retard. */
function doc_badge(array $d): string
{
    if (doc_expired($d)) return badge('Expiré', 'warn');
    if (doc_overdue($d)) return badge('En retard', 'err');
    $tone = ['brouillon' => '', 'envoye' => 'info', 'accepte' => 'ok', 'refuse' => 'err', 'facture' => 'ok',
        'emise' => 'warn', 'partielle' => 'warn', 'payee' => 'ok', 'annulee' => 'err'][$d['status']] ?? '';
    return badge(DOC_STATUS[$d['type']][$d['status']] ?? $d['status'], $tone);
}

function doc_number(string $type): string
{
    $prefix = (string)setting($type === 'devis' ? 'quote_prefix' : 'invoice_prefix', $type === 'devis' ? 'DV-' : '');
    $n = next_seq('doc:' . $type . ':' . date('Y'));
    return $prefix . str_pad((string)$n, 6, '0', STR_PAD_LEFT) . '/' . date('y');
}

/** Prochain numéro qui sera attribué cette année (paramètres). */
function doc_next_number(string $type): int
{
    return (int)(val('SELECT value FROM counters WHERE key = ?', ['doc:' . $type . ':' . date('Y')]) ?? 0) + 1;
}

function doc_set_next_number(string $type, int $next): void
{
    $key = 'doc:' . $type . ':' . date('Y');
    $max = 0;
    $prefix = (string)setting($type === 'devis' ? 'quote_prefix' : 'invoice_prefix', '');
    foreach (all("SELECT number FROM documents WHERE type = ? AND number LIKE ?", [$type, '%/' . date('y')]) as $r) {
        $num = substr($r['number'], strlen($prefix));
        if (preg_match('/^(\d+)\//', $num, $m)) $max = max($max, (int)$m[1]);
    }
    if ($next <= $max) throw new RuntimeException("Le numéro $next est déjà utilisé : le prochain numéro doit être supérieur à $max.");
    q('INSERT INTO counters(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $next - 1]);
}

function doc_items(int $docId): array
{
    return all('SELECT di.*, p.sku, p.image, p.image_url, p.family FROM document_items di LEFT JOIN products p ON p.id = di.product_id
        WHERE di.document_id = ? ORDER BY di.position, di.id', [$docId]);
}

function doc_get(int $id, ?string $type = null): ?array
{
    $d = one('SELECT d.*, u.name AS author FROM documents d LEFT JOIN users u ON u.id = d.created_by WHERE d.id = ?', [$id]);
    if (!$d || ($type && $d['type'] !== $type) || !can_loc($d['location_id'] !== null ? (int)$d['location_id'] : null)) return null;
    return $d;
}

/**
 * Normalise les lignes saisies et calcule les totaux.
 * Lignes : product_id, label, description, qty, unit_price (TTC), discount_pct, vat_rate.
 */
function doc_compute(array $rawItems, $discount, bool $timbre): array
{
    $items = [];
    $subtotal = 0;
    $pos = 0;
    foreach ($rawItems as $it) {
        $label = trim((string)($it['label'] ?? ''));
        $pid = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        if ($label === '' && !$pid) continue;
        $qty = parse_int($it['qty'] ?? 1, 0);
        if ($qty < 1 || $qty > 9999) throw new RuntimeException('Quantité invalide' . ($label ? " pour « $label »" : '') . '.');
        $unit = parse_money($it['unit_price'] ?? null);
        if ($unit === null || $unit < 0) throw new RuntimeException('Prix invalide' . ($label ? " pour « $label »" : '') . '.');
        $disc = (float)str_replace(',', '.', (string)($it['discount_pct'] ?? 0));
        if ($disc < 0 || $disc > 100) throw new RuntimeException('Remise de ligne : entre 0 et 100 %.');
        $vat = (float)str_replace(',', '.', (string)($it['vat_rate'] ?? setting('vat_rate', 19)));
        if (!in_array($vat, [0.0, 7.0, 13.0, 19.0], true)) $vat = (float)setting('vat_rate', 19);
        $warranty = null;
        if ($pid) {
            $p = one('SELECT * FROM products WHERE id = ?', [$pid]);
            if (!$p) throw new RuntimeException('Produit introuvable.');
            if ($label === '') $label = $p['name'];
            $warranty = $p['warranty_months'] !== null ? (int)$p['warranty_months'] : null;
        }
        $total = (int)round($unit * $qty * (1 - $disc / 100));
        $items[] = ['product_id' => $pid, 'label' => mb_substr($label, 0, 200), 'description' => mb_substr(trim((string)($it['description'] ?? '')), 0, 500),
            'qty' => $qty, 'unit_price' => $unit, 'discount_pct' => $disc, 'vat_rate' => $vat, 'total' => $total,
            'warranty_months' => $warranty, 'position' => $pos++];
        $subtotal += $total;
    }
    if (!$items) throw new RuntimeException('Ajoutez au moins une ligne.');
    $discount = parse_money($discount) ?? 0;
    if ($discount < 0 || $discount > $subtotal) throw new RuntimeException('Remise globale invalide.');
    $total = $subtotal - $discount;
    $vat = sale_vat(['subtotal' => $subtotal, 'total' => $total], $items);
    $ht = array_sum(array_column($vat, 'ht'));
    $tim = $timbre ? (int)setting('timbre', 1000) : 0;
    return ['items' => $items, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'total_ht' => $ht,
        'total_vat' => $total - $ht, 'timbre' => $tim, 'net' => $total + $tim, 'vat' => $vat];
}

/** Sort (ou remet, $sign = +1) les articles d'une facture du stock de son showroom. */
function doc_stock(array $doc, array $items, int $sign): void
{
    if (!$doc['location_id']) return;
    foreach ($items as $it) {
        if (!empty($it['product_id'])) {
            stock_change((int)$it['product_id'], (int)$doc['location_id'], $sign * (int)$it['qty'], $sign < 0 ? 'vente' : 'annulation', $doc['number']);
        }
    }
}

function doc_paid_status(int $net, int $paid): string
{
    if ($paid <= 0) return 'emise';
    return $paid >= $net ? 'payee' : 'partielle';
}

/**
 * Crée ou modifie un devis / une facture. $in : type, id, location_id, client{}, date, due_date, items[], discount,
 * timbre, terms, transport, warranty, notes, internal_note, stock. Retourne l'id.
 */
function doc_save(array $in): int
{
    $u = user();
    $id = (int)($in['id'] ?? 0);
    $old = $id ? doc_get($id) : null;
    if ($id && !$old) throw new RuntimeException('Document introuvable.');
    $type = $old['type'] ?? (string)($in['type'] ?? '');
    if (!isset(DOC_TYPES[$type])) throw new RuntimeException('Type de document inconnu.');
    if ($old && !doc_editable($old)) throw new RuntimeException('Ce document ne peut plus être modifié.');

    $locId = scope_loc() ?? ((int)($in['location_id'] ?? 0) ?: null);
    if ($locId && !location($locId)) throw new RuntimeException('Showroom inconnu.');
    $date = parse_date($in['date'] ?? null) ?? today();
    $due = parse_date($in['due_date'] ?? null);
    if ($due && $due < $date) throw new RuntimeException($type === 'devis' ? 'La date de validité précède la date du devis.' : 'L’échéance précède la date de la facture.');

    $c = (array)($in['client'] ?? []);
    $cname = trim((string)($c['name'] ?? ''));
    if ($cname === '') throw new RuntimeException('Le nom du client est obligatoire.');
    $calc = doc_compute((array)($in['items'] ?? []), $in['discount'] ?? 0, !empty($in['timbre']));
    if ($old && $old['type'] === 'facture' && (int)$old['paid'] > $calc['net']) {
        throw new RuntimeException('Le nouveau total est inférieur aux paiements déjà reçus (' . money($old['paid']) . ').');
    }

    return tx(function () use ($u, $old, $type, $locId, $date, $due, $c, $cname, $calc, $in) {
        $phone = normalize_phone((string)($c['phone'] ?? ''));
        $clientId = !empty($c['id']) ? (int)$c['id'] : null;
        if (!$clientId && $phone === '') $clientId = (int)val('SELECT id FROM clients WHERE name = ? COLLATE NOCASE ORDER BY id LIMIT 1', [$cname]) ?: null;
        if (!$clientId) $clientId = client_upsert(['name' => $cname, 'phone' => $phone, 'address' => (string)($c['address'] ?? '')]);
        if ($clientId) {
            $ex = one('SELECT * FROM clients WHERE id = ?', [$clientId]);
            if (!$ex) throw new RuntimeException('Client introuvable.');
            $upd = [];
            foreach (['mf' => 'mf', 'email' => 'email', 'address' => 'address'] as $k => $col) {
                $v = trim((string)($c[$k] ?? ''));
                if ($v !== '' && trim((string)$ex[$col]) === '') $upd[$col] = $v;
            }
            if ($upd) update('clients', $upd + ['updated_at' => now()], 'id = ?', [$clientId]);
        }
        $data = [
            'location_id' => $locId, 'client_id' => $clientId,
            'client_name' => mb_substr($cname, 0, 160), 'client_phone' => $phone, 'client_email' => mb_substr(trim((string)($c['email'] ?? '')), 0, 160),
            'client_address' => mb_substr(trim((string)($c['address'] ?? '')), 0, 300), 'client_mf' => mb_substr(trim((string)($c['mf'] ?? '')), 0, 60),
            'date' => $date, 'due_date' => $due,
            'subtotal' => $calc['subtotal'], 'discount' => $calc['discount'], 'total_ht' => $calc['total_ht'], 'total_vat' => $calc['total_vat'],
            'total' => $calc['total'], 'timbre' => $calc['timbre'], 'net' => $calc['net'],
            'terms' => mb_substr(trim((string)($in['terms'] ?? '')), 0, 300), 'transport' => mb_substr(trim((string)($in['transport'] ?? '')), 0, 120),
            'warranty' => mb_substr(trim((string)($in['warranty'] ?? '')), 0, 160), 'notes' => mb_substr(trim((string)($in['notes'] ?? '')), 0, 1000),
            'internal_note' => mb_substr(trim((string)($in['internal_note'] ?? '')), 0, 1000), 'updated_at' => now(),
        ];
        if ($old) {
            // Les articles déjà sortis du stock sont remis avant d'appliquer les nouvelles lignes.
            if ($old['stock_applied']) doc_stock($old, doc_items((int)$old['id']), +1);
            $id = (int)$old['id'];
            update('documents', $data, 'id = ?', [$id]);
            q('DELETE FROM document_items WHERE document_id = ?', [$id]);
            $saleId = $old['sale_id'];
        } else {
            $saleId = !empty($in['sale_id']) ? (int)$in['sale_id'] : null;
            $id = insert('documents', $data + ['type' => $type, 'number' => doc_number($type), 'status' => $type === 'devis' ? 'brouillon' : 'emise',
                'source_id' => !empty($in['source_id']) ? (int)$in['source_id'] : null, 'sale_id' => $saleId,
                'paid' => 0, 'created_by' => $u['id'] ?? null, 'created_at' => now()]);
        }
        foreach ($calc['items'] as $it) insert('document_items', ['document_id' => $id] + $it);
        $doc = one('SELECT * FROM documents WHERE id = ?', [$id]);
        if ($type === 'facture') {
            $applyStock = !$saleId && $locId && !empty($in['stock']);
            if ($applyStock) doc_stock($doc, $calc['items'], -1);
            update('documents', ['stock_applied' => $applyStock ? 1 : 0], 'id = ?', [$id]);
            doc_refresh_paid($id);
        }
        audit($old ? DOC_TYPES[$type] . ' modifié' . ($type === 'facture' ? 'e' : '') : DOC_TYPES[$type] . ' créé' . ($type === 'facture' ? 'e' : ''),
            'document', $id, $doc['number'] . ' · ' . $cname . ' · ' . money($calc['net']));
        return $id;
    });
}

/** Un devis reste modifiable tant qu'il n'est pas facturé ; une facture tant qu'elle n'est pas annulée (et sans paiement, sauf direction). */
function doc_editable(array $d): bool
{
    if (!can('invoices.edit') || !can_loc($d['location_id'] !== null ? (int)$d['location_id'] : null)) return false;
    if ($d['type'] === 'devis') return $d['status'] !== 'facture';
    if ($d['status'] === 'annulee') return false;
    return is_admin() || (int)$d['paid'] === 0;
}

function doc_set_status(array $d, string $status): void
{
    if ($d['type'] !== 'devis' || !in_array($status, ['brouillon', 'envoye', 'accepte', 'refuse'], true)) throw new RuntimeException('Statut invalide.');
    if ($d['status'] === 'facture') throw new RuntimeException('Ce devis est déjà facturé.');
    $data = ['status' => $status, 'updated_at' => now()];
    if ($status === 'envoye' && !$d['sent_at']) $data['sent_at'] = now();
    if (in_array($status, ['accepte', 'refuse'], true)) $data['closed_at'] = now();
    update('documents', $data, 'id = ?', [$d['id']]);
    audit('devis ' . mb_strtolower(DOC_STATUS['devis'][$status]), 'document', $d['id'], $d['number']);
}

/** Copie un document (devis → facture, ou duplication). Retourne l'id du nouveau document. */
function doc_copy(array $src, string $type, bool $stock = false): int
{
    $items = array_map(fn($it) => ['product_id' => $it['product_id'], 'label' => $it['label'], 'description' => $it['description'],
        'qty' => $it['qty'], 'unit_price' => m_in($it['unit_price']), 'discount_pct' => $it['discount_pct'], 'vat_rate' => $it['vat_rate']], doc_items((int)$src['id']));
    $toInvoice = $src['type'] === 'devis' && $type === 'facture';
    $days = (int)setting($type === 'devis' ? 'quote_validity_days' : 'invoice_due_days', 0);
    return tx(function () use ($src, $type, $items, $toInvoice, $days, $stock) {
        $id = doc_save([
            'type' => $type, 'location_id' => $src['location_id'],
            'client' => ['id' => $src['client_id'], 'name' => $src['client_name'], 'phone' => $src['client_phone'], 'email' => $src['client_email'],
                'address' => $src['client_address'], 'mf' => $src['client_mf']],
            'date' => today(), 'due_date' => $days > 0 ? date('Y-m-d', strtotime("+$days days")) : null,
            'items' => $items, 'discount' => m_in($src['discount']), 'timbre' => (int)$src['timbre'] > 0,
            'terms' => $src['terms'], 'transport' => $src['transport'], 'warranty' => $src['warranty'], 'notes' => $src['notes'],
            'source_id' => $toInvoice ? $src['id'] : null, 'stock' => $stock,
        ]);
        if ($toInvoice) {
            update('documents', ['status' => 'facture', 'closed_at' => $src['closed_at'] ?: now(), 'updated_at' => now()], 'id = ?', [$src['id']]);
            audit('devis facturé', 'document', $src['id'], $src['number']);
        }
        return $id;
    });
}

/** Facture établie à partir d'une vente de caisse (articles et paiements repris, stock déjà sorti). */
function doc_from_sale(int $saleId): int
{
    $s = one('SELECT s.*, c.name cname, c.phone cphone, c.email cemail, c.address caddr, c.city ccity, c.gouvernorat cgov, c.mf cmf
        FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE s.id = ?', [$saleId]);
    if (!$s || !can_loc((int)$s['location_id'])) throw new RuntimeException('Vente introuvable.');
    if ($s['status'] === 'annulee') throw new RuntimeException('Cette vente est annulée.');
    if ($ex = val("SELECT id FROM documents WHERE sale_id = ? AND type = 'facture' AND status <> 'annulee'", [$saleId])) return (int)$ex;
    if (!$s['client_id']) throw new RuntimeException('Associez d’abord un client à la vente : une facture doit être nominative.');
    $items = array_map(fn($it) => ['product_id' => $it['product_id'], 'label' => $it['label'], 'qty' => $it['qty'],
        'unit_price' => m_in($it['unit_price']), 'discount_pct' => 0, 'vat_rate' => $it['vat_rate']], sale_items($saleId));
    return tx(function () use ($s, $items) {
        $id = doc_save([
            'type' => 'facture', 'location_id' => $s['location_id'],
            'client' => ['id' => $s['client_id'], 'name' => $s['cname'], 'phone' => $s['cphone'], 'email' => $s['cemail'],
                'address' => trim(implode(', ', array_filter([$s['caddr'], $s['ccity'], $s['cgov']])), ', '), 'mf' => $s['cmf']],
            'date' => substr($s['created_at'], 0, 10), 'items' => $items, 'discount' => m_in($s['discount']), 'timbre' => false,
            'terms' => setting('invoice_terms', ''), 'transport' => setting('invoice_transport', ''), 'warranty' => setting('invoice_warranty', ''),
            'notes' => 'Vente ' . $s['number'], 'sale_id' => $s['id'], 'stock' => false,
        ]);
        foreach (all('SELECT * FROM payments WHERE sale_id = ? ORDER BY id', [$s['id']]) as $p) {
            insert('document_payments', ['document_id' => $id, 'date' => substr($p['created_at'], 0, 10), 'method' => $p['method'],
                'amount' => (int)$p['amount'], 'reference' => $p['reference'] ?: $s['number'], 'user_id' => $p['user_id'], 'created_at' => now()]);
        }
        doc_refresh_paid($id);
        return $id;
    });
}

function doc_refresh_paid(int $id): void
{
    $d = one('SELECT * FROM documents WHERE id = ?', [$id]);
    $paid = (int)val('SELECT COALESCE(SUM(amount),0) FROM document_payments WHERE document_id = ?', [$id]);
    $data = ['paid' => $paid, 'updated_at' => now()];
    if ($d['type'] === 'facture' && $d['status'] !== 'annulee') $data['status'] = doc_paid_status((int)$d['net'], $paid);
    update('documents', $data, 'id = ?', [$id]);
}

function doc_add_payment(array $d, string $method, int $amount, string $reference, ?string $date): void
{
    if ($d['type'] !== 'facture' || $d['status'] === 'annulee') throw new RuntimeException('Paiement impossible sur ce document.');
    if (!isset(PAY_METHODS[$method])) throw new RuntimeException('Mode de paiement invalide.');
    $rest = (int)$d['net'] - (int)$d['paid'];
    if ($amount <= 0 || $amount > $rest) throw new RuntimeException('Montant invalide (reste à payer : ' . money($rest) . ').');
    tx(function () use ($d, $method, $amount, $reference, $date) {
        insert('document_payments', ['document_id' => $d['id'], 'date' => $date ?: today(), 'method' => $method, 'amount' => $amount,
            'reference' => mb_substr($reference, 0, 80), 'user_id' => user()['id'] ?? null, 'created_at' => now()]);
        doc_refresh_paid((int)$d['id']);
        audit('paiement de facture', 'document', $d['id'], $d['number'] . ' · ' . PAY_METHODS[$method] . ' ' . money($amount));
    });
}

function doc_delete_payment(array $d, int $paymentId): void
{
    if (!is_admin()) throw new RuntimeException('Suppression réservée à l’administrateur.');
    $p = one('SELECT * FROM document_payments WHERE id = ? AND document_id = ?', [$paymentId, $d['id']]);
    if (!$p) throw new RuntimeException('Paiement introuvable.');
    tx(function () use ($d, $p) {
        q('DELETE FROM document_payments WHERE id = ?', [$p['id']]);
        doc_refresh_paid((int)$d['id']);
        audit('paiement de facture supprimé', 'document', $d['id'], $d['number'] . ' · ' . money($p['amount']));
    });
}

/** Annulation d'une facture (direction) : le numéro reste attribué, le stock sorti est remis. */
function doc_cancel(array $d, string $reason): void
{
    if (!is_admin()) throw new RuntimeException('Seule la direction peut annuler une facture.');
    if ($d['type'] !== 'facture' || $d['status'] === 'annulee') throw new RuntimeException('Annulation impossible.');
    if (trim($reason) === '') throw new RuntimeException('Indiquez le motif de l’annulation.');
    tx(function () use ($d, $reason) {
        if ($d['stock_applied']) doc_stock($d, doc_items((int)$d['id']), +1);
        update('documents', ['status' => 'annulee', 'stock_applied' => 0, 'cancelled_at' => now(), 'cancelled_by' => user()['id'] ?? null,
            'cancel_reason' => mb_substr($reason, 0, 300), 'updated_at' => now()], 'id = ?', [$d['id']]);
        if ($d['source_id']) q("UPDATE documents SET status = 'accepte', updated_at = ? WHERE id = ? AND status = 'facture'", [now(), $d['source_id']]);
        audit('facture annulée', 'document', $d['id'], $d['number'] . ' · ' . $reason);
    });
}

function doc_delete(array $d): void
{
    if ($d['type'] !== 'devis') throw new RuntimeException('Une facture ne se supprime pas : annulez-la.');
    if ($d['status'] === 'facture') throw new RuntimeException('Ce devis est facturé : il ne peut pas être supprimé.');
    if (!is_admin() && (int)$d['created_by'] !== (int)user()['id']) throw new RuntimeException('Seul l’auteur du devis ou la direction peut le supprimer.');
    q('DELETE FROM documents WHERE id = ?', [$d['id']]);
    audit('devis supprimé', 'document', $d['id'], $d['number']);
}

/* ---------- Montant en lettres ---------- */

function fr_words(int $n): string
{
    if ($n === 0) return 'zéro';
    $units = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];
    $tens = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante', 8 => 'quatre-vingt'];
    $below100 = function (int $n) use ($units, $tens): string {
        if ($n <= 16) return $units[$n];
        if ($n < 20) return 'dix-' . $units[$n - 10];
        $t = intdiv($n, 10);
        $u = $n % 10;
        if ($t === 7 || $t === 9) {
            $base = $t === 7 ? 'soixante' : 'quatre-vingt';
            $rest = $n - ($t === 7 ? 60 : 80);
            return $base . ($rest === 11 && $t === 7 ? ' et onze' : '-' . ($rest <= 16 ? $units[$rest] : 'dix-' . $units[$rest - 10]));
        }
        if ($u === 0) return $tens[$t] . ($t === 8 ? 's' : '');
        if ($u === 1 && $t !== 8) return $tens[$t] . ' et un';
        return $tens[$t] . '-' . $units[$u];
    };
    $below1000 = function (int $n) use ($below100): string {
        $h = intdiv($n, 100);
        $r = $n % 100;
        $s = '';
        if ($h > 0) $s = ($h > 1 ? $below100($h) . ' ' : '') . 'cent' . ($h > 1 && $r === 0 ? 's' : '');
        if ($r > 0) $s .= ($s ? ' ' : '') . $below100($r);
        return $s;
    };
    $parts = [];
    foreach ([[1000000000, 'milliard'], [1000000, 'million']] as [$div, $word]) {
        if ($n >= $div) {
            $k = intdiv($n, $div);
            $parts[] = $below1000($k) . ' ' . $word . ($k > 1 ? 's' : '');
            $n %= $div;
        }
    }
    if ($n >= 1000) {
        $k = intdiv($n, 1000);
        $w = $k === 1 ? 'mille' : preg_replace('/(vingt|cent)s$/', '$1', $below1000($k)) . ' mille';
        $parts[] = $w;
        $n %= 1000;
    }
    if ($n > 0) $parts[] = $below1000($n);
    return implode(' ', $parts);
}

/** 1 600 000 millimes -> « Mille six cents dinars ». */
function amount_words($millimes): string
{
    $m = (int)$millimes;
    $d = intdiv($m, 1000);
    $r = $m % 1000;
    $s = fr_words($d) . ' dinar' . ($d > 1 ? 's' : '');
    if ($r > 0) $s .= ' et ' . fr_words($r) . ' millime' . ($r > 1 ? 's' : '');
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}
