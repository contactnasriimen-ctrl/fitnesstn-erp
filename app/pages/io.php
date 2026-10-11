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
foreach (glob($tmpDir . '/import-*.csv') ?: [] as $old) if (filemtime($old) < time() - 3600) @unlink($old);

$editableBoards = array_values(array_filter(all('SELECT * FROM boards ORDER BY position, id'), 'board_can_edit'));

if (is_post()) {
    csrf_check();
    try {
        if ($a === 'upload') {
            $key = (string)post('ds');
            $d = $all[$key] ?? null;
            if (!$d || !ds_can($d, 'import')) throw new RuntimeException('Import non autorisé.');
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Choisissez un fichier CSV.');
            if ($f['size'] > 10 * 1024 * 1024) throw new RuntimeException('Fichier trop volumineux (10 Mo maximum).');
            if (!preg_match('/\.(csv|txt)$/i', $f['name'])) throw new RuntimeException('Le fichier doit être au format CSV (.csv). Dans Excel : Fichier › Enregistrer sous › CSV UTF-8.');
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
layout_start('Import / Export CSV', 'io');
?>
<p class="lead">Les fichiers exportés s’ouvrent directement dans Excel (UTF-8, séparateur point-virgule). Pour importer, partez d’un export ou du modèle, modifiez-le puis renvoyez-le : un aperçu est toujours affiché avant d’enregistrer.</p>
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
        <input type="file" name="file" accept=".csv,text/csv" required>
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
