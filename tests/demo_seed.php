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
echo "Démo prête : " . val('SELECT COUNT(*) FROM sales') . " ventes\n";
