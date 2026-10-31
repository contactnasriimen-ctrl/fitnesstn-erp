<?php
// Données de démonstration pour les captures d'écran (base temporaire uniquement, jamais la vraie base).
// Usage : FTN_DB=chemin FTN_SEED_PASSWORD=… php tests/demo_seed.php
declare(strict_types=1);

if (!getenv('FTN_DB')) exit("FTN_DB obligatoire (base temporaire)\n");
require __DIR__ . '/../app/bootstrap.php';
db();
$_SESSION = ['uid' => 1];
mt_srand(2026);

$prods = all('SELECT * FROM products WHERE active = 1 AND price > 0');
$small = array_values(array_filter($prods, fn($p) => product_price($p) < 300000));
$big = array_values(array_filter($prods, fn($p) => product_price($p) >= 1000000 && product_price($p) <= 8000000));
foreach ([1, 2, 3] as $loc) foreach ($prods as $p) stock_change((int)$p['id'], $loc, mt_rand(0, $loc === 3 ? 8 : 3), 'inventaire', 'Inventaire initial');

$camp = insert('campaigns', ['name' => 'Rentrée tapis de course — Facebook', 'channel' => 'facebook', 'status' => 'active', 'budget' => 800000, 'spent' => 520000,
    'leads' => 46, 'messages' => 130, 'reach' => 38500, 'start_date' => date('Y-m-d', strtotime('-20 days')), 'end_date' => date('Y-m-d', strtotime('+10 days')), 'created_at' => now()]);
insert('campaigns', ['name' => 'Vidéos démo vélos — TikTok', 'channel' => 'tiktok', 'status' => 'active', 'budget' => 400000, 'spent' => 150000, 'leads' => 21,
    'messages' => 64, 'reach' => 52000, 'start_date' => date('Y-m-d', strtotime('-8 days')), 'end_date' => date('Y-m-d', strtotime('+20 days')), 'created_at' => now()]);

$names = ['Samia Ben Ali', 'Mounira Trabelsi', 'Ines Gharbi', 'Karim Jaziri', 'Yasmine Ayari', 'Hela Mansouri', 'Rim Chaabane', 'Leila Ferchichi', 'Nadia Bouazizi', 'Sonia Hammami'];
$sources = ['showroom', 'facebook', 'facebook', 'instagram', 'tiktok', 'recommandation', 'google', 'whatsapp'];
for ($d = 29; $d >= 0; $d--) {
    $day = date('Y-m-d', strtotime("-$d days"));
    foreach ([1, 2] as $loc) {
        $sid = insert('cash_sessions', ['location_id' => $loc, 'opened_by' => 1, 'opened_at' => "$day 09:0" . $loc . ':00', 'opening_float' => 300000, 'status' => 'ouverte']);
        $n = mt_rand(0, $loc === 1 ? 4 : 3);
        for ($i = 0; $i < $n; $i++) {
            $items = [];
            $p = mt_rand(1, 10) <= 3 && $big ? $big[array_rand($big)] : $small[array_rand($small)];
            $items[] = ['product_id' => $p['id'], 'qty' => 1, 'unit_price' => m_in(product_price($p))];
            if (mt_rand(0, 2) === 0) { $q = $small[array_rand($small)]; $items[] = ['product_id' => $q['id'], 'qty' => mt_rand(1, 2), 'unit_price' => m_in(product_price($q))]; }
            $total = 0;
            foreach ($items as $it) $total += parse_money($it['unit_price']) * $it['qty'];
            $partial = $total > 1000000 && mt_rand(0, 3) === 0;
            $method = $total > 500000 ? (mt_rand(0, 1) ? 'carte' : 'virement') : 'especes';
            $paid = $partial ? intdiv($total, 3000) * 1000 : $total;
            $name = $names[array_rand($names)];
            $res = sale_create(['location_id' => $loc, 'items' => $items, 'discount' => 0,
                'client' => ['name' => $name, 'phone' => (string)(20000000 + crc32($name) % 9000000), 'gouvernorat' => $loc === 1 ? 'Ariana' : 'Nabeul'],
                'source' => $sources[array_rand($sources)], 'campaign_id' => mt_rand(0, 3) === 0 ? $camp : null,
                'payments' => [['method' => $method, 'amount' => m_in($paid)]],
                'delivery' => $total > 1000000 ? ['enabled' => true, 'carrier_id' => 1, 'planned_date' => date('Y-m-d', strtotime("$day +2 days")), 'fee' => '30'] : null]);
            $at = $day . ' ' . sprintf('%02d:%02d:00', mt_rand(10, 19), mt_rand(0, 59));
            q('UPDATE sales SET created_at = ? WHERE id = ?', [$at, $res['id']]);
            q('UPDATE payments SET created_at = ? WHERE sale_id = ?', [$at, $res['id']]);
            q('UPDATE shipments SET created_at = ?, status = ? WHERE sale_id = ?', [$at, $d > 3 ? 'livree' : 'a_preparer', $res['id']]);
        }
        if ($d > 0) {
            $t = session_totals(one('SELECT * FROM cash_sessions WHERE id = ?', [$sid]));
            $diff = mt_rand(0, 6) === 0 ? -mt_rand(1, 9) * 1000 : 0;
            update('cash_sessions', ['status' => 'fermee', 'closed_by' => 1, 'closed_at' => "$day 19:30:00", 'expected_cash' => $t['expected_cash'],
                'counted_cash' => $t['expected_cash'] + $diff, 'difference' => $diff, 'to_safe' => $t['expected_cash'] + $diff - 300000, 'note' => $diff ? 'Erreur de monnaie' : ''], 'id = ?', [$sid]);
        }
    }
}
q("UPDATE shipments SET cod_collected = 1 WHERE status = 'livree' AND id % 3 <> 0");
foreach ([['loyer', 'Loyer showroom Tunis', 1800000, 1], ['loyer', 'Loyer showroom Nabeul', 1200000, 2], ['energie', 'STEG octobre', 310000, 1],
    ['transport', 'Carburant camion', 180000, null], ['publicite', 'Facebook Ads', 520000, null], ['salaires', 'Salaires septembre', 6400000, null]] as $x) {
    insert('expenses', ['date' => date('Y-m-d', strtotime('-' . mt_rand(1, 9) . ' days')), 'location_id' => $x[3], 'category' => $x[0], 'label' => $x[1], 'amount' => $x[2], 'method' => 'virement', 'user_id' => 1, 'created_at' => now()]);
}
q('UPDATE products SET cost = CAST(price * 0.62 AS INTEGER) WHERE price > 0');
q("UPDATE tasks SET assignee_id = 2, due_date = date('now', '+2 days') WHERE board_id = 2");
q("UPDATE tasks SET assignee_id = 4, due_date = date('now', '-1 days') WHERE board_id = 1 AND priority = 'haute'");

