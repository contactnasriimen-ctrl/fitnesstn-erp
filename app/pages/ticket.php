<?php
declare(strict_types=1);

require_perm('sales.view');
$sale = one('SELECT s.*, u.name AS seller, c.name AS client_name, c.phone AS client_phone, c.address AS client_address,
        c.city AS client_city, c.gouvernorat AS client_gov
    FROM sales s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN clients c ON c.id = s.client_id WHERE s.id = ?', [(int)get('id')]);
if (!$sale || !can_loc((int)$sale['location_id'])) {
    http_response_code(404);
    exit('Vente introuvable.');
}
$loc = location((int)$sale['location_id']);
$items = sale_items((int)$sale['id']);
$pays = all('SELECT * FROM payments WHERE sale_id = ? ORDER BY id', [$sale['id']]);
$vat = sale_vat($sale, $items);
$ships = all("SELECT sh.*, ca.name carrier FROM shipments sh LEFT JOIN carriers ca ON ca.id = sh.carrier_id WHERE sh.sale_id = ? AND sh.status <> 'annulee'", [$sale['id']]);
$a4 = get('format') === 'a4';
$company = setting('company_name', 'FitnessTN');
$mf = setting('company_mf', '');
$cancelled = $sale['status'] === 'annulee';
$rest = (int)$sale['total'] - (int)$sale['paid'];

$warranty = function (array $it) use ($sale): string {
    $m = (int)$it['warranty_months'];
    if ($m <= 0) return '';
    $end = date('d/m/Y', strtotime($sale['created_at'] . " +$m months"));
    return 'Garantie ' . ($m % 12 === 0 ? ($m / 12) . ' an' . ($m > 12 ? 's' : '') : $m . ' mois') . ' (jusqu’au ' . $end . ')';
};
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $a4 ? 'Facture' : 'Ticket' ?> <?= e($sale['number']) ?></title>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/print.css?v=<?= filemtime(APP_ROOT . '/assets/css/print.css') ?>">
</head>
<body class="<?= $a4 ? 'a4' : 'ticket' ?>">
<div class="toolbar no-print">
  <button onclick="window.print()">Imprimer</button>
  <a href="<?= e(url('ticket', ['id' => $sale['id'], 'format' => $a4 ? null : 'a4'])) ?>"><?= $a4 ? 'Version ticket 80 mm' : 'Version facture A4' ?></a>
  <a href="<?= e(url('sales', ['a' => 'view', 'id' => $sale['id']])) ?>">Voir la vente</a>
</div>

<?php if (!$a4): ?>
<main class="paper">
  <header class="t-head">
    <img src="assets/img/logo.svg" alt="" class="t-logo">
    <h1><?= e($company) ?></h1>
    <p><?= e($loc['name'] ?? '') ?><br><?= e($loc['address'] ?? '') ?><br>Tél. <?= e($loc['phone'] ?: setting('company_phone')) ?></p>
    <?php if ($mf): ?><p>MF : <?= e($mf) ?></p><?php endif; ?>
  </header>
  <?php if ($cancelled): ?><p class="stamp">VENTE ANNULÉE</p><?php endif; ?>
  <p class="t-meta">Ticket <b><?= e($sale['number']) ?></b><br><?= fdate($sale['created_at'], true) ?> · <?= e($sale['seller']) ?>
    <?php if ($sale['client_name']): ?><br>Client : <?= e($sale['client_name']) ?> <?= e($sale['client_phone']) ?><?php endif; ?></p>
  <table class="t-items">
    <?php foreach ($items as $it): ?>
      <tr><td colspan="2" class="name"><?= e($it['label']) ?></td></tr>
      <tr><td><?= (int)$it['qty'] ?> × <?= money($it['unit_price'], false) ?></td><td class="num"><?= money($it['total'], false) ?></td></tr>
      <?php if ($w = $warranty($it)): ?><tr><td colspan="2" class="small"><?= e($w) ?></td></tr><?php endif; ?>
    <?php endforeach; ?>
  </table>
  <table class="t-tot">
    <?php if ((int)$sale['discount'] > 0): ?>
      <tr><td>Sous-total</td><td class="num"><?= money($sale['subtotal']) ?></td></tr>
      <tr><td>Remise</td><td class="num">− <?= money($sale['discount']) ?></td></tr>
    <?php endif; ?>
    <tr class="big"><td>TOTAL TTC</td><td class="num"><?= money($sale['total']) ?></td></tr>
    <?php foreach ($vat as $v): if ($v['rate'] <= 0) continue; ?>
      <tr class="small"><td>dont TVA <?= rtrim(rtrim(number_format($v['rate'], 1, ',', ''), '0'), ',') ?> %</td><td class="num"><?= money($v['vat']) ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($pays as $p): ?>
      <tr><td><?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></td><td class="num"><?= money($p['amount']) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($rest > 0 && !$cancelled): ?><tr class="big"><td>RESTE À PAYER</td><td class="num"><?= money($rest) ?></td></tr><?php endif; ?>
  </table>
  <?php foreach ($ships as $sh): ?>
    <p class="t-meta">Livraison <?= e($sh['number']) ?><?= $sh['planned_date'] ? ' prévue le ' . fdate($sh['planned_date']) : '' ?><?= $sh['carrier'] ? ' · ' . e($sh['carrier']) : '' ?><?= (int)$sh['cod_amount'] && !$sh['cod_collected'] ? '<br>À régler à la livraison : <b>' . money($sh['cod_amount']) . '</b>' : '' ?></p>
  <?php endforeach; ?>
  <p class="t-foot"><?= nl2br(e(setting('ticket_footer', ''))) ?><br><?= e(setting('company_site', '')) ?></p>
</main>

<?php else: ?>
<main class="paper">
  <header class="a4-head">
    <div class="brand"><img src="assets/img/logo.svg" alt=""><div><h1><?= e($company) ?></h1>
      <p><?= e($loc['name'] ?? '') ?> — <?= e($loc['address'] ?? '') ?><br>Tél. <?= e($loc['phone'] ?: setting('company_phone')) ?> · <?= e(setting('company_email')) ?> · <?= e(setting('company_site')) ?>
      <?php if ($mf): ?><br>Matricule fiscal : <?= e($mf) ?><?php endif; ?></p></div></div>
    <div class="doc"><h2>FACTURE</h2><p>N° <b><?= e($sale['number']) ?></b><br>Date : <?= fdate($sale['created_at']) ?></p></div>
  </header>
  <?php if ($cancelled): ?><p class="stamp">ANNULÉE le <?= fdate($sale['cancelled_at']) ?></p><?php endif; ?>
  <section class="a4-client">
    <h3>Client</h3>
    <?php if ($sale['client_name']): ?>
      <p><b><?= e($sale['client_name']) ?></b><br><?= e($sale['client_phone']) ?><br><?= e(trim($sale['client_address'] . ' ' . $sale['client_city'] . ' ' . $sale['client_gov'])) ?></p>
    <?php else: ?><p>Client de passage</p><?php endif; ?>
  </section>
  <table class="a4-items">
    <thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">P.U. TTC</th><th class="num">TVA</th><th class="num">Total TTC</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr><td><?= e($it['label']) ?><?php if ($it['sku']): ?> <small>(<?= e($it['sku']) ?>)</small><?php endif; ?><?php if ($w = $warranty($it)): ?><br><small><?= e($w) ?></small><?php endif; ?></td>
        <td class="num"><?= (int)$it['qty'] ?></td><td class="num"><?= money($it['unit_price'], false) ?></td>
        <td class="num"><?= rtrim(rtrim(number_format((float)$it['vat_rate'], 1, ',', ''), '0'), ',') ?> %</td><td class="num"><?= money($it['total'], false) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="a4-bottom">
    <table class="a4-vat">
      <thead><tr><th>Taux</th><th class="num">Base HT</th><th class="num">TVA</th><th class="num">TTC</th></tr></thead>
      <?php foreach ($vat as $v): ?>
        <tr><td><?= rtrim(rtrim(number_format($v['rate'], 1, ',', ''), '0'), ',') ?> %</td><td class="num"><?= money($v['ht'], false) ?></td><td class="num"><?= money($v['vat'], false) ?></td><td class="num"><?= money($v['ttc'], false) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <table class="a4-tot">
      <?php if ((int)$sale['discount'] > 0): ?>
        <tr><td>Sous-total TTC</td><td class="num"><?= money($sale['subtotal']) ?></td></tr>
        <tr><td>Remise</td><td class="num">− <?= money($sale['discount']) ?></td></tr>
      <?php endif; ?>
      <tr><td>Total HT</td><td class="num"><?= money(array_sum(array_column($vat, 'ht'))) ?></td></tr>
      <tr><td>Total TVA</td><td class="num"><?= money(array_sum(array_column($vat, 'vat'))) ?></td></tr>
      <tr class="big"><td>Net à payer TTC</td><td class="num"><?= money($sale['total']) ?></td></tr>
      <tr><td>Déjà réglé</td><td class="num"><?= money($sale['paid']) ?></td></tr>
      <?php if ($rest > 0 && !$cancelled): ?><tr class="big"><td>Reste à payer</td><td class="num"><?= money($rest) ?></td></tr><?php endif; ?>
    </table>
  </div>
  <?php if ($pays): ?>
    <p class="small">Règlements : <?php echo e(implode(' · ', array_map(fn($p) => (PAY_METHODS[$p['method']] ?? $p['method']) . ' ' . money($p['amount']) . ' (' . fdate($p['created_at']) . ')', $pays))); ?></p>
  <?php endif; ?>
  <p class="small"><?= e(setting('invoice_note', '')) ?></p>
  <footer class="a4-foot"><?= e($company) ?> · <?= e(setting('company_phone')) ?> · <?= e(setting('company_site')) ?></footer>
</main>
<?php endif; ?>
<?php if (get('print')): ?><script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script><?php endif; ?>
</body>
</html>
