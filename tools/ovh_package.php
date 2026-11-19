<?php
// Prépare l'ERP pour un hébergement PHP (OVH…) : lancé par ovh.bat, jamais depuis le navigateur.
//   D:\Projects\fitnesstn-erp-ovh\installation\erp\  : première installation (code + base de données actuelle)
//   D:\Projects\fitnesstn-erp-ovh\mise-a-jour\erp\   : mises à jour suivantes (code seulement : la base en ligne n'est pas écrasée)
// + un .zip de chaque dossier et LISEZ-MOI.txt.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;

$src = dirname(__DIR__);
$out = 'D:/Projects/fitnesstn-erp-ovh';
$skipTop = ['tests', 'docs', 'node_modules', '.git', '.vscode', 'data'];
$skipFiles = ['router.php', 'start.bat', 'ovh.bat', 'build.ps1', 'README.md', 'IDENTIFIANTS.txt', '.gitignore', 'tools/ovh_package.php', 'tools/LISEZ-MOI-OVH.txt'];

function rrmdir(string $d): void
{
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

function copy_code(string $src, string $dst, array $skipTop, array $skipFiles): int
{
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
        $top = explode('/', $rel)[0];
        if (in_array($top, $skipTop, true) || in_array($rel, $skipFiles, true)) continue;
        if (str_starts_with($rel, 'assets/img/uploads/') && !in_array(basename($rel), ['.htaccess', '.gitkeep'], true)) continue;
        $target = $dst . '/' . $rel;
        if ($f->isDir()) { if (!is_dir($target)) mkdir($target, 0775, true); continue; }
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
        copy($f->getPathname(), $target);
        $n++;
    }
    return $n;
}

function zip_dir(string $dir, string $zipFile, string $prefix): void
{
    @unlink($zipFile);
    $z = new ZipArchive();
    if ($z->open($zipFile, ZipArchive::CREATE) !== true) throw new RuntimeException("Zip impossible : $zipFile");
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = $prefix . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        $f->isDir() ? $z->addEmptyDir($rel) : $z->addFile($f->getPathname(), $rel);
    }
    $z->close();
}

rrmdir($out);
mkdir($out, 0775, true);
foreach (['installation' => true, 'mise-a-jour' => false] as $name => $withData) {
    $dst = "$out/$name/erp";
    mkdir($dst, 0775, true);
    $n = copy_code($src, $dst, $skipTop, $skipFiles);
    if ($withData) {
        // Dossier data/ protégé, sessions dans data/sessions (à l'abri du nettoyage de /tmp des hébergeurs)
        mkdir("$dst/data/sessions", 0775, true);
        copy("$src/data/.htaccess", "$dst/data/.htaccess");
        file_put_contents("$dst/data/sessions/.keep", '');
        // Copie cohérente de la base actuelle (ventes, livraisons, réparations, clients importés…)
        $db = new PDO('sqlite:' . $src . '/data/erp.sqlite');
        $db->exec('VACUUM INTO ' . $db->quote("$dst/data/erp.sqlite"));
        $n += 3;
    }
    zip_dir("$out/$name/erp", "$out/fitnesstn-erp-$name.zip", 'erp');
    echo str_pad($name, 12) . " : $n fichiers" . ($withData ? ' (avec la base de données)' : ' (sans base de données)') . "\n";
}
copy("$src/tools/LISEZ-MOI-OVH.txt", "$out/LISEZ-MOI.txt");
echo "Prêt : $out\n";
