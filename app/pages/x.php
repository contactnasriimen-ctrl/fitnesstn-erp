<?php
declare(strict_types=1);

/*
 * X — l'assistant IA de la direction. Il répond aux questions sur les données de l'ERP en interrogeant la base
 * en LECTURE SEULE (requêtes SELECT) grâce à Claude (Anthropic). La conversation avec l'API part du navigateur
 * de l'administrateur (assets/js/x.js) : cela fonctionne aussi bien avec start.bat, sur un hébergement que dans
 * la version navigateur. Seul l'administrateur y a accès.
 */
require_perm('x');
if (!is_admin()) {
    http_response_code(403);
    exit('Réservé à l’administrateur.');
}
$a = (string)get('a');

const X_MODEL = 'claude-opus-5-5';
const X_MAX_ROWS = 200;

/** Connexion séparée, ouverte en lecture seule : X ne peut rien modifier. */
function x_ro_db(): PDO
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM];
    try {
        $pdo = new PDO('sqlite:' . db_path(), null, null, $opts + [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    } catch (Throwable $e) {
        $pdo = new PDO('sqlite:' . db_path(), null, null, $opts);
    }
    $pdo->exec('PRAGMA query_only = 1');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

/** Exécute une requête de X. Retourne ['ok' => true, 'colonnes' => …, 'lignes' => …] ou ['ok' => false, 'erreur' => …]. */
function x_run_sql(string $sql): array
{
    $sql = rtrim(trim($sql), "; \t\r\n");
    if (!preg_match('/^\s*(select|with)\b/i', $sql)) return ['ok' => false, 'erreur' => 'Seules les requêtes SELECT (ou WITH … SELECT) sont autorisées : la base est en lecture seule.'];
    if (str_contains($sql, ';')) return ['ok' => false, 'erreur' => 'Une seule requête à la fois, sans point-virgule.'];
    if (preg_match('/\b(attach|detach|pragma|vacuum|load_extension)\b/i', $sql)) return ['ok' => false, 'erreur' => 'Instruction non autorisée.'];
    @set_time_limit(30);
    $secret = (string)setting('x_api_key', '');
    try {
        $st = x_ro_db()->query($sql);
        $cols = [];
        for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = (string)($st->getColumnMeta($i)['name'] ?? "col$i");
        // Jamais de mot de passe ni de clé dans les réponses
        $hidden = array_keys(array_filter($cols, fn($c) => preg_match('/password|hash|api_key/i', $c)));
        $rows = [];
        $more = false;
        while (($r = $st->fetch()) !== false) {
            if (count($rows) >= X_MAX_ROWS) { $more = true; break; }
            foreach ($hidden as $i) $r[$i] = '(masqué)';
            foreach ($r as $i => $v) {
                if (is_string($v) && (($secret !== '' && str_contains($v, $secret)) || preg_match('/^\$(2y|argon2)/', $v))) $r[$i] = '(masqué)';
            }
            $rows[] = $r;
        }
        return ['ok' => true, 'colonnes' => $cols, 'lignes' => $rows, 'nb_lignes' => count($rows),
            'tronque' => $more ? 'Plus de ' . X_MAX_ROWS . ' lignes : résultat coupé. Agrégez (GROUP BY, SUM, COUNT) ou ajoutez LIMIT.' : false];
    } catch (Throwable $e) {
        return ['ok' => false, 'erreur' => 'Erreur SQL : ' . $e->getMessage()];
    }
}

/** Consignes de X : rôle, règles, schéma complet de la base (avec ses commentaires) et codes utilisés. */
function x_system_prompt(): string
{
    $schema = [];
    foreach (all("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT IN ('login_attempts', 'sqlite_sequence', 'counters') AND sql IS NOT NULL ORDER BY name") as $t) {
        $schema[] = preg_replace("/\n\s*\n/", "\n", trim($t['sql'])) . ';';
    }
    $codes = [
        'sales.status' => ['payee' => 'payée', 'partielle' => 'reste à payer', 'annulee' => 'annulée (à exclure du CA)'],
        'payments.method / document_payments.method' => PAY_METHODS,
        'sales.source (comment le client nous a connus)' => SOURCES,
        'shipments.status' => SHIP_STATUS,
        'shipments.holder (où est la marchandise maintenant)' => SHIP_HOLDERS,
        'repairs.status' => REPAIR_STATUS, 'repairs.kind' => REPAIR_KINDS, 'repairs.place' => REPAIR_PLACES,
        'documents.type' => ['devis' => 'devis', 'facture' => 'facture'],
        'documents.status (devis)' => DOC_STATUS['devis'], 'documents.status (facture)' => DOC_STATUS['facture'],
        'expenses.category' => EXPENSE_CATEGORIES, 'campaigns.channel' => CHANNELS, 'campaigns.status' => CAMPAIGN_STATUS,
        'stock_moves.type' => MOVE_TYPES, 'tasks.priority' => PRIORITIES, 'users.role' => ROLES,
    ];
    $codeTxt = '';
    foreach ($codes as $k => $map) $codeTxt .= "- $k : " . implode(', ', array_map(fn($c, $l) => "'$c' = $l", array_keys($map), $map)) . "\n";
    $locs = implode(', ', array_map(fn($l) => "id {$l['id']} = {$l['code']} « {$l['name']} » ({$l['type']})", locations(false)));
    $company = setting('company_legal_name', '') ?: setting('company_name', 'FitnessTN');

    return <<<TXT
Tu es X, l'assistant IA de la direction de FitnessTN ($company), vendeur tunisien d'équipements de sport (tapis de course, vélos, musculation…) avec des showrooms à Tunis et Nabeul et des livraisons dans toute la Tunisie. Tu réponds aux questions de l'administrateur sur les données de l'ERP.

## Comment travailler
- Tu as un outil, `requete_sql`, qui exécute UNE requête SQLite en lecture seule sur la base de l'ERP. Vérifie toujours les chiffres avec l'outil : n'invente jamais un nombre, un nom ou une date.
- Fais calculer SQLite (COUNT, SUM, GROUP BY, ORDER BY … LIMIT) plutôt que de rapatrier des centaines de lignes. Un résultat est limité à 200 lignes.
- Tu peux enchaîner plusieurs requêtes (par exemple : explorer les valeurs d'une colonne, puis calculer). Si une requête échoue, lis l'erreur et corrige-la.
- Tu ne peux rien modifier. Si on te demande de créer ou changer quelque chose, explique où le faire dans l'ERP (menu Caisse, Devis, Factures, Livraisons, Réparations & SAV, Stock, Paramètres…).

## Conventions des données
- Tous les montants sont en MILLIMES (entiers) : 1 DT = 1000. Divise par 1000 et affiche en dinars : « 12 345 DT » (espace pour les milliers, « ,500 » seulement si les millimes comptent).
- Dates en texte « AAAA-MM-JJ » ou « AAAA-MM-JJ HH:MM:SS », heure de Tunis. Utilise date(…), strftime('%Y-%m', …). Affiche les dates en jj/mm/aaaa.
- Chiffre d'affaires (CA) = somme de sales.total des ventes non annulées (status <> 'annulee') + documents.total des factures (type = 'facture') sans vente liée (sale_id IS NULL) et non annulées. Encaissements = payments.amount + document_payments.amount.
- Ventes : sales (une vente) + sale_items (ses lignes : label, product_id, qty, unit_price, total, unit_cost = prix d'achat si connu). Une vente avec import_ref non NULL (numéro « XL-… ») vient du fichier Excel « VENTE GRAND TUNIS » (historique depuis février 2026 du showroom de Tunis) : son libellé de produit peut ne pas être relié au catalogue (product_id NULL) ; regroupe alors sur sale_items.label.
- Livraisons : shipments (trajet : location_id = départ, gouvernorat/city/address = arrivée ; carrier_id = transporteur ; tracking = n° de suivi ; holder = où est la marchandise ; technician + technician_fee = installateur et sa prime ; client_fee = transport payé par le client ; fee = coût payé au transporteur ; cod_amount = argent à encaisser chez le client et cod_collected = 1 quand le transporteur l'a reversé). « Argent à récupérer chez les transporteurs » = status = 'livree' AND cod_collected = 0 AND cod_amount > 0. Marchandise : shipment_items ; historique : shipment_events.
- Réparations & SAV : table repairs (amount = montant facturé, paid = encaissé ; technician = réparateur). Les fiches importées d'Excel n'ont souvent pas de date (received_at NULL).
- Clients : clients (phone = 8 chiffres). Transporteurs : carriers. Sites : $locs.
- Les données importées des fichiers Excel sont parfois incomplètes (client sans téléphone, adresse approximative, gouvernorat deviné). Signale-le quand cela change la réponse.

## Codes utilisés
$codeTxt
## Réponse
- Réponds dans la langue de la question (français, arabe, ou tunisien en lettres latines ou arabes).
- Commence par la réponse directe (le chiffre ou le nom clé) en une ou deux phrases, puis le détail utile : un tableau Markdown court (15 lignes au plus) quand il y a une liste ou une comparaison.
- Si la question est ambiguë (période non précisée…), choisis l'hypothèse la plus raisonnable, dis-la en une ligne et réponds.
- Termine, quand c'est utile, par une ou deux remarques concrètes pour la gestion (ex. un transporteur qui doit beaucoup d'argent, une réparation en attente depuis longtemps). Reste bref.

## Schéma de la base (SQLite)
TXT
        . "\n" . implode("\n\n", $schema) . "\n\nAujourd'hui : " . today() . ' (' . strftime_fr(today()) . ').';
}

if ($a === 'sql') {
    if (!is_post()) json_out(['ok' => false, 'erreur' => 'POST attendu.'], 405);
    csrf_check();
    $in = json_input();
    $q = (string)($in['query'] ?? '');
    $res = x_run_sql($q);
    if (!$res['ok']) error_log('[ERP] X : ' . $res['erreur']);
    json_out($res);
}

$key = (string)setting('x_api_key', '');
$suggestions = [
    'Quel est le chiffre d’affaires par mois depuis février ?',
    'Quels transporteurs nous doivent encore de l’argent ?',
    'Top 10 des machines les plus vendues cette année',
    'Dans quels gouvernorats vendons-nous le plus ?',
    'Combien de réparations sont en attente, et chez quel réparateur ?',
    'Combien avons-nous payé aux transporteurs et aux techniciens en octobre ?',
];
layout_start('X — assistant IA', 'x', ['wide' => true, 'body' => 'x-page']);
if ($key === ''): ?>
  <section class="card x-setup">
    <span class="x-logo lg">X</span>
    <h2>Activez X, votre assistant de données</h2>
    <p>X répond à toutes vos questions sur les ventes, livraisons, transporteurs, réparations, stock, clients et finances, en lisant directement les données de l’ERP (sans rien modifier).</p>
    <p>Il utilise <b>Claude</b>, l’IA d’Anthropic. Pour l’activer, créez une clé API sur <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a> (paiement à l’usage, quelques centimes par question) puis collez-la dans les paramètres.</p>
    <p><a class="btn primary" href="<?= e(url('settings')) ?>#agent-x"><?= icon('settings') ?>Ajouter la clé dans Paramètres</a></p>
  </section>
<?php else: ?>
  <section class="card x-chat">
    <div class="x-head">
      <span class="x-logo">X</span>
      <div class="grow"><h2>X — votre assistant de données</h2><p class="muted small">Ventes, livraisons, transporteurs, réparations, stock, clients, finances : posez votre question, X consulte l’ERP et répond avec les chiffres.</p></div>
      <button type="button" class="btn sm ghost" data-x-new><?= icon('plus') ?>Nouvelle conversation</button>
    </div>
    <div class="x-log" data-x-log aria-live="polite">
      <div class="x-empty" data-x-empty>
        <p class="muted small">Exemples de questions :</p>
        <div class="x-sugg"><?php foreach ($suggestions as $s): ?><button type="button" class="chip" data-x-ask="<?= e($s) ?>"><?= e($s) ?></button><?php endforeach; ?></div>
      </div>
    </div>
    <form class="x-form" data-x-form>
      <textarea rows="2" data-x-q placeholder="Votre question… (Entrée pour envoyer, Maj+Entrée pour aller à la ligne)" aria-label="Votre question"></textarea>
      <button class="btn primary" data-x-send><?= icon('send') ?><span>Envoyer</span></button>
    </form>
    <p class="hint">X utilise Claude (Anthropic) : vos questions et les données qu’il consulte sont envoyées à l’API d’Anthropic. Vérifiez les chiffres importants avant de décider.</p>
  </section>
  <script type="application/json" data-x-config><?= json_encode([
      'key' => $key, 'model' => X_MODEL, 'effort' => 'medium', 'endpoint' => url('x', ['a' => 'sql']),
      'system' => x_system_prompt(), 'q' => (string)get('q'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif;
layout_end($key === '' ? [] : ['vendor/anthropic-sdk-1.4.0.js', 'x.js']);
