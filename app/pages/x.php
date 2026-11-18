<?php
declare(strict_types=1);

/*
 * X — l'assistant de la direction. Les réponses sont calculées dans l'ERP (app/x_engine.php) :
 * aucune connexion extérieure, aucune clé, rien ne sort de la base. Réservé à l'administrateur.
 */
require_perm('x');
if (!is_admin()) {
    http_response_code(403);
    exit('Réservé à l’administrateur.');
}
$a = (string)get('a');

if ($a === 'ask' || $a === 'reset') {
    if (!is_post()) json_out(['ok' => false, 'error' => 'POST attendu.'], 405);
    csrf_check();
    if ($a === 'reset') {
        unset($_SESSION['x_ctx']);
        json_out(['ok' => true]);
    }
    require_once APP_ROOT . '/app/x_engine.php';
    $q = mb_substr(trim((string)(json_input()['q'] ?? '')), 0, 500);
    $ctx = $_SESSION['x_ctx'] ?? [];
    try {
        $html = x_answer($q, $ctx);
        $_SESSION['x_ctx'] = $ctx;
    } catch (Throwable $e) {
        error_log('[ERP] X : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $html = '<p>Je n’ai pas réussi à calculer cette réponse. Reformulez la question, par exemple « ventes du mois » ou « réparations en attente ».</p>';
    }
    json_out(['ok' => true, 'html' => $html]);
}

require_once APP_ROOT . '/app/x_engine.php';
layout_start('X — assistant IA', 'x', ['wide' => true]);
?>
<section class="card x-chat" data-x data-endpoint="<?= e(url('x', ['a' => 'ask'])) ?>" data-reset="<?= e(url('x', ['a' => 'reset'])) ?>" data-q="<?= e((string)get('q')) ?>">
  <div class="x-head">
    <span class="x-logo">X</span>
    <div class="grow"><h2>X — votre assistant</h2><p class="muted small">Posez une question sur les ventes, livraisons, transporteurs, réparations, clients, stock ou finances : X calcule la réponse directement dans l’ERP.</p></div>
    <button type="button" class="btn sm ghost" data-x-new><?= icon('plus') ?>Nouvelle conversation</button>
  </div>
  <div class="x-log" data-x-log aria-live="polite">
    <div class="x-msg bot" data-x-intro><span class="x-logo sm">X</span><div class="x-bubble x-text"><?= x_help('Bonjour ! Je suis X.') ?></div></div>
  </div>
  <form class="x-form" data-x-form>
    <textarea rows="2" data-x-q placeholder="Votre question… (Entrée pour envoyer, Maj+Entrée pour aller à la ligne)" aria-label="Votre question"></textarea>
    <button class="btn primary" data-x-send><?= icon('send') ?><span>Envoyer</span></button>
  </form>
  <p class="hint">X fonctionne entièrement dans l’ERP : rien n’est envoyé à l’extérieur, sans clé ni abonnement. Il comprend aussi quelques mots en tunisien (9adeh, bi3na, flous…).</p>
</section>
<?php
layout_end(['x.js']);
