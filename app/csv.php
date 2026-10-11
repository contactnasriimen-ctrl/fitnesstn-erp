<?php
declare(strict_types=1);

/**
 * Lit un fichier CSV (UTF-8 ou Windows-1252, séparateur ; , ou tabulation détecté automatiquement).
 * Retourne ['headers' => [clés normalisées], 'labels' => [en-têtes d'origine], 'rows' => [[clé => valeur]], 'delimiter' => ...].
 */
function csv_read_file(string $file): array
{
    $raw = (string)file_get_contents($file);
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $firstLine = strtok($raw, "\n") ?: '';
    $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
    arsort($counts);
    $delim = (string)array_key_first($counts);

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $labels = fgetcsv($fh, 0, $delim, '"', '') ?: [];
    $labels = array_map(fn($h) => trim((string)$h), $labels);
    $headers = array_map('slug_key', $labels);
    $rows = [];
    $line = 1;
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
        $row = [];
        foreach ($headers as $i => $h) {
            if ($h === '') continue;
            $row[$h] = isset($r[$i]) ? (string)$r[$i] : '';
        }
        $row['__line'] = $line;
        $rows[] = $row;
    }
    fclose($fh);
    return ['headers' => $headers, 'labels' => $labels, 'rows' => $rows, 'delimiter' => $delim];
}

/** Envoie un CSV compatible Excel (UTF-8 avec BOM, séparateur point-virgule). */
function csv_send(string $filename, array $labels, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $labels, ';', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($v) => is_null($v) ? '' : (string)$v, array_values($r)), ';', '"', '');
    }
    fclose($out);
    exit;
}

/** Montant pour un CSV : 1999,000 (virgule décimale, lisible par Excel en français). */
function csv_money($m): string
{
    return $m === null || $m === '' ? '' : number_format(((int)$m) / 1000, 3, ',', '');
}

function csv_bool($v): string
{
    return $v ? 'oui' : 'non';
}

function csv_yes($v): bool
{
    return in_array(mb_strtolower(trim((string)$v)), ['1', 'oui', 'o', 'yes', 'y', 'true', 'vrai', 'x'], true);
}
