<?php
declare(strict_types=1);

/*
 * Import des classeurs Excel tenus à la main par FitnessTN :
 *  - « VENTE GRAND TUNIS » (une feuille par mois) : ventes du showroom de Tunis + livraisons (transporteur, technicien, frais) ;
 *  - « PAIEMENT » : livraisons confiées aux transporteurs et argent récupéré (payé, retour, non payé) ;
 *  - « RÉPARATION ET SERVICE APRÈS VENTE » : fiches de réparation / SAV.
 * Chaque ligne reçoit une référence (import_ref) : réimporter le même fichier, complété, ne crée pas de doublon
 * et met à jour ce qui a changé (statut d'une réparation, paiement reçu d'un transporteur…).
 */

require_once __DIR__ . '/xlsx.php';

const XL_KINDS = [
    'ventes' => 'Ventes « VENTE GRAND TUNIS »',
    'paiements' => 'Paiements des transporteurs « PAIEMENT »',
    'reparations' => 'Réparations & service après-vente',
];

/** Transporteurs reconnus dans les textes (motif sur le texte en majuscules => nom, type, note). */
const XL_CARRIERS = [
    'JET ?PACK' => ['JetPack', 'externe', 'Société de livraison (paiement à la livraison).'],
    'BEST ?DEL' => ['Best Delivery', 'externe', 'Société de livraison (petits colis).'],
    'HAMMAMI' => ['Hammami Transport', 'externe', 'Société de transport (paiement à la livraison).'],
    '\bTPS\b' => ['TPS', 'externe', 'Société de transport (paiement à la livraison).'],
    'EXPRESS' => ['Express', 'externe', 'Société de livraison (paiement à la livraison).'],
    'KOUKI' => ['Omar Kouki (camion)', 'externe', 'Transporteur indépendant.'],
    'HMIDA' => ['Hmida (camion)', 'externe', 'Transporteur indépendant.'],
    'KHALED' => ['Khaled (camion)', 'externe', 'Transporteur indépendant.'],
    'RAWED' => ['Rawed', 'externe', 'Transporteur.'],
    'MOURAD' => ['Mourad', 'externe', 'Transporteur.'],
];

/** Machines citées dans les fichiers => nom exact du produit au catalogue. */
const XL_PRODUCTS = [
    '/BT ?5100/' => 'TAPIS PRO BT 5100', '/BT ?5700/' => 'TAPIS PRO BT 5700', '/BT ?6100/' => 'TAPIS PRO BT 6100',
    '/BT ?500/' => 'TAPIS ROULANT BT 500+', '/BT ?400/' => 'BT 400+', '/BT ?320|(?<![0-9])320 ?\+/' => 'BT 320 +',
    '/BT ?200|(?<![0-9])200 ?\+/' => 'BT 200+', '/815/' => 'HIT UP SPORT 815 DS', '/8091/' => 'TAPIS ROULANT HIT UP SPORT',
    '/SPEED ?RUN/' => 'HITUP-SPEED RUN inclinaison automatique - 120Kg', '/TITA?N/' => 'HIT UP SPORT TITNAUIM 2.5 HP / 130 KG',
    // « BABDIDA B2 » = tapis B2 ; « BABDIDA » seul = B1
    '/\bB1\b|BAB ?DIDA(?!.*\bB ?[2-4]\b)/' => 'TAPIS B1-BABDIDA',
    '/(?<!VELO )\bB ?2\b(?! ?0)/' => 'TAPIS B2 120KG - AUTO INCLINE', '/AVON|(?<!VELO )\bB ?3\b(?! ?0)/' => 'TAPIS ROULANT B3-AVON FITNESS',
    '/\bB ?4\b(?! ?0)/' => 'TAPIS 140 KG B4', '/VELO.*\bB ?20\b/' => 'VELO B 20', '/VELO.*\bB ?30\b/' => 'VELO B 30',
    '/\bA ?30\b/' => 'Vélo elliptique A30', '/\bE ?20\b/' => 'Vélo elliptique – BT E 20', '/MULTI ?GYM/' => 'MACHINE DE MUSUCLATION-MULTIGYM',
    '/HUILE|LUBRIF/' => 'HUILE LUBRIFIANT TAPIS ROULANT 200ML', '/BARRE ?FIX/' => 'BARREFIXE- PRO', '/CLE DE SECUR/' => 'CLE DE SECURITE STANDARD',
];

function xl_clean($v): string
{
    return trim((string)preg_replace('/\s+/u', ' ', (string)$v));
}

