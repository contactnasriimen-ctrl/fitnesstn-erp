<?php
declare(strict_types=1);

/* ---------- Devis, factures & bons de livraison ---------- */

const DOC_TYPES = ['devis' => 'Devis', 'facture' => 'Facture', 'bl' => 'Bon de livraison'];

const DOC_STATUS = [
    'devis' => ['brouillon' => 'Brouillon', 'envoye' => 'Envoyé', 'accepte' => 'Accepté', 'refuse' => 'Refusé', 'facture' => 'Facturé'],
    'facture' => ['emise' => 'À encaisser', 'partielle' => 'Paiement partiel', 'payee' => 'Payée', 'annulee' => 'Annulée'],
    'bl' => ['a_livrer' => 'À livrer', 'livre' => 'Livré', 'facture' => 'Facturé'],
];

const VAT_RATES = ['19' => '19 %', '13' => '13 %', '7' => '7 %', '0' => '0 %'];

function doc_route(string $type): string
{
    return ['devis' => 'quotes', 'facture' => 'invoices', 'bl' => 'delivery_notes'][$type] ?? 'invoices';
}

/** Accord des messages : « facture créée », mais « devis créé », « bon de livraison créé ». */
function doc_fem(string $type): bool
{
    return $type === 'facture';
}

function doc_icon(string $type): string
{
    return ['devis' => 'quote', 'facture' => 'invoice', 'bl' => 'clipboard'][$type] ?? 'invoice';
}

function doc_prefix(string $type): string
{
    return (string)setting(['devis' => 'quote_prefix', 'facture' => 'invoice_prefix', 'bl' => 'bl_prefix'][$type] ?? 'invoice_prefix',
        ['devis' => 'DV-', 'facture' => '', 'bl' => 'BL-'][$type] ?? '');
}

/* ---------- Modalités de paiement et transporteurs (listes modifiables) ---------- */

function payment_modes(): array
{
    return array_column(all('SELECT label FROM payment_modes ORDER BY position, id'), 'label');
}

/** Options du menu « Modalité de paiement » ; la valeur actuelle reste proposée même si elle a été retirée de la liste. */
function payment_mode_options(string $current = ''): array
{
    $modes = payment_modes();
    if ($current !== '' && !in_array(mb_strtolower($current), array_map('mb_strtolower', $modes), true)) $modes[] = $current;
    return array_combine($modes, $modes) ?: [];
}

function payment_mode_save(int $id, string $label): int
{
    $label = trim(preg_replace('/\s+/u', ' ', $label));
    if ($label === '') throw new RuntimeException('Le libellé de la modalité est obligatoire.');
    $label = mb_substr($label, 0, 60);
    if (val('SELECT id FROM payment_modes WHERE label = ? COLLATE NOCASE AND id <> ?', [$label, $id])) throw new RuntimeException("La modalité « $label » existe déjà.");
    if ($id) {
        $old = one('SELECT * FROM payment_modes WHERE id = ?', [$id]);
        if (!$old) throw new RuntimeException('Modalité introuvable.');
        update('payment_modes', ['label' => $label], 'id = ?', [$id]);
        if (setting('invoice_payment_mode', '') === $old['label']) set_setting('invoice_payment_mode', $label);
        audit('modalité de paiement modifiée', 'payment_mode', $id, $old['label'] . ' → ' . $label);
        return $id;
    }
    $id = insert('payment_modes', ['label' => $label, 'position' => (int)val('SELECT COALESCE(MAX(position), 0) + 1 FROM payment_modes'), 'created_at' => now()]);
    audit('modalité de paiement ajoutée', 'payment_mode', $id, $label);
    return $id;
}

function payment_mode_delete(int $id): string
{
    $m = one('SELECT * FROM payment_modes WHERE id = ?', [$id]);
    if (!$m) throw new RuntimeException('Modalité introuvable.');
    q('DELETE FROM payment_modes WHERE id = ?', [$id]);
    if (setting('invoice_payment_mode', '') === $m['label']) set_setting('invoice_payment_mode', '');
    audit('modalité de paiement supprimée', 'payment_mode', $id, $m['label']);
    return $m['label'];
}

