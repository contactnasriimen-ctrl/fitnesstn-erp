<?php
declare(strict_types=1);

// Listes utilisées dans les devis, factures et bons de livraison : transporteurs (nom, matricule, CIN) et modalités de paiement.
require_perm('invoices.view');
$canEdit = can('invoices.edit');
$self = fn(array $p = []) => url('doc_lists', $p);

if (is_post()) {
    csrf_check();
    $a = (string)get('a');
    try {
        if (!$canEdit) throw new RuntimeException('Votre profil ne permet pas de modifier ces listes.');
        switch ($a) {
            case 'tr_save':
                $id = (int)post('id');
                transporter_save($id, ['name' => post('name'), 'plate' => post('plate'), 'cin' => post('cin')]);
                flash('ok', $id ? 'Transporteur modifié. Les documents déjà établis gardent les informations imprimées.' : 'Transporteur ajouté.');
                break;
            case 'tr_delete':
                $name = transporter_delete((int)post('id'));
                flash('ok', "Transporteur « $name » supprimé. Les documents déjà établis gardent son nom, son matricule et sa CIN.");
                break;
            case 'pm_save':
                $id = (int)post('id');
                payment_mode_save($id, (string)post('label'));
                flash('ok', $id ? 'Modalité de paiement modifiée.' : 'Modalité de paiement ajoutée.');
                break;
            case 'pm_delete':
                $label = payment_mode_delete((int)post('id'));
                flash('ok', "Modalité « $label » retirée de la liste. Les documents déjà établis la gardent.");
                break;
            case 'pm_move':
                payment_mode_move((int)post('id'), (int)post('dir'));
                break;
            case 'pm_default':
                $m = one('SELECT label FROM payment_modes WHERE id = ?', [(int)post('id')]);
                if (!$m) throw new RuntimeException('Modalité introuvable.');
                set_setting('invoice_payment_mode', $m['label']);
                audit('modalité de paiement par défaut', 'payment_mode', (int)post('id'), $m['label']);
                flash('ok', '« ' . $m['label'] . ' » est proposée par défaut sur les nouveaux documents.');
                break;
            case 'pm_nodefault':
                set_setting('invoice_payment_mode', '');
                flash('ok', 'Aucune modalité n’est plus proposée par défaut.');
                break;
            default:
                throw new RuntimeException('Action inconnue.');
        }
        redirect($self());
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
        if (in_array($a, ['tr_save', 'pm_save'], true)) $_SESSION['doc_lists_form'] = [$a => $_POST];
        back($self());
    }
}

$trs = transporters();
$used = [];
foreach (all('SELECT transporter_id, COUNT(*) n FROM documents WHERE transporter_id IS NOT NULL GROUP BY transporter_id') as $r) $used[(int)$r['transporter_id']] = (int)$r['n'];
$modes = all('SELECT * FROM payment_modes ORDER BY position, id');
$modeUse = [];
foreach (all("SELECT payment_mode, COUNT(*) n FROM documents WHERE payment_mode <> '' GROUP BY payment_mode") as $r) $modeUse[mb_strtolower($r['payment_mode'])] = (int)$r['n'];
$default = (string)setting('invoice_payment_mode', '');

// Saisie en cours de modification (lien « modifier ») ou à réafficher après une erreur
$retry = $_SESSION['doc_lists_form'] ?? [];
unset($_SESSION['doc_lists_form']);
$trEdit = ['id' => 0, 'name' => '', 'plate' => '', 'cin' => ''];
if ($canEdit && ($tid = (int)get('tr')) && ($t = one('SELECT * FROM transporters WHERE id = ?', [$tid]))) $trEdit = $t;
if (isset($retry['tr_save'])) $trEdit = ['id' => (int)($retry['tr_save']['id'] ?? 0)] + array_map('strval', array_intersect_key($retry['tr_save'], ['name' => 1, 'plate' => 1, 'cin' => 1])) + $trEdit;
$pmEdit = $canEdit && ($mid = (int)get('pm')) ? one('SELECT * FROM payment_modes WHERE id = ?', [$mid]) : null;
$pmNew = isset($retry['pm_save']) && empty($retry['pm_save']['id']) ? (string)($retry['pm_save']['label'] ?? '') : '';

