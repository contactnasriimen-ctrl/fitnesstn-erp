/* Devis & factures : saisie des lignes avec calculs en direct, recherche client / produit, aperçu A4. */
(() => {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const fmt = (m, unit = false) => (m / 1000).toLocaleString('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + (unit ? ' DT' : '');
  const inp = (m) => { if (m === null || m === undefined || m === '') return ''; const s = (m / 1000).toFixed(3).replace('.', ','); return s.replace(/,?0+$/, '') || '0'; };
  const parse = (v) => {
    let s = String(v ?? '').replace(/[\s  ]|dt/gi, '');
    if (!s) return null;
    if (s.includes(',') && s.includes('.')) s = s.replace(/\./g, '');
    const n = Number(s.replace(',', '.'));
    return Number.isFinite(n) ? Math.round(n * 1000) : null;
  };
  const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- Aperçu A4 mis à l'échelle ---------- */
  $$('[data-paper-frame]').forEach((box) => {
    const fit = () => box.style.setProperty('--s', Math.min(1, box.clientWidth / 794).toFixed(4));
    fit();
    new ResizeObserver(fit).observe(box);
  });

  const form = $('[data-doc-form]');
  if (!form) return;
  const D = JSON.parse($('[data-doc-json]', form).textContent);
  const tbody = $('[data-lines]', form);
  const tpl = $('[data-line-tpl]', form);
  let seq = 0;

  /* ---------- Lignes ---------- */
  const lineData = (tr) => {
    const g = (f) => $(`[data-f="${f}"]`, tr).value;
    const qty = Math.max(0, parseInt(g('qty'), 10) || 0);
    const unit = parse(g('unit_price')) || 0;
    const disc = Math.min(100, Math.max(0, Number(String(g('discount_pct')).replace(',', '.')) || 0));
    const vat = Number(g('vat_rate')) || 0;
    return { qty, unit, disc, vat, total: Math.round(unit * qty * (1 - disc / 100)) };
  };

  function addLine(it = {}, focus = false) {
    const tr = tpl.content.firstElementChild.cloneNode(true);
    const i = seq++;
    $$('[data-f]', tr).forEach((el) => { el.name = `items[${i}][${el.dataset.f}]`; });
    const set = (f, v) => { const el = $(`[data-f="${f}"]`, tr); if (v !== undefined && v !== null) el.value = v; };
    set('product_id', it.product_id || '');
    set('label', it.label || '');
    set('description', it.description || '');
    set('qty', it.qty || 1);
    set('unit_price', it.unit_price !== undefined ? inp(it.unit_price) : '');
    set('discount_pct', it.discount_pct ? String(it.discount_pct).replace('.', ',') : '');
    set('vat_rate', String(Math.round(it.vat_rate ?? D.vat)));
    const meta = [];
    if (it.sku) meta.push('Réf. ' + it.sku);
    if (it.w) meta.push('Garantie ' + (it.w % 12 === 0 ? `${it.w / 12} an${it.w > 12 ? 's' : ''}` : `${it.w} mois`));
    if (it.product_id) meta.unshift('Catalogue');
    $('.dl-meta', tr).textContent = meta.join(' · ');
    if (it.img) $('.dl-thumb', tr).innerHTML = `<img src="${esc(it.img)}" alt="" loading="lazy" onerror="this.remove()">`;
    tr.classList.toggle('from-cat', !!it.product_id);
    tbody.appendChild(tr);
    syncHt(tr);
    refresh();
    if (focus) {
      const target = it.product_id ? $('[data-f="qty"]', tr) : $('[data-f="label"]', tr);
      target.focus(); target.select();
    }
    return tr;
  }

  const syncHt = (tr) => {
    const { unit, vat } = lineData(tr);
    const ttc = $('[data-f="unit_price"]', tr).value;
    $('[data-ht]', tr).value = ttc === '' ? '' : inp(Math.round(unit / (1 + vat / 100)));
  };
  const syncTtc = (tr) => {
    const ht = parse($('[data-ht]', tr).value);
    const vat = Number($('[data-f="vat_rate"]', tr).value) || 0;
    $('[data-f="unit_price"]', tr).value = ht === null ? '' : inp(Math.round(ht * (1 + vat / 100)));
  };

  tbody.addEventListener('input', (e) => {
    const tr = e.target.closest('tr');
    if (!tr) return;
    if (e.target.matches('[data-ht]')) syncTtc(tr);
    else if (e.target.matches('[data-f="unit_price"]')) syncHt(tr);
    refresh();
  });
  tbody.addEventListener('change', (e) => {
    const tr = e.target.closest('tr');
    if (tr && e.target.matches('[data-f="vat_rate"]')) { syncTtc(tr); refresh(); }
  });
  tbody.addEventListener('click', (e) => {
    const del = e.target.closest('[data-del]');
    if (!del) return;
    del.closest('tr').remove();
    refresh();
  });

  /* ---------- Totaux ---------- */
  const words = $('[data-words]', form);
  function refresh() {
    let sub = 0;
    const byRate = {};
    $$('tr', tbody).forEach((tr) => {
      const l = lineData(tr);
      $('.dl-total', tr).textContent = fmt(l.total);
      sub += l.total;
      byRate[l.vat] = (byRate[l.vat] || 0) + l.total;
    });
    const discount = Math.min(sub, Math.max(0, parse($('[data-discount]', form).value) || 0));
    const total = sub - discount;
    const ratio = sub ? total / sub : 0;
    let ht = 0;
    const vatRows = [];
    Object.keys(byRate).map(Number).sort((a, b) => b - a).forEach((r) => {
      const ttc = Math.round(byRate[r] * ratio);
      const h = Math.round(ttc / (1 + r / 100));
      ht += h;
      if (r > 0) vatRows.push(`<div><dt>TVA ${String(r).replace('.', ',')} %</dt><dd>${fmt(ttc - h)}</dd></div>`);
    });
    const timbre = $('[data-timbre]', form).checked ? D.timbre : 0;
    const set = (k, v) => { $(`[data-t="${k}"]`, form).textContent = v; };
    set('subtotal', fmt(sub));
    set('ht', fmt(ht));
    set('timbre', fmt(timbre));
    set('net', fmt(total + timbre, true));
    $('[data-vat-rows]', form).innerHTML = vatRows.join('');
    const n = $$('tr', tbody).length;
    $('[data-lines-empty]', form).hidden = n > 0;
    $('.doc-lines', form).hidden = n === 0;
    words.textContent = n ? `${n} ligne${n > 1 ? 's' : ''} · ${$$('tr', tbody).reduce((s, tr) => s + lineData(tr).qty, 0)} article(s)` : '';
  }
  form.addEventListener('input', (e) => { if (e.target.matches('[data-discount]')) refresh(); });
  form.addEventListener('change', (e) => { if (e.target.matches('[data-timbre]')) refresh(); });

  /* ---------- Recherche dans une liste de suggestions ---------- */
  function combo(input, box, search, render, pick) {
    let items = [];
    let active = -1;
    let blurTimer = null;
    const close = () => { box.hidden = true; active = -1; };
    const paint = () => {
      box.innerHTML = items.length ? items.map((it, i) => `<button type="button" role="option" data-i="${i}" class="${i === active ? 'on' : ''}">${render(it)}</button>`).join('')
        : '<div class="sg-empty">Aucun résultat</div>';
      box.hidden = false;
    };
    const run = async () => {
      const q = input.value.trim();
      if (q.length < 2) { close(); return; }
      items = await search(q);
      if (input.value.trim() !== q) return;
      active = items.length ? 0 : -1;
      paint();
    };
    input.addEventListener('input', () => { clearTimeout(blurTimer); run(); });
    input.addEventListener('focus', () => { clearTimeout(blurTimer); if (input.value.trim().length >= 2) run(); });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') e.preventDefault();
      if (box.hidden) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        paint();
        box.querySelector('.on')?.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter') {
        if (items[active]) { pick(items[active]); close(); }
      } else if (e.key === 'Escape') close();
    });
    box.addEventListener('mousedown', (e) => e.preventDefault());
    box.addEventListener('click', (e) => {
      const b = e.target.closest('[data-i]');
      if (b) { pick(items[+b.dataset.i]); close(); }
    });
    input.addEventListener('blur', () => { blurTimer = setTimeout(close, 120); });
  }

  // Entrée dans un champ passe au champ suivant au lieu d'enregistrer le document
  form.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !e.target.matches('input:not([type=checkbox]), select') || e.defaultPrevented) return;
    e.preventDefault();
    const fields = $$('input:not([type=hidden]):not([disabled]), select, textarea, button.primary', form).filter((el) => el.offsetParent !== null);
    const next = fields[fields.indexOf(e.target) + 1];
    if (next) { next.focus(); if (next.select) next.select(); }
  });

  // Produits du catalogue (recherche locale)
  const prodQ = $('[data-prod-q]', form);
  const prods = D.products.map((p) => ({ ...p, k: norm(p.name + ' ' + (p.sku || '') + ' ' + (p.fam || '')) }));
  combo(prodQ, $('[data-prod-suggest]', form),
    async (q) => { const words = norm(q).split(/\s+/).filter(Boolean); return prods.filter((p) => words.every((w) => p.k.includes(w))).slice(0, 12); },
    (p) => `<span class="sg-img">${p.img ? `<img src="${esc(p.img)}" alt="" loading="lazy" onerror="this.remove()">` : ''}</span><span class="sg-main"><b>${esc(p.name)}</b><small>${esc([p.sku, p.fam].filter(Boolean).join(' · '))}</small></span><span class="sg-price">${p.price ? fmt(p.price, true) : 'Sur devis'}</span>`,
    (p) => {
      addLine({ product_id: p.id, label: p.name, qty: 1, unit_price: p.price || null, vat_rate: D.vat, sku: p.sku, w: p.w, img: p.img }, true);
      prodQ.value = '';
    });
  $('[data-add-free]', form).addEventListener('click', () => addLine({ vat_rate: D.vat }, true));

  // Clients (recherche serveur)
  const cq = $('[data-client-q]', form);
  const cf = (k) => $(`[data-c="${k}"]`, form);
  const linked = $('[data-client-linked]', form);
  combo(cq, $('[data-client-suggest]', form),
    async (q) => { try { const r = await fetch(D.clientsUrl + '&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }); return (await r.json()).clients || []; } catch (e) { return []; } },
    (c) => `<span class="sg-main"><b>${esc(c.name)}</b><small>${esc([c.phone, c.mf ? 'MF ' + c.mf : '', c.city || c.gouvernorat].filter(Boolean).join(' · '))}</small></span>`,
    (c) => {
      cf('id').value = c.id;
      cf('name').value = c.name || '';
      cf('phone').value = c.phone || '';
      cf('mf').value = c.mf || '';
      cf('email').value = c.email || '';
      cf('address').value = [c.address, c.city, c.gouvernorat].filter(Boolean).join(', ');
      linked.hidden = false;
      cq.value = '';
    });
  $('[data-client-unlink]', form).addEventListener('click', () => { cf('id').value = ''; linked.hidden = true; cf('name').focus(); });
  // Changer le nom d'un client lié crée un nouveau client
  cf('name').addEventListener('input', () => { if (cf('id').value) { cf('id').value = ''; linked.hidden = true; } });

  /* ---------- Envoi ---------- */
  form.addEventListener('submit', (e) => {
    const errs = [];
    if (!cf('name').value.trim()) errs.push('le nom du client');
    const rows = $$('tr', tbody);
    if (!rows.length) errs.push('au moins un article');
    rows.forEach((tr) => {
      const lab = $('[data-f="label"]', tr);
      const bad = !lab.value.trim() || $('[data-f="unit_price"]', tr).value.trim() === '' || lineData(tr).qty < 1;
      tr.classList.toggle('invalid', bad);
      if (bad && !errs.includes('les lignes incomplètes (désignation, quantité, prix)')) errs.push('les lignes incomplètes (désignation, quantité, prix)');
    });
    if (errs.length) {
      e.preventDefault();
      let box = $('.form-error', form);
      if (!box) { box = document.createElement('div'); box.className = 'flash err form-error'; form.prepend(box); }
      box.textContent = 'À compléter : ' + errs.join(', ') + '.';
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    form.querySelector('button.primary').disabled = true;
  });

  // Quitter la page avec des modifications non enregistrées
  let dirty = false;
  form.addEventListener('input', () => { dirty = true; });
  form.addEventListener('submit', (e) => { if (!e.defaultPrevented) dirty = false; });
  addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  D.items.forEach((it) => addLine(it));
  refresh();
  if (!cf('name').value) cq.focus();
})();
