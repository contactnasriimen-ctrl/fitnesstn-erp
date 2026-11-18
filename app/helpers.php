<?php
declare(strict_types=1);

/* ---------- Sortie HTML ---------- */

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Montant en millimes -> « 1 999,000 DT ». */
function money($m, bool $unit = true, int $dec = 3): string
{
    $s = number_format(((int)($m ?? 0)) / 1000, $dec, ',', "\u{202F}");
    return $unit ? $s . "\u{00A0}DT" : $s;
}

/** Montant arrondi au dinar pour les tuiles du tableau de bord. */
function money0($m): string
{
    return money($m, true, 0);
}

/** Montant pour un champ de saisie : 1999 / 49,5 / 13,26. */
function m_in($m): string
{
    if ($m === null || $m === '') return '';
    $s = number_format(((int)$m) / 1000, 3, ',', '');
    $s = rtrim(rtrim($s, '0'), ',');
    return $s === '-0' ? '0' : $s;
}

/** « 1 999,000 » / « 1999.5 » / « 49,000 DT » -> millimes (int) ; null si vide ou invalide. */
function parse_money($v): ?int
{
    if ($v === null) return null;
    if (is_int($v)) return $v * 1000;
    if (is_float($v)) return (int)round($v * 1000);
    $s = trim((string)$v);
    $s = str_replace(["\u{00A0}", "\u{202F}", ' ', 'DT', 'dt', 'TND', 'Dt'], '', $s);
    if ($s === '') return null;
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = str_replace('.', '', $s);
    }
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return (int)round(((float)$s) * 1000);
}

function parse_int($v, ?int $default = null): ?int
{
    $s = trim(str_replace([' ', "\u{00A0}"], '', (string)($v ?? '')));
    if ($s === '' || !preg_match('/^-?\d+([.,]0+)?$/', $s)) return $default;
    return (int)$s;
}

function fdate(?string $d, bool $time = false): string
{
    if (!$d) return '';
    $t = strtotime($d);
    if ($t === false) return e($d);
    return date($time ? 'd/m/Y H:i' : 'd/m/Y', $t);
}

/** Date saisie en jj/mm/aaaa ou aaaa-mm-jj -> aaaa-mm-jj (ou null). */
function parse_date($v): ?string
{
    $s = trim((string)($v ?? ''));
    if ($s === '') return null;
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $s, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $s, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : null;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

/* ---------- Requête ---------- */

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $k, $default = '')
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $k, $default = '')
{
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function url(string $r, array $p = []): string
{
    $q = array_filter(array_merge(['r' => $r], $p), fn($v) => $v !== null && $v !== '');
    return 'index.php?' . http_build_query($q);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function back(string $fallback = 'index.php'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref && $host && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
        redirect($ref);
    }
    redirect($fallback);
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_input(): array
{
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

/* ---------- Messages flash ---------- */

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json') || isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            json_out(['ok' => false, 'error' => 'Session expirée : rechargez la page.'], 419);
        }
        exit('Session expirée : revenez en arrière et rechargez la page.');
    }
}

/* ---------- Divers ---------- */

function slug_key(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', '’' => '', "'" => '']);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $i = '';
    foreach ($parts as $p) {
        if (!preg_match('/\pL/u', $p, $m)) continue;
        $i .= mb_strtoupper($m[0]);
        if (mb_strlen($i) >= 2) break;
    }
    return $i ?: '?';
}

