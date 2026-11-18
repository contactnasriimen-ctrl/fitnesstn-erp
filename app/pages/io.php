<?php
declare(strict_types=1);

require_once APP_ROOT . '/app/datasets.php';
require_perm('io');
$a = (string)get('a');
$all = datasets();
$focus = (string)get('ds');

$filters = [
    'from' => parse_date(get('from')) ?? date('Y-m-01'),
    'to' => parse_date(get('to')) ?? today(),
    'loc' => (int)get('loc') ?: null,
    'board' => (int)get('board') ?: null,
];

if ($a === 'export') ds_export((string)get('ds'), $filters);
if ($a === 'template') ds_export((string)get('ds'), $filters, true);

$tmpDir = DATA_DIR . '/tmp';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0775, true);
foreach (array_merge(glob($tmpDir . '/import-*.csv') ?: [], glob($tmpDir . '/excel-*.xlsx') ?: []) as $old) if (filemtime($old) < time() - 3600) @unlink($old);
$canExcel = is_admin();

$editableBoards = array_values(array_filter(all('SELECT * FROM boards ORDER BY position, id'), 'board_can_edit'));

if (is_post()) {
    csrf_check();
    try {
        if ($a === 'excel_upload' || $a === 'excel_commit') {
            if (!$canExcel) throw new RuntimeException('Import réservé à l’administrateur.');
            require_once APP_ROOT . '/app/excel_import.php';
            if ($a === 'excel_upload') {
                $f = $_FILES['file'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Choisissez un fichier Excel (.xlsx).');
                if ($f['size'] > 15 * 1024 * 1024) throw new RuntimeException('Fichier trop volumineux (15 Mo maximum).');
                if (!preg_match('/\.xlsx$/i', $f['name']) || !xlsx_is_file($f['tmp_name'])) throw new RuntimeException('Le fichier doit être un classeur Excel .xlsx (dans Excel : Fichier › Enregistrer sous › Classeur Excel).');
                $token = bin2hex(random_bytes(8));
                move_uploaded_file($f['tmp_name'], "$tmpDir/excel-$token.xlsx");
                $report = xl_import("$tmpDir/excel-$token.xlsx", false);
                $_SESSION['excel'][$token] = ['name' => $f['name'], 'report' => $report];
                redirect(url('io', ['a' => 'excel_preview', 't' => $token]));
            }
            $token = preg_replace('/[^a-f0-9]/', '', (string)post('t'));
            $job = $_SESSION['excel'][$token] ?? null;
            $file = "$tmpDir/excel-$token.xlsx";
            if (!$job || !is_file($file)) throw new RuntimeException('Import expiré : envoyez à nouveau le fichier.');
            $r = xl_import($file, true, $job['report']['kind']);
            @unlink($file);
            unset($_SESSION['excel'][$token]);
            $created = implode(', ', array_map(fn($k, $n) => "$n $k", array_keys($r['created']), $r['created']));
            flash('ok', 'Fichier « ' . $job['name'] . ' » importé : ' . ($created ?: 'rien de nouveau') . ($r['updated'] ? ", {$r['updated']} mise(s) à jour" : '') . ($r['unchanged'] ? ", {$r['unchanged']} ligne(s) déjà présentes" : '') . '.');
            redirect(url(['ventes' => 'sales', 'paiements' => 'deliveries', 'reparations' => 'repairs'][$r['kind']], $r['kind'] === 'paiements' ? ['status' => 'toutes'] : []));
        }
        if ($a === 'upload') {
            $key = (string)post('ds');
            $d = $all[$key] ?? null;
            if (!$d || !ds_can($d, 'import')) throw new RuntimeException('Import non autorisé.');
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Choisissez un fichier CSV.');
            if ($f['size'] > 10 * 1024 * 1024) throw new RuntimeException('Fichier trop volumineux (10 Mo maximum).');
            if (!preg_match('/\.(csv|txt|xlsx)$/i', $f['name'])) throw new RuntimeException('Le fichier doit être au format CSV (.csv) ou Excel (.xlsx).');
            $token = bin2hex(random_bytes(8));
            move_uploaded_file($f['tmp_name'], "$tmpDir/import-$token.csv");
            $opts = [
                'stock_location' => (int)post('stock_location') ?: null,
                'mode' => post('mode') === 'add' ? 'add' : 'set',
                'location' => (int)post('location') ?: null,
                'board' => (int)post('board') ?: null,
            ];
            if ($key === 'taches' && (!$opts['board'] || !array_filter($editableBoards, fn($b) => (int)$b['id'] === $opts['board']))) throw new RuntimeException('Choisissez un tableau de tâches que vous pouvez modifier.');
            $report = ds_import($key, "$tmpDir/import-$token.csv", false, $opts);
            $_SESSION['import'][$token] = ['ds' => $key, 'opts' => $opts, 'name' => $f['name'], 'report' => $report];
            redirect(url('io', ['a' => 'preview', 't' => $token]));
        }
        if ($a === 'commit') {
            $token = preg_replace('/[^a-f0-9]/', '', (string)post('t'));
            $job = $_SESSION['import'][$token] ?? null;
            $file = "$tmpDir/import-$token.csv";
            if (!$job || !is_file($file)) throw new RuntimeException('Import expiré : envoyez à nouveau le fichier.');
            $report = ds_import($job['ds'], $file, true, $job['opts']);
            @unlink($file);
            unset($_SESSION['import'][$token]);
            flash(count($report['errors']) ? 'warn' : 'ok', sprintf('Import « %s » terminé : %d créé(s), %d mis à jour, %d ignoré(s), %d erreur(s).',
                $all[$job['ds']]['label'], $report['created'], $report['updated'], $report['skipped'], count($report['errors'])));
            redirect(url('io', ['ds' => $job['ds']]));
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        redirect(url('io', ['ds' => post('ds') ?: null]));
    }
}

/* ---------- Aperçu d'un fichier Excel FitnessTN ---------- */

if ($a === 'excel_preview') {
    $token = preg_replace('/[^a-f0-9]/', '', (string)get('t'));
    $job = $_SESSION['excel'][$token] ?? null;
    if (!$job || !$canExcel) redirect(url('io'));
    $r = $job['report'];
    $new = array_sum($r['created']);
    layout_start('Vérification de l’import Excel', 'io');
    ?>
    <section class="card">
      <h2><?= icon('upload', 'ic lg') ?> <?= e($r['label']) ?> — <?= e($job['name']) ?></h2>
      <p class="muted">Simulation terminée : <b>rien n’a encore été modifié</b>. Vérifiez puis confirmez.</p>
      <div class="grid4 kpis">
        <div class="kpi"><span>Lignes lues</span><b><?= (int)$r['rows'] ?></b><small><?= count($r['sheets']) ?> feuille(s)<?= $r['skipped'] ? ' · ' . (int)$r['skipped'] . ' ligne(s) de total ignorée(s)' : '' ?></small></div>
        <div class="kpi"><span>À créer</span><b class="pos"><?= $new ?></b><small><?= e(implode(' · ', array_map(fn($k, $n) => "$n $k", array_keys($r['created']), $r['created']))) ?: '—' ?></small></div>
        <div class="kpi"><span>Mises à jour</span><b><?= (int)$r['updated'] ?></b><small><?= (int)$r['unchanged'] ?> ligne(s) déjà importée(s), inchangée(s)</small></div>
        <div class="kpi"><span><?= $r['kind'] === 'reparations' ? 'Déjà encaissé (réparations)' : ($r['kind'] === 'ventes' ? 'Montant des ventes' : 'Montant transporté') ?></span><b><?= money0($r['amount']) ?></b></div>
      </div>
      <?php if ($r['sheets']): ?><p class="small muted"><b>Feuilles :</b> <?= e(implode(' · ', array_map(fn($k, $n) => trim($k) . " ($n)", array_keys($r['sheets']), $r['sheets']))) ?></p><?php endif; ?>
      <?php if ($r['kind'] === 'ventes'): ?>
        <p class="hint">Chaque ligne devient une vente du showroom de Tunis, numérotée <b>XL-26-…</b>, payée en contre-remboursement si un transporteur est cité (sinon en espèces), sans toucher au stock ni aux caisses. Les livraisons gardent le transporteur, le technicien et les frais. Importez ensuite le fichier « PAIEMENT » : les retours et les montants non reçus seront rapprochés de ces ventes.</p>
      <?php elseif ($r['kind'] === 'paiements'): ?>
        <p class="hint">Chaque ligne devient une livraison avec son transporteur, son n° de suivi et l’argent à récupérer. « Payé » = argent reçu ; « RETOUR » = retour ; vide = en attente. Une ligne qui correspond à une vente déjà importée (même client, même montant) complète cette vente au lieu d’en créer une autre.</p>
      <?php else: ?>
        <p class="hint">Chaque ligne devient une fiche « Réparations & SAV » (client, machine, panne, réparateur, statut, montant). Les clients sont retrouvés par téléphone ou créés.</p>
      <?php endif; ?>
      <?php if ($r['unmatched']): ?>
        <details><summary class="small"><b><?= count($r['unmatched']) ?> désignation(s)</b> non reliée(s) à un produit du catalogue (gardées telles quelles)</summary>
          <p class="small muted"><?= e(implode(' · ', array_map(fn($k, $n) => $k . ($n > 1 ? " ×$n" : ''), array_keys($r['unmatched']), $r['unmatched']))) ?></p></details>
      <?php endif; ?>
      <?php if ($r['warnings']): ?>
        <details><summary class="small"><b><?= count($r['warnings']) ?> remarque(s)</b></summary><ul class="errors"><?php foreach ($r['warnings'] as $w) echo '<li>' . e($w) . '</li>'; ?></ul></details>
      <?php endif; ?>
      <form method="post" action="<?= e(url('io', ['a' => 'excel_commit'])) ?>" class="row-actions">
        <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
        <a class="btn ghost" href="<?= e(url('io')) ?>">Annuler</a>
        <button class="btn primary" <?= ($new + (int)$r['updated']) ? '' : 'disabled' ?>><?= icon('check') ?>Confirmer l’import</button>
      </form>
    </section>
    <?php
    layout_end();
    exit;
}

/* ---------- Aperçu avant import ---------- */

if ($a === 'preview') {
    $token = preg_replace('/[^a-f0-9]/', '', (string)get('t'));
    $job = $_SESSION['import'][$token] ?? null;
    if (!$job) redirect(url('io'));
    $r = $job['report'];
    $d = $all[$job['ds']];
    layout_start('Vérification de l’import', 'io');
    ?>
    <section class="card">
      <h2><?= icon($d['icon'], 'ic lg') ?> <?= e($d['label']) ?> — <?= e($job['name']) ?></h2>
      <p class="muted">Simulation terminée : <b>rien n’a encore été modifié</b>. Vérifiez le résultat puis confirmez.</p>
      <div class="grid4 kpis">
        <div class="kpi"><span>Lignes lues</span><b><?= (int)$r['total'] ?></b></div>
        <div class="kpi"><span>À créer</span><b class="pos"><?= (int)$r['created'] ?></b></div>
        <div class="kpi"><span>À mettre à jour</span><b><?= (int)$r['updated'] ?></b></div>
        <div class="kpi"><span>Erreurs</span><b class="<?= $r['errors'] ? 'neg' : '' ?>"><?= count($r['errors']) ?></b><small><?= (int)$r['skipped'] ?> ligne(s) vide(s) ignorée(s)</small></div>
      </div>
      <p><b>Colonnes reconnues :</b> <?= e(implode(', ', $r['columns'])) ?></p>
      <?php if ($r['unknown']): ?><p class="muted"><b>Colonnes ignorées :</b> <?= e(implode(', ', $r['unknown'])) ?></p><?php endif; ?>
      <?php if ($r['errors']): ?>
        <div class="flash err"><?= icon('alert') ?><span>Les lignes en erreur ne seront pas importées :</span></div>
        <ul class="errors"><?php foreach ($r['errors'] as $er) echo '<li>' . e($er) . '</li>'; ?></ul>
      <?php endif; ?>
      <form method="post" action="<?= e(url('io', ['a' => 'commit'])) ?>" class="row-actions">
        <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
        <a class="btn ghost" href="<?= e(url('io', ['ds' => $job['ds']])) ?>">Annuler</a>
        <button class="btn primary" <?= ($r['created'] + $r['updated']) ? '' : 'disabled' ?>><?= icon('check') ?>Confirmer l’import (<?= (int)$r['created'] + (int)$r['updated'] ?> ligne(s))</button>
      </form>
    </section>
    <?php
    layout_end();
    exit;
}

/* ---------- Centre d'import / export ---------- */

$scope = scope_loc();
layout_start('Import / Export', 'io');
?>
<p class="lead">Les fichiers exportés s’ouvrent directement dans Excel. Pour importer, envoyez un fichier CSV ou Excel (.xlsx) : un aperçu est toujours affiché avant d’enregistrer.</p>
<?php if ($canExcel): ?>
  <section class="card io-card focus" id="excel">
    <h2><?= icon('upload') ?> Vos fichiers Excel FitnessTN</h2>
    <p class="muted small">Envoyez tels quels vos classeurs <b>« VENTE GRAND TUNIS »</b> (toutes les feuilles de mois), <b>« PAIEMENT »</b> (transporteurs) et <b>« RÉPARATION ET SERVICE APRÈS VENTE »</b> : le type est reconnu automatiquement.
      Vous pouvez renvoyer un fichier complété plus tard : les lignes déjà importées ne sont pas dupliquées, et les changements (payé, réparé…) sont mis à jour.</p>
    <form method="post" enctype="multipart/form-data" action="<?= e(url('io', ['a' => 'excel_upload'])) ?>" class="io-import">
      <?= csrf_field() ?>
      <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <div class="row-actions"><button class="btn sm primary"><?= icon('upload') ?>Vérifier le fichier</button></div>
    </form>
  </section>
<?php endif; ?>
<div class="io-grid">
<?php foreach ($all as $key => $d):
    $canExp = ds_can($d, 'export');
    $canImp = ds_can($d, 'import');
    if (!$canExp && !$canImp) continue; ?>
  <section class="card io-card<?= $focus === $key ? ' focus' : '' ?>" id="ds-<?= e($key) ?>">
    <h2><?= icon($d['icon']) ?> <?= e($d['label']) ?></h2>
    <p class="muted small"><?= e($d['help']) ?></p>
    <?php if ($canExp): ?>
      <form method="get" class="io-export">
        <input type="hidden" name="r" value="io"><input type="hidden" name="a" value="export"><input type="hidden" name="ds" value="<?= e($key) ?>">
        <?php if (!empty($d['dates'])): ?>
          <label>Du<input type="date" name="from" value="<?= e($filters['from']) ?>"></label>
          <label>Au<input type="date" name="to" value="<?= e($filters['to']) ?>"></label>
        <?php endif; ?>
        <?php if (!empty($d['loc']) && !$scope): ?><label>Site<select name="loc"><?= opt(location_options(false), $filters['loc'], true, 'Tous') ?></select></label><?php endif; ?>
        <?php if (!empty($d['board'])): ?><label>Tableau<select name="board"><?php foreach (all('SELECT * FROM boards ORDER BY position') as $b) if (board_can_view($b)) echo '<option value="' . (int)$b['id'] . '"' . ((int)$b['id'] === (int)$filters['board'] ? ' selected' : '') . '>' . e($b['name']) . '</option>'; ?></select></label><?php endif; ?>
        <button class="btn sm"><?= icon('download') ?>Exporter</button>
      </form>
    <?php endif; ?>
    <?php if ($canImp): ?>
      <form method="post" enctype="multipart/form-data" action="<?= e(url('io', ['a' => 'upload'])) ?>" class="io-import">
        <?= csrf_field() ?><input type="hidden" name="ds" value="<?= e($key) ?>">
        <input type="file" name="file" accept=".csv,.xlsx,text/csv" required>
        <?php if ($key === 'produits'): ?>
          <label>Colonne « Stock » de l’export du site vers<select name="stock_location"><?= opt(location_options(), '', true, 'Ne pas importer le stock') ?></select></label>
        <?php elseif ($key === 'stock'): ?>
          <label>Les quantités du fichier<select name="mode"><option value="set">remplacent le stock (inventaire)</option><option value="add">s’ajoutent au stock (réception)</option></select></label>
          <label>Emplacement par défaut<select name="location"><?= opt(location_options(), '', true, 'Celui indiqué dans le fichier') ?></select></label>
        <?php elseif ($key === 'taches'): ?>
          <label>Tableau<select name="board"><?php foreach ($editableBoards as $b) echo '<option value="' . (int)$b['id'] . '"' . ((int)$b['id'] === (int)$filters['board'] ? ' selected' : '') . '>' . e($b['name']) . '</option>'; ?></select></label>
        <?php endif; ?>
        <div class="row-actions">
          <a class="btn sm ghost" href="<?= e(url('io', ['a' => 'template', 'ds' => $key])) ?>"><?= icon('download') ?>Modèle</a>
          <button class="btn sm primary"><?= icon('upload') ?>Vérifier l’import</button>
        </div>
      </form>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
<?php
layout_end();
