<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
const APP_VERSION = '1.4.1';
const SESSION_IDLE_HOURS = 10; // déconnexion automatique après 10 h sans activité

date_default_timezone_set('Africa/Tunis');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', getenv('FTN_DEBUG') ? '1' : '0');

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/sales.php';
require __DIR__ . '/documents.php';
require __DIR__ . '/sav.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/charts.php';

if (PHP_SAPI !== 'cli' && !defined('FTN_NO_SESSION')) {
    // Sessions dans data/sessions si ce dossier existe (hébergements qui vident /tmp, démo navigateur).
    if (is_dir(DATA_DIR . '/sessions')) session_save_path(DATA_DIR . '/sessions');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');   // refuse un identifiant de session inventé
    ini_set('session.use_only_cookies', '1');
    session_name('FTNERP');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
    if (!empty($_SESSION['uid'])) {
        $seen = (int)($_SESSION['seen'] ?? time());
        if (time() - $seen > SESSION_IDLE_HOURS * 3600) {
            $_SESSION = [];
            session_regenerate_id(true);
        } elseif (time() - $seen > 60) {
            $_SESSION['seen'] = time();
        }
    }
    send_security_headers($https);
    // Pages compressées (≈ 5 fois plus légères). Pas dans la version navigateur : le service worker transmet le corps tel quel.
    if (!str_starts_with(DATA_DIR, '/www') && extension_loaded('zlib') && !ini_get('zlib.output_compression')
        && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')) {
        ob_start('ob_gzhandler');
    }
}

/** En-têtes de sécurité de toutes les pages. */
function send_security_headers(bool $https): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    // Seuls les fichiers de l'ERP s'exécutent ; les photos produits peuvent venir du site (https).
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; "
        . "font-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
    if ($https) header('Strict-Transport-Security: max-age=31536000');
}

/** Coupe la compression avant d'envoyer un fichier (sauvegarde…) dont la taille est annoncée. */
function output_raw(): void
{
    while (ob_get_level() > 0) ob_end_clean();
}
