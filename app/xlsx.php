<?php
declare(strict_types=1);

/*
 * Lecture des classeurs Excel (.xlsx) sans dépendance : un .xlsx est une archive zip de fichiers XML.
 * ZipArchive est utilisé s'il est disponible, sinon un petit lecteur zip intégré (zlib suffit).
 */

function xlsx_is_file(string $file): bool
{
    $h = @fopen($file, 'rb');
    if (!$h) return false;
    $sig = (string)fread($h, 4);
    fclose($h);
    return $sig === "PK\x03\x04";
}

/** Contenu des entrées d'une archive zip dont le nom vérifie $accept (callable ou null = tout). */
function zip_read_entries(string $file, ?callable $accept = null): array
{
    $out = [];
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($file) === true) {
            for ($i = 0; $i < $z->numFiles; $i++) {
                $name = (string)$z->getNameIndex($i);
                if ($accept && !$accept($name)) continue;
                $data = $z->getFromIndex($i);
                if ($data !== false) $out[$name] = $data;
            }
            $z->close();
            return $out;
        }
    }
    $raw = (string)file_get_contents($file);
    $eocd = strrpos($raw, "PK\x05\x06");
    if ($eocd === false) throw new RuntimeException('Fichier Excel illisible (archive zip incomplète).');
    $cd = unpack('ventries/Vsize/Voffset', substr($raw, $eocd + 10, 10));
    $pos = $cd['offset'];
    for ($i = 0; $i < $cd['entries']; $i++) {
        if (substr($raw, $pos, 4) !== "PK\x01\x02") break;
        $h = unpack('vmethod/x8/Vcsize/Vsize/vnlen/velen/vclen/x8/Vlocal', substr($raw, $pos + 10, 36));
        $name = substr($raw, $pos + 46, $h['nlen']);
        $pos += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        if ($accept && !$accept($name)) continue;
        $lh = unpack('vnlen/velen', substr($raw, $h['local'] + 26, 4));
        $data = substr($raw, $h['local'] + 30 + $lh['nlen'] + $lh['elen'], $h['csize']);
        if ($h['method'] === 8) $data = @gzinflate($data);
        elseif ($h['method'] !== 0) $data = false;
        if ($data !== false) $out[$name] = $data;
    }
    return $out;
}

/** « AB » -> 27 (colonnes numérotées à partir de 1). */
function xlsx_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $c) $n = $n * 26 + (ord($c) - 64);
    return $n;
}

/**
 * Lit toutes les feuilles. Retourne [['name' => 'Feuil1', 'rows' => [n° de ligne => [n° de colonne => texte]]], …].
 * Les valeurs restent du texte brut (les dates Excel sont des numéros de série, ex. 46078).
 */
function xlsx_read(string $file): array
{
    $files = zip_read_entries($file, fn(string $n) => str_starts_with($n, 'xl/') && str_ends_with($n, '.xml') || str_ends_with($n, '.rels'));
    if (!isset($files['xl/workbook.xml'])) throw new RuntimeException('Ce fichier n’est pas un classeur Excel (.xlsx).');
    $prev = libxml_use_internal_errors(true);
    $strings = [];
    if (isset($files['xl/sharedStrings.xml'])) {
        $sx = simplexml_load_string($files['xl/sharedStrings.xml']);
        if ($sx) foreach ($sx->si as $si) {
            if (isset($si->t)) { $strings[] = (string)$si->t; continue; }
            $t = '';
            foreach ($si->r as $r) $t .= (string)$r->t;
            $strings[] = $t;
        }
    }
    $targets = [];
    if (isset($files['xl/_rels/workbook.xml.rels'])) {
        $rels = simplexml_load_string($files['xl/_rels/workbook.xml.rels']);
        if ($rels) foreach ($rels->Relationship as $r) $targets[(string)$r['Id']] = (string)$r['Target'];
    }
    $wb = simplexml_load_string($files['xl/workbook.xml']);
    $sheets = [];
    $i = 0;
    foreach ($wb->sheets->sheet ?? [] as $sh) {
        $i++;
        $rid = (string)($sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        $target = $targets[$rid] ?? "worksheets/sheet$i.xml";
        $path = str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . ltrim($target, './');
        if (!isset($files[$path])) continue;
        $xml = simplexml_load_string($files[$path]);
        if (!$xml) continue;
        $rows = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $rn = (int)$row['r'];
            $cells = [];
            $auto = 0;
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = $ref !== '' && preg_match('/^([A-Z]+)/', $ref, $m) ? xlsx_col_index($m[1]) : ++$auto;
                $auto = $col;
                $type = (string)$c['t'];
                if ($type === 'inlineStr') {
                    $v = isset($c->is->t) ? (string)$c->is->t : '';
                    if ($v === '' && isset($c->is->r)) foreach ($c->is->r as $r) $v .= (string)$r->t;
                } else {
                    $v = isset($c->v) ? (string)$c->v : '';
                    if ($type === 's') $v = $strings[(int)$v] ?? '';
                    elseif ($type === 'b') $v = $v === '1' ? 'VRAI' : 'FAUX';
                }
                $v = trim(str_replace(["\u{00A0}", "\r"], [' ', ''], $v));
                if ($v !== '') $cells[$col] = $v;
            }
            if ($cells) $rows[$rn] = $cells;
        }
        $sheets[] = ['name' => trim((string)$sh['name']), 'rows' => $rows];
    }
    libxml_use_internal_errors($prev);
    return $sheets;
}

/** Numéro de série Excel (46078) ou date écrite -> aaaa-mm-jj, ou null. */
function xlsx_date($v): ?string
{
    $s = trim((string)$v);
    if ($s === '') return null;
    if (preg_match('/^\d{5}(\.\d+)?$/', $s)) {
        $d = gmdate('Y-m-d', (int)round(((float)$s - 25569) * 86400));
        return (int)substr($d, 0, 4) >= 2000 && (int)substr($d, 0, 4) <= 2100 ? $d : null;
    }
    return parse_date($s);
}

/** Première feuille d'un .xlsx au format de csv_read_file() (en-têtes = première ligne non vide). */
function xlsx_as_csv(string $file): array
{
    $sheets = xlsx_read($file);
    $rows = $sheets[0]['rows'] ?? [];
    if (!$rows) return ['headers' => [], 'labels' => [], 'rows' => [], 'delimiter' => 'xlsx'];
    $headerLine = (int)array_key_first($rows);
    $head = $rows[$headerLine];
    $max = max(array_keys($head));
    $labels = [];
    for ($c = 1; $c <= $max; $c++) $labels[] = trim((string)($head[$c] ?? ''));
    $headers = array_map('slug_key', $labels);
    $out = [];
    foreach ($rows as $n => $cells) {
        if ($n <= $headerLine) continue;
        $row = [];
        foreach ($headers as $i => $h) {
            if ($h === '') continue;
            $row[$h] = (string)($cells[$i + 1] ?? '');
        }
        $row['__line'] = $n;
        $out[] = $row;
    }
    return ['headers' => $headers, 'labels' => $labels, 'rows' => $out, 'delimiter' => 'xlsx'];
}