/** Déplace une modalité d'un cran vers le haut (-1) ou le bas (+1) dans la liste. */
function payment_mode_move(int $id, int $dir): void
{
    $ids = array_map('intval', array_column(all('SELECT id FROM payment_modes ORDER BY position, id'), 'id'));
    $i = array_search($id, $ids, true);
    $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) return;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    tx(function () use ($ids) {
        foreach ($ids as $pos => $mid) q('UPDATE payment_modes SET position = ? WHERE id = ?', [$pos + 1, $mid]);
    });
}

function transporters(): array
{
    return all('SELECT * FROM transporters ORDER BY name COLLATE NOCASE, id');
}

/** Nom, matricule du véhicule et CIN du chauffeur, nettoyés et vérifiés. */
function transporter_clean(array $in): array
{
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? ''))), 0, 120);
    $plate = mb_substr(mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)($in['plate'] ?? '')))), 0, 20);
    $cin = mb_substr(trim(preg_replace('/\s+/u', '', (string)($in['cin'] ?? ''))), 0, 20);
    // Une CIN tunisienne compte 8 chiffres ; un passeport (lettres et chiffres) est accepté tel quel.
    if ($cin !== '' && ctype_digit($cin) && strlen($cin) !== 8) throw new RuntimeException("CIN « $cin » : une carte d’identité tunisienne compte 8 chiffres.");
    if ($name === '' && ($plate !== '' || $cin !== '')) throw new RuntimeException('Indiquez le nom du transporteur.');
    return ['name' => $name, 'plate' => $plate, 'cin' => $cin];
}

function transporter_save(int $id, array $in): int
{
    $t = transporter_clean($in);
    if ($t['name'] === '') throw new RuntimeException('Le nom du transporteur est obligatoire.');
    if ($id) {
        if (!one('SELECT id FROM transporters WHERE id = ?', [$id])) throw new RuntimeException('Transporteur introuvable.');
        update('transporters', $t + ['updated_at' => now()], 'id = ?', [$id]);
        audit('transporteur modifié', 'transporter', $id, implode(' · ', array_filter($t)));
        return $id;
    }
    $id = insert('transporters', $t + ['created_at' => now(), 'updated_at' => now()]);
    audit('transporteur ajouté', 'transporter', $id, implode(' · ', array_filter($t)));
    return $id;
}

function transporter_delete(int $id): string
{
    $t = one('SELECT * FROM transporters WHERE id = ?', [$id]);
    if (!$t) throw new RuntimeException('Transporteur introuvable.');
    // Les documents gardent leur copie (nom, matricule, CIN) : seul le lien vers la fiche disparaît.
    q('DELETE FROM transporters WHERE id = ?', [$id]);
    audit('transporteur supprimé', 'transporter', $id, $t['name']);
    return $t['name'];
}

/**
 * Transporteur saisi sur un document : fiche choisie dans la liste, ou saisie libre enregistrée dans la liste
 * si « save » est coché (sans doublon : même nom et même matricule = même fiche). Retourne [id|null, copie figée].
 */
function doc_transporter(array $in): array
{
    $t = transporter_clean($in);
    if ($t['name'] === '') return [null, $t];
    $id = !empty($in['id']) ? (int)$in['id'] : null;
    if ($id && !one('SELECT id FROM transporters WHERE id = ?', [$id])) $id = null;
    if (!$id) {
        $id = (int)val('SELECT id FROM transporters WHERE name = ? COLLATE NOCASE AND plate = ? COLLATE NOCASE ORDER BY id LIMIT 1', [$t['name'], $t['plate']]) ?: null;
        if ($id && $t['cin'] !== '' && (string)val('SELECT cin FROM transporters WHERE id = ?', [$id]) === '') update('transporters', ['cin' => $t['cin'], 'updated_at' => now()], 'id = ?', [$id]);
        if (!$id && !empty($in['save'])) $id = transporter_save(0, $t);
    }
    return [$id, $t];
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
        'emise' => 'warn', 'partielle' => 'warn', 'payee' => 'ok', 'annulee' => 'err', 'a_livrer' => 'warn', 'livre' => 'info'][$d['status']] ?? '';
    return badge(DOC_STATUS[$d['type']][$d['status']] ?? $d['status'], $tone);
}