/** Texte en majuscules sans accents ni ponctuation (le « + » est gardé : BT 200+). */
function xl_upper(string $v): string
{
    $s = strtr(mb_strtoupper($v), ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'À' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ç' => 'C']);
    return ' ' . trim((string)preg_replace('/[^A-Z0-9+]+/', ' ', $s)) . ' ';
}

/**
 * Montant écrit à la main -> millimes : « 3790 DT », « 1,520,000 », « 2.050.000 », « 100.000 » (= 100 DT),
 * « 74.87 », « 1300dt twali 1241dt » (= 1241 DT). null si aucun nombre.
 */
function xl_money(string $v): ?int
{
    $s = xl_clean($v);
    if ($s === '' || !preg_match_all('/\d[\d.,]*/', $s, $m)) return null;
    $tok = rtrim(preg_match('/twali|devient|=>|→/iu', $s) ? end($m[0]) : $m[0][0], '.,');
    if (preg_match('/^\d{1,3}([.,]\d{3}){2,}$/', $tok)) return (int)preg_replace('/\D/', '', $tok);
    if (preg_match('/^(\d+)[.,](\d{3})$/', $tok, $mm)) {
        $x = (int)$mm[1];
        return $x < 10 ? (int)($mm[1] . $mm[2]) * 1000 : $x * 1000 + (int)$mm[2];
    }
    if (preg_match('/^(\d+)[.,](\d{1,2})$/', $tok, $mm)) return (int)round((float)($mm[1] . '.' . $mm[2]) * 1000);
    if (preg_match('/^\d+$/', $tok)) {
        $n = (int)$tok;
        return $n >= 100000 ? $n : $n * 1000;
    }
    return null;
}

/** Téléphones d'une cellule (« 55,770,473//50,460,519 », « 95,262,797 93,805,107 sa mère ») + texte restant. */
function xl_phones(string $v): array
{
    $s = preg_replace('/(?<=\d)[.,](?=\d)/', '', $v);
    preg_match_all('/\d{7,12}/', $s, $m);
    $phones = array_values(array_unique(array_filter(array_map('normalize_phone', $m[0]))));
    $rest = xl_clean(preg_replace('/[\d\/\-.,+]+/', ' ', $s));
    return [$phones, preg_match('/\pL{2,}/u', $rest) ? $rest : ''];
}

/** Produit du catalogue correspondant au texte (un seul produit reconnu), sinon null. */
function xl_match_product(string $label, int $price = 0): ?array
{
    static $byName = null;
    if ($byName === null) {
        $byName = [];
        foreach (all('SELECT id, name, price, promo_price, cost, warranty_months FROM products') as $p) $byName[mb_strtolower($p['name'])][] = $p;
    }
    $parts = array_filter(array_map('trim', explode('/', preg_replace('/\bFAC\s?\d+\b/i', '', $label))), fn($p) => preg_match('/[A-Za-z0-9]{2,}/', $p));
    if (count($parts) !== 1) return null;
    $u = xl_upper((string)reset($parts));
    $found = [];
    foreach (XL_PRODUCTS as $re => $name) if (preg_match($re, $u)) $found[$name] = true;
    if (!$found && preg_match('/HIT ?UP/', $u)) $found['TAPIS ROULANT HIT UP SPORT'] = true;
    if (count($found) !== 1) return null;
    $cands = $byName[mb_strtolower((string)array_key_first($found))] ?? [];
    if (!$cands) return null;
    usort($cands, fn($a, $b) => abs((int)$a['price'] - $price) <=> abs((int)$b['price'] - $price));
    return $cands[0];
}

/** Transporteur cité dans un texte : [id, nom] (créé s'il n'existe pas encore), ou [null, '']. */
function xl_carrier(string $text, bool $driverColumn = false): array
{
    $u = xl_upper($text);
    foreach (XL_CARRIERS as $re => [$name, $type, $note]) {
        if (!preg_match('/' . $re . '/', $u)) continue;
        return [xl_carrier_id($name, $type, $note), $name];
    }
    if (!$driverColumn) return [null, ''];
    // Colonne « TRANSPORTEUR » : « OMAR 180dt » = Omar Kouki ; un autre nom (« HAMADI : 300 DT ») devient un transporteur
    if (preg_match('/\bOMAR\b/', $u)) return [xl_carrier_id('Omar Kouki (camion)', 'externe', 'Transporteur indépendant.'), 'Omar Kouki (camion)'];
    [$person] = xl_person($text);
    if ($person === '' || preg_match('/^(retour|livr|client|patron|depot|dépôt)/iu', $person)) return [null, ''];
    $name = $person . ' (camion)';
    return [xl_carrier_id($name, 'externe', 'Transporteur indépendant.'), $name];
}

function xl_carrier_id(string $name, string $type, string $note): int
{
    $id = val('SELECT id FROM carriers WHERE name = ? COLLATE NOCASE', [$name]);
    if ($id) return (int)$id;
    return insert('carriers', ['name' => $name, 'type' => $type, 'phone' => '', 'contact' => '', 'zones' => '', 'default_fee' => 0, 'cod' => 1,
        'notes' => $note . ' Ajouté par l’import Excel.', 'active' => 1, 'created_at' => now()]);
}

/** Le texte cite-t-il un transporteur connu (sans en créer) ? */
function xl_carrier_known(string $text): bool
{
    $u = xl_upper($text);
    foreach (array_keys(XL_CARRIERS) as $re) if (preg_match('/' . $re . '/', $u)) return true;
    return false;
}

/** « ROSTOM :60 DT » / « ILYES 20DT » / « omar » -> ['Rostom', 60000] ; une phrase n'est pas un nom (['', 0]). */
function xl_person(string $text): array
{
    $t = xl_clean($text);
    if ($t === '' || !preg_match('/\pL/u', $t)) return ['', 0];
    $name = xl_clean(preg_split('/[:\d]/u', $t)[0]);
    $name = mb_convert_case(mb_strtolower(rtrim($name, ' :-')), MB_CASE_TITLE);
    $name = strtr($name, ['Rostem' => 'Rostom', 'Ilyess' => 'Ilyes']);
    if ($name === '' || mb_strlen($name) > 20 || count(explode(' ', $name)) > 2 || preg_match('/^(livr|client|non |annul|echange|échange|retour|ok)/iu', $name)) {
        return ['', 0];
    }
    return [mb_substr($name, 0, 60), xl_money($t) ?? 0];
}

/** Adresse sans les cellules remplies de virgules (« ,,,,,,, »). */
function xl_address(string $v): string
{
    $v = xl_clean(trim($v, " ,.;-"));
    return preg_match('/[\pL\d]/u', $v) ? $v : '';
}

/** Retrouve (par téléphone, sinon par nom + adresse) ou crée le client d'une ligne. */
function xl_client(string $name, array $phones, string $address, string $mf = '', ?string $date = null): ?int
{
    $phone = $phones[0] ?? '';
    if ($name === '' && $phone === '') return null;
    $gouv = guess_gouvernorat($address);
    $ex = $phone !== '' ? one('SELECT * FROM clients WHERE phone = ? OR (phone2 = ? AND phone2 <> \'\')', [$phone, $phone])
        : one("SELECT * FROM clients WHERE name = ? COLLATE NOCASE AND phone = '' AND address = ? COLLATE NOCASE", [$name, $address]);
    if ($ex) {
        $upd = [];
        if ($name !== '' && (str_starts_with($ex['name'], 'Client ') || $ex['name'] === '')) $upd['name'] = $name;
        if (($phones[1] ?? '') !== '' && $ex['phone2'] === '' && $phones[1] !== $ex['phone']) $upd['phone2'] = $phones[1];
        if ($address !== '' && $ex['address'] === '') $upd['address'] = $address;
        if ($gouv !== '' && $ex['gouvernorat'] === '') $upd['gouvernorat'] = $gouv;
        if ($mf !== '' && ($ex['mf'] ?? '') === '') $upd['mf'] = mb_substr($mf, 0, 60);
        if ($upd) update('clients', $upd + ['updated_at' => now()], 'id = ?', [$ex['id']]);
        return (int)$ex['id'];
    }
    return insert('clients', [
        'name' => $name !== '' ? mb_substr($name, 0, 120) : 'Client ' . $phone, 'phone' => $phone, 'phone2' => $phones[1] ?? '',
        'gouvernorat' => $gouv, 'city' => '', 'address' => mb_substr($address, 0, 300), 'mf' => mb_substr($mf, 0, 60), 'source' => '',
        'notes' => 'Créé par l’import Excel.', 'created_at' => ($date ?: today()) . ' 12:00:00', 'updated_at' => now(),
    ]);
}

function xl_location(): ?int
{
    $id = val("SELECT id FROM locations WHERE code = 'TUN'") ?: val("SELECT id FROM locations WHERE type = 'showroom' ORDER BY position, id LIMIT 1");
    return $id ? (int)$id : null;
}

/** Type de fichier d'après son titre et ses en-têtes. */
function xl_detect(array $sheets): ?string
{
    $txt = '';
    foreach (array_slice($sheets, 0, 2) as $sh) foreach (array_slice($sh['rows'], 0, 4, true) as $cells) $txt .= ' ' . implode(' ', $cells);
    $u = xl_upper($txt);
    if (preg_match('/\bPANNE\b|REPARATION|APRES VENTE|REPARATEUR/', $u)) return 'reparations';
    if (preg_match('/\bPAIEMENT\b/', $u) || (str_contains($u, ' TRANSPORT ') && preg_match('/\bPAYE\b/', $u))) return 'paiements';
    if (preg_match('/\bVENTE\b/', $u) || (str_contains($u, ' MACHINE ') && str_contains($u, ' TOTAL '))) return 'ventes';
    return null;
}

/** Ligne d'en-têtes : première ligne contenant tous les mots demandés. */
function xl_header_row(array $rows, array $words): ?int
{
    foreach (array_slice($rows, 0, 12, true) as $n => $cells) {
        $u = xl_upper(implode(' | ', $cells));
        $ok = true;
        foreach ($words as $w) if (!str_contains($u, ' ' . $w . ' ')) { $ok = false; break; }
        if ($ok) return (int)$n;
    }
    return null;
}

/** Référence anti-doublon : clé lisible + n° d'occurrence (deux lignes identiques le même jour restent distinctes). */
function xl_ref(array &$seen, string ...$parts): string
{
    $key = implode('|', array_map(fn($p) => slug_key((string)$p), $parts));
    $seen[$key] = ($seen[$key] ?? 0) + 1;
    return mb_substr($key, 0, 240) . '#' . $seen[$key];
}

/**
 * Importe (ou simule, si $commit est faux) un classeur. Retourne un rapport :
 * kind, label, sheets [nom => lignes], rows, created [type => n], updated, unchanged, skipped, warnings[], unmatched[], amount.
 */
function xl_import(string $file, bool $commit, ?string $kind = null): array
{
    $sheets = xlsx_read($file);
    $kind = $kind ?: xl_detect($sheets);
    if (!$kind || !isset(XL_KINDS[$kind])) {
        throw new RuntimeException('Fichier non reconnu : seuls les classeurs « VENTE GRAND TUNIS », « PAIEMENT » et « RÉPARATION ET SERVICE APRÈS VENTE » sont pris en charge ici. Pour les autres données, utilisez les imports CSV ci-dessous.');
    }
    $rep = ['kind' => $kind, 'label' => XL_KINDS[$kind], 'sheets' => [], 'rows' => 0, 'created' => [], 'updated' => 0, 'unchanged' => 0,
        'skipped' => 0, 'warnings' => [], 'unmatched' => [], 'amount' => 0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $before = ['clients' => (int)val('SELECT COUNT(*) FROM clients'), 'transporteurs' => (int)val('SELECT COUNT(*) FROM carriers')];
        if ($kind === 'ventes') xl_import_ventes($sheets, $rep);
        elseif ($kind === 'paiements') xl_import_paiements($sheets, $rep);
        else xl_import_reparations($sheets, $rep);
        foreach ($before as $k => $n) if (($d = (int)val('SELECT COUNT(*) FROM ' . ($k === 'clients' ? 'clients' : 'carriers')) - $n) > 0) $rep['created'][$k] = $d;
        arsort($rep['unmatched']);
        $rep['unmatched'] = array_slice($rep['unmatched'], 0, 15, true);
        $rep['warnings'] = array_slice($rep['warnings'], 0, 60);
        if ($commit) {
            $created = implode(', ', array_map(fn($k, $n) => "$n $k", array_keys($rep['created']), $rep['created']));
            audit('import Excel', $kind, null, XL_KINDS[$kind] . ' · ' . ($created ?: 'rien de nouveau') . " · {$rep['updated']} mise(s) à jour");
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $rep;
}

function xl_count(array &$rep, string $what, int $n = 1): void
{
    $rep['created'][$what] = ($rep['created'][$what] ?? 0) + $n;
}

/* ---------- VENTE GRAND TUNIS ---------- */

function xl_import_ventes(array $sheets, array &$rep): void
{
    $loc = xl_location();
    $uid = user()['id'] ?? null;
    $seen = [];
    foreach ($sheets as $sheet) {
        $rows = $sheet['rows'];
        $hdr = xl_header_row($rows, ['DATE', 'TOTAL']);
        if ($hdr === null) {
            if ($rows) $rep['warnings'][] = "Feuille « {$sheet['name']} » : ligne d’en-têtes (DATE, TOTAL…) introuvable, feuille ignorée.";
            continue;
        }
        $cols = [];
        foreach ($rows[$hdr] as $c => $label) {
            $u = xl_upper($label);
            // « FRAIS DE TRANSPORT PAYÉ PAR LE CLIENT » contient « CLIENT » : FRAIS est testé en premier
            $k = match (true) {
                str_contains($u, ' FRAIS ') => 'fees', str_contains($u, ' TRANSPORTEUR ') => 'driver',
                str_contains($u, ' DATE ') => 'date', str_contains($u, ' MACHINE ') => 'machine',
                str_contains($u, ' MATRICULE ') || trim($u) === 'MF' => 'mf', str_contains($u, ' NUM') => 'phone',
                str_contains($u, ' NOM ') || str_contains($u, ' CLIENT ') => 'client', str_contains($u, ' ADRESSE ') => 'address',
                str_contains($u, ' TOTAL ') => 'total', str_contains($u, ' FRAIS ') => 'fees', str_contains($u, ' TECHNICIEN ') => 'tech',
                str_contains($u, ' TRANSPORTEUR ') => 'driver', str_contains($u, ' TARIF ') => 'tarif', str_contains($u, ' HMIDA ') => 'hmida',
                default => null,
            };
            if ($k && !isset($cols[$k])) $cols[$k] = (int)$c;
        }
        // La colonne des frais est souvent fusionnée avec la suivante (« LIVRÉ », « BEST DELIVERY »…)
        $fees2 = isset($cols['fees']) && !in_array($cols['fees'] + 1, $cols, true) ? $cols['fees'] + 1 : null;
        $month = preg_match('/(\d{1,2})\D+(20\d\d)/', $sheet['name'], $m) ? sprintf('%04d-%02d', $m[2], $m[1]) : null;
        $last = null;
        $n = 0;
        foreach ($rows as $line => $cells) {
            if ($line <= $hdr) continue;
            try {
            $g = fn(string $k) => isset($cols[$k]) ? xl_clean($cells[$cols[$k]] ?? '') : '';
            $date = xlsx_date($g('date'));
            // Date tapée avec une erreur (autre mois que la feuille) : on garde la date précédente
            if ($date && $month && abs((strtotime($date) - strtotime($month . '-15')) / 86400) > 62) $date = null;
            if ($date) $last = $date;
            $date = $date ?: ($last ?: ($month ? $month . '-01' : today()));
            $machine = $g('machine');
            $client = $g('client');
            $phoneTxt = $g('phone');
            $address = xl_address($g('address'));
            $totalTxt = $g('total');
            $fees = xl_clean($g('fees') . ' ' . ($fees2 ? ($cells[$fees2] ?? '') : ''));
            $tech = $g('tech');
            $driver = $g('driver');
            $hmida = $g('hmida');
            if ($machine === '' && $client === '' && $phoneTxt === '' && $totalTxt === '' && $address === '') {
                // Ligne de frais seule (« 50 DT HMIDA ») : dépense de transport du jour
                $feeTxt = $driver ?: ($hmida !== '' ? $hmida . ' HMIDA' : $tech);
                $amount = $feeTxt !== '' ? (int)(xl_money($feeTxt) ?? 0) : 0;
                if ($amount <= 0) { if ($fees . $tech . $driver . $hmida !== '') $rep['skipped']++; continue; }
                $rep['rows']++;
                [, $cname] = xl_carrier($feeTxt, true);
                $ref = xl_ref($seen, 'vente-frais', $date, $feeTxt);
                if (val('SELECT id FROM expenses WHERE import_ref = ?', [$ref])) { $rep['unchanged']++; continue; }
                insert('expenses', ['date' => $date, 'location_id' => $loc, 'category' => 'transport',
                    'label' => 'Transport' . ($cname ? ' — ' . $cname : '') . ' (fichier VENTE GRAND TUNIS)', 'amount' => $amount, 'method' => 'especes',
                    'supplier' => $cname, 'reference' => '', 'note' => $feeTxt, 'user_id' => $uid, 'created_at' => now(), 'import_ref' => $ref]);
                xl_count($rep, 'dépenses de transport');
                continue;
            }
            $rep['rows']++;
            $n++;
            $total = (int)(xl_money($totalTxt) ?? 0);
            [$phones, $phoneNote] = xl_phones($phoneTxt);
            $ref = xl_ref($seen, 'vente', $date, $client ?: $address, $machine, (string)$total);
            if (val('SELECT id FROM sales WHERE import_ref = ?', [$ref]) || val('SELECT id FROM shipments WHERE import_ref = ?', [$ref . '|liv'])) {
                $rep['unchanged']++;
                continue;
            }
            $clientId = xl_client($client, $phones, $address, $g('mf'), $date);
            $inv = preg_match('/\bFAC\s?(\d+)/i', $machine, $mm) ? 'FAC' . $mm[1] : '';
            $label = xl_clean(preg_replace('/^\s*FAC\s?\d+\s*[\/:\-]?\s*/i', '', $machine)) ?: ($machine ?: 'Vente');
            $p = xl_match_product($label, $total);
            if (!$p && $label !== '') $rep['unmatched'][$label] = ($rep['unmatched'][$label] ?? 0) + 1;

            // Livraison : transporteur (société citée dans les frais, sinon camion de la colonne TRANSPORTEUR), technicien, frais
            $pickup = (bool)preg_match('/retrait/i', $fees . ' ' . $tech . ' ' . $driver);
            [$carrierId, $carrierName] = xl_carrier($fees);
            // Un transporteur noté dans la colonne TECHNICIEN (« Transporteur khaled : 60 DT », « HMIDA ») reste un transporteur
            $techIsDriver = $tech !== '' && (preg_match('/transporteur/i', $tech) || xl_carrier_known($tech));
            $driverTxt = $driver !== '' ? $driver : ($hmida !== '' ? 'HMIDA ' . $hmida : ($techIsDriver ? $tech : ''));
            [$driverId, $driverName] = $driverTxt !== '' ? xl_carrier($driverTxt, true) : [null, ''];
            if (!$carrierId) [$carrierId, $carrierName] = [$driverId, $driverName];
            $carrierFee = $driverTxt !== '' ? (int)(xl_money($driverTxt) ?? 0) : 0;
            [$techName, $techFee] = $techIsDriver || preg_match('/retrait/i', $tech) ? ['', 0] : xl_person($tech);
            $clientFee = (int)(xl_money($fees) ?? 0);
            // « TPS (RETOUR) bien reçu » : livraison revenue (client absent, produit endommagé…) = vente annulée, pas d'argent
            $retour = (bool)preg_match('/retour/i', $fees);
            // Retour « bien reçu », ou ancien (plus de 30 jours) : la marchandise est revenue au dépôt
            $backHome = $retour && (preg_match('/re[cç]u/iu', $fees) || $date < date('Y-m-d', strtotime('-30 days')));
            $notes = array_filter([
                $inv ? 'Facture ' . $inv : '', $g('tarif') !== '' ? 'Tarif : ' . $g('tarif') : '', $phoneNote,
                "Importé du fichier Excel « VENTE GRAND TUNIS » (feuille {$sheet['name']}, ligne $line).",
            ]);

            $saleId = null;
            if ($total > 0) {
                $year = substr($date, 0, 4);
                $number = 'XL-' . substr($year, 2) . '-' . str_pad((string)next_seq('sale:XL:' . $year), 5, '0', STR_PAD_LEFT);
                $at = $date . ' 12:00:00';
                $saleId = insert('sales', [
                    'number' => $number, 'location_id' => $loc, 'session_id' => null, 'user_id' => $uid, 'client_id' => $clientId,
                    'created_at' => $at, 'subtotal' => $total, 'discount' => 0, 'total' => $total, 'paid' => $retour ? 0 : $total, 'catalog_total' => $total,
                    'status' => $retour ? 'annulee' : 'payee', 'source' => '', 'note' => mb_substr(implode(' · ', $notes), 0, 500), 'import_ref' => $ref,
                ] + ($retour ? ['cancelled_at' => $date . ' 18:00:00', 'cancelled_by' => $uid, 'cancel_reason' => mb_substr('Retour transporteur : ' . $fees, 0, 300)] : []));
                insert('sale_items', ['sale_id' => $saleId, 'product_id' => $p['id'] ?? null, 'label' => mb_substr($p['name'] ?? $label, 0, 120), 'qty' => 1,
                    'unit_price' => $total, 'list_price' => $total, 'unit_cost' => isset($p['cost']) ? (int)$p['cost'] : null, 'vat_rate' => (float)setting('vat_rate', 19),
                    'total' => $total, 'warranty_months' => $p['warranty_months'] ?? null]);
                if (!$retour) {
                    insert('payments', ['sale_id' => $saleId, 'session_id' => null, 'location_id' => $loc, 'created_at' => $at,
                        'method' => $carrierId && !$pickup ? 'cod' : 'especes', 'amount' => $total, 'reference' => 'Import Excel', 'user_id' => $uid]);
                    $rep['amount'] += $total;
                }
                xl_count($rep, $retour ? 'ventes annulées (retour)' : 'ventes');
            } elseif ($totalTxt === '') {
                $rep['warnings'][] = "Feuille {$sheet['name']}, ligne $line : montant absent (« " . mb_substr($machine ?: $client, 0, 50) . ' ») — livraison enregistrée sans vente.';
            }
            if (!$pickup && ($address !== '' || $carrierId || $techName !== '')) {
                $status = $retour ? 'retour' : 'livree';
                $shId = shipment_create([
                    'sale_id' => $saleId, 'client_id' => $clientId, 'location_id' => $loc, 'carrier_id' => $carrierId,
                    'recipient' => $client !== '' ? $client : ($address !== '' ? $address : 'Client'), 'phone' => $phones[0] ?? '',
                    'gouvernorat' => guess_gouvernorat($address), 'city' => '', 'address' => $address, 'status' => $status,
                    'holder' => $backHome ? 'depart' : ship_holder_for_status($status),
                    'holder_note' => $backHome ? (preg_match('/re[cç]u/iu', $fees) ? 'Retour reçu au dépôt' : 'Revenue au dépôt (retour ancien du fichier Excel)') :($driverName && $driverName !== $carrierName ? 'Camion : ' . $driverName : ''),
                    'tracking' => '', 'fee' => $carrierFee, 'cod_amount' => $saleId && $carrierId && !$retour ? $total : 0, 'cod_collected' => $retour ? 0 : 1,
                    'client_fee' => $clientFee, 'technician' => $techName, 'technician_fee' => $techFee,
                    'planned_date' => $date, 'delivered_at' => $status === 'livree' ? $date . ' 18:00:00' : null,
                    'note' => mb_substr(implode(' · ', array_filter([$fees !== '' ? 'Frais : ' . $fees : '', $driver !== '' ? 'Transporteur : ' . $driver : '', $tech !== '' ? 'Technicien : ' . $tech : ''])), 0, 500),
                    'created_at' => $date . ' 12:00:00', 'updated_at' => $date . ' 18:00:00', 'import_ref' => $ref . '|liv',
                ], $saleId ? null : [['product_id' => $p['id'] ?? null, 'label' => $p['name'] ?? $label, 'qty' => 1]], 'Importée du fichier Excel « VENTE GRAND TUNIS »');
                if ($status === 'livree') shipment_event($shId, 'Livrée' . ($techName ? ' et installée par ' . $techName : ''), 'ok', $date . ' 18:00:00');
                else shipment_event($shId, 'Retour / échec de livraison' . ($backHome ? ' · marchandise revenue au dépôt' : ''), 'err', $date . ' 18:00:00');
                xl_count($rep, 'livraisons');
            }
            } catch (Throwable $e) {
                throw new RuntimeException("Feuille « {$sheet['name']} », ligne $line : " . $e->getMessage(), 0, $e);
            }
        }
        $rep['sheets'][$sheet['name']] = $n;
    }
}

/* ---------- PAIEMENT (transporteurs) ---------- */

function xl_import_paiements(array $sheets, array &$rep): void
{
    $loc = xl_location();
    $seen = [];
    foreach ($sheets as $sheet) {
        $rows = $sheet['rows'];
        $hdr = xl_header_row($rows, ['CLIENT', 'TRANSPORT']);
        if ($hdr === null) continue;
        $cols = [];
        foreach ($rows[$hdr] as $c => $label) {
            $u = xl_upper($label);
            $k = match (true) {
                str_contains($u, ' DATE ') => 'date', str_contains($u, ' REFERENCE ') => 'ref', str_contains($u, ' CLIENT ') => 'client',
                str_contains($u, ' MONTANT ') => 'amount', str_contains($u, ' ADRESSE ') => 'address', str_contains($u, ' TRANSPORT ') => 'carrier',
                str_contains($u, ' NON PAYE ') => 'unpaid', str_contains($u, ' PAYE ') => 'paid', str_contains($u, ' MACHINE ') => 'machine',
                default => null,
            };
            if ($k && !isset($cols[$k])) $cols[$k] = (int)$c;
        }
        $last = null;
        $n = 0;
        foreach ($rows as $line => $cells) {
            if ($line <= $hdr) continue;
            try {
            $g = fn(string $k) => isset($cols[$k]) ? xl_clean($cells[$cols[$k]] ?? '') : '';
            $refTxt = $g('ref');
            $client = $g('client');
            $amountTxt = $g('amount');
            $address = xl_address($g('address'));
            if ($refTxt === '' && $client === '' && $amountTxt === '' && $address === '') { if (count($cells)) $rep['skipped']++; continue; }
            $date = xlsx_date($g('date'));
            if ($date) $last = $date;
            $date = $date ?: ($last ?: today());
            $rep['rows']++;
            $n++;
            $tracking = preg_match('/^\s*(T?\d[\d\/]*)/i', $refTxt, $m) ? strtoupper($m[1]) : '';
            $label = xl_clean(trim(substr($refTxt, strlen($m[0] ?? '')))) ?: $g('machine');
            $amount = (int)(xl_money($amountTxt) ?? 0);
            [$carrierId, $carrierName] = xl_carrier($g('carrier'));
            if (!$carrierId && $g('carrier') !== '') {
                $name = mb_convert_case(mb_strtolower($g('carrier')), MB_CASE_TITLE);
                $carrierId = xl_carrier_id($name, 'externe', 'Transporteur.');
                $carrierName = $name;
            }
            $st = mb_strtolower($g('paid') . ' ' . $g('unpaid'));
            $note = '';
            if (str_contains($st, 'retour')) { $status = 'retour'; $collected = 0; $note = 'Retour (fichier PAIEMENT)'; }
            elseif (preg_match('/vir|vair/u', $st)) { $status = 'livree'; $collected = 1; $note = 'Payé par virement bancaire'; }
            elseif (str_contains($st, 'achat')) { $status = 'livree'; $collected = 1; $note = 'Achat machine'; }
            elseif (preg_match('/pay|pai/u', $st)) { $status = 'livree'; $collected = 1; }
            elseif (str_contains($st, 'livr')) { $status = 'livree'; $collected = 0; $note = 'Livré, argent pas encore reçu du transporteur'; }
            else { $status = 'en_cours'; $collected = 0; $note = 'En attente (fichier PAIEMENT : ni payé ni retour)'; }
            if ($amount === 0) $collected = 1;
            $ref = xl_ref($seen, 'paiement', $date, $refTxt, $client);

            $ex = one('SELECT * FROM shipments WHERE import_ref = ?', [$ref]);
            if ($ex) {
                // Réimport : le statut a pu évoluer (en attente -> payé, livré -> payé…)
                $changed = false;
                if ($ex['status'] !== $status) { shipment_set_status($ex, $status, null, 'mise à jour depuis le fichier PAIEMENT'); $changed = true; }
                if ($collected && !$ex['cod_collected']) { shipment_collect(one('SELECT * FROM shipments WHERE id = ?', [$ex['id']])); $changed = true; }
                $rep[$changed ? 'updated' : 'unchanged']++;
                continue;
            }
            // Vente correspondante (fichier VENTE GRAND TUNIS déjà importé) : même client, même montant, ±45 jours
            $sale = null;
            if ($client !== '' && $amount > 0) {
                foreach (all("SELECT s.*, c.name cname FROM sales s JOIN clients c ON c.id = s.client_id WHERE s.import_ref IS NOT NULL AND s.total = ?
                        AND date(s.created_at) BETWEEN date(?, '-45 days') AND date(?, '+45 days')", [$amount, $date, $date]) as $s) {
                    if (slug_key($s['cname']) === slug_key($client)) { $sale = $s; break; }
                }
            }
            $data = ['carrier_id' => $carrierId, 'tracking' => $tracking, 'cod_amount' => $amount, 'status' => $status, 'holder' => ship_holder_for_status($status),
                'delivered_at' => $status === 'livree' ? $date . ' 18:00:00' : null, 'import_ref' => $ref];
            $shId = null;
            if ($sale && ($sh = one('SELECT * FROM shipments WHERE sale_id = ? ORDER BY id LIMIT 1', [$sale['id']])) && !str_starts_with((string)$sh['import_ref'], 'paiement')) {
                update('shipments', $data + ['cod_collected' => $collected, 'note' => trim($sh['note'] . ' · ' . ($note !== '' ? $note : 'Payé') . (str_contains($note, 'PAIEMENT') ? '' : ' (fichier PAIEMENT)'), ' ·'), 'updated_at' => now()], 'id = ?', [$sh['id']]);
                shipment_event((int)$sh['id'], 'Fichier PAIEMENT : ' . ($note ?: ($collected ? 'payé' : SHIP_STATUS[$status])) . ($carrierName ? ' · ' . $carrierName : ''), $collected ? 'ok' : 'info');
                $shId = (int)$sh['id'];
                xl_count($rep, 'livraisons rapprochées d’une vente');
            } else {
                [$phones] = xl_phones('');
                $clientId = $client !== '' ? xl_client($client, $phones, $address, '', $date) : null;
                $p = $label !== '' ? xl_match_product($label, $amount) : null;
                $shId = shipment_create([
                    'sale_id' => $sale['id'] ?? null, 'client_id' => $clientId ?: ($sale['client_id'] ?? null), 'location_id' => $loc,
                    'recipient' => $client !== '' ? $client : ($address ?: 'Client'), 'phone' => '', 'gouvernorat' => guess_gouvernorat($address),
                    'city' => '', 'address' => $address, 'cod_collected' => $collected, 'fee' => 0, 'planned_date' => $date,
                    'note' => mb_substr(trim($note . ' · Importé du fichier Excel « PAIEMENT » (ligne ' . $line . ').', ' ·'), 0, 500),
                    'created_at' => $date . ' 12:00:00', 'updated_at' => $date . ' 12:00:00',
                ] + $data, $sale ? null : ($label !== '' ? [['product_id' => $p['id'] ?? null, 'label' => $p['name'] ?? $label, 'qty' => 1]] : []),
                    'Remise au transporteur' . ($tracking ? ' (n° ' . $tracking . ')' : ''));
                if ($status === 'livree') shipment_event($shId, $collected ? 'Livrée · argent récupéré' . ($note ? ' (' . mb_strtolower($note) . ')' : '') : 'Livrée · argent à récupérer', $collected ? 'ok' : 'info', $date . ' 18:00:00');
                if ($status === 'retour') shipment_event($shId, 'Retour / échec de livraison', 'err', $date . ' 18:00:00');
                xl_count($rep, 'livraisons');
            }
            // Vente rapprochée mais argent pas reçu : le paiement « contre-remboursement » supposé à l'import des ventes est retiré
            if ($sale && !$collected) {
                $removed = (int)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE sale_id = ? AND reference = 'Import Excel'", [$sale['id']]);
                if ($removed) {
                    q("DELETE FROM payments WHERE sale_id = ? AND reference = 'Import Excel'", [$sale['id']]);
                    $paid = (int)val('SELECT COALESCE(SUM(amount),0) FROM payments WHERE sale_id = ?', [$sale['id']]);
                    update('sales', ['paid' => $paid, 'status' => $paid >= (int)$sale['total'] ? 'payee' : 'partielle'], 'id = ?', [$sale['id']]);
                }
            }
            if ($amount > 0) $rep['amount'] += $amount;
            } catch (Throwable $e) {
                throw new RuntimeException("Feuille « {$sheet['name']} », ligne $line : " . $e->getMessage(), 0, $e);
            }
        }
        $rep['sheets'][$sheet['name']] = $n;
    }
}

/* ---------- RÉPARATION ET SERVICE APRÈS VENTE ---------- */

function xl_import_reparations(array $sheets, array &$rep): void
{
    $loc = xl_location();
    $seen = [];
    foreach ($sheets as $sheet) {
        $rows = $sheet['rows'];
        $hdr = xl_header_row($rows, ['PANNE']) ?? xl_header_row($rows, ['PRODUIT']);
        if ($hdr === null) continue;
        $cols = [];
        foreach ($rows[$hdr] as $c => $label) {
            $u = xl_upper($label);
            $k = match (true) {
                str_contains($u, ' DATE ') => 'date', str_contains($u, ' NOM ') || str_contains($u, ' CLIENT ') => 'client',
                str_contains($u, ' NUM') || str_contains($u, ' TEL') => 'phone', str_contains($u, ' ADRESSE ') => 'address',
                str_contains($u, ' PRODUIT ') || str_contains($u, ' MACHINE ') => 'product', str_contains($u, ' PANNE ') => 'issue',
                str_contains($u, ' REPARATEUR ') || str_contains($u, ' TECHNICIEN ') => 'tech', str_contains($u, ' STATUT ') => 'status',
                (bool)preg_match('/^ R | S A V | SAV /', $u) => 'kind',
                default => null,
            };
            if ($k && !isset($cols[$k])) $cols[$k] = (int)$c;
        }
        $known = array_values($cols);
        $n = 0;
        foreach ($rows as $line => $cells) {
            if ($line <= $hdr) continue;
            try {
            $g = fn(string $k) => isset($cols[$k]) ? xl_clean($cells[$cols[$k]] ?? '') : '';
            // Colonnes sans en-tête (montant payé, diagnostic, rendez-vous…)
            $extra = xl_clean(implode(' · ', array_filter(array_map(fn($c) => xl_clean($c), array_diff_key($cells, array_flip($known))))));
            $client = $g('client');
            $phoneTxt = $g('phone');
            $address = xl_address($g('address'));
            $product = $g('product');
            $issue = $g('issue');
            $statusTxt = $g('status');
            if ($client . $phoneTxt . $address . $product . $issue . $statusTxt === '') {
                if ($extra !== '') $rep['skipped']++; // ligne de total (« total: 360 dt »)
                continue;
            }
            $rep['rows']++;
            $n++;
            [$phones, $phoneNote] = xl_phones($phoneTxt);
            $st = mb_strtolower($statusTxt);
            $money = $statusTxt . ' ' . $extra;
            $amount = (int)(preg_match('/(\d[\d.,]*)\s*(dt|dinar|pay|paye|payé)|(?:pay[ée]?|diagnostique?|r[ée]par[ée])\D{0,6}(\d[\d.,]*)/iu', $money, $mm)
                ? (xl_money($mm[1] !== '' ? $mm[1] : $mm[3]) ?? 0) : 0);
            if (!$amount && preg_match('/^\d+([.,]\d+)?$/', $extra)) $amount = (int)(xl_money($extra) ?? 0);
            $isPaid = (bool)preg_match('/pay/iu', $money);
            $status = match (true) {
                str_contains($st, 'annul') => 'annule', str_contains($st, 'cours') => 'en_cours',
                (bool)preg_match('/r[ée]par/u', $st) => 'repare', $isPaid => 'repare', default => 'nouveau',
            };
            $kind = preg_match('/S\W*A\W*V/i', $g('kind')) ? 'sav' : 'reparation';
            $tech = $g('tech') !== '' ? mb_convert_case(mb_strtolower($g('tech')), MB_CASE_TITLE) : '';
            $p = $product !== '' ? xl_match_product($product) : null;
            $ref = xl_ref($seen, 'reparation', $phones[0] ?? $client, $product, $issue, $address);
            $in = [
                'client_name' => $client, 'phone' => $phones[0] ?? '', 'phone2' => $phones[1] ?? '', 'address' => $address, 'gouvernorat' => '',
                'product' => $product, 'product_id' => $p['id'] ?? null, 'issue' => $issue, 'kind' => $kind, 'technician' => $tech,
                'status' => $status, 'place' => preg_match('/ram[eè]n|atelier|tjib|chijib|ychijib|men seilana/iu', $issue . ' ' . $address) ? 'atelier' : 'domicile',
                'amount' => $amount, 'paid' => $isPaid ? $amount : 0,
                'note' => mb_substr(implode(' · ', array_filter([$phoneNote, $extra, $statusTxt !== '' && $amount ? $statusTxt : '', 'Importé du fichier Excel (ligne ' . $line . ').'])), 0, 1000),
                'location_id' => $loc, 'import_ref' => $ref,
            ];
            $ex = one('SELECT * FROM repairs WHERE import_ref = ?', [$ref]);
            if ($ex) {
                if ($ex['status'] !== $status || (int)$ex['amount'] !== $amount || (int)$ex['paid'] !== $in['paid'] || $ex['technician'] !== $tech) {
                    repair_save(['note' => $ex['note'], 'appointment' => $ex['appointment'], 'received_at' => $ex['received_at']] + $in, $ex);
                    $rep['updated']++;
                } else {
                    $rep['unchanged']++;
                }
                continue;
            }
            repair_save($in);
            xl_count($rep, 'réparations');
            $rep['amount'] += $in['paid'];
            } catch (Throwable $e) {
                throw new RuntimeException("Feuille « {$sheet['name']} », ligne $line : " . $e->getMessage(), 0, $e);
            }
        }
        $rep['sheets'][$sheet['name']] = $n;
    }
}
