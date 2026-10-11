<?php
// Routeur du serveur PHP intégré (start.bat) : seuls les fichiers de assets/ sont servis tels quels.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_starts_with($path, '/assets/') && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
    return false;
}
if ($path === '/check.php') {
    require __DIR__ . '/check.php';
    return true;
}
if ($path !== '/' && $path !== '/index.php') {
    http_response_code(404);
    exit('Introuvable');
}
require __DIR__ . '/index.php';