function opt(array $options, $selected, bool $blank = false, string $blankLabel = '—'): string
{
    $h = $blank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($options as $v => $label) {
        $sel = ((string)$v === (string)$selected) ? ' selected' : '';
        $h .= '<option value="' . e($v) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $h;
}

function pct(float $v, int $dec = 0): string
{
    return number_format($v, $dec, ',', ' ') . "\u{00A0}%";
}

const GOUVERNORATS = ['Ariana', 'Béja', 'Ben Arous', 'Bizerte', 'Gabès', 'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili',
    'Le Kef', 'Mahdia', 'La Manouba', 'Médenine', 'Monastir', 'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
    'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan'];

const PAY_METHODS = [
    'especes' => 'Espèces',
    'carte' => 'Carte bancaire (TPE)',
    'cheque' => 'Chèque',
    'virement' => 'Virement',
    'mobile' => 'Paiement mobile (D17, Flouci…)',
    'cod' => 'Contre-remboursement (transporteur)',
];

const SOURCES = [
    'showroom' => 'Passage au showroom',
    'facebook' => 'Facebook',
    'instagram' => 'Instagram',
    'tiktok' => 'TikTok',
    'google' => 'Google / Maps',
    'site' => 'Site web',
    'whatsapp' => 'WhatsApp',
    'recommandation' => 'Recommandation',
    'ancien' => 'Ancien client',
    'autre' => 'Autre',
];

const CHANNELS = [
    'facebook' => 'Facebook Ads', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'google' => 'Google Ads',
    'whatsapp' => 'WhatsApp', 'influence' => 'Influenceur·se', 'affichage' => 'Affichage / flyers',
    'radio' => 'Radio / TV', 'evenement' => 'Événement / salon', 'autre' => 'Autre',
];

const CAMPAIGN_STATUS = ['planifiee' => 'Planifiée', 'active' => 'Active', 'pause' => 'En pause', 'terminee' => 'Terminée'];

const EXPENSE_CATEGORIES = [
    'achats' => 'Achats marchandises / fournisseurs', 'loyer' => 'Loyer', 'salaires' => 'Salaires & primes',
    'energie' => 'Électricité, eau, internet', 'transport' => 'Transport & carburant', 'publicite' => 'Publicité',
    'entretien' => 'Entretien & réparations', 'fournitures' => 'Fournitures & petit matériel',
    'impots' => 'Impôts, taxes & douane', 'banque' => 'Frais bancaires', 'divers' => 'Divers',
];

const SHIP_STATUS = [
    'a_preparer' => 'À préparer', 'en_cours' => 'En cours de livraison', 'livree' => 'Livrée',
    'retour' => 'Retour / échec', 'annulee' => 'Annulée',
];

/** Où se trouve la marchandise d'une livraison. */
const SHIP_HOLDERS = [
    'depart' => 'Au départ (showroom / dépôt)', 'transporteur' => 'Chez le transporteur', 'technicien' => 'Chez le technicien',
    'client' => 'Chez le client', 'retour' => 'En retour vers le dépôt',
];

const REPAIR_STATUS = ['nouveau' => 'Nouvelle demande', 'en_cours' => 'En cours', 'repare' => 'Réparé', 'annule' => 'Annulé'];
const REPAIR_KINDS = ['reparation' => 'Réparation payante', 'sav' => 'SAV (sous garantie)'];
const REPAIR_PLACES = ['domicile' => 'Chez le client', 'atelier' => 'À l’atelier'];

/**
 * Devine le gouvernorat d'une adresse tunisienne (« GAFSA HAY ESSOUROUR » -> Gafsa, « Boumhel » -> Ben Arous).
 * Les villes de province passent avant les quartiers du Grand Tunis. Retourne '' si rien n'est reconnu.
 */
function guess_gouvernorat(string $text): string
{
    $s = '_' . slug_key($text) . '_';
    if ($s === '__') return '';
    static $map = [
        'Médenine' => ['medenine', 'mednine', 'djerba', 'jerba', 'midoun', 'houmt_souk', 'ajim', 'zarzis', 'zerzis', 'ben_guerdane', 'ben_guerden', 'ben_geuerden',
            'beni_khedache'],
        'Mahdia' => ['mahdia', 'mahdeya', 'salakta', 'ksour_essaf', 'ksour_essef', 'kssour_essif', 'ksour_essif', 'el_jem', 'eljem', 'chebba', 'souassi'],
        'Monastir' => ['monastir', 'ksar_hlel', 'moknine', 'mokenine', 'bembla', 'mestir', 'teboulba', 'ksar_hellal', 'sahline', 'jemmal', 'bekalta', 'bkalta', 'mnara',
            'zeramdine', 'zermedine', 'zermdine'],
        'Sousse' => ['sousse', 'sahloul', 'msaken', 'akouda', 'kalaa_kebira', 'enfidha', 'nfidha', 'nfitha', 'sidi_bou_ali', 'bouhsina', 'hergla', 'kantaoui'],
        'Sfax' => ['sfax', 'sakiet_ezzit', 'sakiet_eddaier', 'mahres', 'jebeniana', 'thyna'],
        'Gabès' => ['gabes', 'mareth', 'el_hamma', 'matmata'],
        'Gafsa' => ['gafsa', 'metlaoui', 'redeyef', 'moulares', 'el_ksar', 'mdhilla', 'sned'],
        'Tozeur' => ['tozeur', 'touzeur', 'nefta', 'degache', 'hamet_jrid', 'hammet_jrid', 'tamerza'],
        'Kébili' => ['kebili', 'gbeli', 'douz', 'souk_lahad'],
        'Tataouine' => ['tataouine', 'remada', 'ghomrassen'],
        'Sidi Bouzid' => ['sidi_bouzid', 'meknassy', 'meknesi', 'elmeknesi', 'regueb', 'jelma', 'mezzouna', 'bir_el_hafey'],
        'Kasserine' => ['kasserine', 'sbeitla', 'sbitla', 'feriana', 'thala', 'foussana'],
        'Kairouan' => ['kairouan', 'kairaouan', 'haffouz', 'sbikha', 'oueslatia', 'bouhajla'],
        'Siliana' => ['siliana', 'bouarada', 'makther', 'makthar', 'bou_arada', 'gaafour'],
        'Le Kef' => ['le_kef', 'el_kef', 'kef', 'tajerouine', 'dahmani', 'sers'],
        'Jendouba' => ['jendouba', 'jandouba', 'boussalem', 'bou_salem', 'tabarka', 'tbarka', 'ain_draham', 'ain_drahim', 'ghardimaou', 'ghar_dima', 'fernana'],
        'Béja' => ['beja', 'testour', 'medjez', 'nefza', 'teboursouk'],
        'Bizerte' => ['bizerte', 'benzart', 'menzel_jemil', 'manzel_jemil', 'menzel_bourguiba', 'zarzouna', 'ras_jbal', 'ras_jebel', 'mateur', 'el_alia',
            'sajnene', 'sejnane', 'ghar_el_melh', 'bni_nafa', 'utique', 'metline'],
        'Zaghouan' => ['zaghouan', 'zaghwen', 'el_fahs', 'fahs', 'zriba', 'bir_mcherga'],
        'Nabeul' => ['nabeul', 'hawaria', 'nebel', 'hammamet', 'hammamrt', 'korba', 'kelibia', 'menzel_temime', 'menzel_tmim', 'grombalia', 'grombelia', 'soliman',
            'beni_khiar', 'bni_khiar', 'dar_chaabane', 'bou_argoub', 'menzel_bouzelfa', 'takelsa', 'beni_khalled', 'beni_khaled', 'haouaria'],
        'Ben Arous' => ['ben_arous', 'morneg', 'borj_sedreyya', 'borj_sedria', 'benarous', 'boumhel', 'boumhal', 'bou_mhel', 'megrine', 'rades', 'hammam_lif', 'hammen_lif', 'hamem_lif', 'hamem_lit',
            'hammam_chott', 'hamem_chat', 'ezzahra', 'zahra', 'fouchana', 'fouchena', 'mghira', 'mourouj', 'yasminet', 'mohamedia', 'mhamdeyya', 'mhamdia',
            'mornag', 'naassen', 'borj_cedria', 'nouvelle_medina', 'madina_jadida', 'medina_jedida'],
        'La Manouba' => ['manouba', 'wed_ellil', 'tebourba', 'tbourba', 'taborba', 'denden', 'dandan', 'danden', 'oued_ellil', 'wed_lil', 'oued_lil', 'douar_hicher',
            'mornaguia', 'jedaida', 'jdidia', 'borj_amri'],
        'Ariana' => ['ariana', 'menzeh', 'mileha', 'milaha', 'kalaat_el_andaloss', 'soukra', 'sokra', 'chotrana', 'chtrana', 'ghazela', 'ghazala', 'ghazella', 'ghaella', 'lghazela', 'mnihla', 'raoued',
            'kalaat_el_andalous', 'borj_louzir', 'borl_louzir', 'borj_touil', 'riadh_andalous', 'riadh_al_andalos', 'riyadh_al_andalos', 'riadh_el_andalous',
            'ennasr', 'nasr', 'naser', 'enkhilet', 'nkhilet', 'sidi_amor', 'hay_nassim', 'ettadhamen', 'tadhamen', 'tathamen', 'jardin_el_manzah',
            'jardins_el_menzah', 'manzah', 'menzah', 'enna7ly', 'ennahli'],
        'Tunis' => ['tunis', 'wardeyya', 'khaznadar', 'marsa', 'gammarth', 'bardo', 'goulette', 'carthage', 'manar', 'aouina', 'la3ouina', 'kram', 'sidi_hassine', 'sidi_hsin', 'ouerdia',
            'agba', 'omrane', 'omran', 'khadra', 'lkhadh', 'ezzouhour', 'zouhour', 'ibn_khaldoun', 'ibno_khouldoun', 'sidi_daoud', 'sidi_dawed',
            'mutuelleville', 'lac', 'bab_saadoun', 'montfleury', 'cite_olympique'],
    ];
    foreach ($map as $gouv => $words) {
        foreach ($words as $w) {
            // Mots courts : mot entier seulement (« kef », « lac », « nasr »…)
            if (strlen($w) <= 4 ? str_contains($s, '_' . $w . '_') : str_contains($s, $w)) return $gouv;
        }
    }
    return '';
}

const PRIORITIES = ['basse' => 'Basse', 'normale' => 'Normale', 'haute' => 'Haute', 'urgente' => 'Urgente'];

const MOVE_TYPES = [
    'entree' => 'Entrée (réception)', 'sortie' => 'Sortie (casse, perte…)', 'vente' => 'Vente',
    'annulation' => 'Annulation de vente', 'transfert_out' => 'Transfert sortant', 'transfert_in' => 'Transfert entrant',
    'inventaire' => 'Ajustement d’inventaire', 'import' => 'Import CSV',
];
