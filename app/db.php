<?php
declare(strict_types=1);

function db_path(): string
{
    return getenv('FTN_DB') ?: DATA_DIR . '/erp.sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = db_path();
    $fresh = !is_file($path) || filesize($path) === 0;
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    // WAL seulement en local : sur un hébergement mutualisé (disque réseau) le mode classique est plus sûr.
    $pdo->exec('PRAGMA journal_mode = ' . (PHP_SAPI === 'cli-server' || getenv('FTN_WAL') ? 'WAL' : 'DELETE'));
    if ($fresh || !table_exists($pdo, 'settings')) {
        db_install($pdo);
    }
    db_migrate($pdo);
    return $pdo;
}

const DB_VERSION = 3;

/** Met à jour le schéma d'une base existante (fichiers app/migrations/NNN_*.sql). */
function db_migrate(PDO $pdo): void
{
    $version = fn() => (int)($pdo->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetchColumn() ?: 1);
    if ($version() >= DB_VERSION) return;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $v = $version();
        foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $file) {
            $n = (int)basename($file);
            if ($n <= $v || $n > DB_VERSION) continue;
            $pdo->exec(file_get_contents($file));
        }
        $defaults = [
            'company_legal_name' => '', 'company_address' => '',
            'invoice_prefix' => '', 'quote_prefix' => 'DV-',
            'invoice_terms' => 'Paiement comptant à la livraison', 'invoice_transport' => 'Gratuit', 'invoice_warranty' => 'Selon le produit',
            'quote_validity_days' => '15', 'invoice_due_days' => '0', 'doc_color' => '#145c54', 'doc_stamp' => '', 'auto_backup' => '1',
            'bank_name' => '', 'bank_rib' => '', 'bank_iban' => '', 'bank_bic' => '',
            'warranty_notice' => "Toute utilisation hors du cadre domestique (usage professionnel, salle de sport, collectivité, kiné…) ou non conforme aux conditions normales d’utilisation entraîne l’annulation immédiate de la garantie.\n"
                . 'يلغى الضمان تلقائيا في حالة ثبوت استخدام الجهاز خارج النطاق المنزلي الخاص أو في ظروف لا تتوافق مع معايير الاستخدام المنصوص عليها.',
        ];
        $st = $pdo->prepare('INSERT INTO settings(key, value) VALUES (?, ?) ON CONFLICT(key) DO NOTHING');
        foreach ($defaults as $k => $d) $st->execute([$k, $d]);
        $pdo->prepare("INSERT INTO settings(key, value) VALUES ('schema_version', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([(string)DB_VERSION]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function backup_dir(): string
{
    return getenv('FTN_BACKUP_DIR') ?: DATA_DIR . '/backups';
}

/**
 * Copie de la base une fois par jour (au premier passage d'un utilisateur connecté), 14 jours conservés.
 * Pas dans la démo navigateur (fichiers sous /www), ni pour une base de test sans FTN_BACKUP_DIR.
 */
function db_auto_backup(): void
{
    if (str_starts_with(DATA_DIR, '/www') || (getenv('FTN_DB') && !getenv('FTN_BACKUP_DIR'))) return;
    if (setting('auto_backup', '1') !== '1' || setting('last_backup') === today()) return;
    $dir = backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
    $file = $dir . '/erp-' . today() . '.sqlite';
    try {
        set_setting('last_backup', today());
        if (!is_file($file)) db()->exec('VACUUM INTO ' . db()->quote($file));
        $all = glob($dir . '/erp-*.sqlite') ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - 14)) as $old) @unlink($old);
    } catch (Throwable $e) {
        error_log('[ERP] sauvegarde automatique : ' . $e->getMessage());
    }
}

function table_exists(PDO $pdo, string $t): bool
{
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$t]);
    return (bool)$st->fetchColumn();
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function one(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r ?: null;
}

function all(string $sql, array $p = []): array
{
    return q($sql, $p)->fetchAll();
}