function doc_number(string $type): string
{
    $prefix = doc_prefix($type);
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
    $prefix = doc_prefix($type);
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

/**
 * Factures et devis partagés par toute l'équipe (Paramètres › Devis & factures, activé par défaut) :
 * chacun voit ceux de tous les showrooms. Désactivé : un responsable ne voit que ceux de son showroom.
 */
function docs_shared(): bool
{
    return setting('docs_shared', '1') === '1';
}

/** Showroom imposé pour lister les factures et devis (null = tous). */
function doc_scope(): ?int
{
    return docs_shared() ? null : scope_loc();
}

function doc_can_loc(?int $locId): bool
{
    return docs_shared() || can_loc($locId);
}

function doc_get(int $id, ?string $type = null): ?array
{
    $d = one('SELECT d.*, u.name AS author FROM documents d LEFT JOIN users u ON u.id = d.created_by WHERE d.id = ?', [$id]);
    if (!$d || ($type && $d['type'] !== $type) || !doc_can_loc($d['location_id'] !== null ? (int)$d['location_id'] : null)) return null;
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
 * Crée ou modifie un devis / une facture / un bon de livraison. $in : type, id, location_id, client{}, date, due_date,
 * items[], discount, timbre, payment_mode, terms, transport, transporter{id, name, plate, cin, save}, warranty, notes,
 * internal_note, stock. Retourne l'id.
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
    $isBl = $type === 'bl';

    // Un responsable crée au nom de son showroom ; s'il modifie le document d'un autre showroom, celui-ci ne change pas.
    if ($old && scope_loc()) $locId = $old['location_id'] !== null ? (int)$old['location_id'] : null;
    else $locId = scope_loc() ?? ((int)($in['location_id'] ?? 0) ?: null);
    if ($locId && !location($locId)) throw new RuntimeException('Showroom inconnu.');
    $date = parse_date($in['date'] ?? null) ?? today();
    // Un bon de livraison n'a ni échéance ni timbre fiscal : sa date est celle de la livraison.
    $due = $isBl ? null : parse_date($in['due_date'] ?? null);
    if ($due && $due < $date) throw new RuntimeException($type === 'devis' ? 'La date de validité précède la date du devis.' : 'L’échéance précède la date de la facture.');

    $c = (array)($in['client'] ?? []);
    $cname = trim((string)($c['name'] ?? ''));
    if ($cname === '') throw new RuntimeException('Le nom du client est obligatoire.');
    $calc = doc_compute((array)($in['items'] ?? []), $in['discount'] ?? 0, !$isBl && !empty($in['timbre']));
    if ($old && $old['type'] === 'facture' && (int)$old['paid'] > $calc['net']) {
        throw new RuntimeException('Le nouveau total est inférieur aux paiements déjà reçus (' . money($old['paid']) . ').');
    }
    $tin = (array)($in['transporter'] ?? []);
    transporter_clean($tin); // vérifie la saisie avant d'ouvrir la transaction
    $mode = mb_substr(trim((string)($in['payment_mode'] ?? '')), 0, 60);

    return tx(function () use ($u, $old, $type, $locId, $date, $due, $c, $cname, $calc, $in, $tin, $mode) {
        [$trId, $tr] = doc_transporter($tin);
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
            'payment_mode' => $mode,
            'terms' => mb_substr(trim((string)($in['terms'] ?? '')), 0, 300), 'transport' => mb_substr(trim((string)($in['transport'] ?? '')), 0, 120),
            'transporter_id' => $trId, 'transporter_name' => $tr['name'], 'transporter_plate' => $tr['plate'], 'transporter_cin' => $tr['cin'],
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
            $id = insert('documents', $data + ['type' => $type, 'number' => doc_number($type),
                'status' => ['devis' => 'brouillon', 'facture' => 'emise', 'bl' => 'a_livrer'][$type],
                'source_id' => !empty($in['source_id']) ? (int)$in['source_id'] : null, 'sale_id' => $saleId,
                'paid' => 0, 'created_by' => $u['id'] ?? null, 'created_at' => now()]);
        }
        foreach ($calc['items'] as $it) insert('document_items', ['document_id' => $id] + $it);
        $doc = one('SELECT * FROM documents WHERE id = ?', [$id]);
        // Facture ou bon de livraison : la marchandise peut sortir du stock du showroom (une seule fois pour la même vente).
        if ($type !== 'devis') {
            $applyStock = !$saleId && $locId && !empty($in['stock']) && !doc_stock_link($doc);
            if ($applyStock) doc_stock($doc, $calc['items'], -1);
            update('documents', ['stock_applied' => $applyStock ? 1 : 0], 'id = ?', [$id]);
        }
        if ($type === 'facture') doc_refresh_paid($id);
        $e = doc_fem($type) ? 'e' : '';
        audit(DOC_TYPES[$type] . ($old ? ' modifié' : ' créé') . $e, 'document', $id, $doc['number'] . ' · ' . $cname . ' · ' . money($calc['net']));
        return $id;
    });
}