// Devis & factures fictifs (clients professionnels)
foreach (['company_legal_name' => 'STE DÉMO FITNESS', 'company_address' => 'Avenue de la Démo, Ariana 2080', 'bank_name' => 'Banque Démo — Agence Ariana',
    'bank_rib' => '00 000 0000000000 000 00', 'bank_iban' => 'TN00 0000 0000 0000 0000 0000', 'bank_bic' => 'DEMOTNTT'] as $k => $v) set_setting($k, $v);
$gym = ['name' => 'Salle Atlas Gym', 'phone' => '71 000 111', 'address' => 'Rue du Lac, Les Berges du Lac, Tunis', 'mf' => '0000001/B/M/000'];
$hotel = ['name' => 'Hôtel Démo Hammamet', 'phone' => '72 000 222', 'address' => 'Zone touristique, Hammamet', 'mf' => '0000002/C/N/000'];
$line = fn(array $p, int $q, float $disc = 0) => ['product_id' => $p['id'], 'label' => $p['name'], 'qty' => $q, 'unit_price' => m_in(product_price($p)), 'discount_pct' => $disc, 'vat_rate' => 19];
$doc = fn(int $id) => one('SELECT * FROM documents WHERE id = ?', [$id]);
$mk = function (string $type, array $client, array $items, int $daysAgo, array $extra = []) use ($doc) {
    $day = date('Y-m-d', strtotime("-$daysAgo days"));
    $id = doc_save($extra + ['type' => $type, 'location_id' => 1, 'client' => $client, 'date' => $day,
        'due_date' => date('Y-m-d', strtotime("$day +15 days")), 'items' => $items, 'timbre' => true,
        'terms' => 'Acompte de 30 % à la commande, solde à la livraison', 'transport' => 'Livraison et installation incluses', 'warranty' => 'Selon le produit']);
    q('UPDATE documents SET created_at = ? WHERE id = ?', ["$day 10:00:00", $id]);
    return $doc($id);
};
if ($big && $small) {
    $inv2 = $mk('facture', $hotel, [$line($small[2 % count($small)], 6)], 40, ['stock' => true]);
    doc_add_payment($inv2, 'cheque', (int)$inv2['net'], 'Chèque n° 0001', date('Y-m-d', strtotime('-30 days')));
    $mk('facture', $gym, [$line($small[3 % count($small)], 4)], 35);
    $mk('devis', ['name' => 'Club Sportif Démo', 'phone' => '73 000 333'], [$line($big[3 % count($big)], 1)], 25);
    $q1 = $mk('devis', $gym, [$line($big[0], 3, 5), $line($big[1 % count($big)], 2, 5), $line($small[0], 10)], 12);
    doc_set_status($q1, 'envoye');
    doc_set_status($doc((int)$q1['id']), 'accepte');
    $inv = $doc(doc_copy($doc((int)$q1['id']), 'facture', true));
    doc_add_payment($inv, 'virement', intdiv((int)$inv['net'] * 3, 10000) * 1000, 'Acompte — virement', date('Y-m-d', strtotime('-9 days')));
    doc_set_status($mk('devis', $hotel, [$line($big[2 % count($big)], 4), $line($small[1 % count($small)], 8)], 3), 'envoye');
}

echo "Démo prête : " . val('SELECT COUNT(*) FROM sales') . " ventes, " . val('SELECT COUNT(*) FROM documents') . " devis et factures\n";
