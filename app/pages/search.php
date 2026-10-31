<?php
declare(strict_types=1);

// Recherche globale (Ctrl+K) : clients, produits, devis, factures et ventes, selon les droits de l'utilisateur.
$term = trim((string)get('q'));
$out = [];
if (mb_strlen($term) >= 2) {
    $like = '%' . $term . '%';
    $digits = normalize_phone($term);
    $scope = scope_loc();
    if (can('clients.view')) {
        foreach (all("SELECT id, name, phone, city FROM clients WHERE name LIKE ? OR (? <> '' AND phone LIKE ?) ORDER BY name LIMIT 5", [$like, $digits, '%' . $digits . '%']) as $r) {
            $out[] = ['g' => 'Clients', 't' => $r['name'], 's' => trim($r['phone'] . ' · ' . $r['city'], ' ·'), 'h' => url('clients', ['a' => 'view', 'id' => $r['id']]), 'i' => 'users'];
        }
    }
    if (can('invoices.view')) {
        foreach (all('SELECT id, type, number, client_name, net FROM documents WHERE (number LIKE ? OR client_name LIKE ?)' . ($scope ? ' AND location_id = ' . (int)$scope : '') . ' ORDER BY id DESC LIMIT 6', [$like, $like]) as $r) {
            $out[] = ['g' => $r['type'] === 'devis' ? 'Devis' : 'Factures', 't' => $r['number'] . ' — ' . $r['client_name'], 's' => money($r['net']),
                'h' => url(doc_route($r['type']), ['a' => 'view', 'id' => $r['id']]), 'i' => $r['type'] === 'devis' ? 'quote' : 'invoice'];
        }
    }
    if (can('sales.view')) {
        foreach (all('SELECT s.id, s.number, s.total, c.name FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE s.number LIKE ?' . ($scope ? ' AND s.location_id = ' . (int)$scope : '') . ' ORDER BY s.id DESC LIMIT 4', [$like]) as $r) {
            $out[] = ['g' => 'Ventes', 't' => $r['number'] . ($r['name'] ? ' — ' . $r['name'] : ''), 's' => money($r['total']), 'h' => url('sales', ['a' => 'view', 'id' => $r['id']]), 'i' => 'receipt'];
        }
    }
    if (can('products.view')) {
        foreach (all('SELECT * FROM products WHERE active = 1 AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 6', [$like, $like]) as $r) {
            $out[] = ['g' => 'Produits', 't' => $r['name'], 's' => trim(($r['sku'] ? $r['sku'] . ' · ' : '') . (product_price($r) ? money(product_price($r)) : 'Sur devis')),
                'h' => url('products', ['a' => 'view', 'id' => $r['id']]), 'i' => 'box'];
        }
    }
}
json_out(['ok' => true, 'results' => $out]);
