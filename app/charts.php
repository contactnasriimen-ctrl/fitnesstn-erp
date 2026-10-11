<?php
declare(strict_types=1);

/* Graphiques SVG rendus côté serveur (palette validée : bleu #2a78d6, orange #eb6834 sur fond blanc). */

const SERIES_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];

function nice_step(float $max, int $ticks = 4): float
{
    if ($max <= 0) return 1;
    $raw = $max / $ticks;
    $mag = 10 ** floor(log10($raw));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $mag >= $raw) return $m * $mag;
    return 10 * $mag;
}

function compact_dt(float $dinars): string
{
    if ($dinars >= 1000000) return rtrim(rtrim(number_format($dinars / 1000000, 1, ',', ''), '0'), ',') . ' M';
    if ($dinars >= 1000) return rtrim(rtrim(number_format($dinars / 1000, 1, ',', ''), '0'), ',') . ' k';
    return number_format($dinars, 0, ',', ' ');
}

/**
 * Colonnes empilées (une par jour). $labels : dates Y-m-d ; $series : [['name' => …, 'values' => [millimes…]], …].
 */
function chart_stacked_columns(array $labels, array $series, string $title): string
{
    $W = 760; $H = 230; $ml = 48; $mr = 8; $mt = 10; $mb = 26;
    $n = count($labels);
    if (!$n) return '';
    $totals = [];
    foreach ($labels as $i => $_) $totals[$i] = array_sum(array_map(fn($s) => (int)($s['values'][$i] ?? 0), $series));
    $maxDt = max($totals) / 1000;
    $step = nice_step($maxDt ?: 1);
    $top = $step * max(1, ceil(($maxDt ?: 1) / $step));
    $plotW = $W - $ml - $mr;
    $plotH = $H - $mt - $mb;
    $slot = $plotW / $n;
    $bw = min(24, max(4, $slot * 0.62));
    $y = fn(float $dt) => $mt + $plotH - ($dt / $top) * $plotH;

    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($title) . '">';
    for ($v = 0; $v <= $top + 0.0001; $v += $step) {
        $yy = round($y($v), 1);
        $svg .= '<line class="grid" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $svg .= '<text class="tick" x="' . ($ml - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . e(compact_dt($v)) . '</text>';
    }
    $every = max(1, (int)ceil($n / 8));
    foreach ($labels as $i => $d) {
        $cx = $ml + $slot * ($i + 0.5);
        $x0 = round($cx - $bw / 2, 1);
        $base = $mt + $plotH;
        $cursor = $base;
        $rows = [];
        $segs = [];
        foreach ($series as $k => $s) {
            $v = (int)($s['values'][$i] ?? 0);
            $rows[] = [SERIES_COLORS[$k % count(SERIES_COLORS)], $s['name'], money($v)];
            if ($v <= 0) continue;
            $h = ($v / 1000 / $top) * $plotH;
            $segs[] = [$k, $cursor - $h, $h];
            $cursor -= $h;
        }
        $g = '';
        $last = count($segs) - 1;
        foreach ($segs as $j => [$k, $sy, $sh]) {
            $gap = $j > 0 ? 2 : 0;
            $sh2 = max(0.5, $sh - $gap);
            $color = SERIES_COLORS[$k % count(SERIES_COLORS)];
            if ($j === $last) {
                $r = min(4, $sh2, $bw / 2);
                $x1 = $x0 + $bw;
                $yb = $sy + $sh2;
                $g .= '<path fill="' . $color . '" d="M' . $x0 . ' ' . round($yb, 1) . 'V' . round($sy + $r, 1) . 'Q' . $x0 . ' ' . round($sy, 1) . ' ' . round($x0 + $r, 1) . ' ' . round($sy, 1)
                    . 'H' . round($x1 - $r, 1) . 'Q' . round($x1, 1) . ' ' . round($sy, 1) . ' ' . round($x1, 1) . ' ' . round($sy + $r, 1) . 'V' . round($yb, 1) . 'Z"/>';
            } else {
                $g .= '<rect fill="' . $color . '" x="' . $x0 . '" y="' . round($sy, 1) . '" width="' . round($bw, 1) . '" height="' . round($sh2, 1) . '"/>';
            }
        }
        $rows[] = ['', 'Total', money($totals[$i])];
        $tip = e(json_encode(['t' => fdate($d) . ' · ' . strftime_fr($d), 'r' => $rows], JSON_UNESCAPED_UNICODE));
        $svg .= '<g class="col" tabindex="0" data-tip="' . $tip . '"><rect class="hit" x="' . round($ml + $slot * $i, 1) . '" y="' . $mt . '" width="' . round($slot, 1) . '" height="' . $plotH . '"/>' . $g . '</g>';
        if (($n - 1 - $i) % $every === 0) {
            $svg .= '<text class="tick" x="' . round($cx, 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . date('d/m', strtotime($d)) . '</text>';
        }
    }
    $svg .= '<line class="axis" x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . ($mt + $plotH) . '" y2="' . ($mt + $plotH) . '"/></svg>';

    $legend = '';
    if (count($series) > 1) {
        $legend = '<div class="legend">';
        foreach ($series as $k => $s) $legend .= '<span><i style="background:' . SERIES_COLORS[$k % count(SERIES_COLORS)] . '"></i>' . e($s['name']) . '</span>';
        $legend .= '</div>';
    }
    $table = '<details class="data-table"><summary>Voir les données</summary><table class="tbl"><thead><tr><th>Jour</th>';
    foreach ($series as $s) $table .= '<th class="num">' . e($s['name']) . '</th>';
    $table .= '<th class="num">Total</th></tr></thead><tbody>';
    foreach ($labels as $i => $d) {
        if (!$totals[$i]) continue;
        $table .= '<tr><td>' . fdate($d) . '</td>';
        foreach ($series as $s) $table .= '<td class="num">' . money($s['values'][$i] ?? 0) . '</td>';
        $table .= '<td class="num"><b>' . money($totals[$i]) . '</b></td></tr>';
    }
    $table .= '</tbody></table></details>';
    return $legend . '<div class="chart-wrap">' . $svg . '</div>' . $table;
}

function strftime_fr(string $d): string
{
    static $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    return $days[(int)date('w', strtotime($d))];
}

/** Liste de barres horizontales (une seule série, valeur en bout de barre). $rows : [[label, value(int), display(string), sub?]]. */
function bar_list(array $rows, string $empty = 'Pas encore de données.'): string
{
    if (!$rows) return '<p class="muted">' . e($empty) . '</p>';
    $max = max(1, max(array_map(fn($r) => (int)$r[1], $rows)));
    $h = '<div class="barlist">';
    foreach ($rows as $r) {
        $w = max(0.5, (int)$r[1] / $max * 100);
        $h .= '<div class="bl-row"><div class="bl-label">' . e($r[0]) . (!empty($r[3]) ? '<small>' . e($r[3]) . '</small>' : '') . '</div>'
            . '<div class="bl-track"><span style="width:' . round($w, 2) . '%"></span></div><div class="bl-val">' . e($r[2]) . '</div></div>';
    }
    return $h . '</div>';
}
