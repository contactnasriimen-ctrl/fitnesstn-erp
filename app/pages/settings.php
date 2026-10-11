<?php
declare(strict_types=1);

require_perm('settings');
$a = (string)get('a');

if ($a === 'backup') {
    $tmp = DATA_DIR . '/tmp/sauvegarde-' . bin2hex(random_bytes(4)) . '.sqlite';
    if (!is_dir(dirname($tmp))) mkdir(dirname($tmp), 0775, true);
    db()->exec('VACUUM INTO ' . db()->quote($tmp));
    audit('sauvegarde téléchargée', 'settings');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="erp-fitnesstn-' . date('Y-m-d-His') . '.sqlite"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

if (is_post()) {
    csrf_check();
    try {
        if ($a === 'general') {
            foreach (['company_name', 'company_phone', 'company_email', 'company_site', 'company_mf', 'ticket_footer', 'invoice_note'] as $k) {
                set_setting($k, mb_substr((string)post($k), 0, 500));
            }
            $vat = (float)str_replace(',', '.', (string)post('vat_rate'));
            if (!in_array($vat, [0.0, 7.0, 13.0, 19.0], true)) throw new RuntimeException('Taux de TVA : 0, 7, 13 ou 19 %.');
            set_setting('vat_rate', (string)$vat);
            set_setting('timbre', (string)max(0, parse_money(post('timbre')) ?? 0));
            set_setting('default_float', (string)max(0, parse_money(post('default_float')) ?? 0));
            set_setting('cash_diff_threshold', (string)max(0, parse_money(post('cash_diff_threshold')) ?? 0));
            $max = (float)str_replace(',', '.', (string)post('max_discount_pct'));
            set_setting('max_discount_pct', (string)max(0, min(100, $max)));
            set_setting('allow_negative_stock', post('allow_negative_stock') ? '1' : '0');
            audit('paramètres modifiés', 'settings');
            flash('ok', 'Paramètres enregistrés.');
        }
        if ($a === 'location') {
            $id = (int)post('id');
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)post('code')));
            $name = (string)post('name');
            $type = post('type') === 'depot' ? 'depot' : 'showroom';
            if ($name === '' || strlen($code) < 2 || strlen($code) > 5) throw new RuntimeException('Nom obligatoire et code de 2 à 5 lettres (ex. SFX).');
            if (val('SELECT id FROM locations WHERE code = ? AND id <> ?', [$code, $id])) throw new RuntimeException('Ce code est déjà utilisé.');
            $data = ['code' => $code, 'name' => $name, 'type' => $type, 'address' => (string)post('address'), 'phone' => (string)post('phone'), 'active' => post('active') ? 1 : 0];
            if ($id) {
                $old = location($id);
                if ($old && $old['code'] !== $code && (int)val('SELECT COUNT(*) FROM sales WHERE location_id = ?', [$id])) throw new RuntimeException('Le code d’un site qui a déjà des ventes ne peut plus changer (il sert aux numéros de ticket).');
                update('locations', $data, 'id = ?', [$id]);
                q('UPDATE boards SET name = ? WHERE key = ?', [$name, 'loc:' . $id]);
            } else {
                $id = insert('locations', $data + ['position' => (int)val('SELECT COALESCE(MAX(position),0)+1 FROM locations')]);
            }
            if ($type === 'showroom' && !val('SELECT id FROM boards WHERE key = ?', ['loc:' . $id])) {
                $bid = insert('boards', ['key' => 'loc:' . $id, 'name' => $name, 'kind' => 'showroom', 'location_id' => $id, 'position' => 5 + $id]);
                foreach ([['À faire', 0, '#64748b'], ['En cours', 0, '#1F7BFF'], ['En validation', 0, '#f59e0b'], ['Terminé', 1, '#16a34a']] as $p => $c) {
                    insert('board_columns', ['board_id' => $bid, 'name' => $c[0], 'position' => $p, 'is_done' => $c[1], 'color' => $c[2]]);
                }
            }
            audit('site enregistré', 'location', $id, $name);
            flash('ok', 'Site enregistré.');
        }
    } catch (RuntimeException $e) {
        flash('err', $e->getMessage());
    }
    redirect(url('settings'));
}