/** Droit de gérer (créer, modifier, encaisser, annuler, supprimer) ce devis ou cette facture. */
function doc_can_manage(array $d): bool
{
    return can('invoices.edit') && doc_can_loc($d['location_id'] !== null ? (int)$d['location_id'] : null);
}

/**
 * Un devis ou un bon de livraison reste modifiable tant qu'il n'est pas facturé ; une facture tant qu'elle n'est pas annulée.
 */
function doc_editable(array $d): bool
{
    if (!doc_can_manage($d)) return false;
    if ($d['type'] === 'facture') return $d['status'] !== 'annulee';
    return $d['status'] !== 'facture';
}

/** Statuts qu'on choisit à la main : suivi d'un devis, livraison d'un bon de livraison. */
const DOC_MANUAL_STATUS = ['devis' => ['brouillon', 'envoye', 'accepte', 'refuse'], 'bl' => ['a_livrer', 'livre']];

function doc_set_status(array $d, string $status): void
{
    if (!in_array($status, DOC_MANUAL_STATUS[$d['type']] ?? [], true)) throw new RuntimeException('Statut invalide.');
    if ($d['status'] === 'facture') throw new RuntimeException(($d['type'] === 'bl' ? 'Ce bon de livraison' : 'Ce devis') . ' est déjà facturé.');
    $data = ['status' => $status, 'updated_at' => now()];
    if ($status === 'envoye' && !$d['sent_at']) $data['sent_at'] = now();
    if (in_array($status, ['accepte', 'refuse', 'livre'], true)) $data['closed_at'] = now();
    if ($status === 'a_livrer') $data['closed_at'] = null;
    update('documents', $data, 'id = ?', [$d['id']]);
    audit(mb_strtolower(DOC_TYPES[$d['type']]) . ' ' . mb_strtolower(DOC_STATUS[$d['type']][$status]), 'document', $d['id'], $d['number']);
}

/** Ce que chaque type de document peut produire : devis → facture, bon de livraison → facture, facture → bon de livraison. */
function doc_can_convert(array $src, string $type): bool
{
    return in_array($src['type'] . '>' . $type, ['devis>facture', 'bl>facture', 'facture>bl'], true);
}

/**
 * Copie un document : transformation (devis ou bon de livraison → facture, facture → bon de livraison) ou duplication.
 * Retourne l'id du nouveau document.
 */
function doc_copy(array $src, string $type, bool $stock = false): int
{
    $items = array_map(fn($it) => ['product_id' => $it['product_id'], 'label' => $it['label'], 'description' => $it['description'],
        'qty' => $it['qty'], 'unit_price' => m_in($it['unit_price']), 'discount_pct' => $it['discount_pct'], 'vat_rate' => $it['vat_rate']], doc_items((int)$src['id']));
    $convert = $src['type'] !== $type;
    if ($convert && !doc_can_convert($src, $type)) throw new RuntimeException('Transformation impossible pour ce document.');
    if ($convert && $type === 'facture' && $src['status'] === 'facture') throw new RuntimeException('Ce document est déjà facturé.');
    if ($convert && $src['type'] === 'bl' && ($inv = doc_bl_invoice($src))) throw new RuntimeException('Ce bon de livraison est déjà lié à la facture ' . $inv['number'] . '.');
    if ($convert && $src['type'] === 'facture' && $src['status'] === 'annulee') throw new RuntimeException('Cette facture est annulée.');
    // La facture d'un bon de livraison ne ressort pas la marchandise déjà sortie par le bon (et inversement).
    if ($convert && $src['stock_applied']) $stock = false;
    $days = ['devis' => (int)setting('quote_validity_days', 0), 'facture' => (int)setting('invoice_due_days', 0)][$type] ?? 0;
    return tx(function () use ($src, $type, $items, $convert, $days, $stock) {
        $id = doc_save([
            'type' => $type, 'location_id' => $src['location_id'],
            'client' => ['id' => $src['client_id'], 'name' => $src['client_name'], 'phone' => $src['client_phone'], 'email' => $src['client_email'],
                'address' => $src['client_address'], 'mf' => $src['client_mf']],
            'date' => today(), 'due_date' => $days > 0 ? date('Y-m-d', strtotime("+$days days")) : null,
            'items' => $items, 'discount' => m_in($src['discount']),
            'timbre' => $src['type'] === 'bl' && $type === 'facture' ? (int)setting('timbre', 1000) > 0 : (int)$src['timbre'] > 0,
            'payment_mode' => $src['payment_mode'] ?? '', 'terms' => $src['terms'], 'transport' => $src['transport'],
            'transporter' => ['id' => $src['transporter_id'] ?? null, 'name' => $src['transporter_name'] ?? '', 'plate' => $src['transporter_plate'] ?? '', 'cin' => $src['transporter_cin'] ?? ''],
            'warranty' => $src['warranty'], 'notes' => $src['notes'],
            'source_id' => $convert ? $src['id'] : null, 'sale_id' => $convert ? $src['sale_id'] : null, 'stock' => $stock,
        ]);
        if ($convert && $type === 'facture') {
            update('documents', ['status' => 'facture', 'closed_at' => $src['closed_at'] ?: now(), 'updated_at' => now()], 'id = ?', [$src['id']]);
            audit(mb_strtolower(DOC_TYPES[$src['type']]) . ' facturé', 'document', $src['id'], $src['number']);
        }
        return $id;
    });
}

