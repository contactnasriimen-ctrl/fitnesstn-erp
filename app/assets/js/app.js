/* Comportements communs de l'ERP. */
(() => {
  'use strict';
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const fmt = (m) => (m / 1000).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' DT';
  const parse = (v) => {
    let s = String(v ?? '').replace(/[\s  ]|dt/gi, '');
    if (!s) return null;
    if (s.includes(',') && s.includes('.')) s = s.replace(/\./g, '');
    const n = Number(s.replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 1000) : null;
  };

  // Menu latéral (mobile)
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-toggle-side]');
    const side = document.getElementById('side');
    if (t && side) { side.classList.toggle('open'); return; }
    if (side && side.classList.contains('open') && !e.target.closest('#side')) side.classList.remove('open');
  });

  // Lignes de tableau cliquables
  document.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-href]');
    if (!tr || e.target.closest('a, button, input, select, textarea, label, form')) return;
    if (e.ctrlKey || e.metaKey) window.open(tr.dataset.href, '_blank'); else location.href = tr.dataset.href;
  });

  // Fenêtres modales
  document.addEventListener('click', (e) => {
    const o = e.target.closest('[data-open]');
    if (o) { const d = document.getElementById(o.dataset.open); if (d) d.showModal(); return; }
    const c = e.target.closest('[data-close]');
    if (c) { const d = c.closest('dialog'); if (d) d.close(); }
  });

  // Période personnalisée (finances)
  $$('[data-period]').forEach((sel) => sel.addEventListener('change', () => {
    const custom = sel.value === 'perso';
    $$('[data-custom]', sel.form).forEach((el) => { el.hidden = !custom; });
    if (!custom) sel.form.submit();
  }));

  // Formulaire utilisateur : le showroom n'est demandé que pour un responsable showroom
  $$('[data-role-form]').forEach((f) => {
    const role = f.querySelector('[data-role]');
    const loc = f.querySelector('[data-loc]');
    const sync = () => { loc.hidden = role.value !== 'showroom'; };
    role.addEventListener('change', sync); sync();
  });

  // Filtre instantané des lignes d'un tableau
  $$('[data-filter-rows]').forEach((input) => {
    const table = document.querySelector(input.dataset.filterRows);
    if (!table) return;
    const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    input.addEventListener('input', () => {
      const q = norm(input.value.trim());
      table.querySelectorAll('tbody tr').forEach((tr) => { tr.hidden = q && !norm(tr.textContent).includes(q); });
    });
  });

  // Mouvement de stock : lignes multiples, transfert
  $$('[data-move-form]').forEach((f) => {
    const type = f.querySelector('[data-move-type]');
    const tbody = f.querySelector('.move-lines tbody');
    const sync = () => {
      const tr = type.value === 'transfert';
      f.querySelector('[data-to]').hidden = !tr;
      f.querySelector('[data-from-label]').textContent = tr ? 'Depuis' : 'Emplacement';
      $$('[data-cost-col]', f).forEach((el) => { el.hidden = type.value !== 'entree'; });
    };
    type.addEventListener('change', sync); sync();
    f.querySelector('[data-add-line]').addEventListener('click', () => {
      const row = tbody.rows[0].cloneNode(true);
      row.querySelectorAll('input').forEach((i) => { i.value = i.classList.contains('qty-in') ? '1' : ''; });
      row.querySelector('select').value = '';
      tbody.appendChild(row);
      row.querySelector('select').focus();
    });
    tbody.addEventListener('click', (e) => {
      if (!e.target.closest('[data-del-line]')) return;
      if (tbody.rows.length > 1) e.target.closest('tr').remove();
    });
  });

  // Clôture de caisse : billetage
  $$('[data-billetage]').forEach((f) => {
    const expected = +f.dataset.expected;
    const threshold = +f.dataset.threshold;
    const keep = f.querySelector('[name=keep]');
    const update = () => {
      let total = 0;
      $$('[data-den]', f).forEach((inp) => {
        const sub = (parseInt(inp.value, 10) || 0) * +inp.dataset.den;
        total += sub;
        inp.closest('tr').querySelector('[data-sub]').textContent = fmt(sub).replace(' DT', '');
      });
      f.querySelector('[data-counted]').textContent = fmt(total);
      const diff = total - expected;
      const d = f.querySelector('[data-diff]');
      d.textContent = (diff > 0 ? '+' : '') + fmt(diff);
      d.className = 'num ' + (Math.abs(diff) > threshold ? 'neg' : diff === 0 ? 'pos' : 'warn-t');
      const k = Math.min(parse(keep.value) || 0, total);
      f.querySelector('[data-tosafe]').textContent = `À verser au coffre : ${fmt(Math.max(0, total - k))} (fond conservé ${fmt(k)}).`;
    };
    f.addEventListener('input', update); update();
  });

  // Info-bulles des graphiques
  let tip = null;
  const showTip = (g, x, y) => {
    let data;
    try { data = JSON.parse(g.dataset.tip); } catch (e) { return; }
    if (!tip) { tip = document.createElement('div'); tip.className = 'chart-tip'; tip.setAttribute('role', 'status'); document.body.appendChild(tip); }
    tip.replaceChildren();
    const t = document.createElement('div'); t.className = 'tt'; t.textContent = data.t; tip.appendChild(t);
    data.r.forEach(([color, label, value]) => {
      const row = document.createElement('div'); row.className = 'tr' + (color ? '' : ' total');
      if (color) { const i = document.createElement('i'); i.style.background = color; row.appendChild(i); }
      const b = document.createElement('b'); b.textContent = value; row.appendChild(b);
      const s = document.createElement('span'); s.textContent = label; row.appendChild(s);
      tip.appendChild(row);
    });
    tip.hidden = false;
    const r = tip.getBoundingClientRect();
    let left = x + 14, top = y - r.height - 10;
    if (left + r.width > innerWidth - 8) left = x - r.width - 14;
    if (top < 8) top = y + 16;
    tip.style.left = Math.max(8, left) + 'px'; tip.style.top = top + 'px';
  };
  const hideTip = () => { if (tip) tip.hidden = true; };
  document.addEventListener('pointermove', (e) => {
    const g = e.target.closest && e.target.closest('.chart .col');
    if (g) showTip(g, e.clientX, e.clientY); else hideTip();
  });
  document.addEventListener('focusin', (e) => {
    const g = e.target.closest && e.target.closest('.chart .col');
    if (g) { const r = g.getBoundingClientRect(); showTip(g, r.left + r.width / 2, r.top + 20); }
  });
  document.addEventListener('focusout', hideTip);
  addEventListener('scroll', hideTip, { passive: true });
})();