$s = fn(string $k, $d = '') => setting($k, $d);
$locs = locations(false);
layout_start('Paramètres', 'settings', ['actions' => '<a class="btn sm ghost" href="' . e(url('settings', ['a' => 'backup'])) . '">' . icon('download') . 'Télécharger une sauvegarde</a>']);
?>
<form method="post" action="<?= e(url('settings', ['a' => 'general'])) ?>" class="card form-grid">
  <?= csrf_field() ?>
  <h2 class="span2">Entreprise & documents</h2>
  <label>Nom commercial<input name="company_name" value="<?= e($s('company_name')) ?>" required></label>
  <label>Matricule fiscal<input name="company_mf" value="<?= e($s('company_mf')) ?>" placeholder="À renseigner pour les factures"></label>
  <label>Téléphone<input name="company_phone" value="<?= e($s('company_phone')) ?>"></label>
  <label>E-mail<input name="company_email" value="<?= e($s('company_email')) ?>"></label>
  <label>Site web<input name="company_site" value="<?= e($s('company_site')) ?>"></label>
  <label>TVA par défaut (%)<select name="vat_rate"><?= opt(['19' => '19 %', '13' => '13 %', '7' => '7 %', '0' => '0 %'], (string)(int)$s('vat_rate', 19)) ?></select></label>
  <label>Timbre fiscal (DT)<input name="timbre" inputmode="decimal" value="<?= e(m_in($s('timbre', 1000))) ?>"><small>Ajouté en caisse avec le bouton « Timbre fiscal »</small></label>
  <label>Pied de ticket<textarea name="ticket_footer" rows="2"><?= e($s('ticket_footer')) ?></textarea></label>
  <label class="span2">Mention en bas des factures<input name="invoice_note" value="<?= e($s('invoice_note')) ?>"></label>
  <h2 class="span2">Caisse & stock</h2>
  <label>Fond de caisse par défaut (DT)<input name="default_float" inputmode="decimal" value="<?= e(m_in($s('default_float', 300000))) ?>"></label>
  <label>Écart de caisse toléré sans justification (DT)<input name="cash_diff_threshold" inputmode="decimal" value="<?= e(m_in($s('cash_diff_threshold', 5000))) ?>"></label>
  <label>Remise maximale des responsables showroom (%)<input name="max_discount_pct" inputmode="decimal" value="<?= e($s('max_discount_pct', 20)) ?>"><small>Au-delà, seule la direction peut valider la vente.</small></label>
  <label class="check"><input type="checkbox" name="allow_negative_stock" value="1" <?= $s('allow_negative_stock', '1') === '1' ? 'checked' : '' ?>> Autoriser la vente même si le stock du showroom est à zéro<small>(utile tant que l’inventaire initial n’est pas fait)</small></label>
  <p class="span2 hint">Les règles fiscales (TVA, timbre, mentions de facture) sont à valider avec votre expert-comptable.</p>
  <div class="span2 row-actions"><button class="btn primary"><?= icon('check') ?>Enregistrer</button></div>
</form>

<section class="card flush">
  <div class="card-head"><b>Showrooms & dépôts</b><span class="muted">Le code sert de préfixe aux numéros de ticket (ex. TUN-26-00001).</span></div>
  <table class="tbl">
    <thead><tr><th>Code</th><th>Nom</th><th>Type</th><th>Adresse</th><th>Téléphone</th><th>Actif</th><th></th></tr></thead>
    <?php $rowsL = array_merge($locs, [['id' => 0, 'code' => '', 'name' => '', 'type' => 'showroom', 'address' => '', 'phone' => '', 'active' => 1]]);
    foreach ($rowsL as $l): $fid = 'locf' . (int)$l['id']; ?>
      <tr>
        <td><input form="<?= $fid ?>" name="code" value="<?= e($l['code']) ?>" maxlength="5" size="5" required placeholder="SFX"></td>
        <td><input form="<?= $fid ?>" name="name" value="<?= e($l['name']) ?>" required placeholder="<?= $l['id'] ? '' : 'Nouveau site…' ?>"></td>
        <td><select form="<?= $fid ?>" name="type"><?= opt(['showroom' => 'Showroom', 'depot' => 'Dépôt'], $l['type']) ?></select></td>
        <td><input form="<?= $fid ?>" name="address" value="<?= e($l['address']) ?>"></td>
        <td><input form="<?= $fid ?>" name="phone" value="<?= e($l['phone']) ?>" size="14"></td>
        <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1" <?= $l['active'] ? 'checked' : '' ?> aria-label="Actif"></td>
        <td><button form="<?= $fid ?>" class="btn sm<?= $l['id'] ? ' ghost' : ' primary' ?>"><?= $l['id'] ? 'Enregistrer' : 'Ajouter' ?></button></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php foreach ($rowsL as $l): ?>
    <form id="locf<?= (int)$l['id'] ?>" method="post" action="<?= e(url('settings', ['a' => 'location'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"></form>
  <?php endforeach; ?>
</section>
<p class="hint">Version <?= APP_VERSION ?> · base de données : <code><?= e(basename(db_path())) ?></code> · pensez à télécharger une sauvegarde chaque semaine.</p>
<?php
layout_end();