/** Facture (non annulée) liée à un bon de livraison : celle qui en est issue, ou celle dont il est issu. */
function doc_bl_invoice(array $bl): ?array
{
    if ($bl['type'] !== 'bl') return null;
    return one("SELECT id, number, status FROM documents WHERE type = 'facture' AND status <> 'annulee' AND (source_id = ? OR id = ?) ORDER BY id LIMIT 1",
        [(int)$bl['id'], (int)($bl['source_id'] ?? 0)]);
}

/** Facture ou bon de livraison lié qui a déjà sorti la marchandise du stock (null sinon). */
function doc_stock_link(array $d): ?array
{
    if ($d['type'] === 'devis' || empty($d['id'])) return null;
    return one("SELECT id, type, number FROM documents WHERE stock_applied = 1 AND type IN ('facture', 'bl') AND status <> 'annulee' AND id <> ?
        AND (id = ? OR source_id = ?) ORDER BY id LIMIT 1", [(int)$d['id'], (int)($d['source_id'] ?? 0), (int)$d['id']]);
}

/** Une facture annulée ou supprimée rend son document d'origine : devis « accepté », bon de livraison « livré » ou « à livrer ». */
function doc_release_source(array $d): void
{
    if ($d['type'] !== 'facture' || !$d['source_id']) return;
    q("UPDATE documents SET status = CASE type WHEN 'devis' THEN 'accepte' ELSE (CASE WHEN closed_at IS NULL THEN 'a_livrer' ELSE 'livre' END) END,
        updated_at = ? WHERE id = ? AND status = 'facture' AND type IN ('devis', 'bl')", [now(), $d['source_id']]);
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
                'amount' => (int)$p['amount'], 'reference' => $p['reference'] ?: $s['number'], 'user_id' => $p['user_id'], 'created_at' => now(),
                'location_id' => $s['location_id']]);
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
    // Espèces reçues au showroom : comptées dans la caisse ouverte (clôture et billetage).
    $session = $method === 'especes' && $d['location_id'] ? open_session((int)$d['location_id']) : null;
    tx(function () use ($d, $method, $amount, $reference, $date, $session) {
        insert('document_payments', ['document_id' => $d['id'], 'date' => $date ?: today(), 'method' => $method, 'amount' => $amount,
            'reference' => mb_substr($reference, 0, 80), 'user_id' => user()['id'] ?? null, 'created_at' => now(),
            'location_id' => $d['location_id'], 'session_id' => $session['id'] ?? null]);
        doc_refresh_paid((int)$d['id']);
        audit('paiement de facture', 'document', $d['id'], $d['number'] . ' · ' . PAY_METHODS[$method] . ' ' . money($amount));
    });
}

