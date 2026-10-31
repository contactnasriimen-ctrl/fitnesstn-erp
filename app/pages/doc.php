<?php
declare(strict_types=1);

// Devis / facture au format A4, prêt à imprimer ou à enregistrer en PDF.
require_perm('invoices.view');
$d = doc_get((int)get('id'));
if (!$d) {
    http_response_code(404);
    exit('Document introuvable.');
}
$isQuote = $d['type'] === 'devis';
$items = doc_items((int)$d['id']);
$vat = sale_vat($d, $items);
$loc = $d['location_id'] ? location((int)$d['location_id']) : null;
$company = setting('company_name', 'FitnessTN');
$legal = setting('company_legal_name', '') ?: $company;
$address = setting('company_address', '') ?: ($loc['address'] ?? '');
$color = preg_match('/^#[0-9a-f]{6}$/i', (string)setting('doc_color', '')) ? setting('doc_color') : '#145c54';
$embed = (bool)get('embed');
$cancelled = $d['status'] === 'annulee';
$rest = (int)$d['net'] - (int)$d['paid'];
$title = $isQuote ? 'DEVIS' : 'FACTURE';
$rate = fn($r) => rtrim(rtrim(number_format((float)$r, 1, ',', ''), '0'), ',') . ' %';
$ht = fn(int $ttc, $r) => (int)round($ttc / (1 + (float)$r / 100));
$bank = array_filter(['Banque' => setting('bank_name', ''), 'RIB' => setting('bank_rib', ''), 'IBAN' => setting('bank_iban', ''), 'BIC' => setting('bank_bic', '')]);
$notice = trim((string)setting('warranty_notice', ''));
$stamp = (string)setting('doc_stamp', '');
if ($stamp !== '' && !is_file(APP_ROOT . '/' . $stamp)) $stamp = '';
$warrantyText = function (array $it) use ($d): string {
    $m = (int)$it['warranty_months'];
    if ($m <= 0) return '';
    return 'Garantie ' . ($m % 12 === 0 ? ($m / 12) . ' an' . ($m > 12 ? 's' : '') : $m . ' mois');
};
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isQuote ? 'Devis' : 'Facture' ?> <?= e($d['number']) ?> — <?= e($d['client_name']) ?></title>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/print.css?v=<?= filemtime(APP_ROOT . '/assets/css/print.css') ?>">
</head>
<body class="a4 doc<?= $embed ? ' embed' : '' ?>" style="--doc: <?= e($color) ?>">
<?php if (!$embed): ?>
<div class="toolbar no-print">
  <button onclick="window.print()">Imprimer / Enregistrer en PDF</button>
  <a href="<?= e(url(doc_route($d['type']), ['a' => 'view', 'id' => $d['id']])) ?>">Retour <?= $isQuote ? 'au devis' : 'à la facture' ?></a>
