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
        $ds = doc_scope();
        foreach (all('SELECT id, type, number, client_name, net FROM documents WHERE (number LIKE ? OR client_name LIKE ?)' . ($ds ? ' AND location_id = ' . (int)$ds : '') . ' ORDER BY id DESC LIMIT 6', [$like, $like]) as $r) {
            $out[] = ['g' => ['devis' => 'Devis', 'facture' => 'Factures', 'bl' => 'Bons de livraison'][$r['type']] ?? 'Factures', 't' => $r['number'] . ' — ' . $r['client_name'], 's' => money($r['net']),
                'h' => url(doc_route($r['type']), ['a' => 'view', 'id' => $r['id']]), 'i' => doc_icon($r['type'])];
        }
    }
    if (can('sales.view')) {
        foreach (all('SELECT s.id, s.number, s.total, c.name FROM sales s LEFT JOIN clients c ON c.id = s.client_id WHERE s.number LIKE ?' . ($scope ? ' AND s.location_id = ' . (int)$scope : '') . ' ORDER BY s.id DESC LIMIT 4', [$like]) as $r) {
            $out[] = ['g' => 'Ventes', 't' => $r['number'] . ($r['name'] ? ' — ' . $r['name'] : ''), 's' => money($r['total']), 'h' => url('sales', ['a' => 'view', 'id' => $r['id']]), 'i' => 'receipt'];
        }
    }
    if (can('deliveries.view')) {
        $sq = 'SELECT id, number, recipient, gouvernorat, city, status FROM shipments WHERE (number LIKE ? OR recipient LIKE ? OR tracking LIKE ?' . ($digits !== '' ? ' OR phone LIKE ?' : '') . ')'
            . ($scope ? ' AND location_id = ' . (int)$scope : '') . ' ORDER BY id DESC LIMIT 4';
        foreach (all($sq, array_merge([$like, $like, $like], $digits !== '' ? ['%' . $digits . '%'] : [])) as $r) {
            $out[] = ['g' => 'Livraisons', 't' => $r['number'] . ' — ' . $r['recipient'], 's' => trim(($r['gouvernorat'] ?: $r['city']) . ' · ' . (SHIP_STATUS[$r['status']] ?? ''), ' ·'),
                'h' => url('deliveries', ['a' => 'edit', 'id' => $r['id']]), 'i' => 'truck'];
        }
    }
    if (can('repairs.view')) {
        $sq = 'SELECT id, number, client_name, product, status FROM repairs WHERE (number LIKE ? OR client_name LIKE ? OR product LIKE ?' . ($digits !== '' ? ' OR phone LIKE ? OR phone2 LIKE ?' : '') . ')'
            . ($scope ? ' AND location_id = ' . (int)$scope : '') . ' ORDER BY id DESC LIMIT 4';
        foreach (all($sq, array_merge([$like, $like, $like], $digits !== '' ? ['%' . $digits . '%', '%' . $digits . '%'] : [])) as $r) {
            $out[] = ['g' => 'Réparations', 't' => $r['number'] . ' — ' . ($r['client_name'] ?: $r['product']), 's' => trim($r['product'] . ' · ' . (REPAIR_STATUS[$r['status']] ?? ''), ' ·'),
                'h' => url('repairs', ['a' => 'edit', 'id' => $r['id']]), 'i' => 'tool'];
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