function doc_delete_payment(array $d, int $paymentId): void
{
    if (!doc_can_manage($d)) throw new RuntimeException('Vous ne pouvez pas modifier les paiements de ce document.');
    $p = one('SELECT * FROM document_payments WHERE id = ? AND document_id = ?', [$paymentId, $d['id']]);
    if (!$p) throw new RuntimeException('Paiement introuvable.');
    if ($p['session_id'] && val("SELECT status FROM cash_sessions WHERE id = ?", [$p['session_id']]) === 'fermee') {
        throw new RuntimeException('Ce paiement en espèces fait partie d’une caisse déjà clôturée : il ne peut plus être supprimé.');
    }
    tx(function () use ($d, $p) {
        q('DELETE FROM document_payments WHERE id = ?', [$p['id']]);
        doc_refresh_paid((int)$d['id']);
        audit('paiement de facture supprimé', 'document', $d['id'], $d['number'] . ' · ' . money($p['amount']));
    });
}

/** Annulation d'une facture : le numéro reste attribué, le stock sorti est remis. */
function doc_cancel(array $d, string $reason): void
{
    if (!doc_can_manage($d)) throw new RuntimeException('Vous ne pouvez pas annuler cette facture.');
    if ($d['type'] !== 'facture' || $d['status'] === 'annulee') throw new RuntimeException('Annulation impossible.');
    if (trim($reason) === '') throw new RuntimeException('Indiquez le motif de l’annulation.');
    tx(function () use ($d, $reason) {
        if ($d['stock_applied']) doc_stock($d, doc_items((int)$d['id']), +1);
        update('documents', ['status' => 'annulee', 'stock_applied' => 0, 'cancelled_at' => now(), 'cancelled_by' => user()['id'] ?? null,
            'cancel_reason' => mb_substr($reason, 0, 300), 'updated_at' => now()], 'id = ?', [$d['id']]);
        doc_release_source($d);
        audit('facture annulée', 'document', $d['id'], $d['number'] . ' · ' . $reason);
    });
}

/** Vrai si $d porte le dernier numéro attribué pour son type et son année (compteur). */
function doc_is_last(array $d): bool
{
    if (!preg_match('/(\d+)\/(\d{2})$/', (string)$d['number'], $m)) return false;
    return (int)$m[1] === (int)val('SELECT value FROM counters WHERE key = ?', ['doc:' . $d['type'] . ':' . (2000 + (int)$m[2])]);
}

/**
 * Toute facture peut être supprimée par qui gère les factures (direction et showrooms). Ses paiements sont supprimés
 * avec elle et le stock sorti est remis. Seule exception : un paiement en espèces compté dans une caisse déjà clôturée.
 * Un bon de livraison déjà facturé attend que sa facture soit annulée ou supprimée.
 * Retourne la raison du refus, ou null.
 */
function doc_delete_block(array $d): ?string
{
    $what = ['devis' => 'ce devis', 'facture' => 'cette facture', 'bl' => 'ce bon de livraison'][$d['type']] ?? 'ce document';
    if (!doc_can_manage($d)) return 'Votre profil ne permet pas de supprimer ' . $what . '.';
    if ($d['type'] === 'bl' && $d['status'] === 'facture') {
        $inv = one("SELECT number FROM documents WHERE source_id = ? AND type = 'facture' AND status <> 'annulee' ORDER BY id LIMIT 1", [$d['id']]);
        return 'Ce bon de livraison est facturé' . ($inv ? ' (facture ' . $inv['number'] . ')' : '') . ' : annulez ou supprimez d’abord la facture.';
    }
    if ($d['type'] !== 'facture') return null;
    $closed = (int)val("SELECT COUNT(*) FROM document_payments p JOIN cash_sessions s ON s.id = p.session_id WHERE p.document_id = ? AND s.status = 'fermee'", [$d['id']]);
    if ($closed) return 'Un paiement en espèces de cette facture fait partie d’une caisse déjà clôturée : la facture ne peut plus être supprimée. Annulez-la plutôt.';
    return null;
}

