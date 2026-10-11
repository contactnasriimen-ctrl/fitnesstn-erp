<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
const APP_VERSION = '1.0.0';

date_default_timezone_set('Africa/Tunis');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', getenv('FTN_DEBUG') ? '1' : '0');

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/sales.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/charts.php';

if (PHP_SAPI !== 'cli' && !defined('FTN_NO_SESSION')) {
    // Sessions dans data/sessions si ce dossier existe (hébergements qui vident /tmp, démo navigateur).
    if (is_dir(DATA_DIR . '/sessions')) session_save_path(DATA_DIR . '/sessions');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('FTNERP');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
