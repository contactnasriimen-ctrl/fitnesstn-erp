<?php
// Diagnostic d'hébergement : ouvrez /check.php après l'envoi des fichiers. Ne révèle aucune donnée de l'ERP.
declare(strict_types=1);

$root = __DIR__;
$checks = [];
$checks[] = ['PHP 8.0 ou plus récent', version_compare(PHP_VERSION, '8.0.0', '>='), 'Version ' . PHP_VERSION . ' — choisissez PHP 8.x dans le panneau de l’hébergeur.'];
$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
$checks[] = ['Base SQLite (pdo_sqlite)', in_array('sqlite', $drivers, true), 'Pilotes disponibles : ' . (implode(', ', $drivers) ?: 'aucun')];
$checks[] = ['Extension mbstring', extension_loaded('mbstring'), 'Nécessaire pour les accents.'];
$checks[] = ['Envoi de fichiers (CSV, photos)', (bool)ini_get('file_uploads'), 'Taille maximale : ' . ini_get('upload_max_filesize')];
$checks[] = ['Dossier data/ inscriptible', is_writable($root . '/data'), 'Donnez les droits d’écriture (755 ou 775) au dossier data/.'];
$checks[] = ['Dossier des photos inscriptible', is_writable($root . '/assets/img/uploads'), 'Droits d’écriture sur assets/img/uploads/.'];
$sqliteOk = false;
$sqliteMsg = '';
if (in_array('sqlite', $drivers, true) && is_writable($root . '/data')) {
    $f = $root . '/data/.check-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT); INSERT INTO t (v) VALUES (\'ok\')');
        $pdo->exec('INSERT INTO t (v) VALUES (\'x\') ON CONFLICT DO NOTHING');
        $sqliteOk = $pdo->query('SELECT v FROM t WHERE id = 1')->fetchColumn() === 'ok';
        $sqliteMsg = 'SQLite ' . $pdo->query('SELECT sqlite_version()')->fetchColumn();
        $pdo = null;
    } catch (Throwable $e) {
        $sqliteMsg = $e->getMessage();
    }
    @unlink($f);
}
$checks[] = ['Écriture dans la base', $sqliteOk, $sqliteMsg ?: 'Test impossible tant que les points précédents ne sont pas verts.'];
$allOk = !in_array(false, array_column($checks, 1), true);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnostic · ERP FitnessTN</title>
<style>
  body { margin: 0; font: 15px/1.5 "Segoe UI", system-ui, sans-serif; background: #f3f5f9; color: #0f172a; }
  main { max-width: 680px; margin: 32px auto; padding: 0 16px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .card { background: #fff; border: 1px solid #e3e8f0; border-radius: 12px; padding: 6px 18px; margin: 16px 0; }
  .row { display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid #eef1f6; }
  .row:last-child { border: 0; }
  .ic { flex: none; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 700; }
  .ok .ic { background: #15803d; } .ko .ic { background: #c0262d; } .wait .ic { background: #94a3b8; }
  b { display: block; } small { color: #66738a; }
  .verdict { padding: 14px 18px; border-radius: 12px; font-weight: 600; }
  .verdict.ok { background: #e7f6ec; color: #0f5a2c; } .verdict.ko { background: #fdecec; color: #8e1b20; }
  a.btn { display: inline-block; margin-top: 10px; background: #1f7bff; color: #fff; padding: 10px 16px; border-radius: 9px; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>
<main>
  <h1>Diagnostic de l’hébergement</h1>
  <p style="color:#66738a">ERP FitnessTN — cette page vérifie que le serveur peut faire tourner l’ERP en sécurité.</p>
  <div class="card">
    <?php foreach ($checks as [$label, $ok, $help]): ?>
      <div class="row <?= $ok ? 'ok' : 'ko' ?>"><span class="ic"><?= $ok ? '✓' : '✗' ?></span><span><b><?= htmlspecialchars($label) ?></b><small><?= htmlspecialchars($help) ?></small></span></div>
    <?php endforeach; ?>
    <div class="row wait" id="sec"><span class="ic">…</span><span><b>Fichiers privés protégés</b><small>Vérification en cours…</small></span></div>
  </div>
  <div id="verdict" class="verdict <?= $allOk ? 'ok' : 'ko' ?>"><?= $allOk ? 'Serveur compatible.' : 'Serveur pas encore prêt : corrigez les points en rouge.' ?></div>
  <a class="btn" href="index.php">Ouvrir l’ERP</a>
</main>
<script>
  // Les fichiers de data/ (base de données, mots de passe initiaux) ne doivent JAMAIS être téléchargeables.
  (async () => {
    const urls = ['data/.htaccess', 'data/produits-site-2026-10-11.csv', 'data/erp.sqlite', 'app/db.php'];
    let leaks = [];
    for (const u of urls) {
      try { const r = await fetch(u, { cache: 'no-store' }); if (r.ok) leaks.push(u); } catch (e) { /* bloqué = bien */ }
    }
    const row = document.getElementById('sec');
    const ok = leaks.length === 0;
    row.className = 'row ' + (ok ? 'ok' : 'ko');
    row.querySelector('.ic').textContent = ok ? '✓' : '✗';
    row.querySelector('small').textContent = ok
      ? 'La base de données et les fichiers internes ne sont pas téléchargeables.'
      : 'DANGER : ces fichiers sont lisibles par tout le monde : ' + leaks.join(', ') + '. Ce serveur n’applique pas les fichiers .htaccess : ne l’utilisez pas tel quel.';
    if (!ok) { const v = document.getElementById('verdict'); v.className = 'verdict ko'; v.textContent = 'Serveur NON sécurisé pour l’ERP.'; }
  })();
</script>
</body>
</html>
