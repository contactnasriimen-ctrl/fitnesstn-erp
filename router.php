<?php
// Routeur du serveur PHP intégré (start.bat) : seuls les fichiers de assets/ sont servis tels quels,
// avec cache navigateur (les adresses ?v=… changent à chaque mise à jour) et compression.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_starts_with($path, '/assets/')) {
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
    $file = __DIR__ . $path;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    // Jamais de script ni de type inconnu servi depuis assets/ (dossier des fichiers envoyés compris)
    if (str_contains($path, '..') || !isset($types[$ext]) || !is_file($file)) {
        http_response_code(404);
        exit('Introuvable');
    }
    $mtime = (int)filemtime($file);
    $etag = '"' . dechex($mtime) . '-' . dechex((int)filesize($file)) . '"';
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=86400'));
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Content-Type-Options: nosniff');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return true;
    }
    $data = (string)file_get_contents($file);
    if (in_array($ext, ['css', 'js', 'svg'], true) && function_exists('gzencode') && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
        $data = gzencode($data, 6);
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
    }
    header('Content-Length: ' . strlen($data));
    echo $data;
    return true;
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
