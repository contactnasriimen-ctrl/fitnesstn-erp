<?php
declare(strict_types=1);

require_perm('tasks');
$u = user();
$a = (string)get('a');

function task_board_or_fail(int $boardId, bool $edit = true): array
{
    $b = one('SELECT * FROM boards WHERE id = ?', [$boardId]);
    if (!$b || !($edit ? board_can_edit($b) : board_can_view($b))) throw new RuntimeException('Tableau non autorisé.');
    return $b;
}

function task_reorder(int $columnId, int $taskId, int $index): void
{
    $ids = array_map('intval', array_column(all('SELECT id FROM tasks WHERE column_id = ? AND id <> ? ORDER BY position, id', [$columnId, $taskId]), 'id'));
    $index = max(0, min($index, count($ids)));
    array_splice($ids, $index, 0, [$taskId]);
    foreach ($ids as $pos => $id) q('UPDATE tasks SET position = ? WHERE id = ?', [$pos, $id]);
}

$boards = array_values(array_filter(all('SELECT * FROM boards ORDER BY position, id'), 'board_can_view'));

/* ---------- Déplacement (glisser-déposer) ---------- */

if ($a === 'move' && is_post()) {
    csrf_check();
    $in = json_input();
    try {
        $t = one('SELECT * FROM tasks WHERE id = ?', [(int)($in['id'] ?? 0)]);
        if (!$t) throw new RuntimeException('Tâche introuvable.');
        task_board_or_fail((int)$t['board_id']);
        $col = one('SELECT * FROM board_columns WHERE id = ? AND board_id = ?', [(int)($in['column_id'] ?? 0), $t['board_id']]);
        if (!$col) throw new RuntimeException('Colonne invalide.');
        tx(function () use ($t, $col, $in) {
            update('tasks', ['column_id' => $col['id'], 'updated_at' => now(), 'done_at' => $col['is_done'] ? ($t['done_at'] ?: now()) : null], 'id = ?', [$t['id']]);
            task_reorder((int)$col['id'], (int)$t['id'], (int)($in['index'] ?? 0));
        });
        json_out(['ok' => true]);
    } catch (RuntimeException $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

/* ---------- Formulaires ---------- */

if (is_post()) {
    csrf_check();
    $boardId = (int)post('board_id');
    try {
        $board = task_board_or_fail($boardId);
        if ($a === 'save') {
            $id = (int)post('id');
            $title = (string)post('title');
            if ($title === '') throw new RuntimeException('Le titre est obligatoire.');
            $col = one('SELECT * FROM board_columns WHERE id = ? AND board_id = ?', [(int)post('column_id'), $boardId]);
            if (!$col) $col = one('SELECT * FROM board_columns WHERE board_id = ? ORDER BY position LIMIT 1', [$boardId]);
            $assignee = (int)post('assignee_id') ?: null;
            if ($assignee && !one('SELECT id FROM users WHERE id = ? AND active = 1', [$assignee])) $assignee = null;
            $prio = (string)post('priority', 'normale');
            $data = [
                'title' => mb_substr($title, 0, 200), 'description' => mb_substr((string)post('description'), 0, 4000),
                'assignee_id' => $assignee, 'priority' => isset(PRIORITIES[$prio]) ? $prio : 'normale',
                'due_date' => parse_date(post('due_date')), 'labels' => mb_substr((string)post('labels'), 0, 200),
                'column_id' => $col['id'], 'updated_at' => now(),
            ];
            if ($id) {
                $t = one('SELECT * FROM tasks WHERE id = ? AND board_id = ?', [$id, $boardId]);
                if (!$t) throw new RuntimeException('Tâche introuvable.');
                $data['done_at'] = $col['is_done'] ? ($t['done_at'] ?: now()) : null;
                if ((int)$t['column_id'] !== (int)$col['id']) $data['position'] = (int)val('SELECT COALESCE(MAX(position), -1) + 1 FROM tasks WHERE column_id = ?', [$col['id']]);
                update('tasks', $data, 'id = ?', [$id]);
                flash('ok', 'Tâche mise à jour.');
            } else {
                $data += ['board_id' => $boardId, 'created_by' => $u['id'], 'created_at' => now(),
                    'position' => (int)val('SELECT COALESCE(MAX(position), -1) + 1 FROM tasks WHERE column_id = ?', [$col['id']]),
                    'done_at' => $col['is_done'] ? now() : null];
                $id = insert('tasks', $data);
                flash('ok', 'Tâche ajoutée.');
            }
        } elseif ($a === 'delete') {
            q('DELETE FROM tasks WHERE id = ? AND board_id = ?', [(int)post('id'), $boardId]);
            flash('ok', 'Tâche supprimée.');
        } elseif ($a === 'col_save') {
            $name = (string)post('name');
            if ($name === '') throw new RuntimeException('Nom de colonne obligatoire.');
            $color = preg_match('/^#[0-9a-f]{6}$/i', (string)post('color')) ? post('color') : '#64748b';
            $data = ['name' => mb_substr($name, 0, 40), 'color' => $color, 'is_done' => post('is_done') ? 1 : 0];
            if ($cid = (int)post('id')) {
                update('board_columns', $data, 'id = ? AND board_id = ?', [$cid, $boardId]);
            } else {
                insert('board_columns', $data + ['board_id' => $boardId, 'position' => (int)val('SELECT COALESCE(MAX(position), -1) + 1 FROM board_columns WHERE board_id = ?', [$boardId])]);
            }
            flash('ok', 'Colonne enregistrée.');
        } elseif ($a === 'col_move') {
            $cols = array_map('intval', array_column(all('SELECT id FROM board_columns WHERE board_id = ? ORDER BY position, id', [$boardId]), 'id'));
            $i = array_search((int)post('id'), $cols, true);
            $j = $i === false ? false : $i + (post('dir') === 'left' ? -1 : 1);
            if ($i !== false && $j >= 0 && $j < count($cols)) {
                [$cols[$i], $cols[$j]] = [$cols[$j], $cols[$i]];
                foreach ($cols as $pos => $cid) q('UPDATE board_columns SET position = ? WHERE id = ?', [$pos, $cid]);
            }
        } elseif ($a === 'col_delete') {
            $cid = (int)post('id');
            if ((int)val('SELECT COUNT(*) FROM tasks WHERE column_id = ?', [$cid])) throw new RuntimeException('Videz la colonne avant de la supprimer.');
            if ((int)val('SELECT COUNT(*) FROM board_columns WHERE board_id = ?', [$boardId]) <= 1) throw new RuntimeException('Un tableau doit garder au moins une colonne.');
            q('DELETE FROM board_columns WHERE id = ? AND board_id = ?', [$cid, $boardId]);
            flash('ok', 'Colonne supprimée.');
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    }
    redirect(url('tasks', ['b' => $boardId, 'mine' => get('mine') ?: null]));
}

/* ---------- Affichage ---------- */

if (!$boards) {
    layout_start('Tâches', 'tasks');
    echo '<div class="empty"><h2>Aucun tableau accessible</h2></div>';
    layout_end();
    exit;
}
$default = $boards[0];
foreach ($boards as $b) {
    if (($u['role'] === 'marketing' && $b['kind'] === 'marketing') || ($u['role'] === 'showroom' && (int)$b['location_id'] === (int)$u['location_id'])) { $default = $b; break; }
}
$board = $default;
foreach ($boards as $b) if ((int)$b['id'] === (int)get('b')) $board = $b;
$editable = board_can_edit($board);
$mine = get('mine') === '1';
$q = (string)get('q');

$cols = all('SELECT * FROM board_columns WHERE board_id = ? ORDER BY position, id', [$board['id']]);
$where = 't.board_id = ?';
$p = [$board['id']];
if ($mine) { $where .= ' AND t.assignee_id = ?'; $p[] = $u['id']; }
if ($q !== '') { $where .= ' AND (t.title LIKE ? OR t.description LIKE ? OR t.labels LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
$tasks = all("SELECT t.*, u.name assignee FROM tasks t LEFT JOIN users u ON u.id = t.assignee_id WHERE $where ORDER BY t.position, t.id", $p);
$byCol = [];
foreach ($tasks as $t) $byCol[$t['column_id']][] = $t;
$users = all('SELECT id, name FROM users WHERE active = 1 ORDER BY name');
$userOpts = array_column($users, 'name', 'id');
$colOpts = array_column($cols, 'name', 'id');
$today = today();

$counts = [];
foreach (all("SELECT t.board_id, COUNT(*) n FROM tasks t JOIN board_columns c ON c.id = t.column_id WHERE c.is_done = 0 GROUP BY t.board_id") as $c) $counts[$c['board_id']] = (int)$c['n'];

$actions = (can('tasks.export') ? '<a class="btn sm ghost" href="' . e(url('io', ['a' => 'export', 'ds' => 'taches', 'board' => $board['id']])) . '">' . icon('download') . 'Export CSV</a>' : '')
    . ($editable && can('tasks.import') ? '<a class="btn sm ghost" href="' . e(url('io', ['ds' => 'taches', 'board' => $board['id']])) . '">' . icon('upload') . 'Import CSV</a>' : '')
    . ($editable ? '<button class="btn sm primary" type="button" data-new-task="' . (int)($cols[0]['id'] ?? 0) . '">' . icon('plus') . 'Nouvelle tâche</button>' : '');
layout_start('Tâches', 'tasks', ['actions' => $actions, 'wide' => true]);
?>
<div class="tabs">
  <?php foreach ($boards as $b): ?>
    <a class="<?= (int)$b['id'] === (int)$board['id'] ? 'on' : '' ?>" href="<?= e(url('tasks', ['b' => $b['id']])) ?>"><?= e($b['name']) ?> <span class="count"><?= $counts[$b['id']] ?? 0 ?></span></a>
  <?php endforeach; ?>
</div>
<form class="filters slim" method="get">
  <input type="hidden" name="r" value="tasks"><input type="hidden" name="b" value="<?= (int)$board['id'] ?>">
  <label class="grow"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher une tâche…"></label>
  <label class="check"><input type="checkbox" name="mine" value="1" <?= $mine ? 'checked' : '' ?> onchange="this.form.submit()"> Mes tâches</label>
  <?php if (!$editable): ?><span class="badge">Lecture seule</span><?php endif; ?>
</form>

<div class="kanban" id="kanban" data-move-url="<?= e(url('tasks', ['a' => 'move'])) ?>" data-editable="<?= $editable ? '1' : '0' ?>">
  <?php foreach ($cols as $i => $c): $list = $byCol[$c['id']] ?? []; ?>
    <section class="kcol" data-col="<?= (int)$c['id'] ?>">
      <header class="kcol-head">
        <span class="dot" style="background:<?= e($c['color']) ?>"></span>
        <b><?= e($c['name']) ?></b><span class="count"><?= count($list) ?></span>
        <?php if ($editable): ?>
          <button class="icon-btn xs" type="button" title="Modifier la colonne" data-col-edit='<?= e(json_encode(['id' => (int)$c['id'], 'name' => $c['name'], 'color' => $c['color'], 'is_done' => (int)$c['is_done'], 'first' => $i === 0, 'last' => $i === count($cols) - 1, 'empty' => !$list])) ?>'><?= icon('edit') ?></button>
        <?php endif; ?>
      </header>
      <div class="kcards" data-drop>
        <?php foreach ($list as $t):
            $overdue = $t['due_date'] && $t['due_date'] < $today && !$c['is_done'];
            $json = ['id' => (int)$t['id'], 'title' => $t['title'], 'description' => $t['description'], 'column_id' => (int)$t['column_id'],
                'assignee_id' => $t['assignee_id'], 'priority' => $t['priority'], 'due_date' => $t['due_date'], 'labels' => $t['labels']]; ?>
          <article class="kcard prio-<?= e($t['priority']) ?><?= $c['is_done'] ? ' done' : '' ?>" draggable="<?= $editable ? 'true' : 'false' ?>" data-id="<?= (int)$t['id'] ?>" data-task='<?= e(json_encode($json)) ?>' tabindex="0">
            <?php if ($t['labels']): ?><div class="klabels"><?php foreach (array_filter(array_map('trim', explode(',', $t['labels']))) as $lb) echo '<span>' . e($lb) . '</span>'; ?></div><?php endif; ?>
            <h4><?= e($t['title']) ?></h4>
            <?php if ($t['description']): ?><p><?= e(mb_strimwidth($t['description'], 0, 110, '…')) ?></p><?php endif; ?>
            <footer>
              <?php if (in_array($t['priority'], ['haute', 'urgente'], true)): ?><span class="prio"><?= e(PRIORITIES[$t['priority']]) ?></span><?php endif; ?>
              <?php if ($t['due_date']): ?><span class="due<?= $overdue ? ' late' : '' ?>"><?= icon('calendar') ?><?= fdate($t['due_date']) ?></span><?php endif; ?>
              <?php if ($t['assignee']): ?><span class="avatar xs" title="<?= e($t['assignee']) ?>"><?= e(initials($t['assignee'])) ?></span><?php endif; ?>
            </footer>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if ($editable): ?>
        <form method="post" action="<?= e(url('tasks', ['a' => 'save'])) ?>" class="kquick">
          <?= csrf_field() ?><input type="hidden" name="board_id" value="<?= (int)$board['id'] ?>"><input type="hidden" name="column_id" value="<?= (int)$c['id'] ?>">
          <input name="title" placeholder="+ Ajouter une tâche" maxlength="200" required>
        </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  <?php if ($editable): ?>
    <section class="kcol kcol-new">
      <form method="post" action="<?= e(url('tasks', ['a' => 'col_save'])) ?>">
        <?= csrf_field() ?><input type="hidden" name="board_id" value="<?= (int)$board['id'] ?>">
        <input name="name" placeholder="+ Nouvelle colonne" maxlength="40" required>
        <input type="hidden" name="color" value="#64748b">
      </form>
    </section>
  <?php endif; ?>
</div>

<dialog id="task-dlg" class="dlg">
  <form method="post" action="<?= e(url('tasks', ['a' => 'save'])) ?>" class="dlg-body">
    <?= csrf_field() ?><input type="hidden" name="board_id" value="<?= (int)$board['id'] ?>"><input type="hidden" name="id">
    <header><h2 data-title>Tâche</h2><button class="icon-btn" type="button" data-close><?= icon('x') ?></button></header>
    <label>Titre<input name="title" required maxlength="200"></label>
    <label>Description<textarea name="description" rows="4"></textarea></label>
    <div class="grid2 tight">
      <label>Colonne<select name="column_id"><?= opt($colOpts, '') ?></select></label>
      <label>Priorité<select name="priority"><?= opt(PRIORITIES, 'normale') ?></select></label>
      <label>Responsable<select name="assignee_id"><?= opt($userOpts, '', true, 'Personne') ?></select></label>
      <label>Échéance<input type="date" name="due_date"></label>
    </div>
    <label>Étiquettes <small class="muted">(séparées par des virgules)</small><input name="labels" maxlength="200" placeholder="vidéo, promo, TikTok"></label>
    <footer>
      <?php if ($editable): ?><button class="btn ghost danger" type="submit" formaction="<?= e(url('tasks', ['a' => 'delete'])) ?>" formnovalidate data-delete onclick="return confirm('Supprimer cette tâche ?')"><?= icon('trash') ?>Supprimer</button><?php endif; ?>
      <span class="grow"></span>
      <button class="btn ghost" type="button" data-close>Fermer</button>
      <?php if ($editable): ?><button class="btn primary"><?= icon('check') ?>Enregistrer</button><?php endif; ?>
    </footer>
  </form>
</dialog>

<?php if ($editable): ?>
<dialog id="col-dlg" class="dlg">
  <form method="post" action="<?= e(url('tasks', ['a' => 'col_save'])) ?>" class="dlg-body">
    <?= csrf_field() ?><input type="hidden" name="board_id" value="<?= (int)$board['id'] ?>"><input type="hidden" name="id">
    <header><h2>Colonne</h2><button class="icon-btn" type="button" data-close><?= icon('x') ?></button></header>
    <label>Nom<input name="name" required maxlength="40"></label>
    <label>Couleur<input type="color" name="color"></label>
    <label class="check"><input type="checkbox" name="is_done" value="1"> Les tâches de cette colonne sont terminées</label>
    <footer class="wrap">
      <button class="btn ghost sm" type="submit" name="dir" value="left" formaction="<?= e(url('tasks', ['a' => 'col_move'])) ?>" formnovalidate data-left><?= icon('left') ?>Gauche</button>
      <button class="btn ghost sm" type="submit" name="dir" value="right" formaction="<?= e(url('tasks', ['a' => 'col_move'])) ?>" formnovalidate data-right>Droite<?= icon('right') ?></button>
      <button class="btn ghost danger sm" type="submit" formaction="<?= e(url('tasks', ['a' => 'col_delete'])) ?>" formnovalidate data-del onclick="return confirm('Supprimer cette colonne ?')"><?= icon('trash') ?>Supprimer</button>
      <span class="grow"></span>
      <button class="btn primary"><?= icon('check') ?>Enregistrer</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>
<?php
layout_end(['kanban.js']);
