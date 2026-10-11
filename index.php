<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$route = preg_replace('/[^a-z_]/', '', (string)($_GET['r'] ?? ''));
if ($route === '') $route = user() ? 'dashboard' : 'login';
$file = __DIR__ . '/app/pages/' . $route . '.php';

if (!is_file($file)) {
    require_login();
    http_response_code(404);
    layout_start('Page introuvable', '');
    echo '<div class="empty"><h2>Page introuvable</h2><p><a class="btn" href="' . e(url('dashboard')) . '">Retour au tableau de bord</a></p></div>';
    layout_end();
    exit;
}
if ($route !== 'login') require_login();

try {
    require $file;
} catch (Throwable $e) {
    error_log('[ERP] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (db()->inTransaction()) db()->rollBack();
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) json_out(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()], 500);
    if (!headers_sent()) http_response_code(500);
    echo '<div style="font:15px system-ui;padding:24px;max-width:720px;margin:40px auto;border:1px solid #f3c2c2;background:#fff5f5;border-radius:12px">'
        . '<b>Une erreur est survenue.</b><br>' . e($e->getMessage())
        . (getenv('FTN_DEBUG') ? '<pre>' . e($e->getTraceAsString()) . '</pre>' : '')
        . '<p><a href="' . e(url('dashboard')) . '">Retour au tableau de bord</a></p></div>';
}