/** Ce que la suppression va faire, en une phrase (affichée avant de confirmer). */
function doc_delete_effects(array $d): string
{
    if ($d['type'] === 'devis') return 'Le devis ' . $d['number'] . ' sera supprimé définitivement.';
    $isBl = $d['type'] === 'bl';
    $out = [($isBl ? 'Le bon de livraison ' : 'La facture ') . $d['number'] . ' (' . money($d['net']) . ') sera ' . ($isBl ? 'supprimé' : 'supprimée') . ' définitivement'];
    if (!$isBl && ($n = (int)val('SELECT COUNT(*) FROM document_payments WHERE document_id = ?', [$d['id']]))) $out[] = 'ses ' . $n . ' paiement(s) (' . money($d['paid']) . ') aussi';
    if ($d['stock_applied']) $out[] = 'les articles seront remis en stock';
    if (!$isBl && $d['source_id'] && ($src = one('SELECT type FROM documents WHERE id = ?', [$d['source_id']]))) {
        $out[] = $src['type'] === 'bl' ? 'le bon de livraison d’origine pourra de nouveau être facturé' : 'le devis d’origine redeviendra « accepté »';
    }
    $out[] = doc_is_last($d) ? 'son numéro sera repris par ' . ($isBl ? 'le prochain bon de livraison' : 'la prochaine facture')
        : 'son numéro ne sera pas réutilisé (un trou dans la numérotation, noté dans le journal)';
    return implode(' ; ', $out) . '.';
}

function doc_delete(array $d): void
{
    if ($why = doc_delete_block($d)) throw new RuntimeException($why);
    if ($d['type'] !== 'devis') {
        $action = $d['type'] === 'bl' ? 'bon de livraison supprimé' : 'facture supprimée';
        preg_match('/(\d+)\/(\d{2})$/', (string)$d['number'], $m);
        $last = doc_is_last($d);
        tx(function () use ($d, $m, $last, $action) {
            if ($d['stock_applied']) doc_stock($d, doc_items((int)$d['id']), +1);
            doc_release_source($d);
            $pays = all('SELECT amount FROM document_payments WHERE document_id = ?', [$d['id']]);
            q('DELETE FROM document_payments WHERE document_id = ?', [$d['id']]);
            // Un document issu de celui-ci (bon de livraison d'une facture, facture d'un bon) reste intact : il perd seulement le lien.
            q('UPDATE documents SET source_id = NULL WHERE source_id = ?', [$d['id']]);
            q('DELETE FROM documents WHERE id = ?', [$d['id']]);
            // Dernier numéro : libéré, le prochain document le reprend. Sinon le numéro reste un trou, tracé dans le journal.
            if ($last && $m) {
                $prefix = substr((string)$d['number'], 0, -strlen($m[0]));
                $n = (int)$m[1] - 1;
                // Les numéros juste avant, laissés libres par d'autres documents supprimés, sont aussi repris : la suite
                // redevient continue. Un numéro jamais attribué (avant le numéro de départ choisi) n'est pas touché.
                while ($n > 0) {
                    $num = $prefix . str_pad((string)$n, 6, '0', STR_PAD_LEFT) . '/' . $m[2];
                    if (val('SELECT 1 FROM documents WHERE type = ? AND number = ?', [$d['type'], $num])
                        || !val('SELECT 1 FROM audit_log WHERE action = ? AND details LIKE ?', [$action, $num . ' · %'])) break;
                    $n--;
                }
                q('UPDATE counters SET value = ? WHERE key = ? AND value = ?', [$n, 'doc:' . $d['type'] . ':' . (2000 + (int)$m[2]), (int)$m[1]]);
            }
            audit($action, 'document', $d['id'], $d['number'] . ' · ' . $d['client_name'] . ' · ' . money($d['net'])
                . ($pays ? ' · ' . count($pays) . ' paiement(s) supprimé(s) : ' . money(array_sum(array_column($pays, 'amount'))) : '')
                . ($last ? ' · numéro libéré' : ' · numéro non réutilisé'));
        });
        return;
    }
    tx(function () use ($d) {
        // Une facture issue de ce devis reste intacte : elle perd seulement le lien vers le devis.
        q('UPDATE documents SET source_id = NULL WHERE source_id = ?', [$d['id']]);
        q('DELETE FROM documents WHERE id = ?', [$d['id']]);
        audit('devis supprimé', 'document', $d['id'], $d['number'] . ' · ' . $d['client_name'] . ' · ' . money($d['net']));
    });
}

/** Supprime plusieurs documents, les plus récents d'abord pour libérer les derniers numéros. Retourne [supprimés, erreurs]. */
function doc_delete_many(array $ids, string $type): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    rsort($ids);
    $done = 0;
    $errors = [];
    foreach ($ids as $id) {
        $d = doc_get($id, $type);
        if (!$d) continue;
        try { doc_delete($d); $done++; } catch (RuntimeException $e) { $errors[] = $d['number'] . ' : ' . $e->getMessage(); }
    }
    return [$done, $errors];
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