</div>
<?php endif; ?>
<main class="paper">
  <header class="d-head">
    <div class="d-logo">
      <span class="d-mark"><img src="assets/img/logo.svg" alt=""></span>
      <span class="d-word"><b><?= e(mb_strtoupper($company)) ?></b><small>Sports equipment</small></span>
    </div>
    <div class="d-title">
      <h1><?= $title ?></h1>
      <table class="d-meta">
        <tr><td><?= $isQuote ? 'Numéro de devis' : 'Numéro de facture' ?></td><td><b><?= e($d['number']) ?></b></td></tr>
        <tr><td>Date</td><td><?= fdate($d['date']) ?></td></tr>
        <?php if ($d['due_date']): ?><tr><td><?= $isQuote ? 'Valable jusqu’au' : 'Échéance' ?></td><td><?= fdate($d['due_date']) ?></td></tr><?php endif; ?>
      </table>
    </div>
  </header>

  <?php if ($cancelled): ?><p class="stamp">FACTURE ANNULÉE LE <?= fdate($d['cancelled_at']) ?></p><?php endif; ?>

  <section class="d-parties">
    <div>
      <h3><?= $isQuote ? 'Devis établi par' : 'Facturé par' ?></h3>
      <p><b><?= e(mb_strtoupper($legal)) ?></b><br>
        <?php if ($address): ?><?= nl2br(e($address)) ?><br><?php endif; ?>
        <?php if ($loc && setting('company_address', '')): ?>Showroom : <?= e($loc['name']) ?><br><?php endif; ?>
        Tél. <?= e(($loc['phone'] ?? '') ?: setting('company_phone', '')) ?><?= setting('company_email') ? ' · ' . e(setting('company_email')) : '' ?><br>
        <?php if (setting('company_mf')): ?>MF : <b><?= e(setting('company_mf')) ?></b><?php endif; ?></p>
    </div>
    <div class="to">
      <h3><?= $isQuote ? 'Devis établi pour' : 'Facturé à' ?></h3>
      <p><b><?= e(mb_strtoupper($d['client_name'])) ?></b><br>
        <?php if ($d['client_address']): ?><?= e($d['client_address']) ?><br><?php endif; ?>
        <?php if ($d['client_phone']): ?>Tél. <?= e($d['client_phone']) ?><br><?php endif; ?>
        <?php if ($d['client_email']): ?><?= e($d['client_email']) ?><br><?php endif; ?>
        <?php if ($d['client_mf']): ?>MF : <b><?= e($d['client_mf']) ?></b><?php endif; ?></p>
    </div>
  </section>

  <table class="d-items">
    <thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">P.U. HT</th><?php $anyDisc = array_sum(array_column($items, 'discount_pct')) > 0; if ($anyDisc): ?><th class="num">Remise</th><?php endif; ?><th class="num">TVA</th><th class="num">Total HT</th><th class="num">Total TTC</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><b><?= e($it['label']) ?></b><?php if ($it['description'] || $it['sku'] || $warrantyText($it)): ?><br><small><?= e(implode(' · ', array_filter([$it['description'], $it['sku'] ? 'Réf. ' . $it['sku'] : '', $warrantyText($it)]))) ?></small><?php endif; ?></td>
        <td class="num"><?= (int)$it['qty'] ?></td>
        <td class="num"><?= money($ht((int)$it['unit_price'], $it['vat_rate']), false) ?></td>
        <?php if ($anyDisc): ?><td class="num"><?= (float)$it['discount_pct'] ? $rate($it['discount_pct']) : '' ?></td><?php endif; ?>
        <td class="num"><?= $rate($it['vat_rate']) ?></td>
        <td class="num"><?= money($ht((int)$it['total'], $it['vat_rate']), false) ?></td>
        <td class="num"><?= money($it['total'], false) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($i = count($items); $i < 4; $i++): ?><tr class="filler"><td colspan="<?= $anyDisc ? 7 : 6 ?>">&nbsp;</td></tr><?php endfor; ?>
    </tbody>
  </table>

  <div class="d-bottom">
    <div class="d-left">
      <?php if ($d['transport']): ?><p><span class="lbl">Transport</span><b><?= e($d['transport']) ?></b></p><?php endif; ?>
      <?php if ($d['terms']): ?><p><span class="lbl">Conditions de paiement</span><b><?= e($d['terms']) ?></b></p><?php endif; ?>
      <?php if ($d['warranty']): ?><div class="d-box"><span class="lbl">Garantie</span><b><?= e($d['warranty']) ?></b></div><?php endif; ?>
      <?php if (count($vat) > 1 || (int)$d['discount'] > 0): ?>
        <table class="d-vat">
          <thead><tr><th>TVA</th><th class="num">Base HT</th><th class="num">Montant TVA</th></tr></thead>
          <?php foreach ($vat as $v): ?><tr><td><?= $rate($v['rate']) ?></td><td class="num"><?= money($v['ht'], false) ?></td><td class="num"><?= money($v['vat'], false) ?></td></tr><?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
    <table class="d-tot">
      <?php if ((int)$d['discount'] > 0): ?>
        <tr><td>Sous-total TTC</td><td class="num"><?= money($d['subtotal']) ?></td></tr>
        <tr><td>Remise</td><td class="num">− <?= money($d['discount']) ?></td></tr>
      <?php endif; ?>
      <tr><td>Total hors taxes</td><td class="num"><?= money($d['total_ht']) ?></td></tr>
      <?php foreach ($vat as $v): if ($v['rate'] <= 0) continue; ?><tr><td>TVA <?= $rate($v['rate']) ?></td><td class="num"><?= money($v['vat']) ?></td></tr><?php endforeach; ?>
      <?php if ((int)$d['timbre'] > 0): ?><tr><td>Timbre fiscal</td><td class="num"><?= money($d['timbre']) ?></td></tr><?php endif; ?>
      <tr class="big"><td>TOTAL TTC</td><td class="num"><?= money($d['net']) ?></td></tr>
      <?php if (!$isQuote && (int)$d['paid'] > 0 && !$cancelled): ?>
        <tr><td>Déjà réglé</td><td class="num"><?= money($d['paid']) ?></td></tr>
        <tr class="rest"><td>Reste à payer</td><td class="num"><?= money(max(0, $rest)) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <p class="d-words"><?= $isQuote ? 'Arrêté le présent devis' : 'Arrêtée la présente facture' ?> à la somme de : <b><?= e(amount_words($d['net'])) ?></b></p>
  <?php if ($d['notes']): ?><p class="d-notes"><?= nl2br(e($d['notes'])) ?></p><?php endif; ?>

  <div class="d-sign">
    <div class="d-bank">
      <?php if ($bank): ?>
        <h3>Coordonnées bancaires</h3>
        <p><span>Titulaire</span><b><?= e(mb_strtoupper($legal)) ?></b></p>
        <?php foreach ($bank as $k => $v): ?><p><span><?= e($k) ?></span><b><?= e($v) ?></b></p><?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php if ($isQuote): ?><div class="d-stamp"><span>Bon pour accord<br><small>Date, nom et signature du client</small></span></div><?php endif; ?>
    <div class="d-stamp<?= $stamp ? ' has-img' : '' ?>"><span>Cachet et signature<br><small><?= e(mb_strtoupper($legal)) ?></small></span><?php if ($stamp): ?><img src="<?= e($stamp) ?>" alt=""><?php endif; ?></div>
  </div>

  <?php if ($notice && !$isQuote || $notice && $d['warranty']): ?>
    <section class="d-notice">
      <h4>Avertissement concernant la garantie</h4>
      <?php foreach (preg_split('/\R/u', $notice) as $line): if (trim($line) === '') continue; ?>
        <p<?= preg_match('/\p{Arabic}/u', $line) ? ' dir="rtl" lang="ar"' : '' ?>><?= e($line) ?></p>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
  <footer class="d-foot"><?= e($legal) ?><?= setting('company_mf') ? ' · MF ' . e(setting('company_mf')) : '' ?> · <?= e(setting('company_phone')) ?> · <?= e(setting('company_site')) ?></footer>
</main>
<?php if (get('print')): ?><script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script><?php endif; ?>
</body>
</html>
