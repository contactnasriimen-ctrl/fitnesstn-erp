<?php
declare(strict_types=1);

require_perm('audit');
$f = ['user' => (int)get('user'), 'q' => (string)get('q'), 'from' => parse_date(get('from')) ?? date('Y-m-d', strtotime('-7 days')), 'to' => parse_date(get('to')) ?? today()];
$where = ['date(a.created_at) BETWEEN ? AND ?'];
$p = [$f['from'], $f['to']];
if ($f['user']) { $where[] = 'a.user_id = ?'; $p[] = $f['user']; }
if ($f['q'] !== '') { $where[] = '(a.action LIKE ? OR a.details LIKE ?)'; array_push($p, "%{$f['q']}%", "%{$f['q']}%"); }
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM audit_log a WHERE $w", $p);
$page = max(1, (int)get('page', 1));
$rows = all("SELECT a.*, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE $w ORDER BY a.id DESC LIMIT 100 OFFSET " . (($page - 1) * 100), $p);
$users = array_column(all('SELECT id, name FROM users ORDER BY name'), 'name', 'id');
layout_start('Journal d’activité', 'audit');
?>
<form class="filters" method="get">
  <input type="hidden" name="r" value="audit">
  <label>Du<input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label>Au<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <label>Utilisateur<select name="user"><?= opt($users, $f['user'], true, 'Tous') ?></select></label>
  <label class="grow">Recherche<input name="q" value="<?= e($f['q']) ?>" placeholder="vente, annulation, prix, clôture…"></label>
  <button class="btn"><?= icon('search') ?>Filtrer</button>
</form>
<div class="card flush">
  <table class="tbl">
    <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Détails</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="nowrap"><?= fdate($r['created_at'], true) ?></td><td><?= e($r['name'] ?: '—') ?></td><td><b><?= e($r['action']) ?></b></td><td class="muted"><?= e($r['details']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="empty-row">Aucune activité.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= pager($total, $page, 100, array_filter($f), 'audit') ?>
<?php
layout_end();