function val(string $sql, array $p = [])
{
    $v = q($sql, $p)->fetchColumn();
    return $v === false ? null : $v;
}

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function update(string $table, array $data, string $where, array $wp = []): int
{
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    return q("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $wp))->rowCount();
}

/** Exécute $fn dans une transaction (ou un savepoint si une transaction est déjà ouverte). */
function tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        $sp = 'sp' . bin2hex(random_bytes(4));
        $pdo->exec("SAVEPOINT $sp");
        try {
            $r = $fn();
            $pdo->exec("RELEASE $sp");
            return $r;
        } catch (Throwable $e) {
            $pdo->exec("ROLLBACK TO $sp");
            $pdo->exec("RELEASE $sp");
            throw $e;
        }
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Numéro suivant d'une séquence (ex. ventes TUN 2026). */
function next_seq(string $key): int
{
    q('INSERT INTO counters(key, value) VALUES (?, 0) ON CONFLICT(key) DO NOTHING', [$key]);
    q('UPDATE counters SET value = value + 1 WHERE key = ?', [$key]);
    return (int)val('SELECT value FROM counters WHERE key = ?', [$key]);
}

/* ---------- Paramètres ---------- */

function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null || $key === '__reset') {
        $cache = [];
        foreach (all('SELECT key, value FROM settings') as $r) $cache[$r['key']] = $r['value'];
        if ($key === '__reset') return null;
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, $value): void
{
    q('INSERT INTO settings(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, (string)$value]);
    setting('__reset');
}

/* ---------- Emplacements ---------- */

function locations(bool $activeOnly = true, ?string $type = null): array
{
    static $all = null;
    if ($all === null) $all = all('SELECT * FROM locations ORDER BY position, id');
    return array_values(array_filter($all, fn($l) => (!$activeOnly || $l['active']) && (!$type || $l['type'] === $type)));
}

function location(?int $id): ?array
{
    foreach (locations(false) as $l) if ((int)$l['id'] === (int)$id) return $l;
    return null;
}

function location_options(bool $activeOnly = true, ?string $type = null): array
{
    $o = [];
    foreach (locations($activeOnly, $type) as $l) $o[$l['id']] = $l['name'];
    return $o;
}

/* ---------- Journal ---------- */

function audit(string $action, string $entity = '', $entityId = null, string $details = ''): void
{
    $u = $_SESSION['uid'] ?? null;
    insert('audit_log', [
        'created_at' => now(), 'user_id' => $u, 'action' => $action, 'entity' => $entity,
        'entity_id' => $entityId, 'details' => mb_substr($details, 0, 1000),
    ]);
}

/* ---------- Installation ---------- */

function db_install(PDO $pdo): void
{
    $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
    $ins = fn(string $sql, array $p = []) => $pdo->prepare($sql)->execute($p);

    $settings = [
        'company_name' => 'FitnessTN',
        'company_phone' => '+216 29 972 043',
        'company_email' => 'contact@fitness-tn.com',
        'company_site' => 'www.fitness-tn.com',
        'company_mf' => '',
        'vat_rate' => '19',
        'ticket_footer' => "Merci pour votre confiance !\nLivraison partout en Tunisie · WhatsApp 29 972 043",
        'invoice_note' => 'Prix TTC. Conditions de garantie selon la fiche produit.',
        'allow_negative_stock' => '1',
        'default_float' => '300000',
        'cash_diff_threshold' => '5000',
        'max_discount_pct' => '20',
        'timbre' => '1000',
    ];
    foreach ($settings as $k => $v) $ins('INSERT INTO settings(key, value) VALUES (?, ?)', [$k, $v]);

    $locs = [
        ['TUN', 'Showroom Tunis', 'showroom', 'Ariana — Route Nationale 8, à côté de BMC Trucking, en face de Glory Gym', '+216 29 972 043', 1],
        ['NAB', 'Showroom Nabeul', 'showroom', 'Beni Khiar — rond-point Som3a et Beni Khaled, Nabeul', '+216 29 972 043', 2],
        ['DEP', 'Dépôt central', 'depot', '', '', 3],
    ];
    foreach ($locs as $l) {
        $ins('INSERT INTO locations(code, name, type, address, phone, position, active) VALUES (?,?,?,?,?,?,1)', $l);
    }

    // Tableaux de tâches et colonnes par défaut
    $boards = [
        ['marketing', 'Marketing & publicité', 'marketing', null],
        ['loc:1', 'Showroom Tunis', 'showroom', 1],
        ['loc:2', 'Showroom Nabeul', 'showroom', 2],
        ['direction', 'Direction', 'direction', null],
    ];
    foreach ($boards as $i => $b) {
        $ins('INSERT INTO boards(key, name, kind, location_id, position) VALUES (?,?,?,?,?)', [$b[0], $b[1], $b[2], $b[3], $i]);
        $bid = (int)$pdo->lastInsertId();
        foreach ([['À faire', 0, '#64748b'], ['En cours', 0, '#1F7BFF'], ['En validation', 0, '#f59e0b'], ['Terminé', 1, '#16a34a']] as $p => $c) {
            $ins('INSERT INTO board_columns(board_id, name, position, is_done, color) VALUES (?,?,?,?,?)', [$bid, $c[0], $p, $c[1], $c[2]]);
        }
    }

    $carriers = [
        ['Livraison interne FitnessTN', 'interne', '', 'Grand Tunis, Cap Bon', 1, 'Camion FitnessTN : livraison + installation + démonstration.'],
        ['Rapid-Poste', 'externe', '', 'Toute la Tunisie', 1, 'Petits colis (accessoires).'],
        ['Aramex', 'externe', '', 'Toute la Tunisie', 1, ''],
    ];
    foreach ($carriers as $c) {
        $ins('INSERT INTO carriers(name, type, phone, zones, cod, notes, active, created_at) VALUES (?,?,?,?,?,?,1,?)', [$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], now()]);
    }

    // Comptes de départ : mots de passe aléatoires (ou FTN_SEED_PASSWORD pour les tests)
    $fixed = getenv('FTN_SEED_PASSWORD') ?: null;
    $accounts = [
        ['admin', 'Direction (administrateur)', 'admin', null],
        ['tunis', 'Responsable showroom Tunis', 'showroom', 1],
        ['nabeul', 'Responsable showroom Nabeul', 'showroom', 2],
        ['marketing', 'Marketing & publicité', 'marketing', null],
    ];
    $lines = ["Identifiants initiaux de l'ERP FitnessTN (créés le " . date('d/m/Y H:i') . ")", ''];
    foreach ($accounts as $a) {
        $pw = $fixed ?: ('ftn-' . substr(bin2hex(random_bytes(4)), 0, 6));
        $ins('INSERT INTO users(username, name, role, location_id, password_hash, initial_password, active, created_at) VALUES (?,?,?,?,?,1,1,?)',
            [$a[0], $a[1], $a[2], $a[3], password_hash($pw, PASSWORD_DEFAULT), now()]);
        $lines[] = str_pad($a[0], 12) . ' ' . str_pad($pw, 14) . ' ' . $a[1];
    }
    $lines[] = '';
    $lines[] = 'Changez ces mots de passe après la première connexion (menu « Mon compte »).';
    if (!$fixed) @file_put_contents(DATA_DIR . '/IDENTIFIANTS.txt', implode("\r\n", $lines) . "\r\n");

    // Quelques tâches de départ
    $now = now();
    $starter = [
        ['marketing', 'Planifier les publications de la semaine', 'Facebook, Instagram, TikTok : 1 vidéo produit + 1 avis client + 1 promo.', 'haute', 0],
        ['marketing', 'Vidéo démonstration du tapis BT 500+ en showroom', 'Format vertical 30 s, sous-titres en darija.', 'normale', 0],
        ['marketing', 'Mettre à jour les prix promo sur le site', '', 'normale', 1],
        ['loc:1', 'Inventaire : compter les tapis de course', 'Saisir les quantités dans Stock › Inventaire.', 'haute', 0],
        ['loc:1', 'Rappeler les clients en attente de livraison', '', 'normale', 0],
        ['loc:2', 'Inventaire : compter les vélos et accessoires', 'Saisir les quantités dans Stock › Inventaire.', 'haute', 0],
        ['loc:2', 'Mettre en place la vitrine de la rentrée', '', 'normale', 0],
    ];
    foreach ($starter as $i => $t) {
        $bid = (int)$pdo->query("SELECT id FROM boards WHERE key = " . $pdo->quote($t[0]))->fetchColumn();
        $cid = (int)$pdo->query("SELECT id FROM board_columns WHERE board_id = $bid ORDER BY position LIMIT 1 OFFSET " . (int)$t[4])->fetchColumn();
        $ins('INSERT INTO tasks(board_id, column_id, title, description, priority, position, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,1,?,?)',
            [$bid, $cid, $t[1], $t[2], $t[3], $i, $now, $now]);
    }

    // Catalogue initial : export WooCommerce du site s'il est présent dans data/
    $csv = glob(DATA_DIR . '/produits-site-*.csv') ?: [];
    if ($csv && !getenv('FTN_NO_CATALOG')) {
        require_once __DIR__ . '/catalog.php';
        catalog_import_woo_file($pdo, end($csv));
    }
}
