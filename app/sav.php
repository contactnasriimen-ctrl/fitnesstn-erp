<?php
declare(strict_types=1);

/* ---------- Réparations & service après-vente ---------- */

function repair_number(): string
{
    return 'SAV-' . date('y') . '-' . str_pad((string)next_seq('repair:' . date('Y')), 5, '0', STR_PAD_LEFT);
}

function repair_load(int $id): ?array
{
    $r = one('SELECT * FROM repairs WHERE id = ?', [$id]);
    return $r && can_loc($r['location_id'] !== null ? (int)$r['location_id'] : null) ? $r : null;
}

/**
 * Crée ou met à jour une fiche. $in : client_name, phone, phone2, address, gouvernorat, product, product_id, issue, kind,
 * technician, status, place, amount (millimes), paid (millimes), appointment, received_at, note, location_id, import_ref.
 */
function repair_save(array $in, ?array $old = null): int
{
    $status = isset(REPAIR_STATUS[$in['status'] ?? '']) ? $in['status'] : ($old['status'] ?? 'nouveau');
    $phone = normalize_phone((string)($in['phone'] ?? ''));
    $name = mb_substr(trim((string)($in['client_name'] ?? '')), 0, 120);
    $address = mb_substr(trim((string)($in['address'] ?? '')), 0, 300);
    $gouv = trim((string)($in['gouvernorat'] ?? ''));
    if ($gouv === '' || !in_array($gouv, GOUVERNORATS, true)) $gouv = guess_gouvernorat($address);
    $data = [
        'kind' => isset(REPAIR_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'reparation',
        'client_name' => $name, 'phone' => $phone, 'phone2' => normalize_phone((string)($in['phone2'] ?? '')),
        'gouvernorat' => $gouv, 'address' => $address,
        'product_id' => !empty($in['product_id']) ? (int)$in['product_id'] : null,
        'product' => mb_substr(trim((string)($in['product'] ?? '')), 0, 160),
        'issue' => mb_substr(trim((string)($in['issue'] ?? '')), 0, 500),
        'technician' => mb_substr(trim((string)($in['technician'] ?? '')), 0, 60),
        'status' => $status,
        'place' => isset(REPAIR_PLACES[$in['place'] ?? '']) ? $in['place'] : 'domicile',
        'amount' => max(0, (int)($in['amount'] ?? 0)), 'paid' => max(0, (int)($in['paid'] ?? 0)),
        'appointment' => ($in['appointment'] ?? '') !== '' ? str_replace('T', ' ', substr((string)$in['appointment'], 0, 16)) : null,
        'received_at' => parse_date($in['received_at'] ?? null),
        'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 1000),
        'updated_at' => now(),
    ];
    if ($data['product_id'] && $data['product'] === '') $data['product'] = (string)val('SELECT name FROM products WHERE id = ?', [$data['product_id']]);
    if ($status === 'repare') $data['done_at'] = $old['done_at'] ?? ($in['done_at'] ?? null) ?: today();
    elseif ($old && $old['status'] === 'repare') $data['done_at'] = null;
    if ($name === '' && $phone === '' && $data['product'] === '' && $data['issue'] === '' && $address === '') throw new RuntimeException('Fiche vide : indiquez au moins le client, le téléphone, l’adresse ou la panne.');

    // Client : retrouvé par téléphone ou créé (fiche client = historique achats + réparations)
    if ($phone !== '' || $name !== '') {
        $data['client_id'] = client_upsert(['name' => $name, 'phone' => $phone, 'address' => $address, 'gouvernorat' => $gouv,
            'source' => $old ? '' : (string)($in['source'] ?? '')]);
        if ($name === '' && $data['client_id']) $data['client_name'] = (string)val('SELECT name FROM clients WHERE id = ?', [$data['client_id']]);
    }
    if ($old) {
        update('repairs', $data, 'id = ?', [$old['id']]);
        if ($old['status'] !== $status) audit('réparation : ' . REPAIR_STATUS[$status], 'repair', $old['id'], $old['number']);
        return (int)$old['id'];
    }
    $data += [
        'number' => repair_number(), 'location_id' => $in['location_id'] ?? null, 'import_ref' => $in['import_ref'] ?? null,
        'user_id' => user()['id'] ?? null, 'created_at' => $in['created_at'] ?? now(),
    ];
    $id = insert('repairs', $data);
    audit('réparation créée', 'repair', $id, $data['number'] . ' · ' . ($data['client_name'] ?: $phone));
    return $id;
}

/** Lien WhatsApp vers le client, avec un message adapté au statut. */
function repair_whatsapp(array $r): string
{
    $p = normalize_phone((string)$r['phone']);
    if (strlen($p) !== 8) return '';
    $machine = $r['product'] ?: 'votre machine';
    $msg = match ($r['status']) {
        'repare' => "Bonjour, ici FitnessTN. Bonne nouvelle : $machine est réparé(e)." . ((int)$r['amount'] > (int)$r['paid'] ? ' Montant : ' . money((int)$r['amount'] - (int)$r['paid']) . '.' : '') . ' Merci pour votre confiance !',
        'en_cours' => "Bonjour, ici FitnessTN. Votre demande de réparation ($machine) est en cours. Notre technicien vous tient informé(e).",
        default => "Bonjour, ici FitnessTN, au sujet de votre demande de réparation ($machine). Quand notre technicien peut-il passer ?",
    };
    return 'https://wa.me/216' . $p . '?text=' . rawurlencode($msg);
}

function repair_tone(string $status): string
{
    return ['nouveau' => 'info', 'en_cours' => 'warn', 'repare' => 'ok', 'annule' => 'err'][$status] ?? '';
}