$actions = '<a class="btn sm ghost" href="' . e(url('invoices')) . '">' . icon('invoice') . 'Factures</a>'
    . '<a class="btn sm ghost" href="' . e(url('delivery_notes')) . '">' . icon('clipboard') . 'Bons de livraison</a>';
layout_start('Transporteurs & modalités de paiement', 'invoices', ['actions' => $actions]);
$hidden = fn(int $id) => csrf_field() . '<input type="hidden" name="id" value="' . $id . '">';
?>
<p class="muted">Ces listes alimentent les menus des devis, factures et bons de livraison. Chaque document garde une copie de ce qui y est imprimé :
  modifier ou supprimer une ligne ici ne change pas les documents déjà établis.</p>

<div class="lists-grid">
  <section class="card flush" id="transporteurs">
    <div class="card-head"><b><?= icon('truck') ?> Transporteurs</b><span class="muted small"><?= count($trs) ?> enregistré(s)</span></div>
    <?php if ($canEdit): ?>
      <form method="post" action="<?= e($self(['a' => 'tr_save'])) ?>" class="list-form<?= $trEdit['id'] ? ' editing' : '' ?>">
        <?= $hidden((int)$trEdit['id']) ?>
        <?php if ($trEdit['id']): ?><p class="small"><b>Modifier « <?= e($trEdit['name']) ?> »</b> · <a href="<?= e($self()) ?>">annuler</a></p><?php endif; ?>
        <div class="list-form-row">
          <label>Nom<input name="name" value="<?= e($trEdit['name']) ?>" required maxlength="120" placeholder="Nom et prénom ou société"<?= $trEdit['id'] ? ' autofocus' : '' ?>></label>
          <label>Matricule<input name="plate" value="<?= e($trEdit['plate']) ?>" maxlength="20" placeholder="245 TU 7788"></label>
          <label>CIN<input name="cin" value="<?= e($trEdit['cin']) ?>" maxlength="20" inputmode="numeric" placeholder="8 chiffres"></label>
          <button class="btn primary"><?= icon($trEdit['id'] ? 'check' : 'plus') ?><?= $trEdit['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
        </div>
      </form>
    <?php endif; ?>
    <table class="tbl stack">
      <thead><tr><th>Nom</th><th>Matricule</th><th>CIN</th><th class="num">Documents</th><?php if ($canEdit): ?><th class="num"></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($trs as $t): $n = $used[(int)$t['id']] ?? 0; ?>
        <tr class="<?= (int)$trEdit['id'] === (int)$t['id'] ? 'row-on' : '' ?>">
          <td data-th="Nom"><b><?= e($t['name']) ?></b></td>
          <td data-th="Matricule"><?= $t['plate'] !== '' ? e($t['plate']) : '<span class="muted">—</span>' ?></td>
          <td data-th="CIN"><?= $t['cin'] !== '' ? e($t['cin']) : '<span class="muted">—</span>' ?></td>
          <td data-th="Documents" class="num"><?= $n ?: '' ?></td>
          <?php if ($canEdit): ?>
          <td class="num row-btns">
            <a class="icon-btn xs" href="<?= e($self(['tr' => $t['id']])) ?>#transporteurs" title="Modifier <?= e($t['name']) ?>" aria-label="Modifier <?= e($t['name']) ?>"><?= icon('edit') ?></a>
            <form method="post" action="<?= e($self(['a' => 'tr_delete'])) ?>" onsubmit="return confirm(<?= e(json_encode('Supprimer le transporteur « ' . $t['name'] . ' » ?' . ($n ? "\n\nIl figure sur $n document(s) : ils gardent son nom, son matricule et sa CIN." : ''))) ?>)">
              <?= $hidden((int)$t['id']) ?><button class="icon-btn xs" title="Supprimer <?= e($t['name']) ?>" aria-label="Supprimer <?= e($t['name']) ?>"><?= icon('trash') ?></button></form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$trs): ?><tr><td colspan="5" class="empty-row">Aucun transporteur pour l’instant.<?= $canEdit ? ' Ajoutez-en un ci-dessus, ou saisissez-le directement dans une facture ou un bon de livraison.' : '' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="card flush" id="modalites">
    <div class="card-head"><b><?= icon('cash') ?> Modalités de paiement</b><span class="muted small">dans l’ordre du menu</span></div>
    <?php if ($canEdit): ?>
      <form method="post" action="<?= e($self(['a' => 'pm_save'])) ?>" class="list-form">
        <?= $hidden(0) ?>
        <div class="list-form-row">
          <label class="grow">Nouvelle modalité<input name="label" value="<?= e($pmNew) ?>" required maxlength="60" placeholder="Ex. Espèces, Carte bancaire, Traite à 60 jours…"></label>
          <button class="btn primary"><?= icon('plus') ?>Ajouter</button>
        </div>
      </form>
    <?php endif; ?>
    <ul class="pm-list">
      <?php foreach ($modes as $i => $m): $isDef = $default !== '' && mb_strtolower($default) === mb_strtolower($m['label']); $n = $modeUse[mb_strtolower($m['label'])] ?? 0; ?>
        <li class="<?= $isDef ? 'is-default' : '' ?>">
          <?php if ($pmEdit && (int)$pmEdit['id'] === (int)$m['id']): ?>
            <form method="post" action="<?= e($self(['a' => 'pm_save'])) ?>" class="pm-edit">
              <?= $hidden((int)$m['id']) ?><input name="label" value="<?= e($m['label']) ?>" required maxlength="60" aria-label="Libellé" autofocus>
              <button class="btn sm primary"><?= icon('check') ?>Enregistrer</button><a class="btn sm ghost" href="<?= e($self()) ?>#modalites">Annuler</a>
            </form>
          <?php else: ?>
            <span class="pm-label"><b><?= e($m['label']) ?></b><?= $isDef ? ' ' . badge('Par défaut', 'ok') : '' ?><?= $n ? '<small class="muted">' . $n . ' document(s)</small>' : '' ?></span>
            <?php if ($canEdit): ?>
              <span class="row-btns">
                <?php if (!$isDef): ?>
                  <form method="post" action="<?= e($self(['a' => 'pm_default'])) ?>"><?= $hidden((int)$m['id']) ?><button class="btn xs ghost" title="Proposer cette modalité sur les nouveaux documents">Par défaut</button></form>
                <?php endif; ?>
                <form method="post" action="<?= e($self(['a' => 'pm_move'])) ?>"><?= $hidden((int)$m['id']) ?><input type="hidden" name="dir" value="-1"><button class="icon-btn xs" title="Monter" aria-label="Monter <?= e($m['label']) ?>" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('up') ?></button></form>
                <form method="post" action="<?= e($self(['a' => 'pm_move'])) ?>"><?= $hidden((int)$m['id']) ?><input type="hidden" name="dir" value="1"><button class="icon-btn xs" title="Descendre" aria-label="Descendre <?= e($m['label']) ?>" <?= $i === count($modes) - 1 ? 'disabled' : '' ?>><?= icon('down') ?></button></form>
                <a class="icon-btn xs" href="<?= e($self(['pm' => $m['id']])) ?>#modalites" title="Modifier" aria-label="Modifier <?= e($m['label']) ?>"><?= icon('edit') ?></a>
                <form method="post" action="<?= e($self(['a' => 'pm_delete'])) ?>" onsubmit="return confirm(<?= e(json_encode('Retirer « ' . $m['label'] . ' » de la liste ?' . ($n ? "\n\nLes $n document(s) qui l’utilisent la gardent." : ''))) ?>)">
                  <?= $hidden((int)$m['id']) ?><button class="icon-btn xs" title="Supprimer" aria-label="Supprimer <?= e($m['label']) ?>"><?= icon('trash') ?></button></form>
              </span>
            <?php endif; ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if (!$modes): ?><li class="muted">Aucune modalité : le menu des documents restera vide.</li><?php endif; ?>
    </ul>
    <?php if ($canEdit && $default !== ''): ?>
      <form method="post" action="<?= e($self(['a' => 'pm_nodefault'])) ?>" class="pm-foot"><?= csrf_field() ?><button class="btn xs ghost">Ne rien proposer par défaut</button></form>
    <?php endif; ?>
  </section>
</div>
<?php
layout_end();
